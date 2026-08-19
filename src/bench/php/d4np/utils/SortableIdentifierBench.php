<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Support\Str;
use PhpBench\Attributes as Bench;

/**
 * NFR-15: generating one time-sortable identifier (roadmap item 14.2, spec r18 FR-46, RFC-0003).
 *
 * **Why this path gets a budget when the clock (FR-45) deliberately did not.** An identifier is
 * drawn once per inserted row, so its cost rides every write a consumer makes — the one place in
 * FR-46's surface where a regression would be paid at volume rather than once at wiring.
 *
 * **`benchRandomToken` is the comparison, and the comparison refuted its own premise.** It was
 * added on item 10.10's lesson — check a budget against the already-accepted cost it contains
 * before setting it — on the assumption that `Str::random(10)`'s ten CSPRNG bytes were the floor
 * beneath both identifiers. **Measured on CI, both identifiers are faster than it**: 3.453 µs
 * (`benchUlid`) and 2.592 µs (`benchUuidV7`) against 6.083 µs. The two are not nested at all.
 * `Str::random()` calls `random_int()` once **per character** — ten rejection-sampled draws — while
 * these call `random_bytes(10)` **once**, so the subject measures a different entropy mechanism
 * rather than a smaller amount of the same one. Kept, relabelled: it is a reference point, not a
 * floor, and it is the reason NFR-15's ceiling is derived from the identifiers' own readings.
 *
 * The number itself is deliberately absent from this file: **the spec owns it** (ADR-0040), it is
 * set from a reference-runner measurement rather than a developer machine (which has overstated
 * CPU-bound work 2–5× on every occasion this project has checked), and the ceiling follows
 * ADR-0058's rule of ≥ 2× the worst observed reading. `tools/bench_budget_gate.py` enforces it
 * from `ci.yml`/`nightly.yml` — one home per number.
 *
 * **No separate control subject**, per item 12.4's finding as implemented in ADR-0057: one control
 * per CI *job* catches a run-wide slowdown for every subject in it, and
 * `RowNormalizerBench::benchInlineTrimHundredRows` already serves that role here. A second would
 * duplicate the signal without adding one.
 */
#[Bench\Iterations(10)]
#[Bench\Revs(1000)]
#[Bench\RetryThreshold(5)]
final class SortableIdentifierBench
{
    /**
     * NFR-15's subject: one ULID, system clock, exactly as a consumer calls it.
     *
     * No clock is injected — reading the clock is part of what an identifier costs, and a
     * benchmark that froze time would measure a call no consumer makes (the shape error ADR-0018,
     * ADR-0020, ADR-0028 and ADR-0030 each corrected once already).
     */
    public function benchUlid(): void
    {
        Str::ulid();
    }

    /** The same measurement for the UUID-shaped alternative; both are FR-46's surface. */
    public function benchUuidV7(): void
    {
        Str::uuidV7();
    }

    /**
     * The reference point described above — `Str::random(10)`, this library's other CSPRNG draw.
     * Unbudgeted on purpose: it exists to be read beside the two subjects, not to be gated, and
     * it is **not** a floor beneath them (measurement said otherwise; see the class docblock).
     */
    public function benchRandomToken(): void
    {
        Str::random(10);
    }
}
