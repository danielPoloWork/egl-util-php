# egl-util-php

> General-purpose PHP utility helpers shared across EGL projects

![Status](https://img.shields.io/badge/Status-v1.0.0-blue)

A library written in **PHP 8.1+**, built and governed to an enterprise quality
bar: full CI matrix, static analysis, sanitizers, documented design decisions, and SemVer
releases.

## What it is

Provide EGL PHP projects (framework-based and native/legacy) with a modern utilities library — Composer package egl/utils, PSR-4 namespace D4np\Utils\, PHP 8.1+ — offering typed readonly DTOs, explicit-mechanism security helpers, safe PDO access, hardened session/CSRF handling, and a minimal PSR-11 DI container, replacing ad-hoc per-project solutions (associative-array DTOs, blocklist sanitizers, string-built SQL, silent PDO error modes).

The frozen specification is in
[`docs/specs/01_spec_utils.md`](docs/specs/01_spec_utils.md).

## Install

```bash
composer require egl/utils:^1.0
```

Registered on [Packagist](https://packagist.org/packages/egl/utils); `^1.0` resolves `v1.0.0`.
A full consumer on-ramp (runnable examples, the naming map, the rest of the surface) is tracked
as [issue #118](https://github.com/danielPoloWork/egl-util-php/issues/118) — this line only
states that the package installs.

## Build, test, run

```bash
composer install --optimize-autoloader
vendor/bin/phpunit
```

- **Toolchain:** Composer (PSR-4 autoload), PHPUnit (Pest optional), PHP-CS-Fixer (PSR-12), PHPStan (max level).
- **Supported platforms:** Linux (PHP 8.1, 8.2, 8.3).
- Consumers import the public surface via: `use D4np\Utils\Dto\DataTransferObject;`.

See [`docs/development/local-build.md`](docs/development/local-build.md) for the full local
setup.

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
| 13 | Documentation & release hygiene (post-1.0) | ⏳ planned |
| 14 | Post-1.0 functional seams (v1.1.0) | ✅ done |


## License

MIT © 2026 Daniel Polo. See [`LICENSE`](LICENSE).
