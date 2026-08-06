<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * An immutable absolute URL: parse, inspect, recompose (spec r3 FR-27, RFC-0002; ADR-0036).
 *
 * Two defects from the surveyed estate shape this class.
 *
 * **The scheme was thrown away.** Its URL helper decomposed a URL and rebuilt it as
 * `"http://{$host}{$path}"` — a hardcoded scheme, so every `https` address it touched came
 * back as plaintext, and the query and fragment vanished with it. Here the scheme is carried
 * by the object, so no recomposition can lose it, and {@see self::withScheme()} additionally
 * **refuses a downgrade** (a TLS-protected scheme to its plaintext counterpart) rather than
 * performing it quietly.
 *
 * **`parse_url()` launders control characters.** It does not reject `\r`, `\n`, `\0` or `\t`
 * — it silently rewrites each to `_`, so `https://example.com\n/evil` parses to the host
 * `example.com_`. Code that validates the parsed components and then uses the *original*
 * string is checking a value the attacker did not send. {@see self::parse()} therefore
 * refuses control characters before `parse_url()` ever sees them, and every wither applies
 * the same guard to what it is given.
 *
 * Immutable throughout: every `with*()` returns a new instance.
 */
final class Url
{
    /**
     * Schemes whose transport is encrypted, mapped to the plaintext scheme a downgrade would
     * reach. {@see self::withScheme()} refuses exactly these transitions.
     */
    private const DOWNGRADE_TARGETS = [
        'https' => ['http'],
        'wss' => ['ws'],
        'ftps' => ['ftp'],
        'sftp' => ['ftp'],
    ];

    /**
     * The port each scheme uses by default. A URL carrying its scheme's default port
     * normalizes to no port at all, since the two forms address the same resource.
     */
    private const DEFAULT_PORTS = [
        'http' => 80,
        'https' => 443,
        'ws' => 80,
        'wss' => 443,
        'ftp' => 21,
        'sftp' => 22,
    ];

    private function __construct(
        private readonly string $scheme,
        private readonly ?string $userInfo,
        private readonly string $host,
        private readonly ?int $port,
        private readonly string $path,
        private readonly string $rawQuery,
        private readonly ?string $fragment,
    ) {
    }

    /**
     * Parses an **absolute** URL — one carrying both a scheme and a host.
     *
     * Relative references are refused rather than guessed at: `parse_url('not a url')`
     * succeeds, returning `['path' => 'not a url']`, so "did this parse?" is not a question
     * `parse_url()` can answer. FR-27's purpose is addresses that survive recomposition, and
     * a string with no scheme has nothing to protect from downgrade.
     *
     * Normalization applied here, and nowhere else: the scheme and host are lowercased (RFC
     * 3986 §6.2.2.1 — both are case-insensitive; the **path is not** and is left alone), a
     * port equal to the scheme's default is dropped, and an empty path becomes `/`. The query
     * string is preserved **byte-exact**; see {@see self::rawQuery()}.
     *
     * @throws InvalidUrlException if the input holds a control character, is not absolute, or
     *                             cannot be decomposed
     */
    public static function parse(string $url): self
    {
        self::guardControlCharacters($url, 'URL');

        $parts = parse_url($url);
        if ($parts === false) {
            throw new InvalidUrlException(sprintf('Cannot parse "%s" as a URL.', $url));
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';

        if ($scheme === '' || $host === '') {
            throw new InvalidUrlException(sprintf(
                'An absolute URL (scheme and host) is required, got "%s".',
                $url,
            ));
        }

        $userInfo = null;
        if (isset($parts['user'])) {
            $userInfo = isset($parts['pass'])
                ? $parts['user'] . ':' . $parts['pass']
                : $parts['user'];
        }

        return new self(
            $scheme,
            $userInfo,
            $host,
            self::normalizePort($scheme, $parts['port'] ?? null),
            self::normalizePath($parts['path'] ?? ''),
            $parts['query'] ?? '',
            $parts['fragment'] ?? null,
        );
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * The port, or `null` when the URL uses its scheme's default (or names none).
     */
    public function port(): ?int
    {
        return $this->port;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The query string exactly as it arrived — no decoding, no re-encoding, no reordering.
     *
     * This is the authoritative form. A query is preserved byte-for-byte until a `withQuery*`
     * call replaces it, because re-encoding one the caller did not touch would invalidate any
     * signature computed over it.
     */
    public function rawQuery(): string
    {
        return $this->rawQuery;
    }

    /**
     * The query decoded into an array, via `parse_str()`.
     *
     * A convenience over {@see self::rawQuery()}, and **lossy in the way `parse_str()` is**:
     * repeated keys collapse to the last occurrence (`a=1&a=2` decodes to `['a' => '2']`) and
     * `a[]=x` becomes a nested array. When exactness matters, read the raw query.
     *
     * Keys are `array-key`, not `string`: `?0=zero` yields the **integer** key `0`, the same
     * superglobal-key lesson ADR-0025 recorded for `Request`.
     *
     * @return array<array-key, array<mixed>|string>
     */
    public function query(): array
    {
        parse_str($this->rawQuery, $decoded);

        return $decoded;
    }

    /**
     * The fragment without its `#`, or `null` when the URL carries none.
     */
    public function fragment(): ?string
    {
        return $this->fragment;
    }

    /**
     * The `user` or `user:password` component, or `null`.
     *
     * Reported truthfully rather than hidden, so a caller can detect credentials and act;
     * {@see self::withoutUserInfo()} is the way to strip them before a URL reaches a log.
     * Note that credentials shift what the host is: in `https://example.com\@evil.com/` the
     * host is **`evil.com`** and `example.com\` is the user — correct per RFC 3986, and the
     * reason a host check must read {@see self::host()} rather than search the raw string.
     */
    public function userInfo(): ?string
    {
        return $this->userInfo;
    }

    /**
     * The same URL under a different scheme — **unless that is a downgrade**.
     *
     * A downgrade is a transition from an encrypted transport to its plaintext counterpart
     * (`https`→`http`, `wss`→`ws`, `ftps`/`sftp`→`ftp`), and is refused. Upgrades and
     * same-scheme calls pass. A scheme this class does not know is allowed through, because
     * the security properties of an unrecognised scheme cannot be asserted and refusing every
     * unknown would make custom schemes unusable — the honest limit, recorded in ADR-0036.
     *
     * @throws InvalidUrlException on a downgrade, a syntactically invalid scheme, or a control
     *                             character
     */
    public function withScheme(string $scheme): self
    {
        self::guardControlCharacters($scheme, 'Scheme');
        $normalized = strtolower($scheme);

        if (preg_match('/\A[a-z][a-z0-9+.\-]*\z/', $normalized) !== 1) {
            throw new InvalidUrlException(sprintf(
                'Invalid scheme "%s": a scheme is a letter followed by letters, digits, '
                . '"+", "-" or "." (RFC 3986 §3.1).',
                $scheme,
            ));
        }

        if (in_array($normalized, self::DOWNGRADE_TARGETS[$this->scheme] ?? [], true)) {
            throw new InvalidUrlException(sprintf(
                'Refusing to downgrade "%s" to "%s": the transport would go from encrypted '
                . 'to plaintext. Build the URL with the intended scheme instead.',
                $this->scheme,
                $normalized,
            ));
        }

        return new self(
            $normalized,
            $this->userInfo,
            $this->host,
            // The old port may have been the old scheme's default; re-normalize against the new.
            self::normalizePort($normalized, $this->port),
            $this->path,
            $this->rawQuery,
            $this->fragment,
        );
    }

    /**
     * @throws InvalidUrlException on a control character
     */
    public function withPath(string $path): self
    {
        self::guardControlCharacters($path, 'Path');

        return new self(
            $this->scheme,
            $this->userInfo,
            $this->host,
            $this->port,
            self::normalizePath($path),
            $this->rawQuery,
            $this->fragment,
        );
    }

    /**
     * Replaces the entire query, encoding `$params` per RFC 3986 (spaces become `%20`, not
     * `+` — `http_build_query()`'s default is the HTML-form encoding, which is not what a URL
     * query is).
     *
     * A `null` value is **refused**, not dropped: `http_build_query()` silently omits null
     * entries, so a caller who meant "send this key empty" would get a URL missing the key
     * with nothing to indicate it. Remove a key with {@see self::withoutQueryParam()} and pass
     * `''` for an empty value. The check descends into nested arrays, naming the dotted path
     * of the offender — `http_build_query()` drops a nested null just as quietly as a
     * top-level one.
     *
     * Values may be scalars or nested arrays of scalars. The parameter is typed
     * `array<array-key, mixed>` rather than a scalar union because the acceptable shape is
     * recursive and PHPDoc cannot express that without lying at some depth; the runtime walk
     * below is the real enforcement, which is also what serves this library's stated
     * native/legacy audience (spec §1) — those consumers run no static analysis at all.
     *
     * @param array<array-key, mixed> $params
     *
     * @throws InvalidUrlException if any value, at any depth, is `null`
     */
    public function withQuery(array $params): self
    {
        self::guardNoNullValues($params, '');

        return new self(
            $this->scheme,
            $this->userInfo,
            $this->host,
            $this->port,
            $this->path,
            http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            $this->fragment,
        );
    }

    /**
     * Adds or replaces one query parameter, leaving the rest as {@see self::query()} decodes
     * them. A `bool` encodes as `1`/`0`, matching `http_build_query()`.
     *
     * Because this re-encodes the whole query, a raw query with repeated keys loses its
     * duplicates here — the `parse_str()` limit named on {@see self::query()}.
     */
    public function withQueryParam(string $key, string|int|float|bool $value): self
    {
        $params = $this->query();
        $params[$key] = $value;

        return $this->withQuery($params);
    }

    /**
     * Removes one query parameter. Removing a key that is not present is a no-op, not an
     * error — the caller's intent ("this key must not be in the URL") is satisfied either way.
     */
    public function withoutQueryParam(string $key): self
    {
        $params = $this->query();
        unset($params[$key]);

        return $this->withQuery($params);
    }

    /**
     * @throws InvalidUrlException on a control character
     */
    public function withFragment(?string $fragment): self
    {
        if ($fragment !== null) {
            self::guardControlCharacters($fragment, 'Fragment');
        }

        return new self(
            $this->scheme,
            $this->userInfo,
            $this->host,
            $this->port,
            $this->path,
            $this->rawQuery,
            $fragment,
        );
    }

    /**
     * The same URL with any credentials stripped — the form to log, store, or report.
     */
    public function withoutUserInfo(): self
    {
        return new self(
            $this->scheme,
            null,
            $this->host,
            $this->port,
            $this->path,
            $this->rawQuery,
            $this->fragment,
        );
    }

    /**
     * The recomposed URL.
     *
     * Stable under re-parsing: `Url::parse((string) $url)` yields an equal object, because
     * every normalization this class performs happens at {@see self::parse()} and is therefore
     * already applied to what this returns.
     */
    public function toString(): string
    {
        $authority = $this->host;
        if ($this->userInfo !== null) {
            $authority = $this->userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        $url = $this->scheme . '://' . $authority . $this->path;

        if ($this->rawQuery !== '') {
            $url .= '?' . $this->rawQuery;
        }
        if ($this->fragment !== null) {
            $url .= '#' . $this->fragment;
        }

        return $url;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Refuses C0 controls and DEL anywhere in `$value`.
     *
     * The guard exists because `parse_url()` **rewrites** these to `_` instead of rejecting
     * them (ADR-0036): the parse appears to succeed and the components come back subtly
     * different from what was supplied, which is how a CRLF payload survives a validation
     * step that inspects the parsed result. Refusing early keeps input and parsed value the
     * same thing.
     *
     * @throws InvalidUrlException if a control character is present
     */
    private static function guardControlCharacters(string $value, string $subject): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidUrlException(sprintf(
                '%s contains a control character. parse_url() would rewrite it to "_" rather '
                . 'than reject it, so the parsed value would differ from the input.',
                $subject,
            ));
        }
    }

    /**
     * Refuses a `null` anywhere in the parameter tree, naming its dotted path.
     *
     * @param array<array-key, mixed> $params
     *
     * @throws InvalidUrlException
     */
    private static function guardNoNullValues(array $params, string $prefix): void
    {
        foreach ($params as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if ($value === null) {
                throw new InvalidUrlException(sprintf(
                    'Query parameter "%s" is null, which http_build_query() would drop '
                    . 'silently. Pass "" for an empty value, or withoutQueryParam() to remove '
                    . 'the key.',
                    $path,
                ));
            }

            if (is_array($value)) {
                self::guardNoNullValues($value, $path);
            }
        }
    }

    private static function normalizePort(string $scheme, ?int $port): ?int
    {
        if ($port === null) {
            return null;
        }

        return (self::DEFAULT_PORTS[$scheme] ?? null) === $port ? null : $port;
    }

    /**
     * An empty path becomes `/`: with an authority present the two forms address the same
     * resource (RFC 3986 §6.2.3), and choosing one keeps recomposition stable under
     * re-parsing.
     */
    private static function normalizePath(string $path): string
    {
        return $path === '' ? '/' : $path;
    }
}
