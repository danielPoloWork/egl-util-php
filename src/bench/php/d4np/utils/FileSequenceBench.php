<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Support\FileSequence;
use PhpBench\Attributes as Bench;

/**
 * NFR-10: `FileSequence::next()` ≤ 200 µs on local disk, lock included (spec r3 FR-32,
 * RFC-0002).
 *
 * **No per-revolution freshness is needed here**, unlike {@see ContainerBench}'s cold
 * subject. `next()`'s cost is dominated by {@see \D4np\Utils\Support\File::update()}'s
 * lock-acquire, read, and atomic rewrite of a fixed-shape one-line file — not by the
 * counter's numeric value, which only ever grows by a digit or two across a whole
 * benchmark run. A single state file created once per **iteration** (phpbench's hooks run
 * once per iteration, not per revolution — {@see ContainerBench}'s docblock) is therefore
 * the correct setup, reused across every revolution inside it.
 *
 * The cap is sized far above the revolution count below so the sequence never refuses
 * mid-iteration — `SequenceExhaustedException` is {@see \D4np\Utils\Support\FileSequenceTest}'s
 * concern, not this benchmark's.
 *
 * The absolute ≤ 200 µs ceiling carries the same caveat every benchmark here documents
 * (roadmap 3.5, ADR-0018, ADR-0030): tied to spec NFR-06's reference machine, gated on CI
 * hardware only because ADR-0030 measured the headroom to survive cross-runner noise.
 */
#[Bench\Iterations(10)]
#[Bench\Revs(1000)]
#[Bench\RetryThreshold(5)]
final class FileSequenceBench
{
    /** Comfortably above the 1000 revolutions one iteration replays against one file. */
    private const CAP = 100_000;

    private string $path;

    private FileSequence $sequence;

    public function setUpSequence(): void
    {
        $this->path = sys_get_temp_dir() . '/egl-utils-seq-bench-' . bin2hex(random_bytes(8)) . '.state';
        $this->sequence = new FileSequence($this->path, self::CAP);
    }

    public function tearDownSequence(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.lock');
    }

    #[Bench\BeforeMethods('setUpSequence')]
    #[Bench\AfterMethods('tearDownSequence')]
    public function benchSequenceNext(): void
    {
        $this->sequence->next('bench');
    }
}
