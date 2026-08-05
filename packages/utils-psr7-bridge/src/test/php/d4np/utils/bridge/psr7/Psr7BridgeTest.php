<?php

declare(strict_types=1);

namespace D4np\Utils\Bridge\Psr7\Tests;

use D4np\Utils\Bridge\Psr7\Psr7Bridge;
use D4np\Utils\Http\Request;
use D4np\Utils\Http\Response;
use D4np\Utils\Support\HttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Spec 02's conversion contract, clause by clause — the **T-B** suite.
 *
 * Every test runs against **every PSR-17 implementation installed**, because a contract proven
 * against one vendor silently encodes that vendor's leniencies. CI pins one implementation per
 * matrix cell (spec 02 §7); locally both are present and both run.
 *
 * The provider **throws** when no implementation is installed rather than yielding nothing: an
 * empty provider is a suite that passes without testing anything, which is the failure this
 * project's gates exist to prevent.
 */
#[Group('T-B')]
final class Psr7BridgeTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryFiles = [];
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function factories(): iterable
    {
        $found = false;

        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            $found = true;
            yield 'nyholm' => [new \Nyholm\Psr7\Factory\Psr17Factory()];
        }

        if (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $found = true;
            yield 'guzzle' => [new \GuzzleHttp\Psr7\HttpFactory()];
        }

        if (!$found) {
            throw new \RuntimeException(
                'No PSR-17 implementation is installed, so the conversion contract would be '
                . 'asserted against nothing. Install nyholm/psr7 or guzzlehttp/psr7.',
            );
        }
    }

    private function bridge(object $factory): Psr7Bridge
    {
        /**
         * One object implements all five PSR-17 factory interfaces in both vendors, which is why
         * the provider can yield a single value.
         *
         * @var \Psr\Http\Message\ServerRequestFactoryInterface&\Psr\Http\Message\ResponseFactoryInterface&\Psr\Http\Message\StreamFactoryInterface&\Psr\Http\Message\UploadedFileFactoryInterface&\Psr\Http\Message\UriFactoryInterface $factory
         */
        return new Psr7Bridge($factory, $factory, $factory, $factory, $factory);
    }

    private function temporaryFileContaining(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'd4np-test-');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    // ---- BFR-01…BFR-08: core -> PSR-7, requests --------------------------------------------------

    #[DataProvider('factories')]
    public function testBfr01TheMethodIsPreserved(object $factory): void
    {
        foreach (['GET', 'POST', 'DELETE', 'PATCH'] as $method) {
            $request = new Request(server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/']);

            self::assertSame($method, $this->bridge($factory)->requestToPsr7($request)->getMethod());
        }
    }

    #[DataProvider('factories')]
    public function testBfr02TheUriCarriesSchemeHostPortPathAndQuery(object $factory): void
    {
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/a/b?x=1&y=2',
            'HTTP_HOST' => 'example.test:8443',
            'HTTPS' => 'on',
        ]);

        $uri = $this->bridge($factory)->requestToPsr7($request)->getUri();

        self::assertSame('https', $uri->getScheme());
        self::assertSame('example.test', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/a/b', $uri->getPath());
        self::assertSame('x=1&y=2', $uri->getQuery());
    }

    #[DataProvider('factories')]
    public function testBfr02AnInsecureRequestProducesAnHttpScheme(object $factory): void
    {
        $request = new Request(server: ['REQUEST_URI' => '/', 'HTTP_HOST' => 'example.test']);

        self::assertFalse($request->isSecure());
        self::assertSame('http', $this->bridge($factory)->requestToPsr7($request)->getUri()->getScheme());
    }

    #[DataProvider('factories')]
    public function testBfr03EveryHeaderAppearsOnTheMessage(object $factory): void
    {
        $request = new Request(server: [
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.test',
            'HTTP_X_REQUEST_ID' => 'abc-123',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $message = $this->bridge($factory)->requestToPsr7($request);

        foreach ($request->headers() as $name => $value) {
            self::assertTrue($message->hasHeader($name), "header {$name} is missing");
            self::assertSame($value, $message->getHeaderLine($name));
            self::assertCount(1, $message->getHeader($name), 'the core is single-valued per name');
        }
    }

    #[DataProvider('factories')]
    public function testBfr04QueryParamsSurviveIncludingArrays(object $factory): void
    {
        $query = ['q' => 'search', 'tag' => ['a', 'b'], 'page' => '2'];
        $request = new Request(query: $query, server: ['REQUEST_URI' => '/']);

        self::assertSame($query, $this->bridge($factory)->requestToPsr7($request)->getQueryParams());
    }

    #[DataProvider('factories')]
    public function testBfr05TheParsedBodyEqualsThePostCollection(object $factory): void
    {
        $post = ['email' => 'user@example.test', 'roles' => ['admin', 'editor']];
        $request = new Request(post: $post, server: ['REQUEST_URI' => '/']);

        self::assertSame($post, $this->bridge($factory)->requestToPsr7($request)->getParsedBody());
    }

    #[DataProvider('factories')]
    public function testBfr06CookieParamsSurvive(object $factory): void
    {
        $cookies = ['PHPSESSID' => 'abc', 'theme' => 'dark'];
        $request = new Request(cookies: $cookies, server: ['REQUEST_URI' => '/']);

        self::assertSame($cookies, $this->bridge($factory)->requestToPsr7($request)->getCookieParams());
    }

    #[DataProvider('factories')]
    public function testBfr07AnUploadedFileKeepsItsMetadataAndContents(object $factory): void
    {
        $path = $this->temporaryFileContaining('file contents here');
        $request = new Request(
            server: ['REQUEST_URI' => '/'],
            files: ['avatar' => [
                'name' => 'me.png',
                'type' => 'image/png',
                'size' => 18,
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
            ]],
        );

        $file = $this->bridge($factory)->requestToPsr7($request)->getUploadedFiles()['avatar'];
        self::assertInstanceOf(UploadedFileInterface::class, $file);

        self::assertSame('me.png', $file->getClientFilename());
        self::assertSame('image/png', $file->getClientMediaType());
        self::assertSame(18, $file->getSize());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
        self::assertSame('file contents here', (string) $file->getStream());
    }

    /**
     * **BFR-07's second half.** A failed upload has nothing valid to read, and `tmp_name` is
     * typically `''`. The stream must never be opened — asserted by pointing `tmp_name` at a path
     * that does not exist: any attempt to open it would raise, so completing without error is the
     * evidence.
     */
    #[DataProvider('factories')]
    public function testBfr07AFailedUploadsStreamIsNeverOpened(object $factory): void
    {
        $request = new Request(
            server: ['REQUEST_URI' => '/'],
            files: ['avatar' => [
                'name' => '',
                'type' => '',
                'size' => 0,
                'tmp_name' => '/nonexistent/path/that/cannot/be/opened',
                'error' => UPLOAD_ERR_NO_FILE,
            ]],
        );

        $file = $this->bridge($factory)->requestToPsr7($request)->getUploadedFiles()['avatar'];
        self::assertInstanceOf(UploadedFileInterface::class, $file);

        self::assertSame(UPLOAD_ERR_NO_FILE, $file->getError(), 'the error code must survive verbatim');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function uploadErrors(): iterable
    {
        yield 'ini size' => [UPLOAD_ERR_INI_SIZE];
        yield 'form size' => [UPLOAD_ERR_FORM_SIZE];
        yield 'partial' => [UPLOAD_ERR_PARTIAL];
        yield 'no file' => [UPLOAD_ERR_NO_FILE];
        yield 'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR];
        yield 'cant write' => [UPLOAD_ERR_CANT_WRITE];
        yield 'extension' => [UPLOAD_ERR_EXTENSION];
    }

    #[DataProvider('uploadErrors')]
    public function testBfr07EveryUploadErrorCodeSurvivesVerbatim(int $error): void
    {
        foreach (self::factories() as [$factory]) {
            $request = new Request(
                server: ['REQUEST_URI' => '/'],
                files: ['f' => ['name' => '', 'type' => '', 'size' => 0, 'tmp_name' => '', 'error' => $error]],
            );

            $file = $this->bridge($factory)->requestToPsr7($request)->getUploadedFiles()['f'];
            self::assertInstanceOf(UploadedFileInterface::class, $file);
            self::assertSame($error, $file->getError());
        }
    }

    /**
     * **BFR-08 — the conversion is a snapshot, not a view.** A message that tracked a superglobal
     * would change under a caller who touched `$_GET` after converting.
     */
    #[DataProvider('factories')]
    public function testBfr08TheMessageDoesNotTrackLaterSuperglobalChanges(object $factory): void
    {
        $_GET = ['a' => 'original'];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $message = $this->bridge($factory)->requestToPsr7(Request::fromGlobals());

        $_GET['a'] = 'mutated';
        $_GET['b'] = 'added';

        self::assertSame(['a' => 'original'], $message->getQueryParams());

        $_GET = [];
    }

    // ---- BFR-09…BFR-12: PSR-7 -> core, requests ---------------------------------------------------

    #[DataProvider('factories')]
    public function testBfr09MultiValuedHeadersReduceByPsr7sOwnRule(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $message = $bridge->requestToPsr7(new Request(server: ['REQUEST_URI' => '/']))
            ->withHeader('Accept', 'text/html')
            ->withAddedHeader('Accept', 'application/json');

        self::assertSame('text/html, application/json', $message->getHeaderLine('Accept'));
        self::assertSame($message->getHeaderLine('Accept'), $bridge->requestFromPsr7($message)->header('accept'));
    }

    /**
     * **BFR-10.** PSR-7 permits `array|object|null`; the core's POST projection is an array, and
     * `get_object_vars()` on an arbitrary object drops everything non-public while appearing to
     * succeed.
     */
    #[DataProvider('factories')]
    public function testBfr10AnObjectParsedBodyIsRefused(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $message = $bridge->requestToPsr7(new Request(server: ['REQUEST_URI' => '/']))
            ->withParsedBody(new \stdClass());

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/parsed body is an object/');

        $bridge->requestFromPsr7($message);
    }

    #[DataProvider('factories')]
    public function testBfr10ANullParsedBodyBecomesAnEmptyPost(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $message = $bridge->requestToPsr7(new Request(server: ['REQUEST_URI' => '/']))->withParsedBody(null);

        self::assertSame([], $bridge->requestFromPsr7($message)->postAll());
    }

    /**
     * **BFR-11.** A successful upload is materialized byte-identically; the core carries uploads as
     * `$_FILES` entries, which name a path on disk.
     */
    #[DataProvider('factories')]
    public function testBfr11ASuccessfulUploadIsMaterializedByteIdentically(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $bytes = random_bytes(2048);
        $path = $this->temporaryFileContaining($bytes);

        $message = $bridge->requestToPsr7(new Request(
            server: ['REQUEST_URI' => '/'],
            files: ['doc' => [
                'name' => 'doc.bin', 'type' => 'application/octet-stream',
                'size' => strlen($bytes), 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK,
            ]],
        ));

        $entry = $bridge->requestFromPsr7($message)->file('doc');

        self::assertIsArray($entry);
        self::assertSame(UPLOAD_ERR_OK, $entry['error']);
        self::assertIsString($entry['tmp_name']);
        $this->temporaryFiles[] = $entry['tmp_name'];

        self::assertSame($bytes, file_get_contents($entry['tmp_name']), 'the bytes must be identical');
        self::assertSame('doc.bin', $entry['name']);
    }

    /**
     * **BFR-11's sharp half.** Both vendors' `UploadedFile::getStream()` **throws** when the error
     * code is not `UPLOAD_ERR_OK` — so if the bridge touched the stream, this would raise. Passing
     * is the evidence that it did not.
     */
    #[DataProvider('factories')]
    public function testBfr11AFailedUploadsStreamIsNeverTouchedComingBack(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $message = $bridge->requestToPsr7(new Request(
            server: ['REQUEST_URI' => '/'],
            files: ['f' => [
                'name' => 'big.iso', 'type' => 'application/octet-stream',
                'size' => 0, 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE,
            ]],
        ));

        $entry = $bridge->requestFromPsr7($message)->file('f');

        self::assertIsArray($entry);
        self::assertSame(UPLOAD_ERR_INI_SIZE, $entry['error']);
        self::assertSame('', $entry['tmp_name'], 'a failed upload has no file on disk');
    }

    #[DataProvider('factories')]
    public function testBfr11ANestedUploadTreeIsRefusedByName(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $path = $this->temporaryFileContaining('x');

        $one = $bridge->requestToPsr7(new Request(
            server: ['REQUEST_URI' => '/'],
            files: ['f' => ['name' => 'a', 'type' => '', 'size' => 1, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK]],
        ))->getUploadedFiles()['f'];
        self::assertInstanceOf(UploadedFileInterface::class, $one);

        $message = $bridge->requestToPsr7(new Request(server: ['REQUEST_URI' => '/']))
            ->withUploadedFiles(['docs' => ['nested' => $one]]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/nested tree/');

        $bridge->requestFromPsr7($message);
    }

    /**
     * **BFR-12.** The bridge transports; the *core's* accessors keep their own refusal semantics
     * (ADR-0025) when the value is read.
     */
    #[DataProvider('factories')]
    public function testBfr12QueryValuesPassThroughWithoutPreCoercion(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $message = $bridge->requestToPsr7(new Request(server: ['REQUEST_URI' => '/']))
            ->withQueryParams(['tags' => ['a', 'b'], 'n' => '42']);

        $request = $bridge->requestFromPsr7($message);

        self::assertSame(['tags' => ['a', 'b'], 'n' => '42'], $request->queryAll());
        self::assertNull($request->queryString('tags'), 'the core still refuses an array as a string');
        self::assertSame(['a', 'b'], $request->queryList('tags'));
    }

    /**
     * **BFR-08b.** A later `with*()` derivative of the source message must not reach back into an
     * already-converted `Request`.
     */
    #[DataProvider('factories')]
    public function testBfr08bTheProducedRequestIsDetachedFromLaterDerivatives(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $message = $bridge->requestToPsr7(new Request(server: ['REQUEST_URI' => '/']))
            ->withQueryParams(['a' => 'original']);

        $request = $bridge->requestFromPsr7($message);
        $message->withQueryParams(['a' => 'mutated']);

        self::assertSame(['a' => 'original'], $request->queryAll());
    }

    // ---- BFR-13…BFR-16: core -> PSR-7, responses --------------------------------------------------

    #[DataProvider('factories')]
    public function testBfr13TheStatusIsPreserved(object $factory): void
    {
        foreach ([200, 201, 302, 404, 422, 500] as $status) {
            self::assertSame(
                $status,
                $this->bridge($factory)->responseToPsr7(Response::create($status))->getStatusCode(),
            );
        }
    }

    #[DataProvider('factories')]
    public function testBfr14EveryResponseHeaderAppears(object $factory): void
    {
        $response = Response::json(['ok' => true])->withHeader('X-Trace', 'abc');
        $message = $this->bridge($factory)->responseToPsr7($response);

        foreach ($response->headers() as $name => $value) {
            self::assertSame($value, $message->getHeaderLine($name));
            self::assertCount(1, $message->getHeader($name));
        }
    }

    #[DataProvider('factories')]
    public function testBfr15TheBodyIsReadableInFullFromTheStart(object $factory): void
    {
        $body = str_repeat('body-', 1000);
        $stream = $this->bridge($factory)->responseToPsr7(Response::text($body))->getBody();

        self::assertSame(0, $stream->tell(), 'a consumer reading without rewinding must get it all');
        self::assertSame($body, $stream->getContents());
    }

    /**
     * **BFR-16.** Conversion is a value operation. Asserted twice: no output escapes, and the
     * source never names `send()` — the mechanism, because a future edit could call it in a branch
     * this test does not reach.
     */
    #[DataProvider('factories')]
    public function testBfr16ConvertingEmitsNothing(object $factory): void
    {
        ob_start();

        try {
            $this->bridge($factory)->responseToPsr7(Response::text('hello'));
            $emitted = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        self::assertSame('', $emitted);

        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(Psr7Bridge::class))->getFileName(),
        );
        self::assertStringNotContainsString('->send(', $source, 'the bridge must never send a response');
    }

    // ---- BFR-17…BFR-19: PSR-7 -> core, responses --------------------------------------------------

    #[DataProvider('factories')]
    public function testBfr17TheStatusComesBack(object $factory): void
    {
        $bridge = $this->bridge($factory);

        foreach ([200, 404, 503] as $status) {
            $message = $bridge->responseToPsr7(Response::create($status));
            self::assertSame($status, $bridge->responseFromPsr7($message)->status());
        }
    }

    /**
     * **BFR-18 — the contract's sharpest edge.** Comma-joining is right for every header except
     * `Set-Cookie`, whose values contain commas of their own (`Expires=Wed, 21 Oct …`). A naive
     * implementation passes every other test here while silently corrupting cookies.
     */
    #[DataProvider('factories')]
    public function testBfr18MultipleSetCookieHeadersAreRefused(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $message = $bridge->responseToPsr7(Response::create(200))
            ->withHeader('Set-Cookie', 'a=1; Path=/; Expires=Wed, 21 Oct 2026 07:28:00 GMT')
            ->withAddedHeader('Set-Cookie', 'b=2; Path=/; HttpOnly');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/Set-Cookie headers/');

        $bridge->responseFromPsr7($message);
    }

    #[DataProvider('factories')]
    public function testBfr18ASingleSetCookiePassesThroughUnchanged(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $cookie = 'sid=abc; Path=/; Expires=Wed, 21 Oct 2026 07:28:00 GMT; HttpOnly';

        $message = $bridge->responseToPsr7(Response::create(200))->withHeader('Set-Cookie', $cookie);

        self::assertSame($cookie, $bridge->responseFromPsr7($message)->header('set-cookie'));
    }

    #[DataProvider('factories')]
    public function testBfr19AnAlreadyReadBodyIsRewoundNotLost(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $message = $bridge->responseToPsr7(Response::text('the whole body'));

        // What middleware that inspected the body would leave behind.
        $message->getBody()->getContents();

        self::assertSame('the whole body', $bridge->responseFromPsr7($message)->body());
    }

    // ---- BFR-20…BFR-22: round-trips ---------------------------------------------------------------

    #[DataProvider('factories')]
    public function testBfr20ARequestSurvivesTheRoundTrip(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $original = new Request(
            query: ['q' => 'search', 'tag' => ['a', 'b']],
            post: ['email' => 'user@example.test'],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/search?q=search',
                'HTTP_HOST' => 'example.test',
                'HTTPS' => 'on',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            cookies: ['sid' => 'abc'],
        );

        $back = $bridge->requestFromPsr7($bridge->requestToPsr7($original));

        self::assertSame($original->method(), $back->method());
        self::assertSame($original->uri(), $back->uri());
        self::assertSame($original->isSecure(), $back->isSecure());
        self::assertSame($original->queryAll(), $back->queryAll());
        self::assertSame($original->postAll(), $back->postAll());
        self::assertSame($original->cookieAll(), $back->cookieAll());
        self::assertSame($original->headers(), $back->headers());
    }

    #[DataProvider('factories')]
    public function testBfr21AResponseSurvivesTheRoundTrip(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $original = Response::json(['id' => 7, 'name' => 'thing'], 201)
            ->withHeader('X-Trace', 'abc-123')
            ->withHeader('Cache-Control', 'no-store');

        $back = $bridge->responseFromPsr7($bridge->responseToPsr7($original));

        self::assertSame($original->status(), $back->status());
        self::assertSame($original->body(), $back->body());
        self::assertSame($original->headers(), $back->headers());
    }

    #[DataProvider('factories')]
    public function testBfr22UploadedBytesSurviveTheFullRoundTrip(object $factory): void
    {
        $bridge = $this->bridge($factory);
        $bytes = random_bytes(4096);
        $path = $this->temporaryFileContaining($bytes);

        $original = new Request(
            server: ['REQUEST_URI' => '/'],
            files: ['payload' => [
                'name' => 'payload.bin', 'type' => 'application/octet-stream',
                'size' => strlen($bytes), 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK,
            ]],
        );

        $entry = $bridge->requestFromPsr7($bridge->requestToPsr7($original))->file('payload');

        self::assertIsArray($entry);
        self::assertIsString($entry['tmp_name']);
        $this->temporaryFiles[] = $entry['tmp_name'];

        self::assertSame($bytes, file_get_contents($entry['tmp_name']));
        self::assertSame(strlen($bytes), $entry['size']);
    }
}
