<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-14 — `FileSequence` under genuine concurrency (spec r3 §6, RFC-0002; ADR-0038).
 *
 * **Real processes, not simulated ones.** Everything inside one PHP process shares a lock
 * owner, so `flock()` never contends and a suite built that way passes against an
 * implementation with no locking at all. Only separate processes make the race real — the
 * same reasoning that put T-03 against a live `php -S` rather than a mock.
 *
 * The property is the one a sequence exists for: across every process, **each number is
 * issued exactly once**. A lost increment shows up here as a duplicate.
 */
#[Group('T-14')]
final class FileSequenceConcurrencyTest extends TestCase
{
    private const WORKERS = 4;
    private const DRAWS_EACH = 30;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/egl-utils-t14-' . bin2hex(random_bytes(8));
        if (!mkdir($this->dir) && !is_dir($this->dir)) {
            self::fail('could not create the test directory');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($this->dir);
    }

    private static function autoloadPath(): string
    {
        return \dirname(__DIR__, 6) . '/vendor/autoload.php';
    }

    private static function workerPath(): string
    {
        return __DIR__ . '/Fixture/sequence_worker.php';
    }

    /**
     * Run `self::WORKERS` processes concurrently and return every number they drew.
     *
     * @return list<int>
     */
    private function drawConcurrently(int $cap, string $window): array
    {
        $state = $this->dir . '/shared.state';
        $processes = [];
        $outputs = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $out = $this->dir . "/worker-{$i}.out";
            $outputs[] = $out;

            $process = @proc_open(
                [
                    PHP_BINARY,
                    self::workerPath(),
                    self::autoloadPath(),
                    $state,
                    (string) $cap,
                    $window,
                    (string) self::DRAWS_EACH,
                    $out,
                ],
                [
                    0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null', 'r'],
                    1 => ['file', $this->dir . "/worker-{$i}.log", 'w'],
                    2 => ['file', $this->dir . "/worker-{$i}.log", 'a'],
                ],
                $pipes,
            );

            self::assertIsResource($process, 'could not spawn ' . PHP_BINARY);
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            proc_close($process);
        }

        $drawn = [];
        foreach ($outputs as $index => $out) {
            self::assertFileExists($out, "worker {$index} produced no output");
            $contents = trim((string) file_get_contents($out));
            self::assertStringStartsNotWith('ERROR:', $contents, "worker {$index} failed: {$contents}");

            foreach (explode("\n", $contents) as $line) {
                if ($line !== '') {
                    $drawn[] = (int) $line;
                }
            }
        }

        return $drawn;
    }

    public function testEveryNumberIsIssuedExactlyOnceAcrossProcesses(): void
    {
        $total = self::WORKERS * self::DRAWS_EACH;

        $drawn = $this->drawConcurrently(cap: $total, window: 'shift-a');

        self::assertCount($total, $drawn, 'every worker should have completed all its draws');

        sort($drawn);

        self::assertSame(range(1, $total), $drawn, 'the drawn numbers must be 1..N with no duplicate and no gap');
    }

    public function testTheSuiteWouldSeeADuplicateIfOneOccurred(): void
    {
        // Guards the assertion above against being vacuous: the comparison it makes is one
        // that a duplicated draw genuinely fails.
        $withDuplicate = [1, 2, 2, 4];
        sort($withDuplicate);

        self::assertNotSame(range(1, 4), $withDuplicate);
    }

    public function testTheCapHoldsUnderConcurrencySoNoWorkerExceedsIt(): void
    {
        // Half the capacity the workers collectively ask for: some draws must be refused,
        // and no number above the cap may ever be issued.
        $cap = intdiv(self::WORKERS * self::DRAWS_EACH, 2);
        $state = $this->dir . '/capped.state';
        $processes = [];
        $outputs = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $out = $this->dir . "/capped-{$i}.out";
            $outputs[] = $out;

            $process = @proc_open(
                [
                    PHP_BINARY,
                    self::workerPath(),
                    self::autoloadPath(),
                    $state,
                    (string) $cap,
                    'shift-b',
                    (string) self::DRAWS_EACH,
                    $out,
                ],
                [
                    0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null', 'r'],
                    1 => ['file', $this->dir . "/capped-{$i}.log", 'w'],
                    2 => ['file', $this->dir . "/capped-{$i}.log", 'a'],
                ],
                $pipes,
            );

            self::assertIsResource($process);
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            proc_close($process);
        }

        $drawn = [];
        $refusals = 0;

        foreach ($outputs as $out) {
            $contents = trim((string) file_get_contents($out));

            if (str_starts_with($contents, 'ERROR:')) {
                $refusals++;
                self::assertStringContainsString('exhausted', $contents);

                continue;
            }

            foreach (explode("\n", $contents) as $line) {
                if ($line !== '') {
                    $drawn[] = (int) $line;
                }
            }
        }

        self::assertGreaterThan(0, $refusals, 'the cap should have refused at least one worker');
        self::assertLessThanOrEqual($cap, \count($drawn), 'no more numbers than the cap may be issued');

        if ($drawn !== []) {
            self::assertLessThanOrEqual($cap, max($drawn), 'no number above the cap may be issued');
            self::assertSame(\count($drawn), \count(array_unique($drawn)), 'no number may be issued twice');
        }
    }
}
