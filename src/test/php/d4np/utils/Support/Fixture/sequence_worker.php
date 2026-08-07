<?php

/**
 * T-14's worker: a separate PHP process that draws numbers from a shared {@see FileSequence}.
 *
 * Separate *processes* are the point. Threads or sequential calls inside one PHPUnit run share
 * a lock owner, so `flock()` never actually contends and the test would pass against an
 * implementation that has no locking at all. Only real processes make the race real.
 *
 * Usage: php sequence_worker.php <autoload> <statePath> <cap> <window> <draws> <outFile>
 * Writes one number per line to <outFile>, or `ERROR: ...` if a draw failed.
 */

declare(strict_types=1);

use D4np\Utils\Support\FileSequence;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];

if (count($argv) !== 7) {
    fwrite(STDERR, "usage: sequence_worker.php <autoload> <statePath> <cap> <window> <draws> <outFile>\n");

    exit(2);
}

[, $autoload, $statePath, $cap, $window, $draws, $outFile] = $argv;

require_once $autoload;

$sequence = new FileSequence($statePath, (int) $cap);
$drawn = [];

try {
    for ($i = 0; $i < (int) $draws; $i++) {
        $drawn[] = $sequence->next($window);
    }
} catch (Throwable $e) {
    file_put_contents($outFile, 'ERROR: ' . $e->getMessage());

    exit(1);
}

file_put_contents($outFile, implode("\n", $drawn));

exit(0);
