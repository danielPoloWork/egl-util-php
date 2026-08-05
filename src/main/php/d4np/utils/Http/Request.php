<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

/**
 * A typed reader over one request's input (spec FR-13, ADR-0025).
 *
 * **This is not PSR-7, and deliberately mirrors its naming rather than its types.** RFC-0001
 * rejected both implementing PSR-7 here and depending on an implementation: doing it well is a
 * solved project (streams, immutability, uploaded files), and exposing PSR-7 types only would
 * force factory wiring and stream handling on framework-less users who want
 * `$request->postString('email')`. The sanctioned crossing point is the optional
 * `egl/utils-psr7-bridge`, and **these wrappers never grow middleware ambitions** — PSR-15 stacks
 * belong on the other side of that bridge.
 *
 * **Nothing here reads a superglobal.** The constructor takes the four arrays and
 * {@see fromGlobals()} is the only place `$_GET` and friends are touched, which is what makes a
 * request testable without a web server and what lets the bridge construct one from a PSR-7
 * message.
 *
 * **The typed accessors refuse rather than coerce, and that is the security decision in this
 * class.** `?email=x` gives a string, but `?email[]=x` gives an **array** — the same key, a
 * different PHP type, chosen by whoever wrote the query string. A `(string)` cast on that emits
 * *"Array to string conversion"* and yields the literal `"Array"`; `implode()` invents a value
 * nobody sent. Both turn attacker-controlled *shape* into a value the application then trusts,
 * which is the parameter-pollution family. So a scalar accessor asked for a non-scalar returns its
 * default, exactly as if the key were absent — the honest answer, because a string is not what
 * arrived.
 */
final class Request
{
    /**
     * Keys are `array-key`, not `string`, and that is not pedantry: `?0=zero` produces an
     * **integer** key in `$_GET` — verified — so a `array<string, mixed>` annotation would be a
     * lie PHPStan at max level correctly refuses to accept. The accessors take `string $key`
     * because that is what callers write, and PHP's own array lookup handles the numeric-string
     * equivalence.
     *
     * @param array<array-key, mixed> $query   `$_GET`
     * @param array<array-key, mixed> $post    `$_POST`
     * @param array<array-key, mixed> $server  `$_SERVER`
     * @param array<array-key, mixed> $files   `$_FILES`
     * @param array<array-key, mixed> $cookies `$_COOKIE`
     */
    public function __construct(
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly array $cookies = [],
    ) {
    }

    /**
     * The current request, read from the superglobals.
     *
     * The single point in this library that touches them, so everything else is a pure function of
     * its constructor arguments.
     */
    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    // ---- typed readers -------------------------------------------------------------------------

    public function queryString(string $key, ?string $default = null): ?string
    {
        return self::asString($this->query[$key] ?? null, $default);
    }

    public function queryInt(string $key, ?int $default = null): ?int
    {
        return self::asInt($this->query[$key] ?? null, $default);
    }

    public function queryBool(string $key, ?bool $default = null): ?bool
    {
        return self::asBool($this->query[$key] ?? null, $default);
    }

    public function postString(string $key, ?string $default = null): ?string
    {
        return self::asString($this->post[$key] ?? null, $default);
    }

    public function postInt(string $key, ?int $default = null): ?int
    {
        return self::asInt($this->post[$key] ?? null, $default);
    }

    public function postBool(string $key, ?bool $default = null): ?bool
    {
        return self::asBool($this->post[$key] ?? null, $default);
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        return self::asString($this->cookies[$key] ?? null, $default);
    }

    /**
     * An input that genuinely *is* a list — `?tag[]=a&tag[]=b`.
     *
     * Separate from {@see queryString()} on purpose: a caller asking for a list has decided a list
     * is acceptable here, which is a different decision from being handed one unexpectedly.
     * Non-list values yield an empty array rather than being wrapped, because wrapping would erase
     * the distinction this method exists to preserve.
     *
     * @return list<string>
     */
    public function queryList(string $key): array
    {
        return self::asStringList($this->query[$key] ?? null);
    }

    /**
     * @return list<string>
     */
    public function postList(string $key): array
    {
        return self::asStringList($this->post[$key] ?? null);
    }

    // ---- whole-collection readers ----------------------------------------------------------------

    /*
     * The four methods below return their input **raw and entire**, which is a deliberate exception
     * to everything the typed accessors above stand for — so it is worth saying why it is not a
     * retreat from ADR-0025.
     *
     * ADR-0025's rule is about *scalar* reads: `queryString('email')` refuses an array rather than
     * producing the literal `"Array"`, because a caller asking for one string has been handed
     * something else and coercion would hide that. These methods make no such promise and cannot
     * mislead in that way — a caller asking for the whole collection is asking for exactly what
     * arrived, values of every shape included.
     *
     * They exist because the PSR-7 bridge (ADR-0033, ADR-0034) must project a whole request across
     * the boundary, and a key-scoped reader cannot enumerate. `headers()` and `file()` already
     * returned raw collections before them, so this is the established shape rather than a new one.
     *
     * PHP arrays are values: the caller gets a copy, so nothing here hands out a mutable view of
     * this request's state.
     */

    /**
     * The whole query collection, exactly as it arrived.
     *
     * @return array<array-key, mixed>
     */
    public function queryAll(): array
    {
        return $this->query;
    }

    /**
     * The whole POST collection, exactly as it arrived.
     *
     * @return array<array-key, mixed>
     */
    public function postAll(): array
    {
        return $this->post;
    }

    /**
     * The whole cookie collection, exactly as it arrived.
     *
     * @return array<array-key, mixed>
     */
    public function cookieAll(): array
    {
        return $this->cookies;
    }

    /**
     * Every uploaded-file entry, in `$_FILES` shape.
     *
     * The counterpart to {@see file()}, which reads one by key. Uploaded files are the one
     * collection with no typed accessor at all — an uploaded-file abstraction is precisely what
     * RFC-0001 declined to re-implement — so this returns what PHP produced and the bridge turns it
     * into `UploadedFileInterface` instances.
     *
     * @return array<array-key, mixed>
     */
    public function uploadedFiles(): array
    {
        return $this->files;
    }

    // ---- request line and headers --------------------------------------------------------------

    /**
     * The HTTP method, upper-cased, defaulting to `GET` when absent.
     */
    public function method(): string
    {
        return strtoupper(self::asString($this->server['REQUEST_METHOD'] ?? null, 'GET') ?? 'GET');
    }

    /**
     * The request target as the server reported it — path and query string, not a full URL.
     */
    public function uri(): string
    {
        return self::asString($this->server['REQUEST_URI'] ?? null, '') ?? '';
    }

    public function isSecure(): bool
    {
        $https = self::asString($this->server['HTTPS'] ?? null);

        // `$_SERVER['HTTPS']` is 'off' on some servers rather than absent, which is why this is not
        // an isset() check. Forwarded-proto headers are deliberately NOT consulted: they are
        // client-supplied unless a trusted proxy has rewritten them, and this class cannot know
        // whether one has.
        return $https !== null && $https !== '' && strtolower($https) !== 'off';
    }

    /**
     * One header, by case-insensitive name.
     *
     * Derived from `$_SERVER` rather than `getallheaders()`, which does not exist outside
     * Apache-like SAPIs — verified absent on this CLI build. `HTTP_X_FORWARDED_FOR` becomes
     * `x-forwarded-for`; `CONTENT_TYPE` and `CONTENT_LENGTH` are included despite carrying no
     * `HTTP_` prefix, because CGI reports those two without one.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers()[strtolower($name)] ?? $default;
    }

    /**
     * Every header, keyed by lower-case name.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->server as $key => $value) {
            $name = self::headerNameOf((string) $key);

            if ($name === null) {
                continue;
            }

            $string = self::asString($value);

            if ($string !== null) {
                $headers[$name] = $string;
            }
        }

        return $headers;
    }

    /**
     * One uploaded file's `$_FILES` entry, or `null`.
     *
     * Returned as the raw array rather than wrapped in an object: an uploaded-file abstraction
     * (moving, streaming, error codes) is precisely the surface RFC-0001 declined to re-implement,
     * and the bridge is where a PSR-7 `UploadedFileInterface` comes from.
     *
     * @return array<array-key, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    // ---- coercion ------------------------------------------------------------------------------

    private static function asString(mixed $value, ?string $default = null): ?string
    {
        // Arrays and objects are refused, not flattened — see the class docblock.
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    private static function asInt(mixed $value, ?int $default = null): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $default;
        }

        // FILTER_VALIDATE_INT rather than a cast: `(int) "12abc"` is 12, which invents a value the
        // client did not send. This returns the default for anything that is not wholly an integer.
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? $default : $parsed;
    }

    private static function asBool(mixed $value, ?bool $default = null): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $default;
        }

        // The same coercion `Env::get()` uses, and for the same reason: an unchecked cast makes the
        // string "false" true. FILTER_NULL_ON_FAILURE distinguishes "not boolean-shaped" from
        // "false".
        //
        // One surprise, verified rather than assumed and asserted in the tests: PHP's filter reads
        // an **empty** (or whitespace-only) value as `false`, not as "not boolean-shaped". So
        // `?flag=` is false, not absent. Following PHP here rather than inventing a third answer
        // keeps this consistent with `Env::get()`, which uses the same filter.
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    /**
     * @return list<string>
     */
    private static function asStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            $string = self::asString($item);

            // A nested array (`?tag[a][b]=1`) is skipped rather than flattened: this method
            // promises a list of strings, and inventing one from a deeper structure would be the
            // same coercion the scalar accessors refuse.
            if ($string !== null) {
                $list[] = $string;
            }
        }

        return $list;
    }

    /**
     * The header name a `$_SERVER` key corresponds to, or `null` when it is not a header.
     */
    private static function headerNameOf(string $key): ?string
    {
        if (str_starts_with($key, 'HTTP_')) {
            return strtolower(str_replace('_', '-', substr($key, 5)));
        }

        // CGI reports these two without the prefix. `HTTP_CONTENT_TYPE` maps to the same name, and
        // whichever the server supplied wins — they cannot disagree in a well-formed request, and
        // preferring one over the other would be inventing a rule no specification states.
        if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
            return strtolower(str_replace('_', '-', $key));
        }

        return null;
    }
}
