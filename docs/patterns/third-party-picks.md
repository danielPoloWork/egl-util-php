# Third-party picks

*Companion to [the catalogue](README.md). This library's stance is tiny runtime dependencies and
explicit mechanisms ([`AGENTS.md`](../../AGENTS.md), [RFC-0001](../rfc/0001-egl-utils-library.md));
that stance means real needs are deliberately left for you to bring your own answer to. This page
names the answer, so the deliberateness is recorded somewhere a consumer actually looks, instead
of being discoverable only by noticing the absence.*

Nothing on this page is a dependency of `egl/utils`. Each pick is a recommendation for a need
this library will not build itself — install it yourself, alongside this library, when you hit
the need.

## Endorsed choices

| Need | Pick | Why this one |
|---|---|---|
| Money / decimal arithmetic | [`brick/math`](https://github.com/brick/math) | Arbitrary-precision integers and decimals with no float rounding error — the exact property money arithmetic cannot compromise on, and the reason this library won't hand-roll it (see *What this library will not build* below). |
| PSR-6 / PSR-16 caching | [`symfony/cache`](https://github.com/symfony/cache) | Implements both PSR-6 and PSR-16 behind one adapter family (filesystem, APCu, Redis, array/null for tests), works framework-agnostic in the native/legacy consumers this library also targets, and is maintained at the pace an enterprise dependency needs to be. |
| SMTP transport, attachments, queuing | [`symfony/mailer`](https://github.com/symfony/mailer) behind `Mail\Mailer` | This library's own [`Mail\Mailer`](../../src/main/php/d4np/utils/Mail/Mailer.php) interface is the seam, by design: one method, no return value, `send(MailMessage): void` throwing `MailException` on failure. Implement `Mailer` over `symfony/mailer` (or any transport) and calling code never changes — the interface, not `NativeMailer`, is what application code should depend on. |

None of these are `require`/`suggest` entries in `composer.json` — adding one is your dependency
decision, made for your project, not this library's to impose.

## What this library will not build

Recorded so a feature request against one of these has a citable answer instead of a fresh
argument every time ([RFC-0003](../rfc/0003-post-1-0-functional-scope.md), issue #84's third
acceptance criterion):

- **Money / decimal arithmetic** — `brick/math` is the right answer, above; a reimplementation
  inside this library would either compromise the precision guarantee or duplicate a
  well-maintained library for no gain.
- **ORM features** — identity map, change tracking, lazy loading, and JOIN support are refused by
  `TableGateway`/`Repository`'s own stated non-goals (spec FR-35). `Persistence` is a Table Data
  Gateway over `QueryBuilder`, not an object-relational mapper, and is not becoming one.
- **An SMTP client** — `symfony/mailer` behind `Mail\Mailer`, above; this library ships
  `NativeMailer` over PHP's `mail()` and nothing more (spec FR-44).
- **Console or i18n helpers** — neither is a utility concern for a library at this layer. A
  console framework and a translation catalogue are application-level decisions this library has
  no basis to make on your behalf.

## What's deliberately not on this page

**Rate limiting and a PSR-18 HTTP client bridge are not endorsed third-party picks** — both are
in-scope future work this library may build itself, tracked as [issue #91](https://github.com/danielPoloWork/egl-util-php/issues/91)
and [issue #93](https://github.com/danielPoloWork/egl-util-php/issues/93) respectively, currently
deferred by [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) for reasons specific to each
(no shared-state seam exists yet; the bridge-publication pipeline needs a first successful run).
Deferred is not the same claim as *"bring your own"* — recommending a stand-in here would blur
that distinction for exactly the two items where it matters most.
