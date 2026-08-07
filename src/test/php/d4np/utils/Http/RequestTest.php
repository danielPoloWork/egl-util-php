<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-13's typed superglobal reader.
 *
 * The cases that matter most are the ones where an accessor is handed something that is not the
 * type it names — because that shape is chosen by whoever wrote the query string, not by the
 * application.
 */
final class RequestTest extends TestCase
{
    public function testTypedQueryReaders(): void
    {
        $request = new Request(query: ['name' => 'Ada', 'n' => '42', 'flag' => 'yes']);

        self::assertSame('Ada', $request->queryString('name'));
        self::assertSame(42, $request->queryInt('n'));
        self::assertTrue($request->queryBool('flag'));
    }

    public function testTypedPostReaders(): void
    {
        $request = new Request(post: ['email' => 'a@b.com', 'age' => '30', 'subscribe' => 'off']);

        self::assertSame('a@b.com', $request->postString('email'));
        self::assertSame(30, $request->postInt('age'));
        self::assertFalse($request->postBool('subscribe'));
    }

    public function testAbsentKeysReturnTheDefault(): void
    {
        $request = new Request();

        self::assertNull($request->queryString('nope'));
        self::assertSame('fallback', $request->queryString('nope', 'fallback'));
        self::assertSame(7, $request->postInt('nope', 7));
        self::assertTrue($request->queryBool('nope', true));
    }

    /**
     * **The parameter-pollution case.** `?email[]=x` gives the same key a different PHP type,
     * chosen by the client. A `(string)` cast yields the literal `"Array"`; `implode()` invents a
     * value nobody sent. Both hand attacker-controlled shape to an application that then trusts
     * it, so a scalar accessor returns its default instead — the honest answer, because a string
     * is not what arrived.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonScalarValues(): iterable
    {
        yield 'list' => [['admin']];
        yield 'map' => [['role' => 'admin']];
        yield 'nested' => [[['deep']]];
        yield 'empty array' => [[]];
    }

    #[DataProvider('nonScalarValues')]
    public function testScalarAccessorsRefuseNonScalarsRatherThanCoercingThem(mixed $value): void
    {
        $request = new Request(query: ['x' => $value], post: ['x' => $value]);

        self::assertNull($request->queryString('x'));
        self::assertNull($request->queryInt('x'));
        self::assertNull($request->queryBool('x'));
        self::assertNull($request->postString('x'));
        self::assertSame('safe', $request->queryString('x', 'safe'));
    }

    /**
     * `(int) "12abc"` is `12` — a value the client never sent. `FILTER_VALIDATE_INT` refuses
     * anything that is not wholly an integer.
     *
     * @return iterable<string, array{string, int|null}>
     */
    public static function integerish(): iterable
    {
        yield 'plain' => ['42', 42];
        yield 'negative' => ['-7', -7];
        yield 'zero' => ['0', 0];
        yield 'leading digits then letters' => ['12abc', null];
        yield 'float string' => ['1.5', null];
        yield 'empty' => ['', null];
        yield 'whitespace' => ['  ', null];
        yield 'hex-looking' => ['0x1A', null];
        yield 'huge' => ['999999999999999999999999', null];
    }

    #[DataProvider('integerish')]
    public function testIntegerReadingRefusesPartialNumbers(string $raw, ?int $expected): void
    {
        self::assertSame($expected, (new Request(query: ['n' => $raw]))->queryInt('n'));
    }

    /**
     * The classic: an unchecked cast makes the *string* `"false"` true. Same coercion `Env::get()`
     * uses, for the same reason.
     *
     * @return iterable<string, array{string, bool|null}>
     */
    public static function booleanish(): iterable
    {
        yield 'true' => ['true', true];
        yield 'string false' => ['false', false];
        yield '1' => ['1', true];
        yield '0' => ['0', false];
        yield 'on' => ['on', true];
        yield 'off' => ['off', false];
        yield 'yes' => ['yes', true];
        yield 'no' => ['no', false];
        yield 'not boolean-shaped' => ['maybe', null];
        yield 'numeric but not 0/1' => ['2', null];
        yield 'case-insensitive' => ['TRUE', true];
        // PHP's filter treats an empty (and whitespace-only) value as FALSE rather than
        // "not boolean-shaped" -- verified, and asserted here because it is surprising: `?flag=`
        // reads as false, not as absent. Following PHP rather than inventing a third answer keeps
        // this consistent with `Env::get()`, which uses the same filter.
        yield 'empty is false, not null' => ['', false];
        yield 'whitespace is false too' => ['  ', false];
    }

    #[DataProvider('booleanish')]
    public function testBooleanReadingUsesFilterVarNotACast(string $raw, ?bool $expected): void
    {
        self::assertSame($expected, (new Request(query: ['b' => $raw]))->queryBool('b'));
    }

    /**
     * A list is available when the caller has *decided* a list is acceptable — which is a
     * different decision from being handed one unexpectedly.
     */
    public function testListReadersReturnListsAndRefuseScalars(): void
    {
        $request = new Request(query: ['tag' => ['a', 'b'], 'one' => 'x'], post: ['t' => ['p']]);

        self::assertSame(['a', 'b'], $request->queryList('tag'));
        self::assertSame(['p'], $request->postList('t'));
        // A scalar is not silently wrapped into a one-element list: wrapping would erase the
        // distinction the list readers exist to preserve.
        self::assertSame([], $request->queryList('one'));
        self::assertSame([], $request->queryList('absent'));
    }

    public function testNestedArraysAreSkippedRatherThanFlattenedInLists(): void
    {
        $request = new Request(query: ['t' => ['ok', ['deep'], 'also-ok']]);

        self::assertSame(['ok', 'also-ok'], $request->queryList('t'));
    }

    // ---- request line ---------------------------------------------------------------------------

    public function testMethodIsUpperCasedAndDefaultsToGet(): void
    {
        self::assertSame('POST', (new Request(server: ['REQUEST_METHOD' => 'post']))->method());
        self::assertSame('GET', (new Request())->method());
    }

    public function testUriIsTheServerReportedTarget(): void
    {
        self::assertSame('/a/b?c=1', (new Request(server: ['REQUEST_URI' => '/a/b?c=1']))->uri());
        self::assertSame('', (new Request())->uri());
    }

    /**
     * `$_SERVER['HTTPS']` is the string `'off'` on some servers rather than being absent, so an
     * `isset()` check reports every such request as secure.
     */
    public function testIsSecureHandlesTheOffString(): void
    {
        self::assertTrue((new Request(server: ['HTTPS' => 'on']))->isSecure());
        self::assertTrue((new Request(server: ['HTTPS' => '1']))->isSecure());
        self::assertFalse((new Request(server: ['HTTPS' => 'off']))->isSecure());
        self::assertFalse((new Request(server: ['HTTPS' => '']))->isSecure());
        self::assertFalse((new Request())->isSecure());
    }

    /**
     * A forwarded-proto header is client-supplied unless a trusted proxy rewrote it, and this
     * class cannot know whether one did. Trusting it would let any client claim HTTPS.
     */
    public function testIsSecureIgnoresClientSuppliedForwardedHeaders(): void
    {
        $request = new Request(server: ['HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertFalse($request->isSecure());
    }

    // ---- headers --------------------------------------------------------------------------------

    public function testHeadersComeFromServerAndAreLowerCased(): void
    {
        $request = new Request(server: [
            'HTTP_USER_AGENT' => 'curl/8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
            'REQUEST_METHOD' => 'GET',
        ]);

        self::assertSame([
            'user-agent' => 'curl/8',
            'x-forwarded-for' => '203.0.113.1',
        ], $request->headers());
    }

    /**
     * CGI reports these two without the `HTTP_` prefix, so a prefix-only rule loses them.
     */
    public function testContentTypeAndLengthAreFoundWithoutTheHttpPrefix(): void
    {
        $request = new Request(server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '42']);

        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('42', $request->header('content-length'));
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = new Request(server: ['HTTP_X_CUSTOM' => 'v']);

        self::assertSame('v', $request->header('X-Custom'));
        self::assertSame('v', $request->header('x-custom'));
        self::assertSame('v', $request->header('X-CUSTOM'));
        self::assertNull($request->header('X-Absent'));
        self::assertSame('d', $request->header('X-Absent', 'd'));
    }

    public function testNonHeaderServerKeysAreNotReportedAsHeaders(): void
    {
        $request = new Request(server: ['DOCUMENT_ROOT' => '/var/www', 'PATH' => '/usr/bin']);

        self::assertSame([], $request->headers());
    }

    // ---- files ----------------------------------------------------------------------------------

    public function testFileReturnsTheRawEntryOrNull(): void
    {
        $entry = ['name' => 'a.txt', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $request = new Request(files: ['doc' => $entry, 'bad' => 'not-an-array']);

        self::assertSame($entry, $request->file('doc'));
        self::assertNull($request->file('bad'));
        self::assertNull($request->file('absent'));
    }

    public function testCookiesAreReadAsStrings(): void
    {
        $request = new Request(cookies: ['session' => 'abc', 'weird' => ['a']]);

        self::assertSame('abc', $request->cookie('session'));
        self::assertNull($request->cookie('weird'));
    }

    /**
     * Nothing but `fromGlobals()` touches a superglobal, which is what makes every other method a
     * pure function of the constructor arguments — and what lets the PSR-7 bridge build one.
     */
    public function testFromGlobalsReadsTheSuperglobals(): void
    {
        $_GET = ['q' => 'search'];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $request = Request::fromGlobals();

        self::assertSame('search', $request->queryString('q'));
        self::assertSame('GET', $request->method());

        $_GET = [];
    }
}
