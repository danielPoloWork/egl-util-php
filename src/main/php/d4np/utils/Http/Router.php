<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use D4np\Utils\Support\HttpException;
use D4np\Utils\Support\MethodNotAllowedException;
use D4np\Utils\Support\RouteNotFoundException;

/**
 * A minimal front-controller router: method + path in, a handler out (spec r3 FR-38, RFC-0002;
 * ADR-0050).
 *
 * The surveyed estate had **37 deployed folders**, each holding an `index.php` that differed
 * from its neighbours in one line — the controller it instantiated. Routing was the filesystem,
 * so every cross-cutting concern (the autoloader, the JSON envelope, error handling) was
 * copy-pasted 37 times and drifted. This class is the one table those files collapse into; the
 * ~20-line front controller that replaces them is written out in
 * [`docs/patterns/endpoint-kernel.md`](../../../../../../docs/patterns/endpoint-kernel.md).
 *
 * **404 and 405 are different answers**, and keeping them apart is the requirement rather than
 * a nicety: {@see RouteNotFoundException} means nobody registered that path,
 * {@see MethodNotAllowedException} means somebody did and it carries the `Allow` list RFC 9110
 * makes mandatory on such a response.
 *
 * **Non-goals, stated so they are decisions rather than omissions** (ADR-0050): no middleware
 * pipeline — PSR-15 is defined in PSR-7 terms and the bridge is this library's only sanctioned
 * crossing (RFC-0001 Alternative #3); no route caching — a 50-route table matches in
 * microseconds (NFR-11) and a cache is a second source of truth to invalidate; no attribute
 * discovery — it trades an explicit table for a scan, and the table *is* the value here; no
 * implicit `HEAD`-to-`GET` fallback — a router that answers a method nobody registered is
 * guessing, and the caller sees the `Allow` list instead.
 */
final class Router
{
    /**
     * One segment of a path. `[^/]+` rather than `.+` is what stops `{id}` from swallowing
     * separators and matching `42/edit` as an identifier.
     */
    private const SEGMENT_PATTERN = '[^/]+';

    /**
     * `{name}`, where the name is identifier-shaped.
     *
     * Spelled with `\w` — equivalent to `[A-Za-z0-9_]` in a non-unicode pattern — rather than
     * the character class written out, and deliberately so: `IdentifierTest` asserts that the
     * **SQL** identifier allowlist appears in exactly one file, by scanning the tree for that
     * class as text (ADR-0044). This rule is a different one that happens to share the shape,
     * so writing it the long way would trip a guard protecting something else. Loosening that
     * guard was the alternative and was rejected — an unanchored copy of the SQL allowlist is
     * ADR-0015's original bug, and the scan must stay able to see it.
     */
    private const PLACEHOLDER_PATTERN = '/\{([A-Za-z_]\w*)\}/';

    /** @var array<string, array<string, callable>> normalized path => method => handler */
    private array $routes = [];

    /** @var array<string, string> normalized path => the PCRE it compiles to */
    private array $compiled = [];

    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): self
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): self
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    /**
     * Registers one route.
     *
     * **A duplicate is refused, not overwritten.** Two registrations for the same method and
     * path are a bug in the wiring, and the version that wins would depend on include order —
     * which is the kind of failure that only shows up in the environment where the order
     * differs.
     *
     * @throws HttpException if the path is not absolute, repeats a placeholder name, or
     *                       duplicates an existing route
     */
    public function add(string $method, string $path, callable $handler): self
    {
        $method = \strtoupper($method);
        $path = self::normalize($path);

        if (!\str_starts_with($path, '/')) {
            throw new HttpException(\sprintf('Route path "%s" must start with "/".', $path));
        }

        $names = self::placeholderNames($path);

        if (\count($names) !== \count(\array_unique($names))) {
            throw new HttpException(\sprintf('Route path "%s" repeats a placeholder name.', $path));
        }

        if (isset($this->routes[$path][$method])) {
            throw new HttpException(\sprintf('%s %s is already routed.', $method, $path));
        }

        $this->routes[$path][$method] = $handler;
        $this->compiled[$path] ??= self::compile($path);

        return $this;
    }

    /**
     * Resolves a method and path to a handler.
     *
     * The path is matched **case-sensitively**: RFC 3986 makes the scheme and host
     * case-insensitive and the path not, which is the same line {@see \D4np\Utils\Support\Url}
     * draws (ADR-0036).
     *
     * @throws RouteNotFoundException    if no registered path matches
     * @throws MethodNotAllowedException if one does, for other methods — carrying them
     */
    public function match(string $method, string $path): MatchedRoute
    {
        $method = \strtoupper($method);
        $path = self::normalize(self::pathOnly($path));
        $allowed = [];

        foreach ($this->compiled as $route => $pattern) {
            if (\preg_match($pattern, $path, $captures) !== 1) {
                continue;
            }

            $handlers = $this->routes[$route];

            if (isset($handlers[$method])) {
                return new MatchedRoute($handlers[$method], self::captured($captures));
            }

            foreach (\array_keys($handlers) as $registered) {
                $allowed[$registered] = true;
            }
        }

        if ($allowed !== []) {
            $methods = \array_keys($allowed);
            \sort($methods);

            throw new MethodNotAllowedException(
                \sprintf('%s is not allowed for "%s".', $method, $path),
                $methods,
            );
        }

        throw new RouteNotFoundException(\sprintf('No route matches "%s".', $path));
    }

    /**
     * {@see self::match()} against a {@see Request}, which is what a front controller has.
     *
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     */
    public function matchRequest(Request $request): MatchedRoute
    {
        return $this->match($request->method(), $request->uri());
    }

    /**
     * The methods registered for a path, or `[]` — what a handler needs to answer `OPTIONS`
     * without catching an exception to find out.
     *
     * @return list<string>
     */
    public function allowedMethodsFor(string $path): array
    {
        $path = self::normalize(self::pathOnly($path));

        foreach ($this->compiled as $route => $pattern) {
            if (\preg_match($pattern, $path) === 1) {
                $methods = \array_keys($this->routes[$route]);
                \sort($methods);

                return $methods;
            }
        }

        return [];
    }

    /**
     * Strips the query and fragment: a router matches paths, and `/users?page=2` is the same
     * route as `/users`. Done here rather than expected of the caller, because
     * `$_SERVER['REQUEST_URI']` carries the query and a front controller that forgot to strip
     * it would 404 on every parameterised request.
     */
    private static function pathOnly(string $uri): string
    {
        foreach (['?', '#'] as $delimiter) {
            $position = \strpos($uri, $delimiter);

            if ($position !== false) {
                $uri = \substr($uri, 0, $position);
            }
        }

        return $uri;
    }

    /**
     * Trailing slashes are equivalent — `/users/` and `/users` reach the same route — except at
     * the root, which *is* `/`.
     */
    private static function normalize(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return \rtrim($path, '/');
    }

    /**
     * @return list<string>
     */
    private static function placeholderNames(string $path): array
    {
        \preg_match_all(self::PLACEHOLDER_PATTERN, $path, $matches);

        return $matches[1];
    }

    /**
     * Compiles `/orders/{id}` to a PCRE with a named capture per placeholder.
     *
     * The literal parts are `preg_quote`d, so a route containing a regex metacharacter is a
     * literal path rather than an accidental pattern — a route table is not a place to be
     * writing regular expressions by side effect.
     */
    private static function compile(string $path): string
    {
        // PLACEHOLDER_PATTERN captures the name, so splitting with DELIM_CAPTURE alternates
        // literal, name, literal, name … — even indexes are text to quote, odd ones are
        // placeholders to expand.
        $parts = \preg_split(self::PLACEHOLDER_PATTERN, $path, -1, \PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '';

        foreach ($parts === false ? [$path] : $parts as $index => $part) {
            $pattern .= $index % 2 === 1
                ? \sprintf('(?P<%s>%s)', $part, self::SEGMENT_PATTERN)
                : \preg_quote($part, '#');
        }

        return '#^' . $pattern . '$#';
    }

    /**
     * The named captures, percent-decoded.
     *
     * **Decoded after the match, never before.** A path arriving with `%2F` in it must not have
     * that turned into a `/` while segments are being counted: doing so lets a single parameter
     * invent a segment boundary and match a route it was never given. Decoding the captured
     * value afterwards keeps the routing decision on the bytes the client actually sent.
     *
     * @param array<array-key, string> $captures
     *
     * @return array<string, string>
     */
    private static function captured(array $captures): array
    {
        $parameters = [];

        foreach ($captures as $key => $value) {
            if (\is_string($key)) {
                $parameters[$key] = \rawurldecode($value);
            }
        }

        return $parameters;
    }
}
