<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Errors\Level;
use D4np\Utils\Errors\LevelFilteredLogger;
use D4np\Utils\Errors\MultiLogger;
use PhpBench\Attributes as Bench;
use Psr\Log\AbstractLogger;

/**
 * NFR-14: a level-suppressed record costs ≤ 0.5 µs (roadmap item 12.3, spec r16).
 *
 * **Why the suppressed record is the one with a budget.** A record that is *written* is dominated by
 * its destination — `file_put_contents()` with a lock, or a stream — and no budget on it would say
 * anything about this library. The dropped record is the opposite: it is pure library overhead, it
 * is the common case in any application that leaves DEBUG calls in place under an INFO floor, and it
 * is paid on every one of them. NFR-14 budgets the only number a logging façade fully controls.
 *
 * The subject calls `->debug()` rather than `->log('debug', …)` deliberately: the shortcut is what
 * consumer code actually writes, and it routes through `AbstractLogger`, so the measured path
 * includes the dispatch a real call pays.
 *
 * **A control subject is included** ({@see self::benchSinkDirectly}), following the method item 10.12
 * settled: a subject the change cannot affect, measured in the same job, so the runner's own noise
 * is visible in the report instead of being attributed to the code. Here it is the bare sink,
 * bypassing the decorator entirely — the difference between the two is the decorator's whole cost.
 *
 * **{@see self::benchFanOutSuppressed} covers the composed shape** {@see \D4np\Utils\Errors\LoggerFactory}
 * builds, where the filter wraps a fan-out. It is the same suppressed path plus one constructor's
 * worth of indirection, and it is what a channel configured with two destinations actually costs
 * when the record is dropped.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(10)]
#[Bench\Revs(1000)]
#[Bench\RetryThreshold(5)]
final class LoggingBench
{
    private LevelFilteredLogger $filtered;

    private LevelFilteredLogger $filteredFanOut;

    private AbstractLogger $sink;

    public function setUp(): void
    {
        // A sink that does nothing measurable, so what is timed is the filtering decision and not a
        // destination. The suppressed path never reaches it — that is the point — and the control
        // subject needs it to be free.
        $this->sink = new class () extends AbstractLogger {
            /**
             * @param mixed                $level
             * @param array<string, mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        };

        $this->filtered = new LevelFilteredLogger($this->sink, Level::Warning);
        $this->filteredFanOut = new LevelFilteredLogger(
            new MultiLogger($this->sink, $this->sink),
            Level::Warning,
        );
    }

    /**
     * NFR-14's subject: a DEBUG record under a WARNING floor — validated, ranked, dropped.
     *
     * The 0.5 µs ceiling is enforced by `tools/bench_budget_gate.py` in CI and nightly, not by a
     * `Bench\Assert` here. Two homes for one number is the drift this project has already paid for
     * twice (the severity map this item removed from `Logger`; the identifier corpus item 10.5
     * found split across two suites), and the gate is the home that also prints the measured value
     * and fails on an absent report.
     */
    #[Bench\Subject]
    public function benchSuppressedRecord(): void
    {
        $this->filtered->debug('a record nobody will read');
    }

    /**
     * The same drop through the shape `LoggerFactory` builds: filter over fan-out.
     *
     * No `Assert`: NFR-14 budgets the suppressed record, and this subject exists to show that
     * composing the two classes does not change what that costs — the filter returns before the
     * composite is touched, so it should measure as the subject above. Asserting it separately would
     * invent a second budget the spec does not own (ADR-0040).
     */
    #[Bench\Subject]
    public function benchFanOutSuppressed(): void
    {
        $this->filteredFanOut->debug('a record nobody will read');
    }

    /**
     * The control: the sink on its own. Unaffected by anything item 12.3 changed, so its movement
     * between runs is the runner's noise floor, and no claim below that spread is worth making
     * (item 10.12's standing rule on this harness).
     *
     * **Wired as `ci.yml`'s regression-gate `--control` alongside `RowNormalizerBench::
     * benchInlineTrimHundredRows`** (roadmap item 12.6, ADR-0057) — a second, independent
     * runner-noise sentinel in the same CI job, so a run-wide slowdown localized to one part of
     * the suite is still caught if the other control happens to sit inside the threshold.
     */
    #[Bench\Subject]
    public function benchSinkDirectly(): void
    {
        $this->sink->debug('a record nobody will read');
    }

    /**
     * The counterpart, for context rather than for a budget: a record that passes the floor and is
     * handed to the sink. The difference from the suppressed subject is what a passing record adds
     * *before* a destination gets involved.
     */
    #[Bench\Subject]
    public function benchPassedRecord(): void
    {
        $this->filtered->error('a record that passes the floor');
    }
}
