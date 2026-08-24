# egl-util-php

> General-purpose PHP utility helpers shared across EGL projects

![Status](https://img.shields.io/badge/Status-v1.1.0-blue)

A library written in **PHP 8.1+**, built and governed to an enterprise quality bar: a full CI
matrix on 8.1/8.2/8.3, PHPStan at max level, PHP-CS-Fixer (PSR-12), enforced layer boundaries
(deptrac), a mutation-score floor, per-diff coverage gates, benchmarked performance budgets,
documented design decisions, and SemVer releases.

## What it is

A modern utilities library for EGL PHP projects — framework-based and native/legacy alike —
replacing the ad-hoc per-project solutions this codebase was written to retire: associative-array
DTOs, blocklist sanitizers, string-built SQL, silent PDO error modes.

**Every component refuses rather than guesses.** An unknown DTO key throws instead of being
dropped; a response header carrying CR/LF is refused when it is set, not when it is sent; a
connection executes a `SqlStatement` and nothing else. The one place the library deliberately does
*not* refuse is where refusing would mutilate data — the CSV formula guard is opt-in, because
silently rewriting `=A1` in round-tripped data is worse than the export being pasted into a
spreadsheet.

Nine component groups sit over a `Support` layer, and the dependency direction is enforced in CI by
deptrac rather than by convention:

| Namespace | What it is for |
|---|---|
| [`D4np\Utils\Dto\`](src/main/php/d4np/utils/Dto/) | Typed `readonly` DTOs — strict hydration by default, `Collection<T>`, withers |
| [`D4np\Utils\Database\`](src/main/php/d4np/utils/Database/) | PDO with safe defaults pinned, a fluent `QueryBuilder`, closure-scoped `Transaction`, and `SqlStatement` — the only shape the connection will execute |
| [`D4np\Utils\Persistence\`](src/main/php/d4np/utils/Persistence/) | `Repository` and `TableGateway` over that: rows normalized, then hydrated, every failure typed |
| [`D4np\Utils\Security\`](src/main/php/d4np/utils/Security/) | `Escaper` (four contexts), `Sanitizer`, `Hash` (Argon2id), `Crypto` (AES-256-GCM) |
| [`D4np\Utils\Http\`](src/main/php/d4np/utils/Http/) | `Request`/`Response` that refuse rather than coerce, hardened `Session`, `CsrfToken`, `HttpClient`, `Router`, `ApiEnvelope` |
| [`D4np\Utils\Errors\`](src/main/php/d4np/utils/Errors/) | `Result`, a PSR-3 `Logger`, level-filtered and fan-out channels, `ExceptionHandler` |
| [`D4np\Utils\Mail\`](src/main/php/d4np/utils/Mail/) | Validated `EmailAddress`, a `MailMessage` that cannot carry a header terminator, `Mailer`/`NativeMailer` |
| [`D4np\Utils\Container\`](src/main/php/d4np/utils/Container/) | A minimal PSR-11 container and `ServiceProvider`, with circular dependencies detected |
| [`D4np\Utils\Support\`](src/main/php/d4np/utils/Support/) | `Str`, `File`, `Csv`, `Url`, `Lookup`, `Env`, `Json`, `FileSequence`, and the exception hierarchy everything above throws into |

The frozen specification is in [`docs/specs/01_spec_utils.md`](docs/specs/01_spec_utils.md).

### The three names, reconciled

They are three different naming systems for one project, and the mismatch is deliberate — a
recorded maintainer decision (RFC-0001, *Alternatives* #5), not drift:

| You will see | Where it applies |
|---|---|
| `egl-util-php` | the **repository** — this GitHub project |
| `egl/utils` | the **Composer package** — what you `require`, and its name on Packagist |
| `D4np\Utils\` | the **PSR-4 namespace** — what you `use` in code |

So `composer require egl/utils` gives you classes under `D4np\Utils\`, from a repository called
`egl-util-php`. The PSR-4 base directory is `src/main/php/d4np/utils/`.

## Install

```bash
composer require egl/utils
```

It resolves from **[Packagist](https://packagist.org/packages/egl/utils)** — no VCS repository
entry needed. The API is frozen for the whole 1.x line
([ADR-0059](docs/adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)),
so `^1.0` is a safe constraint and picks up every release in it. Its only hard dependencies are
interface-only packages — `psr/container`, `psr/log`, and `psr/clock` from v1.1.0 onward.

Released so far: **v1.1.0** (M14's five additive seams and M13's close-out) and **v1.0.0** (the
first release and the freeze). What each contains is in [`CHANGELOG.md`](CHANGELOG.md); why you
might upgrade is in [`docs/releases/`](docs/releases/).

**Requires** PHP ≥ 8.1 with `ext-pdo` and `ext-fileinfo`. Two things are *suggested* rather than
required, and each refuses at the call site when absent rather than degrading silently:
`ext-iconv` (for `Str::transcode()`) and `symfony/html-sanitizer` (for `Sanitizer::richText()`).

> **`master` runs ahead of the newest tag, by design.** Work lands on `master` continuously and is
> released in batches, so the surface tables above describe the *repository*. For what a given
> version actually contains, read [`CHANGELOG.md`](CHANGELOG.md) — `[Unreleased]` is exactly the
> part you cannot install yet.

## Quickstart

Four tasks, each a complete program. Every example below was executed against `egl/utils`
**installed from Packagist** — not against this working tree — and the output shown is what it
printed. They were verified at **v1.0.0** and the 1.x freeze keeps them valid: nothing they use has
changed shape since.

### Hydrate a DTO

```php
<?php

use D4np\Utils\Dto\Collection;
use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Support\HydrationException;

require 'vendor/autoload.php';

final class UserDto extends DataTransferObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
    ) {}
}

$user = UserDto::fromArray(['id' => 1, 'email' => 'ada@example.com', 'name' => 'Ada']);
echo $user->name;                      // Ada

// Strict is the default: a key the DTO does not declare is an error, not a shrug.
try {
    UserDto::fromArray(['id' => 1, 'email' => 'a@b.c', 'name' => 'Ada', 'is_admin' => true]);
} catch (HydrationException $e) {
    echo $e->getMessage();             // names the offending key: is_admin
}

// ...unless you genuinely receive wider payloads than you map.
$user = UserDto::lenient()->fromArray(['id' => 1, 'email' => 'a@b.c', 'name' => 'Ada', 'is_admin' => true]);

$users = Collection::of(UserDto::class, [$user]);
$names = $users->map(fn (UserDto $u): string => $u->name)->toArray();   // ['Ada']
```

Dropping an undeclared key silently is how a typo becomes a field that was never assigned, and how
a mass-assignment attempt becomes invisible — so `fromArray()` refuses and `lenient()` is the
per-call opt-out.

### Build a safe query

```php
<?php

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\SqlStatement;

require 'vendor/autoload.php';

$db = new DatabaseConnection(new PDO('sqlite::memory:'));   // safe defaults pinned here

// Your schema already exists; this is here so the example runs as-is.
$db->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT, active INTEGER)');
$db->execute(SqlStatement::literal(
    'INSERT INTO users (id, email, name, active) VALUES (?, ?, ?, ?)',
    [1, 'ada@example.com', 'Ada', 1],
));

$query = (new QueryBuilder($db, 'users'))
    ->select('id', 'email', 'name')
    ->where('active', Operator::Equals, 1)
    ->orderBy('name')
    ->limit(10);

$rows = $db->select(SqlStatement::fromQueryBuilder($query));
echo $rows[0]['email'];   // ada@example.com

echo $query->toSql();
// SELECT "id", "email", "name" FROM "users" WHERE "active" = ? ORDER BY "name" ASC LIMIT 10
echo json_encode($query->bindings());   // [1] — the value never entered the SQL text
```

`select()`, `selectOne()` and `execute()` accept **only** a `SqlStatement`, so there is one choke
point where SQL text and its parameters must travel together. Its constructor is private:
`literal()` takes hand-written SQL that PHPStan proves is a literal string,
`fromQueryBuilder()`/`fromMutation()` take the builder *object* so no SQL text crosses as a bare
argument, and `composed()` is the single conspicuous escape hatch — with zero uses inside the
library, so `grep composed(` is the whole review list.

Table and column names are checked against an allowlist when they enter the builder; values never
enter the SQL at all.

### Wire CSRF

```php
<?php

use D4np\Utils\Http\CsrfToken;
use D4np\Utils\Http\Session;
use D4np\Utils\Security\Escaper;

require 'vendor/autoload.php';

$session = new Session();       // Secure + HttpOnly + SameSite=Lax by default
$session->start();              // before any output — see below
$csrf = new CsrfToken($session);

// Rendering the form
$token = $csrf->issue('checkout');
echo '<input type="hidden" name="_token" value="' . Escaper::attr($token) . '">';

// Handling the POST that follows. In a real handler the token arrives as
// $_POST['_token'] ?? '' — it is a plain variable here so the example runs.
$submitted = $token;

if (!$csrf->validate($submitted, 'checkout')) {
    http_response_code(419);
    exit;
}

echo 'accepted';
```

`issue()` is stable within a session, so concurrently open forms all carry a token that still
validates; `rotate()` is the explicit way to get a new one. Scopes are isolated — a `checkout`
token does not validate against `profile`.

Two constraints worth knowing before you hit them. `Session::start()` throws if the headers have
already been sent, because cookie parameters cannot be applied afterwards and it refuses rather
than starting a session whose cookie lacks the required flags. And `SameSite::None` paired with
`secure: false` is refused at construction: browsers drop that cookie combination entirely, so the
misconfiguration surfaces at wiring time rather than as "sessions do not work" in production.

### Handle a Result

```php
<?php

use D4np\Utils\Errors\Result;
use D4np\Utils\Support\Json;

require 'vendor/autoload.php';

$requestBody = '{"qty": 3}';   // whatever arrived on the wire

$order = Result::try(static fn (): array => Json::decode($requestBody));

$qty = $order
    ->map(static fn (array $d): int => $d['qty'] * 2)
    ->orElse(0);                       // 6 here; 0 when anything above failed

if ($order->isFailure()) {
    $error = $order->error();          // the Throwable, for your log
}

echo $qty;                             // 6
```

`Result` is for the failures you expect to handle, not the ones you want to crash on: `try()`
captures the throw, `map()`/`flatMap()` only run on the success path, and `orElse()` /
`orElseThrow()` are the two ways back out. Nothing is swallowed — `error()` always carries the
original throwable.

### More

The frozen 1.0 surface is browsable under
[`src/main/php/d4np/utils/`](src/main/php/d4np/utils/) — every class carries its contract, its
`@throws` and its reasoning in the docblock, which is where the detail behind these four examples
lives. For needs this library deliberately does not cover, see
[third-party picks](docs/patterns/third-party-picks.md).

The pattern pages under [`docs/patterns/`](docs/patterns/) explain *why* things are shaped the way
they are, and are worth reading before extending the library. Start with
[the endpoint kernel](docs/patterns/endpoint-kernel.md) — a front controller wiring `Router`,
`Response` and `ApiEnvelope`, whose blocks were assembled into a running application and driven over
HTTP for the 200, 404 and 405 branches (ROADMAP 13.3).

One caveat that still stands: **no doc example is executed by CI**, so every one of them is verified
as of the change that touched it and not continuously. When an example and the code disagree, the
code is right.

## Build, test, run

For working *on* this library rather than *with* it:

```bash
composer install --optimize-autoloader
vendor/bin/phpunit
```

- **Toolchain:** Composer (PSR-4 autoload), PHPUnit, PHP-CS-Fixer (PSR-12), PHPStan (max level),
  deptrac, Infection, phpbench.
- **Supported platforms:** Linux (PHP 8.1, 8.2, 8.3).

See [`docs/development/local-build.md`](docs/development/local-build.md) for the full local setup,
and [`CONTRIBUTING.md`](CONTRIBUTING.md) for what a change must clear.

## How this project is run

| Document | Purpose |
|---|---|
| [`AGENTS.md`](AGENTS.md) | How AI agents (and humans) work in this repo — the contract. |
| [`ROADMAP.md`](ROADMAP.md) | The numbered plan and what is done. |
| [`ISSUES.md`](ISSUES.md) | The GitHub issues, newest first, each with its advisory model/effort route. |
| [`docs/adr/`](docs/adr/) | Why it is built the way it is (Architecture Decision Records). |
| [`docs/patterns/`](docs/patterns/) | Design patterns adopted, rejected, or considered. |
| [`docs/patterns/third-party-picks.md`](docs/patterns/third-party-picks.md) | Endorsed third-party libraries for needs this library deliberately doesn't cover. |
| [`docs/workflow/`](docs/workflow/) | Git, documentation, release, and maintenance conventions. |
| [`CHANGELOG.md`](CHANGELOG.md) | User-visible changes per release. |
| [`SECURITY.md`](SECURITY.md) | How to report a vulnerability. |
| [`docs/upgrading.md`](docs/upgrading.md) | The deprecation lifecycle and the supported-versions window, in consumer terms. |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | How to open an issue or a PR, and what it must clear. |
| [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) | Community standards for this project. |

## Milestones

| # | Title | Status |
|---|---|---|
| 1 | Project bootstrap & CI | ✅ done |
| 2 | Support layer | ✅ done |
| 3 | DTO & data mapping | ✅ done |
| 4 | Database | ✅ done |
| 5 | Security | ✅ done |
| 6 | HTTP, container, errors | ✅ done |
| 7 | Release engineering & bridge | ✅ done |
| 8 | PSR-7 bridge (`packages/utils-psr7-bridge`) | ✅ done |
| 9 | Support & values | ✅ done |
| 10 | Persistence | ✅ done |
| 11 | Http application layer | ✅ done |
| 12 | Security & channels | ✅ done |
| 13 | Documentation & release hygiene (post-1.0) | ✅ done |
| 14 | Post-1.0 functional seams (v1.1.0) | ✅ done |

## License

MIT © 2026 Daniel Polo. See [`LICENSE`](LICENSE).
