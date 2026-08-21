# The endpoint kernel

*The ~20 lines that replace a folder per endpoint. Companion to the **Front Controller** entry in
[the catalogue](README.md); the router it is built on is [`Http\Router`](../../src/main/php/d4np/utils/Http/Router.php)
(spec r3 FR-38, [ADR-0050](../adr/0050-classify-the-miss-and-keep-the-router-a-table.md).)*

## The shape this replaces

The surveyed estate routed with the filesystem: **37 deployed folders**, each holding an
`index.php` that differed from its neighbours in one line.

*The estate's code, quoted — not this library's, and the only block on this page that is not meant
to run.*

```php
<?php
require_once './../../Autoload.php';
Autoload::embed();

$controller = new SomeController();
echo $controller->someAction();
```

Nothing about that is unreasonable in isolation. The cost is that it is thirty-seven copies:
the autoload path, the response encoding, the error handling and the header policy each exist
thirty-seven times, and they drift — because a fix applied where the bug was found is not
applied to the other thirty-six.

## The shape that replaces it

One front controller, one route table, everything cross-cutting written once.

```php
<?php // public/index.php — the only entry point

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use D4np\Utils\Http\Request;
use D4np\Utils\Http\Response;
use D4np\Utils\Http\Router;
use D4np\Utils\Support\MethodNotAllowedException;
use D4np\Utils\Support\RouteNotFoundException;

$container = require __DIR__ . '/../config/services.php';

/** @var Router $router */
$router = require __DIR__ . '/../config/routes.php';   // the table, below; it reads $container
$request = Request::fromGlobals();

$allow = null;

try {
    $matched = $router->matchRequest($request);
    $payload = ($matched->handler)($request, $matched->parameters);
    $status = 200;
} catch (RouteNotFoundException) {
    $payload = ['error' => 'not found'];
    $status = 404;
} catch (MethodNotAllowedException $e) {
    // RFC 9110 §15.5.6 makes this header mandatory, which is why the exception carries it.
    $allow = $e->allowHeader();
    $payload = ['error' => 'method not allowed'];
    $status = 405;
}

// `Response` has no public constructor — the five named ones are the way in — and it is
// immutable, so `withHeader()` returns a NEW instance. Assigning the result is not a style
// preference: dropping it is how a mandatory header goes missing on one branch only.
$response = Response::json($payload, $status);

if ($allow !== null) {
    $response = $response->withHeader('Allow', $allow);
}

$response->send();
```

```php
<?php // config/routes.php — the table those 37 folders become

declare(strict_types=1);

use App\Http\OrderController;
use D4np\Utils\Http\Router;
use Psr\Container\ContainerInterface;

/** @var ContainerInterface $container — from services.php, in the caller's scope */
$orders = $container->get(OrderController::class);

return (new Router())
    ->get('/orders', [$orders, 'index'])
    ->post('/orders', [$orders, 'store'])
    ->get('/orders/{id}', [$orders, 'show'])
    ->delete('/orders/{id}', [$orders, 'destroy']);
```

A `require`d file runs in the caller's scope, which is why `$container` is visible here without
being passed — and why `index.php` has to build it *before* the `require`, not after. Resolving the
controller once rather than four times is the reason the four rows fit on one line each.

## What the kernel is for

Everything in it is a **cross-cutting concern that used to be duplicated**, and that is the
test for whether something belongs here:

| In the kernel | Why |
|---|---|
| Autoloading | One path, not thirty-seven relative ones |
| The 404 / 405 classification | The distinction is the router's; the *status codes* are the application's policy |
| The `Allow` header on a 405 | Mandatory per RFC 9110; a per-endpoint copy is a per-endpoint omission |
| Response encoding | One envelope shape for the whole surface — [`ApiEnvelope`](../../src/main/php/d4np/utils/Http/ApiEnvelope.php) ([ADR-0051](../adr/0051-one-envelope-shape-and-a-reference-instead-of-the-exception.md)) |
| The error boundary | `Errors\ExceptionHandler` decides what a production build reveals (ADR-0029) |

And what does **not** belong: anything specific to one endpoint. If a `catch` in the kernel
names a domain exception, that exception is being handled in the wrong place.

## Mapping a `Result` to an envelope — the three lines that stay in your application

`Errors\Result` and `Http\ApiEnvelope` are in different groups, and RFC-0001's layering rule
forbids `Http` importing `Errors` ([ADR-0051](../adr/0051-one-envelope-shape-and-a-reference-instead-of-the-exception.md)).
That is not an omission to work around — the mapping is *policy*, and only the application knows
which failure deserves which outcome. It belongs here:

```php
<?php // src/Http/EnvelopeMapper.php — yours, not the library's

use App\Domain\DomainRefusal;
use D4np\Utils\Errors\Result;
use D4np\Utils\Http\ApiEnvelope;
use Psr\Log\LoggerInterface;

final class EnvelopeMapper
{
    public function __construct(private readonly LoggerInterface $log) {}

    /** @param Result<mixed> $result */
    public function map(Result $result): ApiEnvelope
    {
        if ($result->isSuccess()) {
            // Cannot throw on this branch — `Result` has no unguarded value reader, and
            // `orElseThrow()` is how you say "I have already checked".
            return ApiEnvelope::ok($result->orElseThrow());
        }

        $failure = $result->error();

        // Anticipated refusals become `failed`/`invalid` with wording your locale owns …
        if ($failure instanceof DomainRefusal) {
            return ApiEnvelope::failed($this->translate($failure));
        }

        // … and anything else is a defect: log it, and hand the client the reference only.
        $reference = \bin2hex(\random_bytes(8));
        $this->log->error('unhandled failure', ['reference' => $reference, 'exception' => $failure]);

        return ApiEnvelope::caught($reference);
    }

    /** Your locale's wording for a refusal the domain anticipated. */
    private function translate(DomainRefusal $refusal): string
    {
        return $refusal->getMessage();
    }
}
```

The last branch is why `ApiEnvelope::caught()` takes a **reference and not the throwable**: the
exception reaches the log, the client reaches a support ticket, and no schema name or file path
travels over HTTP (ADR-0029's stance at the payload boundary).

## Notes worth carrying

**Handlers are `callable`, which includes `[$object, 'method']`.** The table can therefore be
built from a container without the router knowing that containers exist — which is why
`Router` has no dependency on `Container` and there is no deptrac edge between them.

**The parameters arrive as strings.** `{id}` is client-chosen text; `MatchedRoute` hands it over
undecided, and the handler validates it. A router that returned an `int` would have decided that
`/orders/00042` and `/orders/42` are the same order.

**One entry point means one place the web server points at.** The rewrite rule (`try_files`,
`FallbackResource`, or the IIS equivalent) is part of adopting this pattern, and it is the step
that actually retires the folders.
