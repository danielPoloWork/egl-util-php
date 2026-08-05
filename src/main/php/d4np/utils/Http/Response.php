<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use D4np\Utils\Support\HttpException;
use D4np\Utils\Support\Json;

/**
 * A response to build and send (spec FR-14, ADR-0025).
 *
 * The counterpart to {@see Request}: PSR-7-mirroring naming, no PSR-7 types, and the optional
 * `egl/utils-psr7-bridge` as the only sanctioned crossing point (RFC-0001).
 *
 * **Immutable, with `with*()` methods** — unlike `Request`, which is only ever read. A response is
 * *built*, usually in stages and often across layers, and the alternative to immutability is a
 * mutable object that a helper can change behind its caller's back. The naming follows PSR-7 so
 * the shape is familiar and the bridge is mechanical.
 *
 * **Headers are stored case-insensitively but remember how they were spelled.** HTTP header names
 * are case-insensitive per RFC 9110, so `Content-Type` and `content-type` must not become two
 * headers — a duplicated `Content-Type` is how a response smuggles a second interpretation past a
 * proxy. The original casing is preserved for output because some clients are, in practice, less
 * tolerant than the specification.
 */
final class Response
{
    /**
     * @param array<string, array{string, string}> $headers lower-case name => [original name, value]
     */
    private function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {
    }

    /**
     * @throws HttpException if the status code is not a valid HTTP status
     */
    public static function create(int $status = 200, string $body = ''): self
    {
        return new self(self::validStatus($status), [], $body);
    }

    /**
     * A `text/plain` response.
     *
     * @throws HttpException
     */
    public static function text(string $body, int $status = 200): self
    {
        return self::create($status, $body)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * A `text/html` response.
     *
     * The body is **not** escaped here. Escaping is a render-time decision that depends on where
     * each value lands (ADR-0019's four contexts), and a response object cannot know that — a
     * blanket `htmlspecialchars()` over an assembled document would corrupt the markup it is meant
     * to carry.
     *
     * @throws HttpException
     */
    public static function html(string $body, int $status = 200): self
    {
        return self::create($status, $body)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * A JSON response.
     *
     * Encoded through {@see Json::encode()} rather than `json_encode()` directly, so a value that
     * cannot be encoded raises this library's own exception instead of silently becoming the
     * string `false` in the body (RFC-0001 R-7 — `JSON_THROW_ON_ERROR` is always on).
     *
     * @throws HttpException
     * @throws \D4np\Utils\Support\JsonException if `$data` cannot be encoded
     */
    public static function json(mixed $data, int $status = 200): self
    {
        return self::create($status, Json::encode($data))
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * A redirect.
     *
     * @throws HttpException if the status is not a 3xx redirect code
     */
    public static function redirect(string $location, int $status = 302): self
    {
        if ($status < 300 || $status > 399) {
            throw new HttpException(sprintf(
                'A redirect needs a 3xx status, got %d. Sending a Location header with a non-3xx '
                . 'status produces a response most clients will not follow and some will render.',
                $status,
            ));
        }

        return self::create($status)->withHeader('Location', $location);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Every header, keyed by the name as it will be sent.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $out = [];

        foreach ($this->headers as [$name, $value]) {
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * One header by case-insensitive name.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)][1] ?? $default;
    }

    /**
     * @throws HttpException if the name or value is not a legal header
     */
    public function withHeader(string $name, string $value): self
    {
        self::assertLegalHeader($name, $value);

        $headers = $this->headers;
        $headers[strtolower($name)] = [$name, $value];

        return new self($this->status, $headers, $this->body);
    }

    public function withoutHeader(string $name): self
    {
        $headers = $this->headers;
        unset($headers[strtolower($name)]);

        return new self($this->status, $headers, $this->body);
    }

    /**
     * @throws HttpException
     */
    public function withStatus(int $status): self
    {
        return new self(self::validStatus($status), $this->headers, $this->body);
    }

    public function withBody(string $body): self
    {
        return new self($this->status, $this->headers, $body);
    }

    /**
     * Send the status line, headers and body.
     *
     * Refuses when output has already begun, because `header()` would emit a warning and be
     * ignored — leaving a response whose body was sent with somebody else's headers. The check is
     * `headers_sent()` rather than a `try`, since PHP does not raise here.
     *
     * @throws HttpException if headers have already been sent
     */
    public function send(): void
    {
        if (headers_sent($file, $line)) {
            throw new HttpException(sprintf(
                'Cannot send the response: output already started at %s:%d. Whatever was emitted '
                . 'there has already committed the status and headers, so this response cannot '
                . 'set them.',
                $file,
                $line,
            ));
        }

        http_response_code($this->status);

        foreach ($this->headers as [$name, $value]) {
            header($name . ': ' . $value, true);
        }

        echo $this->body;
    }

    /**
     * @throws HttpException
     */
    private static function validStatus(int $status): int
    {
        // The range HTTP defines. A three-digit code outside it is not "unusual", it is not a
        // status code, and passing it through would produce a response no client can classify.
        if ($status < 100 || $status > 599) {
            throw new HttpException(sprintf('%d is not a valid HTTP status code (100-599).', $status));
        }

        return $status;
    }

    /**
     * Refuse a header that could split the response.
     *
     * A CR or LF in a name or value ends the header line early and lets everything after it be
     * read as a new header, or as the body — **response splitting**, and the reason a header value
     * containing user input is dangerous. Modern PHP's `header()` rejects these itself, but this
     * class validates at the point the value is *set* rather than at the point it is sent, so a
     * response assembled and inspected in tests fails the same way as one sent to a client.
     *
     * A null byte is refused for the same reason: it truncates in C-level consumers that PHP's own
     * check does not cover.
     *
     * @throws HttpException
     */
    private static function assertLegalHeader(string $name, string $value): void
    {
        if ($name === '' || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new HttpException(sprintf(
                'Illegal header name %s. RFC 9110 allows only token characters in a field name.',
                var_export($name, true),
            ));
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new HttpException(sprintf(
                'Header %s contains a carriage return, line feed or null byte. That would end the '
                . 'header line early and let the remainder be read as further headers or as the '
                . 'body — a response-splitting vector, refused rather than stripped so the caller '
                . 'finds out their value was not what they thought.',
                $name,
            ));
        }
    }
}
