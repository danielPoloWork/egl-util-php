# egl/utils-psr18-bridge

> A PSR-18 HTTP client over `egl/utils`' `HttpClient`, producing PSR-7 responses through any PSR-17
> factory.

**Not published yet.** The source lives in the [`egl-util-php`](https://github.com/danielPoloWork/egl-util-php)
monorepo under `packages/utils-psr18-bridge/` and is published to a generated split repository
(ADR-0033). Until that first publication happens, install the monorepo.

## What it is for

Ecosystem HTTP middleware and SDKs consume `Psr\Http\Client\ClientInterface`. This package lets you
hand them `egl/utils`' `HttpClient` — with its pinned TLS verification, per-phase timeout and
wall-clock budget (ADR-0049) — instead of writing the adapter yourself.

```php
use D4np\Utils\Bridge\Psr18\Psr18Client;
use D4np\Utils\Http\HttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;   // or any other PSR-17 implementation

$factory = new Psr17Factory();

$client = new Psr18Client(
    new HttpClient(timeoutSeconds: 5.0, totalTimeoutSeconds: 20.0),
    $factory,   // ResponseFactoryInterface
    $factory,   // StreamFactoryInterface
);

$response = $client->sendRequest($factory->createRequest('GET', 'https://example.com/things'));

echo $response->getStatusCode();          // 200
echo (string) $response->getBody();
```

**Factories are injected, never discovered.** "Any PSR-17 factory" means yours: this package ships
no default and falls back to nothing.

## What it is *not*

It is **not** [`egl/utils-psr7-bridge`](../utils-psr7-bridge), and it does not depend on it. That
package converts the *server* vocabulary — `Request` and `Response`, what your application receives
and emits. This one wraps the *client*: `HttpClient` and its `HttpResponse`. They share a problem
domain and no code, and you can install either without the other.

## The two exceptions, and why the split matters

PSR-18 separates a malformed request from a network failure because **only the second is worth
retrying**. This package honours that split:

| Thrown | When | PSR-18 interface |
|---|---|---|
| `RequestRefused` | the request cannot be sent as written — no host, a scheme other than `http`/`https`, a header that could smuggle a second one | `RequestExceptionInterface` |
| `TransportFailed` | it was sent and produced no response — refused connection, failed TLS, expired timeout | `NetworkExceptionInterface` |

Both also extend the core's `HttpException`, so they satisfy **both** hierarchies: a PSR-18 retry
middleware recognises them, and a consumer already writing `catch (UtilsThrowable)` still catches
them (ADR-0004).

**A 4xx or 5xx is neither.** Like PSR-18 itself, and like `HttpClient` already did, an error status
comes back as a *response* for the caller to judge.

## Redirects

`HttpClient` does not follow redirects by default (ADR-0049), which is what PSR-18 expects — a 3xx
is returned as a response. If you construct the client with `followRedirects: true`, you have opted
in, and this bridge does not second-guess it.

## Requirements

- PHP 8.1+ — the same floor as the core; the bridge never narrows it
- `egl/utils` ^1.0
- A PSR-17 implementation of your choosing (tested against `nyholm/psr7` and `guzzlehttp/psr7`)

## Licence

MIT — see [LICENSE](LICENSE).
