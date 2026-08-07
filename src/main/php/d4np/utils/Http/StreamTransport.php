<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use D4np\Utils\Support\HttpClientException;

/**
 * {@see Transport} over PHP's own HTTP stream wrapper — no `ext-curl` (spec r3 FR-37; ADR-0049).
 *
 * Three implementation details are load-bearing, and each was probed rather than assumed:
 *
 * 1. **`fopen()`, not `file_get_contents()`.** The body is read in a loop against a wall-clock
 *    deadline, because the wrapper's own `timeout` is applied *per phase* — measured: a
 *    2-second `timeout` cut a hanging connect at 2.01 s, and it likewise re-arms on every read.
 *    An origin dripping one byte inside each window therefore holds a `file_get_contents()`
 *    call open indefinitely. The loop is what makes {@see HttpClient}'s total-duration promise
 *    real, and it was verified against a server that answers and then stalls: the deadline
 *    fired, and the bytes already received survived.
 * 2. **The status line comes from the stream's metadata.** `fopen()` populates
 *    `stream_get_meta_data($handle)['wrapper_data']` with exactly what `$http_response_header`
 *    would have held — status line first, then the headers — so nothing is lost by not using
 *    the magic variable, and the value is a local rather than a variable PHP injects into
 *    scope. **When redirects are followed, that array holds the whole chain**, one status line
 *    per hop, and the response reported is the last of them (ADR-0052).
 * 3. **A 4xx/5xx body is read, not discarded.** `ignore_errors` is set by {@see HttpClient};
 *    without it the wrapper returns `false` for any error status and the response is gone.
 */
final class StreamTransport implements Transport
{
    private const READ_CHUNK_BYTES = 8192;

    /** Sleep between empty non-blocking reads: long enough not to spin, short enough to be prompt. */
    private const IDLE_SLEEP_MICROSECONDS = 2000;

    public function send(string $url, array $contextOptions, float $totalTimeoutSeconds): HttpResponse
    {
        $deadline = \microtime(true) + $totalTimeoutSeconds;
        $context = \stream_context_create($contextOptions);

        // Warnings are suppressed and converted: the wrapper emits one for a refused
        // connection, a failed TLS handshake and a DNS miss alike, and a warning is not a
        // control-flow mechanism. The message is preserved as the exception's, so nothing the
        // caller needs to diagnose the failure is thrown away.
        $handle = @\fopen($url, 'rb', false, $context);

        if ($handle === false) {
            throw new HttpClientException(\sprintf(
                'Request to "%s" produced no response: %s',
                $url,
                self::lastErrorMessage(),
            ));
        }

        try {
            $meta = \stream_get_meta_data($handle);
            /** @var list<string> $wrapperData */
            $wrapperData = \is_array($meta['wrapper_data'] ?? null) ? \array_values($meta['wrapper_data']) : [];

            if ($wrapperData === []) {
                throw new HttpClientException(\sprintf(
                    'Request to "%s" returned no status line; the stream is not an HTTP response.',
                    $url,
                ));
            }

            [$status, $headers] = self::lastExchangeIn($wrapperData, $url);
            $body = $this->readWithin($handle, $deadline, $url);
        } finally {
            \fclose($handle);
        }

        return new HttpResponse($status, $headers, $body);
    }

    /**
     * @param resource $handle
     *
     * @throws HttpClientException when the wall-clock deadline expires mid-body
     */
    private function readWithin($handle, float $deadline, string $url): string
    {
        \stream_set_blocking($handle, false);
        $body = '';

        while (!\feof($handle)) {
            if (\microtime(true) >= $deadline) {
                throw new HttpClientException(\sprintf(
                    'Request to "%s" exceeded its total time budget while reading the response body.',
                    $url,
                ));
            }

            $chunk = \fread($handle, self::READ_CHUNK_BYTES);

            if ($chunk === false) {
                throw new HttpClientException(\sprintf('Reading the response from "%s" failed.', $url));
            }

            if ($chunk === '') {
                // Non-blocking and nothing ready yet. Yield rather than spin; the deadline
                // above is what ends this, so a silent origin cannot hold the loop forever.
                \usleep(self::IDLE_SLEEP_MICROSECONDS);

                continue;
            }

            $body .= $chunk;
        }

        return $body;
    }

    /**
     * The **last** response in `wrapper_data`, with only its own headers.
     *
     * A single exchange puts one status line first and its headers after it. A *followed*
     * redirect puts every hop in the same flat array — `302`, its headers, `200`, its headers —
     * and the body belongs to the last one. Reading the first status line therefore described a
     * response that no longer existed: item 11.4's live suite measured a chain reporting **302
     * with the target's body**, so `isSuccessful()` was `false` for a fetch that had succeeded,
     * and a chain ending in `404` reported `302` — the failure invisible. Merging the hops'
     * headers is the same error in a more dangerous place: `header('Set-Cookie')` returned the
     * *intermediate* hop's cookie, and `header('Location')` a redirect already followed.
     *
     * The first line must still be a status line — that strictness is what catches a stream
     * which is not an HTTP response at all, and it is unchanged (ADR-0052).
     *
     * @param list<string> $wrapperData
     *
     * @return array{int, list<string>} the final status and the headers belonging to it
     *
     * @throws HttpClientException if the first line is not a status line
     */
    private static function lastExchangeIn(array $wrapperData, string $url): array
    {
        $status = self::statusIn($wrapperData[0]);

        if ($status === null) {
            throw new HttpClientException(\sprintf(
                'Request to "%s" returned an unreadable status line: "%s".',
                $url,
                $wrapperData[0],
            ));
        }

        $headers = [];

        foreach (\array_slice($wrapperData, 1) as $line) {
            $hop = self::statusIn($line);

            if ($hop === null) {
                $headers[] = $line;

                continue;
            }

            // A new response begins here, so everything collected so far belonged to a hop
            // that has been left behind.
            $status = $hop;
            $headers = [];
        }

        return [$status, $headers];
    }

    /** The status code a line carries, or `null` when the line is not a status line. */
    private static function statusIn(string $line): ?int
    {
        if (\preg_match('#^HTTP/\d(?:\.\d)? (\d{3})\b#', $line, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private static function lastErrorMessage(): string
    {
        $error = \error_get_last();

        return $error === null ? 'no diagnostic available' : $error['message'];
    }
}
