<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Mail\Fixture;

use RuntimeException;

/**
 * The wire witness for T-10 (issue #101, ADR-0078): a thin client over Mailpit's HTTP API.
 *
 * Every other leg of T-10 stops at a seam. {@see RecordingMailApi} proves what reaches PHP's
 * `mail()` *arguments*, which is the right place to assert the array-header mechanism and the wrong
 * place to assert anything about SMTP — the arguments are where this library's responsibility ends
 * and PHP's begins. ADR-0056 answered three questions by probing a real transport by hand and
 * recording the results in prose; this class is what turns that prose into assertions that run.
 *
 * **Configuration is one environment variable**, {@see self::BASE_URL}, pointing at Mailpit's HTTP
 * API. Unset, the wire suite skips: a bare checkout has no Mailpit and — unlike T-03's and T-07's
 * `php -S`, which ships with PHP — cannot provision one, so a skip is the honest answer rather than
 * a silence. Set, every failure to use it is red. That is the fork ADR-0071 drew for the database
 * leg, for its reason: a leg that goes green without reaching the service reports coverage nobody
 * has.
 *
 * ```bash
 * docker run -d -p 1025:1025 -p 8025:8025 axllent/mailpit
 * EGL_TEST_MAILPIT_URL=http://127.0.0.1:8025 vendor/bin/phpunit --group mail-wire
 * ```
 *
 * …with PHP itself started against the relay, which cannot be arranged from inside the process:
 * `sendmail_path` is `PHP_INI_SYSTEM`, so `ini_set()` returns `false` and only the launching
 * php.ini (in CI, `setup-php`'s `ini-values`) decides where `mail()` sends. {@see self::unusable()}
 * checks it for that reason — left unset, `mail()` invokes an absent `/usr/sbin/sendmail`, and the
 * leg fails as "the transport declined the message", which names the wrong culprit.
 *
 * ## What this receiver does to the evidence, and why it matters
 *
 * **Mailpit rewrites the message it stores.** On ingest it compares the SMTP envelope's recipients
 * against the addresses in the headers and, for any envelope recipient the headers do not mention,
 * *prepends a synthetic `Bcc:` header* to the stored copy. There is no field anywhere in the API
 * that reports the raw `RCPT TO` list.
 *
 * Two consequences shape every assertion in the suite, and getting either backwards produces a
 * confidently wrong test:
 *
 * 1. **Never assert that a `Bcc:` header is absent.** msmtp strips `Bcc:` on the wire exactly as
 *    documented, and Mailpit then puts one back — so the absence check fails against a pipeline
 *    that is working perfectly.
 * 2. **The injected header is itself the proof, because Mailpit only injects what the headers
 *    omit.** If the address had never reached `RCPT TO`, msmtp would have stripped the header and
 *    Mailpit would have had nothing to add, leaving {@see CapturedMail::bcc()} empty. So a
 *    non-empty `bcc()` is a discriminating witness that the address was delivered *as an envelope
 *    recipient*, which is the half of ADR-0056 D3 that matters to a consumer.
 *
 * The other half — that the header did not travel — is **not observable through this receiver**,
 * and is recorded as a known limit rather than asserted weakly.
 *
 * A third consequence, smaller but able to break a careless test: msmtp adds `From`, `Date` and
 * `Message-ID` when they are missing, so the header set Mailpit reports is a **superset** of what
 * PHP emitted. Assert on the headers the library sets; never on the whole set, and never on
 * ordering or a byte-for-byte comparison against what was handed to `sendmail`.
 */
final class Mailpit
{
    /** Mailpit's HTTP API root, e.g. `http://127.0.0.1:8025`. Unset means the wire suite skips. */
    public const BASE_URL = 'EGL_TEST_MAILPIT_URL';

    /** The listing endpoint, which is also the reachability probe. */
    private const MESSAGES = '/api/v1/messages';

    /** Seconds allowed for any one API call. */
    private const TIMEOUT = 10;

    /**
     * How long to wait for a message to land: `mail()` returning `true` means the local relay
     * accepted it, not that Mailpit has finished storing it. Asserting at that instant is the
     * classic way a wire test becomes intermittent.
     */
    private const ARRIVAL_ATTEMPTS = 100;

    private const ARRIVAL_INTERVAL_US = 50_000;

    /**
     * How long a negative assertion waits before concluding nothing was sent.
     *
     * Short, and soundly so: `mail()` runs `sendmail_path` as a **child process and blocks on it**,
     * so by the time it has returned, a message that was going to be sent has already been handed
     * to msmtp and relayed. This wait covers Mailpit's own store-after-accept, not an unbounded
     * delivery queue — there is no queue in this pipeline.
     */
    private const SETTLE_US = 2_000_000;

    private function __construct()
    {
    }

    /**
     * Whether a Mailpit instance was configured for this run.
     *
     * The switch between "a skip is honest" and "a skip is a lie", so it reads the variable rather
     * than probing the network: a *configured* but unreachable Mailpit must fail, never skip.
     */
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== null;
    }

    /**
     * The reason this leg cannot run as configured, or `''` when it can.
     *
     * Returns a string rather than throwing or asserting, so the fixture stays free of
     * test-framework calls and the suite decides what a failure means — {@see DevServer::start()}'s
     * convention, which T-03 and T-07 both follow.
     */
    public static function unusable(): string
    {
        $base = self::baseUrl();

        if ($base === null) {
            return 'no ' . self::BASE_URL . ' is configured';
        }

        // Checked before the network, because this one is a misconfiguration of the *sender* and it
        // surfaces as a failure that reads like the receiver's fault.
        $sendmail = \ini_get('sendmail_path');

        if (!\is_string($sendmail) || \trim($sendmail) === '') {
            return 'sendmail_path is empty, so mail() would invoke the default /usr/sbin/sendmail '
                . 'and never reach Mailpit. It is PHP_INI_SYSTEM — set it when starting PHP '
                . '(setup-php ini-values), because the suite cannot.';
        }

        // Probed with the listing endpoint the suite itself depends on rather than a dedicated
        // health route: a reachability check that exercises a different endpoint can pass while the
        // one every assertion uses is broken.
        if (self::json('GET', self::MESSAGES) === null) {
            return \sprintf('Mailpit is configured at %s=%s but did not answer %s', self::BASE_URL, $base, self::MESSAGES);
        }

        return '';
    }

    /**
     * Discard every captured message, so one test cannot read another's mail.
     *
     * @throws RuntimeException when Mailpit will not answer
     */
    public static function purge(): void
    {
        // A DELETE with no body is Mailpit's "delete everything".
        if (self::request('DELETE', self::MESSAGES) === null) {
            throw new RuntimeException('Could not purge Mailpit; the next assertion would read another test\'s mail.');
        }
    }

    /**
     * How many messages Mailpit is holding.
     *
     * @throws RuntimeException when Mailpit will not answer
     */
    public static function count(): int
    {
        $listing = self::json('GET', self::MESSAGES);
        $total = $listing['total'] ?? null;

        if (!\is_int($total)) {
            throw new RuntimeException('Mailpit did not report a message total.');
        }

        return $total;
    }

    /**
     * Wait for exactly one message and return it.
     *
     * A timeout is a failure carrying the count, never a silent zero.
     *
     * @throws RuntimeException when no message, or more than one, arrives
     */
    public static function awaitOne(): CapturedMail
    {
        $seen = 0;

        for ($attempt = 0; $attempt < self::ARRIVAL_ATTEMPTS; $attempt++) {
            $seen = self::count();

            if ($seen >= 1) {
                break;
            }

            \usleep(self::ARRIVAL_INTERVAL_US);
        }

        if ($seen !== 1) {
            throw new RuntimeException(\sprintf(
                'Expected exactly one captured message; Mailpit holds %d after %.1f seconds.',
                $seen,
                self::ARRIVAL_ATTEMPTS * self::ARRIVAL_INTERVAL_US / 1_000_000,
            ));
        }

        return self::latest();
    }

    /**
     * Wait long enough to be confident nothing is coming, then report the count.
     *
     * The negative assertions need this and cannot borrow {@see self::awaitOne()}'s loop: at the
     * instant `mail()` returns, "nothing was sent" and "nothing has arrived yet" are the same
     * observation, so the only honest form of the claim is one that waited. Deliberately a fixed
     * settle rather than a poll — there is no event to poll for.
     *
     * @throws RuntimeException when Mailpit will not answer
     */
    public static function countAfterSettling(): int
    {
        \usleep(self::SETTLE_US);

        return self::count();
    }

    /**
     * @throws RuntimeException when Mailpit will not hand the message over
     */
    private static function latest(): CapturedMail
    {
        // `latest` is Mailpit's own alias for the newest message, so the id never has to be
        // threaded through the listing. Every test purges first and sends one message, so "latest"
        // and "the one this test sent" are the same message by construction.
        $detail = self::json('GET', '/api/v1/message/latest');
        $headers = self::json('GET', '/api/v1/message/latest/headers');
        $raw = self::request('GET', '/api/v1/message/latest/raw');

        if ($detail === null || $headers === null || $raw === null) {
            throw new RuntimeException('Mailpit would not hand over the captured message.');
        }

        return new CapturedMail($detail, $headers, $raw);
    }

    private static function baseUrl(): ?string
    {
        $value = \getenv(self::BASE_URL);

        // The empty string is treated as absent: a workflow that writes
        // `EGL_TEST_MAILPIT_URL: ${{ matrix.url }}` and forgets the matrix key sets it to '', and
        // "configured to nothing" is the one reading that must not survive into a green run.
        return \is_string($value) && $value !== '' ? \rtrim($value, '/') : null;
    }

    /**
     * @return array<string, mixed>|null the decoded body, or `null` when the call or the JSON failed
     */
    private static function json(string $method, string $path): ?array
    {
        $body = self::request($method, $path);

        if ($body === null) {
            return null;
        }

        $decoded = \json_decode($body, true);

        if (!\is_array($decoded)) {
            return null;
        }

        // Only string keys survive. Every endpoint this client calls returns a JSON *object*, so
        // string keys are the shape; a numeric key means the response was a bare array and is not
        // something this client understands. Filtered rather than asserted, so the declared return
        // type is true of the value rather than promised about it.
        $object = [];

        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    /**
     * @return string|null the response body, or `null` on a non-2xx or a transport failure
     */
    private static function request(string $method, string $path): ?string
    {
        $base = self::baseUrl();

        if ($base === null) {
            return null;
        }

        // Streams rather than curl. This library declares no ext-curl, and while the test tree's
        // BrowserClient uses it, that fixture is a GET-only cookie jar built for T-03 and has
        // neither DELETE nor JSON. Routing these calls through the library's own HttpClient was the
        // other candidate and is worse: it would make a red Mail leg ambiguous between Mail and Http.
        $context = \stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => self::TIMEOUT,
                // Wanted, so a 404 yields its body and a status line rather than `false` — the
                // status is then read below instead of being inferred from the absence of content.
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        // Declared before the call so it is defined for static analysis; file_get_contents()
        // replaces it in this scope when an HTTP response was received at all.
        $http_response_header = [];
        $body = @\file_get_contents($base . $path, false, $context);

        if (!\is_string($body)) {
            return null;
        }

        /** @var list<string> $http_response_header */
        return self::succeeded($http_response_header) ? $body : null;
    }

    /**
     * @param list<string> $headers
     */
    private static function succeeded(array $headers): bool
    {
        // The first entry is the status line; an empty array means no response reached us.
        if ($headers === [] || \preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $match) !== 1) {
            return false;
        }

        return \str_starts_with($match[1], '2');
    }
}
