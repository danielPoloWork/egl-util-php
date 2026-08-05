<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http\Fixture;

/**
 * One request/response pair, kept as a value so assertions read as statements about the wire.
 *
 * The raw header lines are retained rather than parsed into a map: T-03's subject is the literal
 * `Set-Cookie` text, and a helpfully normalised structure is exactly where a missing flag would
 * stop being visible.
 */
final class HttpExchange
{
    /**
     * @param list<string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $curlError = '',
    ) {
    }

    /**
     * @return list<string> the value of every `Set-Cookie` header, in order
     */
    public function setCookieHeaders(): array
    {
        $found = [];
        foreach ($this->headers as $header) {
            if (preg_match('/^Set-Cookie:\s*(.+)$/i', $header, $m) === 1) {
                $found[] = trim($m[1]);
            }
        }

        return $found;
    }

    public function sessionCookie(): ?string
    {
        foreach ($this->setCookieHeaders() as $header) {
            if (stripos($header, 'PHPSESSID=') === 0) {
                return $header;
            }
        }

        return null;
    }
}
