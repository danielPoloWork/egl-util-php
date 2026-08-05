<?php

declare(strict_types=1);

namespace D4np\Utils\Bridge\Psr7;

use D4np\Utils\Http\Request;
use D4np\Utils\Http\Response;
use D4np\Utils\Support\HttpException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Conversion between `egl/utils` HTTP values and PSR-7 messages (spec 02, imported ADR-002).
 *
 * The only sanctioned crossing point between the two vocabularies. The core keeps no PSR-7
 * dependency and never learns this class exists — deptrac's `Bridge` layer makes a core import a
 * build failure (ADR-0033).
 *
 * **Factories are injected, never discovered.** "Using any PSR-17 factory" means the consumer's:
 * this class does not guess at an implementation, fall back to one, or ship a default.
 *
 * **It converts values, not streams.** A PSR-7 body is read in full to a string and a string
 * becomes a fresh stream — the lightweight tier this package serves has no streaming ambitions
 * (spec 02 §1). A message too large to hold in memory belongs to a PSR-15 stack, not here.
 *
 * Every refusal throws {@see HttpException} naming what was seen, carrying ADR-0025's
 * refuse-don't-coerce semantics across the boundary unchanged.
 */
final class Psr7Bridge
{
    public function __construct(
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UploadedFileFactoryInterface $uploadedFileFactory,
        private readonly UriFactoryInterface $uriFactory,
    ) {
    }

    // ---- requests --------------------------------------------------------------------------------

    /**
     * A core request as a PSR-7 server request (BFR-01…BFR-08).
     *
     * @throws HttpException if an uploaded-file entry is malformed
     */
    public function requestToPsr7(Request $request): ServerRequestInterface
    {
        $message = $this->serverRequestFactory
            ->createServerRequest($request->method(), $this->uriOf($request))
            ->withQueryParams(self::stringKeyed($request->queryAll()))
            ->withParsedBody($request->postAll())
            ->withCookieParams(self::stringKeyed($request->cookieAll()))
            ->withUploadedFiles($this->uploadedFilesOf($request));

        foreach ($request->headers() as $name => $value) {
            $message = $message->withHeader($name, $value);
        }

        return $message;
    }

    /**
     * A PSR-7 server request as a core request (BFR-09…BFR-12).
     *
     * @throws HttpException if the parsed body is an object, or an uploaded-file tree is nested
     */
    public function requestFromPsr7(ServerRequestInterface $message): Request
    {
        $parsed = $message->getParsedBody();

        // BFR-10. PSR-7 permits `array|object|null`; the core's POST projection is an array, and no
        // lossless array projection of an arbitrary object exists — `get_object_vars()` would drop
        // everything non-public and silently succeed.
        if (is_object($parsed)) {
            throw new HttpException(sprintf(
                'Cannot convert a PSR-7 request whose parsed body is an object (%s). The core\'s '
                . 'POST projection is an array, and projecting an arbitrary object into one loses '
                . 'whatever is not public. Call withParsedBody() with an array first, deciding for '
                . 'yourself what the array should contain.',
                $parsed::class,
            ));
        }

        return new Request(
            query: $message->getQueryParams(),
            post: is_array($parsed) ? $parsed : [],
            server: $this->serverParamsOf($message),
            files: $this->filesArrayOf($message),
            cookies: $message->getCookieParams(),
        );
    }

    // ---- responses -------------------------------------------------------------------------------

    /**
     * A core response as a PSR-7 response (BFR-13…BFR-16).
     *
     * Emits nothing: `Response::send()` is never called, and no header reaches PHP.
     */
    public function responseToPsr7(Response $response): ResponseInterface
    {
        $message = $this->responseFactory
            ->createResponse($response->status())
            ->withBody($this->streamFactory->createStream($response->body()));

        foreach ($response->headers() as $name => $value) {
            $message = $message->withHeader($name, $value);
        }

        return $message;
    }

    /**
     * A PSR-7 response as a core response (BFR-17…BFR-19).
     *
     * @throws HttpException if the message carries more than one `Set-Cookie` header
     */
    public function responseFromPsr7(ResponseInterface $message): Response
    {
        $response = Response::create($message->getStatusCode(), self::readFully($message->getBody()));

        foreach (array_keys($message->getHeaders()) as $name) {
            $name = (string) $name;

            // BFR-18, the contract's sharpest edge. `getHeaderLine()` comma-joins, which is right
            // for every header except this one: RFC 6265 cookie strings contain commas of their own
            // (`Expires=Wed, 21 Oct 2026 07:28:00 GMT`), so joining two Set-Cookie values produces
            // a string no client can split back into the cookies that were sent. The core's header
            // projection is single-valued and cannot carry the list, so this refuses rather than
            // silently corrupting the one header where the join is wrong.
            if (strtolower($name) === 'set-cookie' && count($message->getHeader($name)) > 1) {
                throw new HttpException(sprintf(
                    'Cannot convert a PSR-7 response carrying %d Set-Cookie headers. Comma-joining '
                    . 'them — correct for every other header — corrupts them, because cookie '
                    . 'strings contain commas themselves; and the core response holds one value per '
                    . 'header name, so the list cannot survive. Send the cookies through your PSR-7 '
                    . 'stack, or set a single Set-Cookie before converting.',
                    count($message->getHeader($name)),
                ));
            }

            $response = $response->withHeader($name, $message->getHeaderLine($name));
        }

        return $response;
    }

    // ---- request helpers -------------------------------------------------------------------------

    /**
     * The absolute URI for a core request.
     *
     * The core reports the request *target* (`REQUEST_URI` — path and query) plus a `Host` header
     * and a secure flag; PSR-7 wants a URI object. Assembling them here is the whole of the
     * translation, and the `Host` header is used verbatim because it already carries a port when
     * there is one.
     */
    private function uriOf(Request $request): \Psr\Http\Message\UriInterface
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        $host = $request->header('host') ?? '';
        $target = $request->uri();

        // With no Host header there is no authority to build, so the target stands alone: a
        // relative-reference URI is what PSR-7 implementations accept for that case, and inventing
        // `localhost` would put a hostname in the message that nobody sent.
        $uri = $host === '' ? $target : $scheme . '://' . $host . $target;

        return $this->uriFactory->createUri($uri);
    }

    /**
     * `$_FILES` entries as `UploadedFileInterface` instances (BFR-07).
     *
     * @return array<string, UploadedFileInterface>
     *
     * @throws HttpException
     */
    private function uploadedFilesOf(Request $request): array
    {
        $files = [];

        foreach ($request->uploadedFiles() as $key => $entry) {
            if (!is_array($entry)) {
                throw new HttpException(sprintf(
                    'Uploaded-file entry "%s" is a %s, not the array PHP puts in $_FILES.',
                    (string) $key,
                    get_debug_type($entry),
                ));
            }

            $error = is_int($entry['error'] ?? null) ? $entry['error'] : UPLOAD_ERR_NO_FILE;
            $size = is_int($entry['size'] ?? null) ? $entry['size'] : null;
            $tmpName = is_string($entry['tmp_name'] ?? null) ? $entry['tmp_name'] : '';

            // BFR-07's second half: a failed upload has nothing valid to read, and PSR-7 permits
            // getStream() to throw for one. Opening `tmp_name` here — which for a failed upload is
            // usually '' — would turn a reportable error into an I/O failure at conversion time.
            $stream = $error === UPLOAD_ERR_OK && $tmpName !== ''
                ? $this->streamFactory->createStreamFromFile($tmpName, 'r')
                : $this->streamFactory->createStream('');

            $files[(string) $key] = $this->uploadedFileFactory->createUploadedFile(
                $stream,
                $size,
                $error,
                is_string($entry['name'] ?? null) ? $entry['name'] : null,
                is_string($entry['type'] ?? null) ? $entry['type'] : null,
            );
        }

        return $files;
    }

    /**
     * PSR-7 uploaded files as a `$_FILES`-shaped array (BFR-11).
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws HttpException
     */
    private function filesArrayOf(ServerRequestInterface $message): array
    {
        $files = [];

        foreach ($message->getUploadedFiles() as $key => $file) {
            // PSR-7 permits a nested tree here (`getUploadedFiles()` mirrors the input names), and
            // the core's `file(string $key)` surface is flat. Flattening would invent a key
            // structure nobody sent, so a nested tree is refused by name.
            if (!$file instanceof UploadedFileInterface) {
                throw new HttpException(sprintf(
                    'Uploaded file "%s" is a nested tree, and the core exposes uploads as a flat '
                    . 'key => entry map. Flatten it yourself, choosing the key names, rather than '
                    . 'having this bridge invent them.',
                    (string) $key,
                ));
            }

            $error = $file->getError();
            $entry = [
                'name' => $file->getClientFilename() ?? '',
                'type' => $file->getClientMediaType() ?? '',
                'size' => $file->getSize() ?? 0,
                'error' => $error,
                'tmp_name' => '',
            ];

            // BFR-11: materialize only a successful upload, and never touch the stream otherwise.
            if ($error === UPLOAD_ERR_OK) {
                $entry['tmp_name'] = self::materialize($file);
            }

            $files[(string) $key] = $entry;
        }

        return $files;
    }

    /**
     * A `$_SERVER`-shaped array carrying everything the core reads back out of one.
     *
     * The inverse of `Request::headers()`'s derivation: `content-type` and `content-length` are the
     * two CGI reports without an `HTTP_` prefix, and the core reads them from either spelling, so
     * they are written unprefixed to match what a real SAPI would produce.
     *
     * @return array<string, mixed>
     */
    private function serverParamsOf(ServerRequestInterface $message): array
    {
        $uri = $message->getUri();
        $target = $uri->getPath();

        if ($uri->getQuery() !== '') {
            $target .= '?' . $uri->getQuery();
        }

        /** @var array<string, mixed> $server */
        $server = $message->getServerParams();
        $server['REQUEST_METHOD'] = $message->getMethod();
        $server['REQUEST_URI'] = $target;

        if ($uri->getScheme() === 'https') {
            $server['HTTPS'] = 'on';
        } else {
            // Explicitly removed rather than left: a server param inherited from the source message
            // would otherwise make a plain-http request report itself as secure.
            unset($server['HTTPS']);
        }

        foreach ($message->getHeaders() as $name => $_) {
            $name = (string) $name;
            $line = $message->getHeaderLine($name);
            $upper = strtoupper(str_replace('-', '_', $name));

            $server[in_array($upper, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $upper : 'HTTP_' . $upper] = $line;
        }

        return $server;
    }

    // ---- shared helpers --------------------------------------------------------------------------

    /**
     * A successful upload's bytes on disk, as a path the core's `$_FILES` shape can carry.
     *
     * @throws HttpException
     */
    private static function materialize(UploadedFileInterface $file): string
    {
        $path = tempnam(sys_get_temp_dir(), 'd4np-psr7-');

        if ($path === false) {
            throw new HttpException(
                'Could not create a temporary file for an uploaded file. The core carries uploads '
                . 'as $_FILES entries, which name a path on disk, so there is nowhere else to put '
                . 'the bytes.',
            );
        }

        file_put_contents($path, self::readFully($file->getStream()));

        return $path;
    }

    /**
     * A PSR-7 stream's whole contents (BFR-19).
     *
     * Rewound first when seekable: a stream already read by middleware would otherwise yield an
     * empty body, which is the failure mode that looks like "the response lost its content".
     */
    private static function readFully(\Psr\Http\Message\StreamInterface $stream): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream->getContents();
    }

    /**
     * PSR-7 keys query and cookie collections by string; PHP's superglobals may carry integer keys
     * (`?0=zero`). Casting here keeps the produced message well-typed without discarding an entry.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
