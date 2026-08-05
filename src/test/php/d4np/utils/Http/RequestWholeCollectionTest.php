<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * `Request`'s whole-collection readers (ADR-0034).
 *
 * They exist because the PSR-7 bridge must project an entire request across the boundary and a
 * key-scoped reader cannot enumerate — `postAll()` and `uploadedFiles()` in particular have no
 * substitute, since a POST body and `$_FILES` are recoverable from nothing else the class exposes.
 *
 * The tests worth having are the ones that pin what these do **not** do: they return raw values,
 * they do not coerce, and they hand out a copy rather than a view.
 */
final class RequestWholeCollectionTest extends TestCase
{
    public function testEachReaderReturnsItsCollectionEntire(): void
    {
        $request = new Request(
            query: ['q' => 'search', 'tag' => ['a', 'b']],
            post: ['email' => 'user@example.test'],
            server: ['REQUEST_URI' => '/'],
            files: ['avatar' => ['name' => 'a.png', 'error' => UPLOAD_ERR_OK]],
            cookies: ['sid' => 'abc'],
        );

        self::assertSame(['q' => 'search', 'tag' => ['a', 'b']], $request->queryAll());
        self::assertSame(['email' => 'user@example.test'], $request->postAll());
        self::assertSame(['sid' => 'abc'], $request->cookieAll());
        self::assertSame(['avatar' => ['name' => 'a.png', 'error' => UPLOAD_ERR_OK]], $request->uploadedFiles());
    }

    public function testTheyAreEmptyWhenNothingArrived(): void
    {
        $request = new Request();

        self::assertSame([], $request->queryAll());
        self::assertSame([], $request->postAll());
        self::assertSame([], $request->cookieAll());
        self::assertSame([], $request->uploadedFiles());
    }

    /**
     * **The distinction from the typed accessors.** `queryString('tag')` refuses an array because a
     * caller asking for one string was handed something else (ADR-0025). `queryAll()` makes no such
     * promise: a caller asking for the whole collection is asking for exactly what arrived, values
     * of every shape included. Asserting both here so the contrast is stated where someone would
     * otherwise read the raw reader as a loophole.
     */
    public function testTheRawReadersDoNotCoerceAndTheTypedOnesStillRefuse(): void
    {
        $request = new Request(query: ['tag' => ['a', 'b'], 'n' => 42, 'ok' => null]);

        self::assertSame(['tag' => ['a', 'b'], 'n' => 42, 'ok' => null], $request->queryAll());

        self::assertNull($request->queryString('tag'), 'an array is still refused as a string');
        self::assertNull($request->queryString('ok'));
        self::assertSame(['a', 'b'], $request->queryList('tag'));
    }

    /**
     * A copy, not a view: PHP arrays are values, and nothing here hands out mutable access to the
     * request's own state.
     */
    public function testTheReturnedArrayIsACopy(): void
    {
        $request = new Request(query: ['a' => 'original']);

        $copy = $request->queryAll();
        $copy['a'] = 'mutated';
        $copy['b'] = 'added';

        self::assertSame(['a' => 'original'], $request->queryAll());
    }

    /**
     * Integer keys survive. `?0=zero` produces an integer key in `$_GET` — the same fact that made
     * PHPStan reject an `array<string, mixed>` superglobal type back at item 6.1 — and a reader
     * that quietly dropped or re-indexed them would lose data the caller can see in `$_GET`.
     */
    public function testIntegerKeysSurvive(): void
    {
        $request = new Request(query: [0 => 'zero', 'a' => 'one']);

        self::assertSame([0 => 'zero', 'a' => 'one'], $request->queryAll());
    }

    public function testTheyReflectTheSuperglobalsThroughFromGlobals(): void
    {
        $_GET = ['g' => '1'];
        $_POST = ['p' => '2'];
        $_COOKIE = ['c' => '3'];
        $_FILES = ['f' => ['name' => 'x', 'error' => UPLOAD_ERR_NO_FILE]];

        $request = Request::fromGlobals();

        self::assertSame(['g' => '1'], $request->queryAll());
        self::assertSame(['p' => '2'], $request->postAll());
        self::assertSame(['c' => '3'], $request->cookieAll());
        self::assertSame(['f' => ['name' => 'x', 'error' => UPLOAD_ERR_NO_FILE]], $request->uploadedFiles());

        $_GET = $_POST = $_COOKIE = $_FILES = [];
    }
}
