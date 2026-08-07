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
 *    scope.
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

            $status = self::parseStatus(\array_shift($wrapperData), $url);
            $body = $this->readWithin($handle, $deadline, $url);
        } finally {
            \fclose($handle);
        }

        return new HttpResponse($status, $wrapperData, $body);
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
     * @throws HttpClientException if the status line is not one
     */
    private static function parseStatus(string $statusLine, string $url): int
    {
        if (\preg_match('#^HTTP/\d(?:\.\d)? (\d{3})\b#', $statusLine, $matches) !== 1) {
            throw new HttpClientException(\sprintf(
                'Request to "%s" returned an unreadable status line: "%s".',
                $url,
                $statusLine,
            ));
        }

        return (int) $matches[1];
    }

    private static function lastErrorMessage(): string
    {
        $error = \error_get_last();

        return $error === null ? 'no diagnostic available' : $error['message'];
    }
}
