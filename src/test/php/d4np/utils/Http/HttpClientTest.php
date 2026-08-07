<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\HttpClient;
use D4np\Utils\Http\HttpResponse;
use D4np\Utils\Http\Transport;
use D4np\Utils\Support\HttpClientException;
use D4np\Utils\Support\HttpException;
use D4np\Utils\Support\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `HttpClient` — spec r3 **FR-37** (RFC-0002), ADR-0049.
 *
 * The behavioural half of this class lives against a live origin in suite **T-07** (roadmap
 * item 11.4). What is asserted here is the half a live origin *cannot* show: the security
 * policy is a property of the context the client builds, and a request that succeeds against a
 * cooperative server proves nothing about whether TLS verification was ever switched on.
 *
 * So the policy is asserted as a **value** and the transport is driven by a fake — the shape
 * ADR-0026 arrived at for `Session`, for the same reason.
 */
final class HttpClientTest extends TestCase
{
    // ---- the policy, asserted as a value -----------------------------------------------------

    /**
     * The probe that motivated this: a freshly created stream context carries **no** `ssl`
     * options, so anything this client does not state is inherited from the process default —
     * which `stream_context_set_default()` lets any bootstrap file weaken. Each of these keys
     * is therefore expected to be present *and* correct, not merely correct-by-default.
     *
     * @param string|bool $expected
     */
    #[DataProvider('tlsPolicy')]
    public function testTlsOptionsAreStatedExplicitlyRatherThanInherited(string $key, mixed $expected): void
    {
        $options = (new HttpClient())->contextOptionsFor('GET');

        self::assertArrayHasKey($key, $options['ssl'], \sprintf(
            '"%s" is absent, so the process default decides it — which is exactly what an explicit policy must not allow',
            $key,
        ));
        self::assertSame($expected, $options['ssl'][$key]);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function tlsPolicy(): iterable
    {
        yield 'the certificate is verified' => ['verify_peer', true];
        yield 'the hostname is verified' => ['verify_peer_name', true];
        yield 'a self-signed certificate is not accepted' => ['allow_self_signed', false];
        yield 'SNI is on' => ['SNI_enabled', true];
        yield 'TLS compression is off' => ['disable_compression', true];
    }

    public function testEveryRequestCarriesATimeout(): void
    {
        $options = (new HttpClient(timeoutSeconds: 2.5))->contextOptionsFor('GET');

        self::assertSame(2.5, $options['http']['timeout']);
    }

    /**
     * The estate's helper had no timeout at all, so a client that could be built without one
     * would reproduce the defect this class exists to remove.
     */
    #[DataProvider('impossibleTimeouts')]
    public function testATimelessClientCannotBeConstructed(float $timeout, float $total): void
    {
        $this->expectException(HttpClientException::class);

        new HttpClient(timeoutSeconds: $timeout, totalTimeoutSeconds: $total);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function impossibleTimeouts(): iterable
    {
        yield 'zero per-phase' => [0.0, 30.0];
        yield 'negative per-phase' => [-1.0, 30.0];
        yield 'zero total' => [10.0, 0.0];
        yield 'a total below the per-phase value' => [10.0, 5.0];
    }

    public function testRedirectsAreNotFollowedByDefault(): void
    {
        $options = (new HttpClient())->contextOptionsFor('GET');

        self::assertSame(0, $options['http']['follow_location']);
        self::assertSame(0, $options['http']['max_redirects']);
    }

    public function testRedirectsAreFollowedOnlyWhenAskedFor(): void
    {
        $options = (new HttpClient(followRedirects: true, maxRedirects: 3))->contextOptionsFor('GET');

        self::assertSame(1, $options['http']['follow_location']);
        self::assertSame(3, $options['http']['max_redirects']);
    }

    /**
     * Without this the wrapper returns `false` for any 4xx/5xx and the response — status, body
     * and all — is simply gone.
     */
    public function testAnErrorResponseIsReadRatherThanDiscarded(): void
    {
        self::assertTrue((new HttpClient())->contextOptionsFor('GET')['http']['ignore_errors']);
    }

    public function testTheRequestIsHttp11(): void
    {
        // The wrapper still defaults to 1.0, which drops Host-based virtual hosting semantics
        // a modern origin assumes.
        self::assertSame(1.1, (new HttpClient())->contextOptionsFor('GET')['http']['protocol_version']);
    }

    // ---- refusals ----------------------------------------------------------------------------

    #[DataProvider('injectedHeaders')]
    public function testAHeaderThatCouldSmuggleAnotherIsRefused(string $name, string $value): void
    {
        $this->expectException(HttpClientException::class);

        (new HttpClient())->contextOptionsFor('GET', null, [$name => $value]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function injectedHeaders(): iterable
    {
        yield 'CRLF in the value' => ['X-Trace', "abc\r\nX-Admin: 1"];
        yield 'bare LF in the value' => ['X-Trace', "abc\nX-Admin: 1"];
        yield 'bare CR in the value' => ['X-Trace', "abc\rX-Admin: 1"];
        yield 'NUL in the value' => ['X-Trace', "abc\x00def"];
        yield 'a newline in the name' => ["X-Trace\r\nX-Admin", '1'];
        yield 'a colon in the name' => ['X-Trace: injected', '1'];
        yield 'a space in the name' => ['X Trace', '1'];
    }

    #[DataProvider('refusedSchemes')]
    public function testOnlyHttpAndHttpsAreSentTo(string $url): void
    {
        $this->expectException(HttpClientException::class);

        (new HttpClient(transport: new RecordingTransport()))->get($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedSchemes(): iterable
    {
        yield 'file' => ['file://etc/passwd'];
        yield 'ftp' => ['ftp://example.test/x'];
        yield 'ws' => ['ws://example.test/x'];
    }

    // ---- what reaches the transport ----------------------------------------------------------

    public function testTheUrlAndOptionsReachTheTransportUnchanged(): void
    {
        $transport = new RecordingTransport();
        $client = new HttpClient(timeoutSeconds: 4.0, totalTimeoutSeconds: 9.0, transport: $transport);

        $client->post('https://example.test/orders?page=2', '{"a":1}', ['X-Trace' => 'abc']);

        self::assertSame('https://example.test/orders?page=2', $transport->url);
        self::assertSame('POST', $transport->options['http']['method']);
        self::assertSame('{"a":1}', $transport->options['http']['content']);
        self::assertContains('X-Trace: abc', self::headerLines($transport));
        self::assertSame(9.0, $transport->totalTimeout, 'the transport must be told the whole-request ceiling');
    }

    public function testPostJsonEncodesAndDeclaresItsContentType(): void
    {
        $transport = new RecordingTransport();

        (new HttpClient(transport: $transport))->postJson('https://example.test/x', ['a' => 1]);

        self::assertSame('{"a":1}', $transport->options['http']['content']);
        self::assertContains('Content-Type: application/json', self::headerLines($transport));
    }

    public function testAPerCallHeaderOverridesTheDefaultOfTheSameName(): void
    {
        $transport = new RecordingTransport();
        $client = new HttpClient(defaultHeaders: ['X-Trace' => 'default'], transport: $transport);

        $client->get('https://example.test/x', ['X-Trace' => 'per-call']);

        self::assertContains('X-Trace: per-call', self::headerLines($transport));
        self::assertNotContains('X-Trace: default', self::headerLines($transport));
    }

    /**
     * The header lines the client built, narrowed for static analysis: the context shape is
     * `array<string, mixed>` because PHP's own options are heterogeneous.
     *
     * @return array<int, mixed>
     */
    private static function headerLines(RecordingTransport $transport): array
    {
        $lines = $transport->options['http']['header'] ?? null;
        self::assertIsArray($lines);

        return \array_values($lines);
    }

    public function testAUrlObjectIsAcceptedAsWellAsAString(): void
    {
        $transport = new RecordingTransport();

        (new HttpClient(transport: $transport))->get(Url::parse('https://example.test/a/b'));

        self::assertSame('https://example.test/a/b', $transport->url);
    }

    // ---- the response is a result, not a verdict ---------------------------------------------

    public function testAnErrorStatusIsReturnedRatherThanThrown(): void
    {
        $transport = new RecordingTransport(new HttpResponse(404, ['Content-Type: application/json'], '{"e":1}'));

        $response = (new HttpClient(transport: $transport))->get('https://example.test/missing');

        self::assertSame(404, $response->status);
        self::assertFalse($response->isSuccessful());
        self::assertSame(['e' => 1], $response->json());
    }

    public function testARedirectIsNotSuccessful(): void
    {
        // 3xx counts as unfinished, not as success: redirects are not followed by default, so a
        // caller treating 3xx as data would be parsing a redirect page.
        self::assertFalse((new HttpResponse(302, ['Location: https://elsewhere.test/'], ''))->isSuccessful());
    }

    public function testTheClientIsCatchableAsAnHttpFailure(): void
    {
        // The hierarchy claim from ADR-0049: a consumer catching the group's failures gets this.
        $this->expectException(HttpException::class);

        (new HttpClient(transport: new RecordingTransport()))->get('ftp://example.test/x');
    }
}

/**
 * A {@see Transport} that records what it was handed and returns a canned response.
 */
final class RecordingTransport implements Transport
{
    public string $url = '';

    /** @var array{http: array<string, mixed>, ssl: array<string, mixed>} */
    public array $options = ['http' => [], 'ssl' => []];

    public float $totalTimeout = 0.0;

    public function __construct(private readonly ?HttpResponse $response = null)
    {
    }

    public function send(string $url, array $contextOptions, float $totalTimeoutSeconds): HttpResponse
    {
        $this->url = $url;
        $this->options = $contextOptions;
        $this->totalTimeout = $totalTimeoutSeconds;

        return $this->response ?? new HttpResponse(200, ['Content-Type: text/plain'], 'ok');
    }
}
