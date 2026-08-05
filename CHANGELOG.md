# Changelog

All notable changes to `egl-util-php` are documented here, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

Every PR that introduces a user-visible change adds a line to `[Unreleased]` in the same
PR. A release PR moves the `[Unreleased]` entries into a new per-version file under
`docs/changelog/v<MAJOR>/v<X.Y.Z>.md` and adds an index row below.

## [Unreleased]

### Added

- **Benchmark regression and budget gates in CI** (**ADR-0030**) — spec NFR-06, plus
  `.github/workflows/nightly.yml`. No production code changed.
  NFR-06's *"regression > 10% fails"* could not be built against a stored baseline: measured from
  this repository's own CI history, nine `master` runs with `QueryBuilder` and its benchmark
  **provably unchanged** ranged **2.684–3.767 µs — 40.4% peak to peak** on GitHub's shared runners.
  Five consecutive phpbench passes inside **one** job spread **0.4–1.5%**, so the gate measures the
  base commit and HEAD **on the same runner** via `git worktree`, which leaves the 10% threshold
  roughly six times the noise it must clear.
  `tools/bench_regression_gate.py` (relative) and `tools/bench_budget_gate.py` (absolute ceilings,
  and a *range* since NFR-05's lower bound is the serious one — a hash that got faster got weaker)
  both hold `coverage_gate.py`'s absence-is-failure discipline. A subject new at HEAD is a notice; one
  that disappeared is reported, because a deleted benchmark is how a regression stops being visible.
  `tools/assert_bench_env.php` refuses to let the suite run outside NFR-06's environment (PHP 8.3,
  OPcache and JIT off) rather than setting those values in a workflow and trusting them.
  `tools/bench_ratio_gate.py` is finally wired into CI, having sat in the tree since item 3.5 marked
  it "advisory today, not yet wired into CI".

### Fixed

- **All three deferred NFR budgets are met**, and the reason is a measurement error rather than an
  optimisation — **no production code changed**. NFR-01 **0.958 µs / 2.40×** (≤ 5 µs, ≤ 3×), NFR-03
  **3.776 µs** (≤ 10 µs), NFR-05 **148.3 ms** (50–200 ms). The earlier records reporting NFR-03 and
  NFR-05 as *over* budget were produced with `--php-disable-ini`, which does not disable OPcache alone
  but discards the entire `php.ini` and every extension with it, on Windows — not NFR-06's
  environment. They were honest measurements of a different thing.
  Still outstanding and named rather than skipped: the runner is not NFR-06's Ryzen 7 5800X, so every
  budget is "met on this runner"; and NFR-04 is ungated, because it budgets a *memory delta* and
  phpbench's `mem_peak` reports the whole process's peak.

- `D4np\Utils\Errors\Result`, `Logger` and `ExceptionHandler` (**ADR-0029**) — spec FR-16, FR-17,
  FR-18. **Milestone 6 is complete.**
  **`Result`** replaces boolean/null returns for service outcomes (RFC-0001): `success()`,
  `failure()`, `try()`, `map()`, `flatMap()`, `recover()`, `orElseThrow()`, `orElse()`, `error()`. A
  failure carries a **`Throwable`**, so `orElseThrow()` rethrows the *original instance* with the
  trace still pointing at where the operation failed — manufacturing an exception at unwrap time
  would put the trace in the accessor. `map()` deliberately does **not** catch: a mapper that throws
  has a defect, and converting a `TypeError` into a business failure would hide it. `Result::try()`
  is the named opt-in for catching.
  **`Logger`** is a PSR-3 logger writing one line per record. Two behaviours were probed rather than
  assumed, and both defaults lose data in silence: `file_put_contents()` with `LOCK_EX` on a `php://`
  stream returns **`false` and writes nothing**, so real files are locked and streams are not; and a
  `Throwable` in the context **encodes to `{}`** — `json_encode()` sees only public properties — so
  throwables are expanded explicitly, recursively through `getPrevious()`. The destination is
  validated at construction and writes never throw, because a logger that throws inside an exception
  handler turns a handled failure into a fatal one. An unknown level throws
  `Psr\Log\InvalidArgumentException`, as PSR-3 requires.
  **`ExceptionHandler`** produces an RFC 7807 problem document and captures fatal errors through a
  shutdown handler — the only route by which an `E_ERROR` is ever reported. **In production it
  withholds the exception message as well as the trace**, which is stricter than FR-18's letter and
  recorded as such: a message names schemas (`Base table not found: 'users_backup'`) and paths
  (`/srv/app/config/secrets.php`) just as effectively as a trace. A random **reference** goes into
  both the response and the log record, so the two can be correlated. Debug is off unless the
  environment says otherwise, so a missing `APP_DEBUG` cannot be what exposes a trace.

- `D4np\Utils\Container\Container` (PSR-11), `ServiceProvider`, and the `ContainerException` /
  `NotFoundException` / `CircularDependencyException` trio (**ADR-0028**) — spec FR-04, FR-05,
  NFR-02, imported ADR-001. Constructor autowiring over the **shared** `ReflectionCache`, plus
  `instance()`, `singleton()`, `factory()` and `bind()` definitions.
  **The refusals are the design.** Imported ADR-001 bought a hand-written container by promising it
  would fail loudly wherever a mature one adds a feature, so an unbound interface, an abstract
  class, a non-public constructor, a built-in or untyped parameter without a default, and a
  union-typed parameter are each declined with a message naming the parameter and the class.
  Circular dependencies throw with the **full path** (`A -> B -> C -> A`) and are a distinct
  exception type, because the container acts on the distinction: a parameter default may answer an
  absent dependency and must not answer a cycle.
  Autowired instances are **shared** by default, with `factory()` as the explicit opt-out; `has()`
  answers for what `get()` would actually do rather than for the registration table.
  **`get()` declares a conditional return type PSR-11 does not have** — `get(Mailer::class)` is a
  `Mailer`, while a string-keyed entry stays `mixed`. `ContainerInterface::get()` carries no return
  type at all in psr/container 2.0.2, so without this every consumer at PHPStan max would narrow at
  every call site.
  **NFR-02 measured and met**: 0.173 µs warm singleton resolve (≤ 2 µs) and 18.593 µs first
  autowired resolve of a four-class graph (≤ 30 µs), on a developer machine rather than NFR-06's
  reference machine — not asserted as a CI gate, per ADR-0018; nightly tracking is item 7.1.

- **T-03**, spec §7's session/CSRF integration suite, against a real `php -S` process — 17 tests
  covering everything ADR-0026 structurally could not reach in CLI: FR-15's three flags on a live
  `Set-Cookie`, the configured policy reaching the header, state surviving across requests,
  `regenerate()` rotating the identifier while carrying the data forward, cross-session and
  cross-scope CSRF rejection, `rotate()` invalidating the previous token, and a token not
  outliving its session.
  The suite's centre is that **the pre-rotation identifier stops resolving**: `session_regenerate_id(true)`
  deletes the old record, and without the `true` the session is merely renamed while the old
  identifier keeps working — session fixation. Planting that one-character change fails exactly one
  test; rotation still happens, data still survives, flags are still correct, and the other sixteen
  stay green.
  Two probe findings shaped the harness: **`Secure` is emitted over plain HTTP** (PHP writes the
  attribute unconditionally, enforcement is the browser's), so no TLS is needed; and reading a live
  process's pipe blocks forever, so the server logs to a file. A server that cannot start **fails**
  the suite rather than skipping it.
- `docs/specs/01_spec_utils.md` gains a **Revision history** and moves to **r2**.

- Project bootstrap: enterprise scaffolding generated by EADOS (agent contract, docs system,
  CI gate, consistency lint).
- Composer build system: the package is `egl/utils`, PSR-4 autoloading `D4np\Utils\` from
  `src/main/php/d4np/utils/`, requiring PHP >= 8.1 with `ext-pdo` and `ext-fileinfo`, plus the
  interface-only `psr/container` and `psr/log`. Component-group skeleton laid out per
  RFC-0001 (`Dto`, `Container`, `Database`, `Security`, `Http`, `Errors`, `Support`).
- Test harness: PHPUnit 10.5 wired via `phpunit.xml.dist` (`src/test/php/d4np/utils/`,
  coverage source `src/main/php/d4np/utils/`), with a `BootstrapTest` smoke suite proving the
  PHP version floor and the PSR-4 autoloader wiring.
- Formatter + linter: PHP-CS-Fixer 3.95 (`@PSR12`/`@PSR12:risky`, `declare_strict_types`,
  `strict_comparison`/`strict_param`) and PHPStan 2.2 at `level: max`, both scoped to
  `src/main/php/d4np/utils/` and `src/test/php/d4np/utils/`. `ergebnis/composer-normalize`
  keeps `composer.json` canonical (CI `hygiene` job). `.gitattributes` normalizes line endings
  to LF so Windows checkouts match what CI lints.
- `D4np\Utils\Version::VERSION` (`Version.php`) as the single source of truth for the
  released version, kept in lockstep with the README badge by `tools/consistency_lint.py`.
- Enum-typed DTO properties: a **backed** enum (`enum Status: string { … }`) hydrates from its
  scalar backing value via `tryFrom()`; an invalid value is a `TypeMismatchException` naming the
  valid ones. A **pure** (non-backed) enum has no scalar to resolve from and stays
  instance-only. `#[Group('T-01')]` covers the case.
- `D4np\Utils\Dto\Collection` (**ADR-0010**) — an immutable, functional list wrapper with
  `map()`/`filter()`/`reduce()`, `@template-covariant` (sound because nothing mutates), and an
  **optional** runtime `instanceof` guard: PHP has no runtime generics, so `Collection<Foo>` is
  enforced by PHPStan and, when asked, by `Collection::of(Foo::class, …)`. `filter()` carries
  the guard across; `map()` drops it, since the element type changes.
- `D4np\Utils\Dto\CollectionOf` — `#[CollectionOf(Foo::class)]` on a `Collection` constructor
  parameter lets hydration build its elements, closing the `Collection<T>` gap deferred from
  the DTO item. An attribute rather than the docblock because PHP resolves its argument to a
  real class-string, whereas `Collection<Addr>` in a docblock is an alias token only a full
  parser could resolve. Failures name the index: `stops.1.postcode`.
- `D4np\Utils\Dto\WithersTrait` (**ADR-0009**) — `$user->with(name: 'Grace')` returns a modified
  copy of an immutable DTO, leaving the receiver untouched. It **rebuilds through the
  constructor** rather than cloning, which works identically on PHP 8.1/8.2/8.3 (8.3's readonly
  amendment only permits reassignment *inside* `__clone()`) and, independently of versions,
  keeps any validation the constructor performs applying to the result. Changes route through
  the same checks as hydration: an undeclared name raises `UnknownKeyException`, a bad value
  raises `TypeMismatchException`.
- `D4np\Utils\Dto\DataTransferObject` (**ADR-0008**) — typed, immutable DTOs hydrated from
  arrays: `UserDto::fromArray($data)` is **strict by default** (an undeclared key raises
  `UnknownKeyException`, so a typo or a mass-assignment attempt cannot pass silently), and
  `UserDto::lenient()->fromArray($data)` is the per-call opt-out that ignores extra keys while
  still requiring declared ones and still type-checking. Nested DTOs hydrate recursively, and
  every failure names its path (`customer.address.postcode`). Absence follows RFC-0001 R-4: a
  defaulted parameter keeps its default, a nullable one without a default becomes `null`, and
  anything else raises `MissingKeyException`. `Collection<T>` properties land with `Collection`
  itself.
- `tools/coverage_gate.py` enforces the **≥ 90% line-coverage floor** (**ADR-0007**) that
  `AGENTS.md` §10 and spec NFR-07 stated but nothing measured — PHPUnit 10 has no fail-under
  option, so a dedicated CI job produces a Clover report and the gate compares it. A missing or
  empty report fails rather than skipping. The `build` matrix no longer loads `pcov`, which it
  never used. **The first measurement came back at 82.08%** — the stated bar had never been met; raised to **91.51%** in the same PR rather than by lowering the threshold.
- Tests for `File`'s failure paths (unwritable directory, unopenable lock file, failed rename
  leaving no temporary file behind, unreadable file for `read()` and `mime()`) — the branches
  ADR-0005's design exists to handle, previously never executed — and for the static-utility
  contract every helper class follows (final, non-instantiable, private inert constructor, all
  public methods static).
- Spec §7's **T-05 property-test suite** (Json round-trips, `Str::slug()` idempotence, `Env`
  boolean coercion) is now a runnable, countable unit: `vendor/bin/phpunit --group T-05`. The
  three cases already existed, landed with their own items; `#[Group('T-05')]` ties them
  together so the spec's named suite is a mechanical fact rather than a docblock claim.
- `D4np\Utils\Support\ReflectionCache` with `ClassMetadata`/`ParameterMetadata` (**ADR-0006**):
  the single per-class constructor-metadata cache imported ADR-001 commits to, shared by the DTO
  hydrator (M3) and the Container (M6) — reflection is paid once per class, every later lookup is
  an array hit, which is what NFR-01/NFR-02 rest on. Instance-scoped, no interface, no static
  accessor; union and intersection types are recorded as un-autowirable with their declaration
  preserved for diagnostics, and a failed reflection is not cached.
- `D4np\Utils\Support\Env::get()`: boolean coercion built on PHP's own
  `filter_var(…, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` — fixes the classic bug where
  `getenv('FLAG')` returns the truthy **string** `"false"`. An explicitly empty variable
  (`FOO=""`) returns `''`, not `false` — a deliberate exception, since the coercion recognises
  yes/no-shaped words, not "unset-looking" values.
- `D4np\Utils\Support\Json::encode()`/`decode()`: always pass `JSON_THROW_ON_ERROR`, wrapping
  the native `\JsonException` via `JsonException::wrap()` (RFC-0001 R-7) so a caller can never
  forget the flag and get a silent `null`/`false` back.
- `D4np\Utils\Support\File` (**ADR-0005**): `write()` replaces the target **atomically** — a
  temporary file in the same directory, `rename()`d over — so a reader never observes a
  half-written file; writers are serialised through an exclusive `flock()` on a `<path>.lock`
  sidecar, which is where the lock has to live because a handle held on the target makes the
  replacement fail on Windows. `read()` reads under a shared lock. `mime()` detects from
  **contents** via `ext-fileinfo`, never from the filename. Every failure throws `FileException`
  instead of returning `false`.
- `D4np\Utils\Support\Str`: `slug()` (three-tier ASCII transliteration — `ext-intl`, then
  `iconv`, then a printable-ASCII filter, degrading gracefully rather than throwing; idempotent
  per spec T-05), `uuid()` (RFC 4122 v4 from `random_bytes()`), and `random()` (CSPRNG
  alphanumeric tokens via `random_int()`, custom length and alphabet).
- Exception hierarchy under `D4np\Utils\Support\` (**ADR-0004**): the `UtilsThrowable`
  interface every exception implements — `catch (UtilsThrowable $e)` catches anything this
  library raises — over `UtilsException extends \RuntimeException`, with
  `DatabaseException`, `HttpException`, `FileException`, `JsonException` (wrapping PHP's native
  one via `wrap()`, original preserved as `getPrevious()`), and `HydrationException` carrying
  the failing property path, with `UnknownKeyException`, `MissingKeyException` and
  `TypeMismatchException` under it. Concrete leaves are `final`; `UtilsException` and
  `HydrationException` are the documented extension points.
- phpbench benchmarks for NFR-01 (hydration) and NFR-04 (memory) (**ADR-0011**):
  `HydrationBench` and `MemoryBench` under `src/bench/php/d4np/utils/`, sharing one
  `TenScalarPropsDto` fixture. `MemoryBench` carries a real, CI-enforced
  `@Assert('mode(variant.mem.peak) < 16 mebibytes +/- 10%')` — comfortable headroom measured.
  NFR-01's ≤3× hydration-vs-manual-construction ratio cannot be a phpbench `@Assert` (its
  `baseline` means a previous tagged run, not a sibling subject in the same run), so
  `tools/bench_ratio_gate.py` reports it standalone from a `--dump-file` XML report instead of
  gating CI. Measured: hydration is currently **~15.4×** manual construction, well over budget —
  recorded honestly rather than adjusted to pass, shipped non-blocking by explicit maintainer
  decision, with closing the gap filed as roadmap item 3.7. The `benchmark` CI job (self-skipped
  since item 1.9) is now active, since `phpbench.json.dist` exists.
### Fixed

- `masterminds/html5` floored at `^2.7.5` in `require-dev`. It is a transitive dependency of
  `symfony/html-sanitizer`, and its 2.7.2 release passes `null` to a `string` parameter of
  `DOMImplementation::createDocument()` — deprecated on PHP 8.1+, and so a failure under this
  project's warnings-as-errors bar. Only the `prefer-lowest` CI job saw it; the normal resolution
  picks 2.10.1. 2.7.5 is the exact fixing version, found by bisecting upstream rather than by
  choosing a comfortable floor.
- **NFR-03's benchmark measured the wrong workload** (**ADR-0020**, correcting **ADR-0018**). The
  subject added a five-column `select()`, an `orderBy`, a `limit` and an `offset` on top of its
  five conditions — twelve quoted identifiers where NFR-03 budgets a *"5-condition SELECT"* — while
  its own docblock claimed to count exactly five conditions. Roughly two thirds of the ~23 µs gap
  reported at item 4.5 was benchmark scope, not builder cost. The subject now matches the spec's
  wording; the heavier shape is **kept and still published** as `benchBuildRealisticPagedQuery`,
  asserting nothing, so the correction cannot be mistaken for a benchmark narrowed until it passed.
  Item 4.5's report and ADR-0018 are annotated rather than silently edited.

### Changed

- **Spec §7/T-03 amended to revision r2** (**ADR-0027**, authorised by the maintainer): the
  `hash_equals` **timing test** is replaced by a **mechanism assertion**, required in both
  directions — that `hash_equals()` is the comparator on every secret-comparison path, and that
  `==`, `===`, `strcmp()`, `strncmp()` and equivalents are absent from those paths.
  Measured before it was changed, on 64-char tokens over 2,000,000 iterations × 5 rounds: the
  gradient a timing test depends on is **+2.8 ns/op** against **38 ns/op** of noise — about **13×
  below the noise floor** on an idle machine, and six orders of magnitude below request latency
  over HTTP, where T-03 runs. `hash_equals()`'s own gradient measures *negative*.
  The scoping point is the substance: whether `hash_equals()` is constant-time is **PHP's** contract,
  verified upstream; the property that exists at this layer is *which comparator the code invokes*,
  which is decidable exactly from the source. Rejected: asserting `hash_equals()` is measurably
  slower than `===` (an implementation artifact with an inverted failure profile — red on a
  legitimate PHP optimisation, green on a slow non-constant-time comparator), and a dudect-style
  statistical test (right technique, wrong layer; zero discriminative power at this SNR).
  This is an amendment, **not a standing deviation** — there is nothing left to track.
- The constant-time assertion moved from `CsrfTokenTest` to a dedicated
  `Security\ConstantTimeComparisonTest` covering every registered secret-comparison path —
  `CsrfToken::validate()` via `hash_equals()` and `Hash::verify()` via `password_verify()`, the
  correct comparator there. The registry **guards its own completeness**: any constant-time call
  outside a registered path fails the suite, naming the file and line.
- `QueryBuilder` resolves its driver's quoting characters **once per builder** instead of per
  identifier (**ADR-0020**), and builds conditions by concatenation rather than `sprintf()`.
  Together: **14.430 → 12.979 µs** (−10.1%) on the corrected five-condition subject. The driver
  saving is sub-noise per call, so it is pinned by an exact **count** (7 → 1 lookups for a
  five-condition query, 13 → 1 for a paged one) rather than a flaky timing assertion.
  **NFR-03 remains unmet** — ~30% over ≤ 10 µs, versus the 2.3× previously reported. The residual
  is deferred to item 7.1 because phpbench (12.979 µs) and a plain in-process loop (9.246 µs)
  disagree by more than the remaining overage, and an empty phpbench subject costs 0.079 µs — so
  it is not harness overhead, and which instrument is authoritative is NFR-06/7.1's question.

### Added

- `D4np\Utils\Http\Session`, `CsrfToken`, `SameSite`, and the `SessionStore` / `SessionApi`
  interfaces with `NativeSessionApi`
  (**ADR-0026**) — spec FR-12 and FR-15. `Session` applies `httponly`, `secure` and
  `samesite=Lax` before starting, and `regenerate()` wraps `session_regenerate_id(true)` — the
  `true` being the half that closes session fixation, since without it the old identifier keeps
  working. `CsrfToken` issues 32 CSPRNG bytes per scope and compares with `hash_equals()`.
  **The cookie policy is exposed as a value** (`cookieParams()`) and `CsrfToken` depends on a
  three-method `SessionStore` rather than `$_SESSION`, because PHP will not run a session in CLI —
  `session_start()`, `session_set_cookie_params()` and `session_regenerate_id()` all return
  `false` — so without those seams FR-15's flags and all of CSRF validation would have had no unit
  assertion at all.
  `secure` defaults to `true` with an explicit named opt-out for local `http://` development,
  rather than auto-detection from `$_SERVER['HTTPS']` which would silently disable the flag behind
  a misconfigured proxy. `httponly` has **no** opt-out. `SameSite` is an enum, so an illegal value
  is a compile-time impossibility; `None` without `Secure` is refused at construction, because
  browsers drop that combination entirely.
  A token is issued **once per scope and reused** — regenerating per render would invalidate the
  token in another open tab — with `rotate()` as the explicit call for a privilege transition.
  Scope names are validated: a scope becomes a session-storage key, so one taken from user input
  would let a client grow the session record one key per request.
  PHP's session functions themselves sit behind a third seam, `SessionApi` (**ADR-0026 §8**), whose
  `NativeSessionApi` default is five single-statement delegations. That exists for one property
  behaviour cannot see: the cookie parameters must be applied **before** the session starts, since
  `session_set_cookie_params()` has no effect afterwards. Get it wrong and the session still works
  perfectly — with a cookie carrying none of FR-15's flags. Only the call sequence distinguishes the
  two, so a fake asserts the sequence.
  **Known gap:** that a real browser cookie carries the flags, and that a real identifier changes
  across `regenerate()`, remain behavioural — roadmap item 6.3's `php -S` integration suite owns
  them.
- `D4np\Utils\Http\Request` and `D4np\Utils\Http\Response` (**ADR-0025**) — opens Milestone 6.
  Native lightweight wrappers **mirroring PSR-7's naming without its types**, per RFC-0001: the
  optional `egl/utils-psr7-bridge` is the only sanctioned crossing point, and these never grow
  middleware ambitions.
  **The typed accessors refuse rather than coerce**, which is the security decision: `?email=x`
  gives a string but `?email[]=x` gives an **array** — the same key, a different PHP type, chosen by
  the client. A `(string)` cast yields the literal `"Array"` and `implode()` invents a value nobody
  sent, so a scalar accessor handed a non-scalar returns its default instead.
  `queryList()`/`postList()` are there for when a list is genuinely expected, and refuse scalars
  rather than wrapping them. `queryInt()` uses `FILTER_VALIDATE_INT`, not a cast, because
  `(int) "12abc"` is `12`.
  Headers are derived from `$_SERVER` (`getallheaders()` does not exist outside Apache-like SAPIs),
  including `CONTENT_TYPE`/`CONTENT_LENGTH` which CGI reports without the `HTTP_` prefix.
  `isSecure()` ignores `X-Forwarded-Proto` — client-supplied absent a trusted proxy — but does
  handle `$_SERVER['HTTPS']` being the string `'off'`.
  `Response` is immutable, refuses CR/LF/NUL in header values (**response splitting**) at the point
  they are *set* rather than at send time, and stores header names case-insensitively so a
  duplicated `Content-Type` cannot smuggle a second interpretation past a proxy. `json()` encodes
  through `Json::encode()` so an unencodable value raises instead of putting `false` in the body;
  `html()` deliberately escapes nothing, since escaping is a render-time decision (ADR-0019).
- Spec §7's **Hash matrix** and NFR-05's timing (**ADR-0024**) — closes Milestone 5. NFR-05 is a
  **range** (50–200 ms), unlike every other budget here, and the *lower* bound is the serious one:
  a hash completing in 5 ms means the work factor is inadequate. So it is split by what each half
  can prove. The **security** half is asserted as the **work factor** — `memory_cost`/`time_cost`
  against OWASP's published Argon2id minimums — which is machine-independent and catches what a
  stopwatch cannot: a duration cannot distinguish strong parameters on slow hardware from weak ones
  on fast. The **capacity** half is a benchmark asserting nothing (`HashBench`).
  Measured `Hash::make()` at **349 ms against the 50–200 ms range** — over, and deliberately **not**
  fixed: the cost parameters are PHP's defaults and clear OWASP's floor by more than 3× on memory,
  so lowering them to hit the range would trade security for latency. `verify()` is measured too
  (~348 ms): NFR-05 does not budget it, but it runs on **every login** and is what actually caps
  authentication throughput.
  Documented PHP behaviour: `password_needs_rehash()` reports `true` for **stronger** parameters as
  well as weaker ones, because it compares for equality with the current defaults — so a hash
  hardened beyond them is silently downgraded on next login.
- Spec §7's **OWASP XSS corpus snapshot suite** and **DOM-bypass corpus** for `richText()`
  (**ADR-0023**). Every payload is run through all four escaper contexts and recorded in a
  committed snapshot, so any change to escaping output becomes a reviewable diff rather than silent
  drift — paired with context invariants, because **a snapshot proves stability, not safety**: a
  snapshot of broken output is a valid snapshot. Re-recording is deliberate (`UPDATE_SNAPSHOTS=1`),
  never automatic, since an assertion that repairs itself is not an assertion.
  For mutation XSS the load-bearing check is **idempotence** — `richText(richText($x))` must equal
  `richText($x)`. mXSS payloads are inert when parsed once and become executable after re-parse, so
  "no `<script>` in the output" cannot detect them; instability under re-parse is the signature, and
  it holds for payloads nobody has written yet.
  **Correction to ADR-0021:** it claimed the scheme allowlist "is what refuses `javascript:` and
  `data:`". A probe adding `javascript` to the allowed schemes **passed** — `symfony/html-sanitizer`
  refuses that scheme unconditionally. The allowlist is the sole barrier for `data:` (including
  `data:text/html`) and defence-in-depth for `javascript:`. The restriction is unchanged; the
  explanation of it was imprecise, which matters when deciding whether to widen the list.
- `D4np\Utils\Security\Hash` (**ADR-0022**) — password hashing with an Argon2id default, per spec
  FR-11. **`PASSWORD_DEFAULT` is deliberately not used**: it is bcrypt (`'2y'`) on every PHP release
  to date, even where Argon2id is available, so code reaching for it expecting "whatever is
  strongest" silently gets the weaker algorithm — pinned by a test so the day PHP changes it, the
  suite says so. Availability is `defined('PASSWORD_ARGON2ID')`, checked *before* use because an
  unknown algorithm raises a bare `ValueError` outside ADR-0004's exception family.
  Unlike `Escaper` and `Sanitizer` this is an **instance**, because it carries a policy and a
  collaborator, and security configuration that can change mid-request is the wrong shape. The
  fallback is decided **once at construction**: `bcryptFallback: false` refuses to construct at all
  (so a misconfigured deployment fails while being wired, not at the first user registration), and
  the default `true` logs a single WARNING rather than one per hash. `algorithm()` exposes the
  outcome as a **value**, so a deployment without a PSR-3 logger can still detect the degradation.
  `verify()` works across algorithms and `needsRehash()` flags both a weaker algorithm and weaker
  *parameters*, which is what makes upgrade-on-login work.
- `deptrac.yaml` gains a `Psr` layer, granted to `Security` only for now. `psr/log` is a *required*
  interface-only dependency (RFC-0001 R-3) rather than an optional one, and further groups will
  need it as `Errors` (PSR-3) and `Container` (PSR-11) arrive, so it is granted per group as each
  does.
- `D4np\Utils\Security\Sanitizer` (**ADR-0021**) — `richText()` and `sqlLikePattern()`. **This
  completes spec §7's T-02 suite**, whose LIKE-wildcard leg roadmap item 4.4 deferred here.
  `sqlLikePattern()` neutralises the `%` and `_` that survive parameter binding intact — binding
  stops a `LIKE` value injecting SQL but does nothing about wildcards, so a search box forwarding
  `%` turns an indexed lookup into a scan. The escape character is **`!`, not `\`**: a backslash is
  special inside SQL string literals on several drivers, and `ESCAPE '\'` is a **parse error** on
  SQLite. `QueryBuilder::whereLike()` is added to emit the `ESCAPE` clause, because **without one
  an escaped pattern silently matches nothing on SQLite while working by accident on
  MySQL/PostgreSQL** — a wrong answer that only appears on one driver.
  `richText()` delegates to `symfony/html-sanitizer` (RFC-0001: *"no hand-rolled tag stripper"*), a
  new **optional** dependency declared in `suggest`. When it is absent `richText()` **throws**
  rather than returning the input unsanitized, and no Symfony type appears in its signature so
  "optional" holds at the API boundary. The curated profile limits link schemes to
  `https`/`http`/`mailto` (this is what refuses `javascript:` and `data:`), refuses relative links
  and media, and forces `rel="noopener noreferrer"`.
- `deptrac.yaml` gains an `HtmlSanitizer` layer that **only `Security` may reach** — the library's
  first third-party production dependency took the layering gate from `Uncovered: 0` to `6`, and
  declaring it keeps a future import from another group a build failure rather than a silent new
  coupling to an optional package.
- `D4np\Utils\Security\Escaper` (**ADR-0019**) — opens Milestone 5. RFC-0001's third security
  mechanism: context-aware output escaping. Four methods — `html()`, `attr()`, `js()`, `url()` —
  and deliberately **no general-purpose `escape()`**, because a method that did not name its
  context is the one that would get used in the wrong one. Each documents what it is *not* safe
  for, and a test asserts that separation rather than leaving it as advice.
  **`attr()` assumes the attribute is unquoted**, escaping every non-alphanumeric ASCII character
  as `&#xHH;`: an escaper cannot see its own call site, and the two possible assumptions are
  asymmetric — assuming quoted is wrong toward an XSS hole, assuming unquoted is wrong toward
  verbose output. Valid multibyte passes through, since no non-ASCII byte can terminate an
  attribute and escaping bytes individually would only produce mojibake.
  `js()` escapes on an allowlist, including `/` (inside `<script>` the HTML parser ends the element
  at `</script>` regardless of JavaScript string state) and U+2028/U+2029 (JavaScript line
  terminators before ES2019); output is pure ASCII with surrogate pairs above U+FFFF.
  **Invalid UTF-8 becomes U+FFFD in all four.** Without `ENT_SUBSTITUTE`, `htmlspecialchars()`
  returns an **empty string** for input containing one malformed byte — total silent data loss —
  so `attr()` and `js()` reproduce the same substitution via PCRE rather than `mbstring`, which is
  not a declared extension (NFR-08) and substitutes `?` rather than U+FFFD.
  `url()` covers one URL *component*; it is **not** the defence for a whole-URL sink such as
  `href="…"`, which needs scheme allowlisting — named as a gap rather than half-built.
- phpbench benchmark for NFR-03 (**ADR-0018**) — closes Milestone 4. `QueryBuilderBench` measures
  a 5-condition `SELECT`'s build time; `QueryBuilderTest::testBuildingNeverRunsAQuery()` asserts
  the "0 queries executed" half directly via the same `QueryLog` fixture item 4.4 built (timing
  cannot prove an absence, so this is a real assertion, not a benchmark, and it **is** CI-enforced).
  Measured build time: **~23µs against the ≤10µs budget**, attributed to ~1µs per identifier
  quoted (allowlist + driver-quote, ADR-0015) across the query's 12 identifiers, plus per-call
  cloning from the same ADR's immutability guarantee. Same shape as item 3.5's NFR-01 finding,
  handled the same way per ADR-0011's precedent: recorded honestly, shipped non-blocking (no
  reference-machine baseline exists yet — that's item 7.1's job), and filed as a separate follow-up
  (**roadmap item 4.6**) rather than fixed under a benchmark item's own route.
- Spec §7's **T-02 injection suite** (**ADR-0017**) — 29 fuzzed payloads × 6 value-accepting paths,
  asserting via a real **query log** (`PDO::ATTR_STATEMENT_CLASS`) that a payload appears in the
  bound-parameter array and **never** in the statement text. This is stronger than the round-trip
  assertions items 4.1–4.3 carried, and measurably so: against a planted *correctly-escaping*
  interpolation, the round-trip assertions passed 28 of 29 times while the query-log assertions
  failed 28 of 29. The PDO boundary is a sufficient proof point *because* ADR-0014 pins real
  prepares — with no client-side interpolation, placeholder-only text there is placeholder-only
  text on the wire. `--group T-02` now runs 321 tests and `--group T-04` 17, making spec §7's named
  suites runnable units.
  **T-02 is not complete:** its LIKE-wildcard leg needs `Sanitizer::sqlLikePattern()` (FR-10,
  roadmap item 5.2, not yet built). A test asserts the current behaviour and names the gap rather
  than leaving silence — a `LIKE` value binds and cannot inject SQL, but a user-supplied `%` still
  behaves as a wildcard.
- `D4np\Utils\Database\Transaction` (**ADR-0016**) — closure-scoped transactions: commit on return,
  roll back and rethrow on any **`Throwable`** (a `TypeError` leaves the same half-written state as
  an exception). Nested calls use savepoints, which is not an optimisation but the only mechanism
  available: a second `beginTransaction()` on an open connection **throws** rather than nesting or
  no-opping. A handled inner failure therefore undoes only the inner work, leaving the enclosing
  transaction intact. If the rollback *itself* fails its error is swallowed so the closure's
  exception — the actionable one — still reaches the caller; PHP has no suppressed-exception
  mechanism, so the choice is strictly between losing the cause and losing the cleanup failure.
  Savepoint names come from a process-wide monotonic counter and are never caller-influenced.
  Documented caveat no wrapper can fix: on MySQL, DDL causes an implicit commit mid-closure.
- `D4np\Utils\Database\QueryBuilder`, `Sort` and `Operator` (**ADR-0015**) — RFC-0001's second
  security mechanism: prepared statements bind *values*, never table or column names, so an
  identifier has nothing to hide behind and the allowlist is the whole defence. Identifiers are
  **refused** (`DatabaseException`), never sanitised, then driver-quoted (backticks on MySQL,
  brackets on SQL Server, double quotes elsewhere). Values always bind. `LIMIT`/`OFFSET` are `int`
  by signature and refused when negative rather than clamped.
  **Security fix found while building it:** spec FR-07 writes the allowlist as
  `^[A-Za-z_][A-Za-z0-9_]*$`, and transcribed literally into PHP that is a **bypass** — PCRE's `$`
  also matches before a trailing newline, so `"id\n"` passed and rendered as
  `SELECT "id\n" FROM "users"`. The pattern is anchored with `\z` instead, implementing FR-07's
  intent rather than copying its notation; ADR-0015 records it so nobody restores `$` for spec
  fidelity. `Operator` is likewise an extension of FR-07 rather than a silent addition: FR-07 makes
  the `ORDER BY` direction an enum because it is concatenated into the SQL text and cannot be
  bound, and a comparison operator is concatenated for exactly the same reason.
- `D4np\Utils\Database\DatabaseConnection` (**ADR-0014**) — opens Milestone 4. Wraps a
  **consumer-owned** `PDO` (RFC-0001: the library opens no connections) and pins spec FR-06's four
  safe defaults: `ERRMODE_EXCEPTION`, real prepares (`EMULATE_PREPARES=false`), `FETCH_ASSOC`, and
  `SET NAMES utf8mb4` on MySQL. A connection that will not take a pinned default is **refused**
  with a `DatabaseException` rather than handed back weakened — `PDO::setAttribute()` signals
  refusal by *returning `false`*, not by throwing, so the obvious implementation would let a
  security-relevant default fail silently. The ambiguous `false` is disambiguated by reading the
  attribute back: a driver with no emulation concept at all (SQLite) is fine; one that is still
  emulating is refused. The order the defaults are applied in is load-bearing and documented —
  `SET NAMES` is only safe *because* real prepares are already on, leaving no client-side escaping
  to fool. `select()`, `selectOne()` (returns `null`, not PDO's `false`) and `execute()` always
  bind values; identifiers cannot be bound and belong to `QueryBuilder` (item 4.2). Failures wrap
  `PDOException` per ADR-0004 and deliberately **omit the SQL** from the message, since a failing
  statement's text is the likeliest place for data that should not reach a log.
- `D4np\Utils\Dto\HydrationCompiler` (**ADR-0013**) closes NFR-01's performance gap: hydrating a
  10-scalar-property DTO went from **15.40× to 2.74×** manual constructor assignment
  (14.155 µs → 2.511 µs), meeting both halves of the budget for the first time. Four approaches
  were measured before any code was written, and the budget proved **unreachable** by tuning the
  interpreted loop (best case 4.80×) — so the hydrator now generates a per-class closure, but only
  for the all-scalar shape NFR-01 actually specifies (non-variadic builtin `int`/`float`/`string`/
  `bool`, no defaults). **Nested DTOs, `Collection`, enums, unions, `array`, variadics and
  defaulted parameters are not compiled** and run the existing interpreter unchanged, at roughly
  their previous cost — so this makes the specified shape fast, not every DTO. `HydrationParityTest`
  runs every case down both paths and compares them against each other, and `new
  HydrationCompiler(false)` turns generation off entirely for anyone who would rather no `eval`
  ran in their process. Full before/after with the environment, the rejected alternatives, and the
  ≈5.5-hydrations-per-process break-even for `eval`'s uncacheable compile cost is recorded in
  [`docs/benchmarks/2026/08/nfr01-hydration-compiled-closure.md`](docs/benchmarks/2026/08/nfr01-hydration-compiled-closure.md).
  NFR-04's 10 000-hydration benchmark improved as a side effect (149.7 ms → 37.8 ms).
- `deptrac.yaml` (**ADR-0012**) turns on the layering gate RFC-0001 named but nothing enforced:
  *groups depend downward on Support only; no cross-group imports*. One layer per component group
  plus `Support` and `Version`, collected by **directory** (bound to the source tree RFC-0001 §A-9
  fixes, rather than a second class-name-regex vocabulary that could drift from it), analysed over
  `src/main` only — tests and benchmarks legitimately cross groups and are out of scope. The empty
  `Support: ~` ruleset is the load-bearing half: it makes the `Support → Dto` inversion that
  ADR-0006 and ADR-0010 each designed around a build failure rather than a review opinion.
  Verified failing on a planted `Support → Dto` import and on a planted peer `Http → Dto` import,
  and verified *passing* on an allowed `Http → Support` import, so the gate is known to
  discriminate rather than merely reject. The CI `layering` job (self-skipped since item 1.9) is
  now active. `deptrac/deptrac` is held at `^4.4` by the PHP 8.1 platform pin — 4.7 requires 8.2.
- `composer.json`'s `config.audit.abandoned` set to `"report"` (was implicitly `"fail"`):
  phpbench pulls in `doctrine/annotations` as its own transitive dependency, flagged abandoned
  on Packagist. Not a security vulnerability and not a package this library depends on directly,
  so `composer audit` now surfaces it in output rather than failing CI on a maintenance-status
  flag of a dependency-of-a-dependency we cannot swap.

### Changed

- **Milestone 1 (`v0.1.0`) complete** — build system, test harness, formatter/linter,
  version constant, CI matrix, and the benchmark job are all in place and green.
- CI `benchmark` job hardened: it now self-enables on the presence of a phpbench config (the
  same step-level guard as the `deptrac` and `Infection` jobs) instead of failing until the
  harness lands, pins its interpreter to PHP 8.3 per spec NFR-06 rather than through a
  matrix expression the job never had, and runs without coverage instrumentation.

### Deprecated

### Removed

### Fixed

- `tools/consistency_lint.py`'s `version_file` path was doubled
  (`src/main/php/d4np/utils/src/main/php/d4np/utils/Version.php`), which would have silently
  disarmed `version-lockstep` — the check falls back to the README badge when the configured
  path does not exist, so the bug was invisible until this PR created `Version.php`. Fixed at
  the source (the manifest's `toolchain.version_file`) and re-rendered.

### Security

- Every GitHub Actions `uses:` reference in `.github/workflows/` is now pinned to an immutable
  commit SHA with a truthful version comment, per **ADR-0003**. Previously the same action was
  pinned two ways in one file — SHA in the generated steps, floating tag (`@v7`, `@v2`) in the
  quality jobs — so a re-pointed upstream tag could have changed what CI executes with no diff
  here. Dependabot (already configured for the `github-actions` ecosystem) keeps the pins and
  their comments current.
- `tools/action_pin_lint.py` enforces ADR-0003 mechanically in CI: `pin-shape` offline, and
  `pin-label-truth` resolving every version comment against its upstream repository — the half
  no purely local check can perform, since a comment that lies uniformly leaves nothing for a
  cross-file comparison to disagree with.

---

## Released versions

_No releases yet._
