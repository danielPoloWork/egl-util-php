<?php

declare(strict_types=1);

namespace D4np\Utils\Bridge\Psr18\Tests;

use D4np\Utils\Bridge\Psr18\Psr18Client;
use D4np\Utils\Bridge\Psr18\RequestRefused;
use D4np\Utils\Bridge\Psr18\TransportFailed;
use D4np\Utils\Http\HttpClient;
use D4np\Utils\Http\HttpResponse;
use D4np\Utils\Support\HttpClientException;
use D4np\Utils\Support\UtilsThrowable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The PSR-18 contract, clause by clause — the **T-C** suite (spec 03, ADR-0075).
 *
 * Every test runs against **every PSR-17 implementation installed**, for the reason the PSR-7
 * bridge's T-B suite gives: a contract proven against one vendor silently encodes that vendor's
 * leniencies. CI pins one per matrix cell; locally both are present and both run. The provider
 * **throws** when none is installed rather than yielding nothing — an empty provider is a suite
 * that passes without testing anything.
 *
 * No network is touched. The core's `Transport` seam stands in for it
 * ({@see RecordingTransport}), which also makes the two assertions PSR-18 actually cares about
 * visible: *which* exception is raised, and *what* was handed to the wire.
 */
#[Group('T-C')]
final class Psr18ClientTest extends TestCase
{
    /**
     * @return iterable<string, array{object}>
     */
    public static function factories(): iterable
    {
        $found = false;

        if (\class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            $found = true;
            yield 'nyholm' => [new \Nyholm\Psr7\Factory\Psr17Factory()];
        }

        if (\class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $found = true;
            yield 'guzzle' => [new \GuzzleHttp\Psr7\HttpFactory()];
        }

        if (!$found) {
            throw new \RuntimeException(
                'No PSR-17 implementation is installed, so the PSR-18 contract would be asserted '
                . 'against nothing. Install nyholm/psr7 or guzzlehttp/psr7.',
            );
        }
    }

    /**
     * @param object $factory one object implements every PSR-17 interface in both vendors
     */
    private function clientFor(object $factory, RecordingTransport $transport): Psr18Client
    {
        /** @var ResponseFactoryInterface&StreamFactoryInterface $factory */
        return new Psr18Client(
            new HttpClient(transport: $transport),
            $factory,
            $factory,
        );
    }

    private function request(object $factory, string $method, string $uri): \Psr\Http\Message\RequestInterface
    {
        /** @var RequestFactoryInterface $factory */
        return $factory->createRequest($method, $uri);
    }

    // ---- the happy path ------------------------------------------------------------------------

    #[DataProvider('factories')]
    public function testItIsAPsr18Client(object $factory): void
    {
        self::assertInstanceOf(
            ClientInterface::class,
            $this->clientFor($factory, new RecordingTransport()),
        );
    }

    #[DataProvider('factories')]
    public function testAResponseComesBackAsAPsr7Message(object $factory): void
    {
        $transport = new RecordingTransport(new HttpResponse(
            201,
            ['Content-Type: application/json', 'X-Trace: abc'],
            '{"ok":true}',
        ));

        $response = $this->clientFor($factory, $transport)
            ->sendRequest($this->request($factory, 'GET', 'https://example.test/things'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"ok":true}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame('abc', $response->getHeaderLine('x-trace'));
    }

    /**
     * **The header case the PSR-7 bridge has to refuse, and this one does not.**
     *
     * `responseFromPsr7()` throws on a message carrying two `Set-Cookie` headers, because the
     * core's *server* `Response` holds one value per name and comma-joining cookie strings
     * corrupts them. `HttpResponse` — inbound, client-side — keeps a list per name, so the same
     * contract has the opposite outcome here. Asserted rather than claimed, because "different
     * type, different answer" is exactly the sort of thing that quietly stops being true.
     */
    #[DataProvider('factories')]
    public function testTwoSetCookieHeadersBothSurvive(object $factory): void
    {
        $transport = new RecordingTransport(new HttpResponse(
            200,
            ['Set-Cookie: a=1; Expires=Wed, 21 Oct 2026 07:28:00 GMT', 'Set-Cookie: b=2'],
            '',
        ));

        $response = $this->clientFor($factory, $transport)
            ->sendRequest($this->request($factory, 'GET', 'https://example.test/'));

        self::assertSame(
            ['a=1; Expires=Wed, 21 Oct 2026 07:28:00 GMT', 'b=2'],
            $response->getHeader('set-cookie'),
        );
    }

    /**
     * PSR-18: the client returns the response for **every** status the origin produced, and
     * reserves exceptions for requests that produced none. The core already agreed (ADR-0049), so
     * this asserts the agreement rather than a translation.
     */
    #[DataProvider('factories')]
    public function testAnErrorStatusIsAResponseAndNotAnException(object $factory): void
    {
        foreach ([404, 500] as $status) {
            $transport = new RecordingTransport(new HttpResponse($status, [], 'nope'));

            $response = $this->clientFor($factory, $transport)
                ->sendRequest($this->request($factory, 'GET', 'https://example.test/'));

            self::assertSame($status, $response->getStatusCode());
        }
    }

    /**
     * A 3xx is a response too — the client must not follow it unless configured to, and the core's
     * default is off.
     */
    #[DataProvider('factories')]
    public function testARedirectIsReturnedRatherThanFollowed(object $factory): void
    {
        $transport = new RecordingTransport(new HttpResponse(302, ['Location: https://elsewhere.test/'], ''));

        $response = $this->clientFor($factory, $transport)
            ->sendRequest($this->request($factory, 'GET', 'https://example.test/'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://elsewhere.test/', $response->getHeaderLine('location'));
        self::assertSame(1, $transport->calls, 'a followed redirect would have sent a second request');
        self::assertSame(0, $transport->options['http']['follow_location'] ?? null);
    }

    // ---- what reaches the wire -----------------------------------------------------------------

    #[DataProvider('factories')]
    public function testTheMethodUriAndBodyReachTheTransportUnchanged(object $factory): void
    {
        $transport = new RecordingTransport();
        /** @var StreamFactoryInterface $factory */
        $request = $this->request($factory, 'POST', 'https://example.test/things?a=1')
            ->withBody($factory->createStream('{"name":"Ada"}'))
            ->withHeader('Content-Type', 'application/json');

        $this->clientFor($factory, $transport)->sendRequest($request);

        self::assertSame('https://example.test/things?a=1', $transport->url);
        self::assertSame('POST', $transport->options['http']['method'] ?? null);
        self::assertSame('{"name":"Ada"}', $transport->options['http']['content'] ?? null);
        self::assertSame('application/json', $transport->sentHeaders()['content-type'] ?? null);
    }

    /**
     * A body already consumed by middleware is rewound before it is read, or the request would go
     * out empty — the failure that looks like "the request lost its payload".
     */
    #[DataProvider('factories')]
    public function testAnAlreadyReadBodyIsRewoundRatherThanSentEmpty(object $factory): void
    {
        $transport = new RecordingTransport();
        /** @var StreamFactoryInterface $factory */
        $stream = $factory->createStream('payload');
        $stream->getContents();   // drain it, as a middleware would

        $this->clientFor($factory, $transport)->sendRequest(
            $this->request($factory, 'POST', 'https://example.test/')->withBody($stream),
        );

        self::assertSame('payload', $transport->options['http']['content'] ?? null);
    }

    /**
     * PSR-7 guarantees the message carries a `Host` matching its URI, and the core derives one from
     * the URL it is given. Forwarding the header as well would put two `Host` lines in play.
     */
    #[DataProvider('factories')]
    public function testTheHostHeaderIsNotForwardedTwice(object $factory): void
    {
        $transport = new RecordingTransport();

        $this->clientFor($factory, $transport)
            ->sendRequest($this->request($factory, 'GET', 'https://example.test/'));

        self::assertArrayNotHasKey('host', $transport->sentHeaders());
    }

    /**
     * An empty PSR-7 body becomes `null`, not `''`: PSR-7 gives every request a stream whether or
     * not anything was written to it, and the core's signature distinguishes "no body" from "an
     * empty one". A `GET` through this bridge must look like a `GET` through `HttpClient::get()`.
     */
    #[DataProvider('factories')]
    public function testAGetCarriesNoBody(object $factory): void
    {
        $transport = new RecordingTransport();

        $this->clientFor($factory, $transport)
            ->sendRequest($this->request($factory, 'GET', 'https://example.test/'));

        self::assertArrayNotHasKey('content', $transport->options['http'] ?? []);
    }

    // ---- PSR-18's exception split --------------------------------------------------------------

    /**
     * ★ **The split PSR-18 exists for.** A malformed request and a dead network are different
     * failures because only the second is worth retrying, and the core raises one exception type
     * for both — so this asserts the classification, not merely that something was thrown.
     */
    #[DataProvider('factories')]
    public function testANetworkFailureIsANetworkException(object $factory): void
    {
        $transport = new RecordingTransport(failWith: 'connection refused');

        try {
            $this->clientFor($factory, $transport)
                ->sendRequest($this->request($factory, 'GET', 'https://example.test/'));
            self::fail('expected a network exception');
        } catch (NetworkExceptionInterface $e) {
            // Asserted before the narrowing below, so it stays a real check: PSR-18's two
            // exception kinds must be disjoint, or a retry middleware cannot tell them apart.
            self::assertNotInstanceOf(RequestExceptionInterface::class, $e, 'a dead socket is not a malformed request');
            self::assertInstanceOf(TransportFailed::class, $e);
            self::assertSame('https://example.test/', (string) $e->getRequest()->getUri());
        }
    }

    /**
     * The header-smuggling pre-flight, and **why it needs a hand-built request to reach at all**.
     *
     * Measured, not assumed: `withHeader('X-Trace', "ok\r\nX-Injected: yes")` **throws inside both
     * vendors** — nyholm's *"Header values must be RFC 7230 compatible strings"*, guzzle's *"is not
     * valid header value"*. A conformant PSR-7 implementation will not build the message, so this
     * path is unreachable through one.
     *
     * That does not make the pre-flight dead weight, and it is the reason it stays: PSR-18 hands
     * this client *a* `RequestInterface`, and guarantees nothing about who implemented it. The
     * mock below is exactly that case — an implementation that did not validate — and the
     * assertion is that the core's own guard (ADR-0025) still catches it *and* that the refusal is
     * classified as PSR-18's **request** failure rather than a network one, since retrying would
     * refuse identically forever.
     */
    #[DataProvider('factories')]
    public function testAHeaderThatCouldSmuggleASecondOneIsARequestException(object $factory): void
    {
        $transport = new RecordingTransport();
        /** @var \Psr\Http\Message\UriFactoryInterface&StreamFactoryInterface $factory */
        $hostile = "ok\r\nX-Injected: yes";

        $request = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $request->method('getUri')->willReturn($factory->createUri('https://example.test/'));
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaders')->willReturn(['X-Trace' => [$hostile]]);
        $request->method('getHeaderLine')->willReturn($hostile);
        $request->method('getBody')->willReturn($factory->createStream(''));

        try {
            $this->clientFor($factory, $transport)->sendRequest($request);
            self::fail('expected a request exception');
        } catch (RequestExceptionInterface $e) {
            self::assertInstanceOf(RequestRefused::class, $e);
            self::assertInstanceOf(HttpClientException::class, $e->getPrevious());
            self::assertSame(0, $transport->calls, 'nothing may be sent for a request refused as malformed');
        }
    }

    #[DataProvider('factories')]
    public function testAnUnsupportedSchemeIsARequestException(object $factory): void
    {
        $transport = new RecordingTransport();

        try {
            $this->clientFor($factory, $transport)
                ->sendRequest($this->request($factory, 'GET', 'ftp://example.test/file'));
            self::fail('expected a request exception');
        } catch (RequestExceptionInterface $e) {
            self::assertInstanceOf(RequestRefused::class, $e);
            self::assertSame(0, $transport->calls);
        }
    }

    /**
     * Both exceptions satisfy **both** hierarchies: PSR-18's, so ecosystem middleware recognises
     * them, and this library's `UtilsThrowable`, so a consumer's existing `catch` still works
     * (ADR-0004). Satisfying only one would make the class wrong for the other audience.
     */
    #[DataProvider('factories')]
    public function testBothExceptionsSatisfyBothHierarchies(object $factory): void
    {
        // A dead socket: the core threw, so its exception is carried as the cause.
        try {
            $this->clientFor($factory, new RecordingTransport(failWith: 'boom'))
                ->sendRequest($this->request($factory, 'GET', 'https://example.test/'));
            self::fail('expected a client exception');
        } catch (ClientExceptionInterface $e) {
            self::assertInstanceOf(TransportFailed::class, $e);
            self::assertInstanceOf(UtilsThrowable::class, $e);
            self::assertInstanceOf(HttpClientException::class, $e->getPrevious());
        }

        // A refused scheme: the bridge decided this one itself, before the core was asked, so
        // there is deliberately **no** cause to carry — inventing one would claim the core
        // spoke when it never saw the request.
        try {
            $this->clientFor($factory, new RecordingTransport())
                ->sendRequest($this->request($factory, 'GET', 'ftp://example.test/'));
            self::fail('expected a client exception');
        } catch (ClientExceptionInterface $e) {
            self::assertInstanceOf(RequestRefused::class, $e);
            self::assertInstanceOf(UtilsThrowable::class, $e);
            self::assertNull($e->getPrevious());
        }
    }

    // ---- the duplicated constant, kept in step -------------------------------------------------

    /**
     * ★ **The anti-drift assertion for `Psr18Client::ALLOWED_SCHEMES`.**
     *
     * That constant is duplicated from `HttpClient`'s `private const` of the same name — the
     * arrangement `QueryBuilder::LIKE_ESCAPE` has with `Sanitizer::LIKE_ESCAPE`, affordable only
     * because a test keeps the two in step. This is that test, and it asks the **real core**
     * rather than re-reading the bridge: each scheme the bridge permits must be one the core
     * actually sends, and a scheme it does not permit must be one the core actually refuses.
     *
     * Without this, the core narrowing its list would leave the bridge classifying a refusal as a
     * network failure — a retry loop against a request that can never succeed.
     */
    #[DataProvider('factories')]
    public function testTheBridgesSchemeListAgreesWithTheCores(object $factory): void
    {
        foreach (['http', 'https'] as $scheme) {
            $transport = new RecordingTransport();
            $this->clientFor($factory, $transport)
                ->sendRequest($this->request($factory, 'GET', $scheme . '://example.test/'));

            self::assertSame(1, $transport->calls, "the core must accept {$scheme}");
        }

        // And the core genuinely refuses one the bridge rejects, before any socket is opened.
        $transport = new RecordingTransport();
        $core = new HttpClient(transport: $transport);

        try {
            $core->send('GET', 'ftp://example.test/file');
            self::fail('the core was expected to refuse ftp, so the bridge duplicating that rule is wrong');
        } catch (HttpClientException) {
            self::assertSame(0, $transport->calls, 'the core must refuse before the transport is touched');
        }
    }
}
