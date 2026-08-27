<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\HttpClient;
use D4np\Utils\Support\HttpClientException;
use D4np\Utils\Support\Json;
use D4np\Utils\Tests\Http\Fixture\DevServer;
use D4np\Utils\Tests\Http\Fixture\TlsOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec §7's **T-07**: {@see HttpClient} against a real origin (roadmap item 11.4, RFC-0002).
 *
 * Item 11.1 built the client behind a {@see \D4np\Utils\Http\Transport} seam and asserted its
 * policy as a *value* — the ssl options it would set, the `follow_location` it would ask for,
 * the timeouts it would carry. That is everything a unit test can see, and it stops one step
 * short of every guarantee ADR-0049 actually makes: a value describing a refusal is not a
 * refusal. This suite supplies the missing step.
 *
 * What only a live origin can answer, and what each answer costs if it is wrong:
 *
 * - **A certificate that fails verification is not accepted** — and, the sharper case, *is still
 *   not accepted after a process-wide default has asked for verification to be skipped*. ADR-0049
 *   was written around that hijack; here the hijack is performed.
 * - **The wall-clock ceiling ends a dripping origin.** The per-phase timeout cannot: every window
 *   delivers a byte, so it re-arms forever. This is the difference between a bounded request and
 *   a hung worker.
 * - **A refused redirect does not travel.** Only the target can say whether it was contacted, so
 *   it writes a file when it is.
 * - **{@see \D4np\Utils\Http\StreamTransport} runs at all.** Its read loop, its chunk assembly
 *   and its error paths had never executed against a socket; the fake transport skipped all of
 *   them. Unlike T-03 — whose library code runs inside the server process — the client here runs
 *   in *this* process, so these tests do carry coverage.
 *
 * A failure to start the origin **fails** the suite rather than skipping it: `php -S` ships with
 * PHP, the project already spawns processes in T-03 and T-14, and a security suite that skips
 * itself into silence is how it stops running without anyone noticing.
 */
#[Group('T-07')]
final class HttpClientLiveTest extends TestCase
{
    private static ?DevServer $origin = null;

    /** Distinguishes this run's redirect-target marker from a stale one left by an earlier run. */
    private static string $run = '';

    public static function setUpBeforeClass(): void
    {
        $origin = new DevServer(\dirname(__DIR__, 4) . '/resources/t07-origin');
        $failure = $origin->start();

        if ($failure !== '') {
            $origin->stop();
            self::fail("T-07 needs a live `php -S` origin and could not get one: {$failure}");
        }

        self::$origin = $origin;
        self::$run = \bin2hex(\random_bytes(6));
    }

    public static function tearDownAfterClass(): void
    {
        self::$origin?->stop();
        self::$origin = null;

        $marker = self::markerPath();

        if (\is_file($marker)) {
            @\unlink($marker);
        }
    }

    private static function url(string $mode, string $extra = ''): string
    {
        return (self::$origin?->url("mode={$mode}&run=" . self::$run) ?? '') . ($extra === '' ? '' : '&' . $extra);
    }

    private static function markerPath(): string
    {
        return \sys_get_temp_dir() . '/d4np-t07-target-' . self::$run . '.txt';
    }

    private static function forgetTheTargetWasReached(): void
    {
        $marker = self::markerPath();

        if (\is_file($marker)) {
            \unlink($marker);
        }

        self::assertFileDoesNotExist($marker, 'the marker survived deletion, so the next assertion would be meaningless');
    }

    // ---- The time limits, against origins that test each one ------------------------------------

    /**
     * The case the per-phase timeout provably cannot cover: an origin that answers, then sends one
     * byte per window forever. Each byte re-arms the socket timeout, so only the wall-clock ceiling
     * ends this — which is why ADR-0049 ships both and lets neither be omitted.
     */
    public function testTheTotalBudgetEndsAnOriginThatDripsForever(): void
    {
        // Both at 1.0s, up from 0.5/1.0. The per-phase value is the one that matters here: it bounds
        // `fopen()` — connect plus waiting for the response headers — while the total budget is
        // enforced by StreamTransport's *non-blocking* read loop and so cannot be overrun by a slow
        // read. If the per-phase timeout fires first, this test fails with "produced no response"
        // and never exercises the total budget, which is its only claim.
        //
        // The order-dependent cause is fixed in `setUpBeforeClass()`, which now warms the origin so
        // no test pays `php -S`'s first-request cost. This widening is the separate, remaining
        // margin: the origin drips every 150 ms, so at 0.5s a single stalled drip only had to run
        // 3.3x long to beat the budget. 1.0s makes that 6.7x.
        //
        // It cannot go higher. HttpClient refuses a per-phase timeout above the total budget, and
        // the total must stay well under the origin's ~1.8s drip or the response completes and there
        // is nothing left to bound — 1.0s keeps 0.8s of margin on that side.
        $client = new HttpClient(timeoutSeconds: 1.0, totalTimeoutSeconds: 1.0);

        $startedAt = \microtime(true);

        try {
            $response = $client->get(self::url('drip'));
            self::fail(\sprintf(
                'the drip was read to completion (%d bytes, status %d): nothing bounded the request',
                \strlen($response->body),
                $response->status,
            ));
        } catch (HttpClientException $e) {
            $elapsed = \microtime(true) - $startedAt;

            self::assertStringContainsString('total time budget', $e->getMessage());
            self::assertLessThan(
                1.8,
                $elapsed,
                'the budget was 1.0s and the origin drips for about 1.8s; a later stop means the origin ended the request, not the client',
            );
        }
    }

    /**
     * The other half of the pair, and a different failure: an origin that accepts the connection
     * and says nothing produces no response at all, so the exception arrives from the connect
     * phase rather than from the read loop. Both are `HttpClientException`; the messages differ,
     * and the distinction is the diagnosis.
     */
    public function testASilentOriginHitsThePerPhaseTimeout(): void
    {
        $client = new HttpClient(timeoutSeconds: 0.4, totalTimeoutSeconds: 8.0);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/produced no response/');

        $client->get(self::url('silent'));
    }

    /**
     * A budget that ends slow-but-legal traffic would be worse than none: it would make the client
     * unusable against exactly the origins it exists to survive. The pause here is longer than the
     * transport's idle sleep and well inside the budget.
     */
    public function testASlowButLegalOriginIsReadToCompletion(): void
    {
        $client = new HttpClient(timeoutSeconds: 5.0, totalTimeoutSeconds: 6.0);

        self::assertSame('firstsecond', $client->get(self::url('pause'))->body);
    }

    // ---- The response, as it actually arrives ----------------------------------------------------

    /**
     * 40 960 bytes of every byte value, which is five of the transport's read chunks and includes
     * NUL. A single-read implementation truncates it; a string-terminating one stops at the first
     * NUL. Asserted by hash, since a diff of 40 kB of binary helps nobody.
     */
    public function testABodyLargerThanOneReadChunkArrivesByteForByte(): void
    {
        $block = '';

        for ($byte = 0; $byte < 256; $byte++) {
            $block .= \chr($byte);
        }

        $expected = \str_repeat($block, 160);
        $body = (new HttpClient())->get(self::url('binary'))->body;

        self::assertSame(\strlen($expected), \strlen($body), 'the body was truncated');
        self::assertSame(\hash('sha256', $expected), \hash('sha256', $body), 'the bytes changed in transit');
    }

    /**
     * `Set-Cookie` is the header that is repeated in practice, and the one whose values must not
     * collapse into each other. {@see \D4np\Utils\Http\HttpResponse} keeps a list per name; this
     * is the first time the list is filled by a real wire rather than a fixture array.
     */
    public function testARepeatedHeaderKeepsEveryValue(): void
    {
        $response = (new HttpClient())->get(self::url('repeated'));

        self::assertSame(
            ['first=1; Path=/', 'second=2; Path=/'],
            $response->headerLine('Set-Cookie'),
            'a repeated header lost a value on the way in',
        );
        self::assertSame('first=1; Path=/', $response->header('Set-Cookie'));
    }

    /**
     * The status a caller reads is the status the origin sent, and the body of an error survives.
     * `ignore_errors` is what makes the second half true; without it the wrapper returns `false`
     * for any 4xx/5xx and the response is simply gone — the unit suite can asserts the option is
     * set, not that the body comes back.
     */
    public function testAnErrorStatusArrivesWithItsBody(): void
    {
        $response = (new HttpClient())->get(self::url('error'));

        self::assertSame(503, $response->status);
        self::assertFalse($response->isSuccessful());
        self::assertSame('the body of an error is still a body', $response->body);
    }

    /**
     * Everything {@see HttpClient::contextOptionsFor()} promises, checked at the far end: the verb,
     * HTTP/1.1 (the wrapper's own default is 1.0), the JSON content type, a default header and a
     * per-call one, and a body that arrives unchanged.
     */
    public function testTheRequestThatArrivesIsTheRequestThatWasBuilt(): void
    {
        $client = new HttpClient(defaultHeaders: ['X-Agent' => 'egl-utils']);

        $response = $client->postJson(self::url('echo'), ['key' => 'value'], ['X-Probe' => 'yes']);
        $seen = Json::decode($response->body);

        self::assertIsArray($seen);
        self::assertSame('POST', $seen['method']);
        self::assertSame('HTTP/1.1', $seen['protocol'], 'the wrapper fell back to HTTP/1.0');
        self::assertSame('application/json', $seen['contentType']);
        self::assertSame('yes', $seen['probe'], 'the per-call header never left');
        self::assertSame('egl-utils', $seen['agent'], 'the default header never left');
        self::assertSame('{"key":"value"}', $seen['body']);
    }

    // ---- Redirects: what the client does, and where it does not go -------------------------------

    /**
     * The assertion the unit suite cannot make. `follow_location => 0` is a value there; here the
     * target records every visit, so "did not follow" is measured at the place it would have
     * arrived. A silently followed redirect is how a request to an allow-listed host ends up
     * somewhere else, and how a POST body is replayed against an origin nobody named.
     */
    public function testARefusedRedirectNeverReachesTheTarget(): void
    {
        self::forgetTheTargetWasReached();

        $response = (new HttpClient())->get(self::url('redirect'));

        self::assertSame(302, $response->status);
        self::assertStringContainsString('mode=target', (string) $response->header('Location'));
        self::assertFalse($response->isSuccessful(), 'a redirect is not a completed request');
        self::assertFileDoesNotExist(self::markerPath(), 'the client followed a redirect it was not asked to follow');
    }

    /**
     * And the opt-in works — otherwise the default would be the only behaviour and the flag a lie.
     */
    public function testARedirectIsFollowedWhenTheCallerAsksForIt(): void
    {
        self::forgetTheTargetWasReached();

        $response = (new HttpClient(followRedirects: true))->get(self::url('redirect'));

        self::assertFileExists(self::markerPath(), 'the target was never contacted');
        self::assertSame('target', $response->body);
    }

    /**
     * **Issue #102's first item, pinned rather than guarded (ADR-0079).**
     *
     * `guardScheme()` checks the http/https allowlist on the URL the caller passed, once. With
     * `followRedirects: true` the hops belong to PHP's stream wrapper, which offers no per-hop
     * callback — so the review board asked whether a hostile `Location` could walk the request off
     * the allowlist, and whether the check needs re-applying per hop.
     *
     * **It cannot, and it does not.** Measured (see ADR-0079's table): PHP's http wrapper never
     * leaves http/https on a redirect. A `Location` carrying a scheme it does not speak is either
     * refused outright or degraded to a *path on the same host*, which is why the assertions below
     * are about a 404 rather than about a file's contents. A per-hop scheme check would therefore be
     * unreachable code, and ADR-0022's precedent is that this project does not ship defensive code a
     * probe proves inert.
     *
     * What it ships instead is this test. The claim is about PHP's behaviour rather than about
     * ours, so the risk is not that our code regresses — it is that a future PHP changes underneath
     * a decision made on today's behaviour, silently. That is exactly what a pinning test is for.
     *
     * @param string $location the hostile `Location` the origin will emit verbatim
     */
    #[DataProvider('offAllowlistRedirectTargets')]
    public function testAnOffAllowlistRedirectNeverLeavesHttp(string $location): void
    {
        $client = new HttpClient(followRedirects: true);

        try {
            $response = $client->get(self::url('redirect-raw', 'location=' . \rawurlencode($location)));
        } catch (HttpClientException $e) {
            // One lawful outcome: the wrapper refused the hop and produced no response at all.
            // `ftp://` and `gopher://` land here. Nothing was fetched, which is the property.
            self::assertStringContainsString('produced no response', $e->getMessage());

            return;
        }

        // The other lawful outcome: the wrapper treated the whole `Location` as a *path on the
        // origin*, so the request stayed on http and this server answered it — with its own 404 for
        // a path it cannot map, which is why the status is not asserted here (it is the fixture's
        // business, and it differs between a routed path and an unmappable one).
        //
        // What is asserted is the security property itself: whatever came back is **not** the
        // resource the off-allowlist scheme named. Each payload below is chosen to be
        // unmistakable if it were ever fetched.
        $body = (string) $response->body;

        self::assertStringNotContainsString('<?php', $body, \sprintf(
            'a Location of "%s" returned PHP source, so `file://` was honoured and the hop left http',
            $location,
        ));
        self::assertStringNotContainsString('SECRET-should-never-be-read', $body, \sprintf(
            'a Location of "%s" returned a data: URI payload, so the hop left http',
            $location,
        ));
        // php://filter would hand back base64 rather than source, so the source check above cannot
        // see it. `<?php` encodes to this prefix under convert.base64-encode.
        self::assertStringNotContainsString(\base64_encode('<?php'), $body, \sprintf(
            'a Location of "%s" returned base64 of a local file, so php:// was honoured',
            $location,
        ));

        // Not vacuous, in both directions: `testARedirectIsFollowedWhenTheCallerAsksForIt` proves a
        // legitimate hop *is* followed with this configuration, and the test below proves these
        // payloads are readable by this process — so their absence is a property of the redirect
        // path rather than of an unreadable resource.
    }

    /**
     * The other half of the vacuity guard for the test above.
     *
     * `assertStringNotContainsString` passes for any body at all, including one that could never
     * have contained the payload. So this asserts the payloads are genuinely reachable **by this
     * PHP process, right now** — the file is readable and `data://` is enabled. Without it, the
     * test above would keep passing on a build where `allow_url_fopen` was off or the fixture had
     * moved, and would be asserting nothing.
     */
    public function testTheOffAllowlistPayloadsAreReadableSoTheirAbsenceMeansSomething(): void
    {
        $self = \str_replace(\DIRECTORY_SEPARATOR, '/', \dirname(__DIR__, 4) . '/resources/t07-origin/index.php');

        self::assertStringContainsString(
            '<?php',
            (string) @\file_get_contents('file:///' . $self),
            'the fixture this test points file:// at is not readable, so the redirect test proves nothing',
        );
        self::assertStringContainsString(
            'SECRET-should-never-be-read',
            (string) @\file_get_contents('data://text/plain,SECRET-should-never-be-read'),
            'data:// is not usable in this build, so the redirect test proves nothing about it',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function offAllowlistRedirectTargets(): iterable
    {
        // A real, readable file, so the case is discriminating rather than merely absent: if PHP
        // honoured `file://` the body would be this dispatcher's own source, which starts `<?php`
        // and could not be mistaken for the `plain` the assertion demands.
        $self = \str_replace(\DIRECTORY_SEPARATOR, '/', \dirname(__DIR__, 4) . '/resources/t07-origin/index.php');

        yield 'file, absolute' => ['file:///' . $self];
        yield 'php filter, base64 of a real file' => ['php://filter/read=convert.base64-encode/resource=' . $self];
        yield 'data, inline payload' => ['data://text/plain,SECRET-should-never-be-read'];
        yield 'ftp' => ['ftp://127.0.0.1/x'];
        yield 'gopher' => ['gopher://127.0.0.1/x'];
        // Not a scheme at all, but the shape that most often slips an allowlist written as a string
        // prefix test: no scheme, an authority, and somebody else's host.
        yield 'protocol-relative' => ['//127.0.0.1:1/x'];
    }

    /**
     * **The defect this suite found (ADR-0052).** A followed redirect leaves *every* hop in the
     * stream's metadata — `302`, its headers, then `200` and its headers — and the transport read
     * the first status line. So a successful fetch reported `302` with the target's body
     * (`isSuccessful()` false for a request that had succeeded), a chain ending in `404` reported
     * `302` and hid the failure, and `header()` answered from the hop that had been left behind.
     *
     * Both directions are asserted, because only the pair distinguishes "reports the last hop"
     * from "reports whatever the final status happens to be".
     */
    public function testAFollowedRedirectReportsTheFinalHopNotTheFirst(): void
    {
        $client = new HttpClient(followRedirects: true);

        $arrived = $client->get(self::url('redirect'));

        self::assertSame(200, $arrived->status, 'the status came from the redirect, not from the response');
        self::assertTrue($arrived->isSuccessful());
        self::assertNull($arrived->header('Location'), "the followed hop's Location leaked into the response");

        $failed = $client->get(self::url('redirect', 'to=missing'));

        self::assertSame(404, $failed->status, 'a chain ending in a failure reported the redirect instead');
        self::assertSame('no such thing', $failed->body);
    }

    // ---- No response at all ----------------------------------------------------------------------

    /**
     * A closed port is the commonest failure in production and the one path the fake transport can
     * only imitate. The URL belongs in the message: a client that reports "connection refused" and
     * not *to what* leaves the reader guessing at the dependency that is down.
     */
    public function testAnOriginThatIsNotListeningProducesNoResponse(): void
    {
        $port = self::aPortNobodyIsListeningOn();

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/produced no response/');

        (new HttpClient(timeoutSeconds: 1.0, totalTimeoutSeconds: 2.0))->get("http://127.0.0.1:{$port}/");
    }

    /**
     * Something is listening, but it is not an HTTP server. Measured: PHP's wrapper rejects the
     * stream itself rather than handing over an unreadable status line, so this arrives as "no
     * response" — the transport's own status-line guard is the second line of defence, not the
     * first.
     */
    public function testAnOriginSpeakingSomethingOtherThanHttpIsRefused(): void
    {
        $port = self::aPortNobodyIsListeningOn();
        $null = DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null';

        $garbage = \proc_open(
            [
                PHP_BINARY,
                '-r',
                '$s = stream_socket_server("tcp://127.0.0.1:' . $port . '"); $c = stream_socket_accept($s, 8); if ($c !== false) { fwrite($c, "GREETINGS\r\n\r\n"); fclose($c); }',
            ],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );

        self::assertIsResource($garbage, 'could not spawn the non-HTTP origin');

        try {
            for ($i = 0; $i < 40; $i++) {
                $socket = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

                if ($socket !== false) {
                    \fclose($socket);

                    break;
                }

                \usleep(50_000);
            }

            $this->expectException(HttpClientException::class);

            (new HttpClient(timeoutSeconds: 2.0, totalTimeoutSeconds: 4.0))->get("http://127.0.0.1:{$port}/");
        } finally {
            @\proc_terminate($garbage);
            @\proc_close($garbage);
        }
    }

    // ---- TLS: the policy, against a certificate that fails it ------------------------------------

    /**
     * The refusal, with its own control in the same test.
     *
     * A test that only asserted the failure would pass just as well against an origin that never
     * started — so the control runs first: a raw context with verification switched off must
     * *succeed* against the same URL. Only then does the client's refusal mean what it says.
     */
    public function testASelfSignedCertificateIsRefusedWhileTheOriginItselfIsReachable(): void
    {
        $origin = new TlsOrigin();
        $failure = $origin->start();

        if ($failure !== '') {
            $origin->stop();
            self::fail("T-07's TLS leg needs a local HTTPS origin and could not get one: {$failure}");
        }

        try {
            $trusting = \stream_context_create([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
                'http' => ['timeout' => 5],
            ]);

            self::assertSame(
                TlsOrigin::BODY,
                @\file_get_contents($origin->url(), false, $trusting),
                'the control could not reach the origin either, so the refusal below would prove nothing',
            );

            try {
                $response = (new HttpClient(timeoutSeconds: 5.0, totalTimeoutSeconds: 10.0))->get($origin->url());
                self::fail("the client accepted a self-signed certificate and read '{$response->body}'");
            } catch (HttpClientException $e) {
                self::assertStringContainsString($origin->url(), $e->getMessage());
            }
        } finally {
            $origin->stop();
        }
    }

    /**
     * **ADR-0049's central claim, performed rather than described.**
     *
     * `stream_context_set_default(['ssl' => ['verify_peer' => false]])` in a host application's
     * bootstrap silently becomes the policy of every stream that does not state its own — and a
     * fresh context states none. The client writes its ssl options on every request precisely so
     * that this cannot reach it. The default is hijacked here, proved to work (the raw read
     * succeeds against a certificate nothing should accept), and the client still refuses.
     */
    public function testAProcessWideVerificationDefaultCannotWeakenTheClient(): void
    {
        $origin = new TlsOrigin();
        $failure = $origin->start();

        if ($failure !== '') {
            $origin->stop();
            self::fail("T-07's TLS leg needs a local HTTPS origin and could not get one: {$failure}");
        }

        try {
            \stream_context_set_default([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
                'http' => ['timeout' => 5],
            ]);

            self::assertSame(
                TlsOrigin::BODY,
                @\file_get_contents($origin->url()),
                'the hijacked default did not take effect, so this test is not testing the hijack',
            );

            $this->expectException(HttpClientException::class);

            (new HttpClient(timeoutSeconds: 5.0, totalTimeoutSeconds: 10.0))->get($origin->url());
        } finally {
            \stream_context_set_default(['ssl' => [], 'http' => []]);
            $origin->stop();
        }
    }

    private static function aPortNobodyIsListeningOn(): int
    {
        $socket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        self::assertIsResource($socket, 'could not claim a local port');

        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }
}
