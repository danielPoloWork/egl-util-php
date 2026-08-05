<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http\Fixture;

/**
 * One HTTP client with its own cookie jar — a stand-in for a single browser.
 *
 * Two instances are two unrelated visitors, which is how T-03's cross-session assertions are
 * written: a token issued to one must be worthless to the other.
 *
 * The jar is kept by hand rather than handing the job to curl. The suite needs to replay a
 * *stale* identifier on purpose — that is the whole session-fixation test — and a jar that
 * behaves correctly would refuse to do it. Explicit storage also means every cookie the tests act
 * on is one the test itself read off the wire.
 */
final class BrowserClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function get(string $url): HttpExchange
    {
        $headers = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $headers[] = $trimmed;
                }

                return strlen($line);
            },
        ]);

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = "{$name}={$value}";
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $exchange = new HttpExchange(
            $status,
            $headers,
            is_string($body) ? $body : '',
            $error,
        );

        foreach ($exchange->setCookieHeaders() as $header) {
            if (preg_match('/^([^=]+)=([^;]*)/', $header, $m) === 1) {
                $this->cookies[trim($m[1])] = $m[2];
            }
        }

        return $exchange;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Put a specific value in the jar — used to present an identifier the server has already
     * rotated away from.
     */
    public function presentCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    public function forgetCookies(): void
    {
        $this->cookies = [];
    }
}
