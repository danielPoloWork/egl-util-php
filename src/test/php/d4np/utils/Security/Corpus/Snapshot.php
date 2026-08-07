<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security\Corpus;

use RuntimeException;

/**
 * Reads and writes the committed snapshot of what the escapers produce.
 *
 * **What a snapshot is for, and what it is not for.** It records *what the code currently does*,
 * so that any change to that becomes a reviewable diff instead of an invisible drift. It proves
 * **stability**, not **safety** — a snapshot of broken output is a perfectly valid snapshot. That
 * is why every suite using this also asserts context invariants (no `<` survives `html()`, `js()`
 * output is pure ASCII, and so on): the snapshot catches *change*, the invariants catch *wrong*.
 * Neither is sufficient alone, and conflating them is how snapshot suites end up blessing bugs.
 *
 * Updating is deliberate: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`. It is an environment variable
 * rather than an automatic rewrite because a snapshot that silently re-records itself asserts
 * nothing at all — the whole value is that a human has to look at the diff and agree with it.
 */
final class Snapshot
{
    private function __construct()
    {
        // Static-only: no instances.
    }

    public static function path(string $name): string
    {
        return \dirname(__DIR__, 5) . '/resources/snapshots/' . $name . '.json';
    }

    public static function shouldUpdate(): bool
    {
        return \getenv('UPDATE_SNAPSHOTS') === '1';
    }

    /**
     * The recorded snapshot, or `null` when it has never been written.
     *
     * @return array<string, string>|null
     */
    public static function read(string $name): ?array
    {
        $path = self::path($name);

        if (!\is_file($path)) {
            return null;
        }

        $raw = \file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('Could not read snapshot: ' . $path);
        }

        /** @var array<string, string> */
        return \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $actual
     */
    public static function write(string $name, array $actual): void
    {
        $path = self::path($name);
        $directory = \dirname($path);

        if (!\is_dir($directory) && !\mkdir($directory, 0o777, true) && !\is_dir($directory)) {
            throw new RuntimeException('Could not create snapshot directory: ' . $directory);
        }

        // Sorted keys and pretty-printing are not cosmetic: this file's entire job is to produce a
        // diff a human will read, and an unordered or single-line JSON blob produces a diff nobody
        // can review. Slashes and unicode are left unescaped for the same reason.
        \ksort($actual);

        $json = \json_encode(
            $actual,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        \file_put_contents($path, $json . "\n");
    }
}
