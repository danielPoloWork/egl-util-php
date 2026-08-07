# The endpoint kernel

*The ~20 lines that replace a folder per endpoint. Companion to the **Front Controller** entry in
[the catalogue](README.md); the router it is built on is [`Http\Router`](../../src/main/php/d4np/utils/Http/Router.php)
(spec r3 FR-38, [ADR-0050](../adr/0050-classify-the-miss-and-keep-the-router-a-table.md).)*

## The shape this replaces

The surveyed estate routed with the filesystem: **37 deployed folders**, each holding an
`index.php` that differed from its neighbours in one line.

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

$router = require __DIR__ . '/../config/routes.php';   // the table, below
$request = Request::fromGlobals();
$response = new Response();

try {
    $matched = $router->matchRequest($request);
    $payload = ($matched->handler)($request, $matched->parameters);
    $status = 200;
} catch (RouteNotFoundException) {
    $payload = ['error' => 'not found'];
    $status = 404;
} catch (MethodNotAllowedException $e) {
    // RFC 9110 §15.5.6 makes this header mandatory, which is why the exception carries it.
    $response->setHeader('Allow', $e->allowHeader());
    $payload = ['error' => 'method not allowed'];
    $status = 405;
}

$response->json($payload, $status)->send();
```

```php
<?php // config/routes.php — the table those 37 folders become

declare(strict_types=1);

use D4np\Utils\Http\Router;

return (new Router())
    ->get('/orders', [$container->get(OrderController::class), 'index'])
    ->post('/orders', [$container->get(OrderController::class), 'store'])
    ->get('/orders/{id}', [$container->get(OrderController::class), 'show'])
    ->delete('/orders/{id}', [$container->get(OrderController::class), 'destroy']);
```

## What the kernel is for

Everything in it is a **cross-cutting concern that used to be duplicated**, and that is the
test for whether something belongs here:

| In the kernel | Why |
|---|---|
| Autoloading | One path, not thirty-seven relative ones |
| The 404 / 405 classification | The distinction is the router's; the *status codes* are the application's policy |
| The `Allow` header on a 405 | Mandatory per RFC 9110; a per-endpoint copy is a per-endpoint omission |
| Response encoding | One envelope shape for the whole surface (item 11.3's `ApiEnvelope`) |
| The error boundary | `Errors\ExceptionHandler` decides what a production build reveals (ADR-0029) |

And what does **not** belong: anything specific to one endpoint. If a `catch` in the kernel
names a domain exception, that exception is being handled in the wrong place.

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
