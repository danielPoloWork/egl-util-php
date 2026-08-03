# egl-util-php

> General-purpose PHP utility helpers shared across EGL projects

![Status](https://img.shields.io/badge/Status-v0.0.0-blue)

A
library written in **PHP 8.1+**, built and governed to an enterprise quality
bar: full CI matrix, static analysis, sanitizers, documented design decisions, and SemVer
releases.

## What it is

Provide EGL PHP projects (framework-based and native/legacy) with a modern utilities library — Composer package egl/utils, PSR-4 namespace D4np\Utils\, PHP 8.1+ — offering typed readonly DTOs, explicit-mechanism security helpers, safe PDO access, hardened session/CSRF handling, and a minimal PSR-11 DI container, replacing ad-hoc per-project solutions (associative-array DTOs, blocklist sanitizers, string-built SQL, silent PDO error modes).

The frozen specification is in
[`docs/specs/01_spec_utils.md`](docs/specs/01_spec_utils.md).

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
| [`docs/adr/`](docs/adr/) | Why it is built the way it is (Architecture Decision Records). |
| [`docs/patterns/`](docs/patterns/) | Design patterns adopted, rejected, or considered. |
| [`docs/workflow/`](docs/workflow/) | Git, documentation, release, and maintenance conventions. |
| [`CHANGELOG.md`](CHANGELOG.md) | User-visible changes per release. |
| [`SECURITY.md`](SECURITY.md) | How to report a vulnerability. |

## Milestones

| # | Title | Status |
|---|---|---|
| 1 | Project bootstrap & CI | ⏳ in progress |
| 2 | Support layer | ⏳ planned |
| 3 | DTO & data mapping | ⏳ planned |
| 4 | Database | ⏳ planned |
| 5 | Security | ⏳ planned |
| 6 | HTTP, container, errors | ⏳ planned |
| 7 | Release engineering & bridge | ⏳ planned |


## License

MIT © 2026 Daniel Polo. See [`LICENSE`](LICENSE).
