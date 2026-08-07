<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\Request;
use D4np\Utils\Http\Router;
use D4np\Utils\Support\HttpException;
use D4np\Utils\Support\MethodNotAllowedException;
use D4np\Utils\Support\RouteNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec §6's **T-11 router matrix** — `Router`, spec r3 FR-38 (RFC-0002), ADR-0050.
 *
 * The matrix is the point: FR-38's requirement is not "routes match", it is that a miss is
 * classified — nobody registered this path (404) versus somebody did and not for this method
 * (405, carrying `Allow`). A suite that only tested hits would pass on a router that answered
 * `404` to everything it did not serve.
 */
#[Group('T-11')]
final class RouterTest extends TestCase
{
    private static function router(): Router
    {
        $router = new Router();
        $router->get('/health', static fn (): string => 'health');
        $router->get('/orders', static fn (): string => 'list');
        $router->post('/orders', static fn (): string => 'create');
        $router->get('/orders/{id}', static fn (): string => 'show');
        $router->delete('/orders/{id}', static fn (): string => 'destroy');
        $router->get('/orders/{id}/lines/{line}', static fn (): string => 'line');
        $router->get('/', static fn (): string => 'root');

        return $router;
    }

    // ---- hits -------------------------------------------------------------------------------

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('hits')]
    public function testAMatchYieldsItsHandlerAndParameters(
        string $method,
        string $path,
        string $expected,
        array $parameters,
    ): void {
        $matched = self::router()->match($method, $path);

        self::assertSame($expected, ($matched->handler)());
        self::assertSame($parameters, $matched->parameters);
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, string>}>
     */
    public static function hits(): iterable
    {
        yield 'a literal path' => ['GET', '/health', 'health', []];
        yield 'the root' => ['GET', '/', 'root', []];
        yield 'same path, the other method' => ['POST', '/orders', 'create', []];
        yield 'one placeholder' => ['GET', '/orders/42', 'show', ['id' => '42']];
        yield 'two placeholders' => ['GET', '/orders/42/lines/7', 'line', ['id' => '42', 'line' => '7']];
        yield 'a lowercase method is accepted' => ['get', '/health', 'health', []];
        yield 'a trailing slash is the same route' => ['GET', '/orders/', 'list', []];
        yield 'a query string is not part of the path' => ['GET', '/orders?page=2', 'list', []];
        yield 'a fragment is not either' => ['GET', '/orders#top', 'list', []];
        yield 'a placeholder captures a non-numeric segment' => ['GET', '/orders/abc-123', 'show', ['id' => 'abc-123']];
    }

    // ---- the 404 / 405 distinction, which is the requirement ---------------------------------

    #[DataProvider('misses')]
    public function testAnUnroutedPathIsNotFound(string $method, string $path): void
    {
        $this->expectException(RouteNotFoundException::class);

        self::router()->match($method, $path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function misses(): iterable
    {
        yield 'no such path' => ['GET', '/nope'];
        yield 'a deeper path than any route' => ['GET', '/orders/42/lines/7/extra'];
        yield 'a shallower one' => ['GET', '/orders/42/lines'];
        yield 'a prefix of a route is not the route' => ['GET', '/heal'];
        yield 'case matters in a path' => ['GET', '/Health'];
    }

    public function testARoutedPathWithTheWrongMethodIsNotAllowed(): void
    {
        try {
            self::router()->match('PUT', '/orders');

            self::fail('expected a MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'POST'], $e->allowedMethods());
            self::assertSame('GET, POST', $e->allowHeader(), 'RFC 9110 makes Allow mandatory on a 405');
        }
    }

    public function testTheAllowListCoversAParameterisedPathToo(): void
    {
        try {
            self::router()->match('POST', '/orders/42');

            self::fail('expected a MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['DELETE', 'GET'], $e->allowedMethods());
        }
    }

    /**
     * The failure this guards against: collapsing both misses into one 404, which is what a
     * router does when it stops looking after the first method mismatch.
     *
     * Asserted through an actual `match()` rather than on constructed instances — the types of
     * two `new` expressions are decidable without running anything, so that version would be a
     * tautology PHPStan can see through (and did). Catching as the shared base and asking which
     * one arrived is the property that can actually break.
     *
     * Only the positive half is asserted, for the same reason: after `assertInstanceOf()` the
     * analyser has narrowed `$e`, so "and not the other one" is decidable too. That half is
     * covered where it is *not* free — `ExceptionHierarchyTest` pins both as final leaves of the
     * same base, and a final class cannot be an instance of its sibling.
     */
    public function testTheRouterChoosesBetweenTheTwoMissTypes(): void
    {
        try {
            self::router()->match('GET', '/nope');
            self::fail('expected a miss');
        } catch (HttpException $e) {
            self::assertInstanceOf(RouteNotFoundException::class, $e);
        }

        try {
            self::router()->match('PUT', '/orders');
            self::fail('expected a miss');
        } catch (HttpException $e) {
            self::assertInstanceOf(MethodNotAllowedException::class, $e);
        }
    }

    public function testTheAllowedMethodsAreReadableWithoutCatching(): void
    {
        self::assertSame(['GET', 'POST'], self::router()->allowedMethodsFor('/orders'));
        self::assertSame(['DELETE', 'GET'], self::router()->allowedMethodsFor('/orders/42'));
        self::assertSame([], self::router()->allowedMethodsFor('/nope'));
    }

    // ---- what a placeholder may not do -------------------------------------------------------

    public function testAPlaceholderDoesNotMatchAcrossASeparator(): void
    {
        // `{id}` is one segment. If it were `.+`, this would match `/orders/{id}` with an id of
        // "42/lines/7" and route a request to the wrong handler.
        $this->expectException(RouteNotFoundException::class);

        (new Router())
            ->get('/orders/{id}', static fn (): string => 'show')
            ->match('GET', '/orders/42/lines/7');
    }

    public function testAnEncodedSeparatorStaysInsideItsParameter(): void
    {
        // %2F must not become a segment boundary while segments are being counted: decoding
        // before matching would let one parameter forge a path. Decoded after, it is data.
        $matched = self::router()->match('GET', '/orders/a%2Fb');

        self::assertSame(['id' => 'a/b'], $matched->parameters);
    }

    public function testAPercentEncodedValueIsDecodedOnce(): void
    {
        $matched = self::router()->match('GET', '/orders/a%20b');

        self::assertSame(['id' => 'a b'], $matched->parameters);
    }

    public function testARegexMetacharacterInARouteIsALiteral(): void
    {
        $router = (new Router())->get('/files/report.txt', static fn (): string => 'report');

        self::assertSame('report', ($router->match('GET', '/files/report.txt')->handler)());

        // The dot must not match any character; otherwise this would resolve too.
        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/files/reportXtxt');
    }

    // ---- registration refusals ---------------------------------------------------------------

    public function testADuplicateRouteIsRefusedRatherThanOverwritten(): void
    {
        $router = (new Router())->get('/orders', static fn (): string => 'first');

        $this->expectException(HttpException::class);
        $router->get('/orders', static fn (): string => 'second');
    }

    public function testADuplicateIsStillADuplicateWithATrailingSlash(): void
    {
        $router = (new Router())->get('/orders', static fn (): string => 'first');

        $this->expectException(HttpException::class);
        $router->get('/orders/', static fn (): string => 'second');
    }

    public function testARelativeRoutePathIsRefused(): void
    {
        $this->expectException(HttpException::class);

        (new Router())->get('orders', static fn (): string => 'x');
    }

    public function testARepeatedPlaceholderNameIsRefused(): void
    {
        // Two captures of the same name: PCRE would keep one and the caller would never know
        // which segment it holds.
        $this->expectException(HttpException::class);

        (new Router())->get('/a/{id}/b/{id}', static fn (): string => 'x');
    }

    // ---- the front-controller entry point ----------------------------------------------------

    public function testARequestCanBeMatchedDirectly(): void
    {
        $request = new Request(
            query: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/orders/42?page=2'],
            cookies: [],
            files: [],
        );

        self::assertSame(['id' => '42'], self::router()->matchRequest($request)->parameters);
    }

    public function testParameterReadsBackByName(): void
    {
        $matched = self::router()->match('GET', '/orders/42');

        self::assertSame('42', $matched->parameter('id'));
        self::assertNull($matched->parameter('absent'));
    }
}
