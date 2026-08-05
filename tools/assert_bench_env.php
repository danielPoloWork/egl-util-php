#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Assert that the benchmark environment is the one spec NFR-06 specifies.
 *
 * NFR-06 does not merely suggest a configuration — it names *"PHP 8.3 CLI with OPcache+JIT off"* as
 * part of the methodology, which makes every number the suite produces conditional on it. Setting
 * those ini values in the workflow and trusting them is how a measurement environment drifts
 * silently: a `setup-php` upgrade that changed a default would leave the benchmarks green, faster,
 * and no longer comparable to anything recorded before it.
 *
 * So the environment is asserted rather than assumed, and the assertion runs in CI immediately
 * before the suite. Exits non-zero with the specific mismatch named.
 *
 * A file rather than an inline `php -r` in the workflow: the check needs a newline in its output,
 * and a backslash escape that has to survive YAML, a shell heredoc and JSON is a backslash escape
 * that will eventually not.
 */
$problems = [];

if ((string) ini_get('opcache.enable_cli') !== '' && (bool) ini_get('opcache.enable_cli')) {
    $problems[] = 'opcache.enable_cli is on';
}

// PHP reports a disabled JIT variously as 'disable', 'off', '0' or the empty string depending on
// how it was turned off; all four mean the same thing here.
$jit = (string) ini_get('opcache.jit');
if (!in_array($jit, ['disable', 'off', '0', ''], true)) {
    $problems[] = sprintf('opcache.jit is "%s"', $jit);
}

if (PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '8.3') {
    $problems[] = sprintf('PHP is %s, and NFR-06 specifies 8.3', PHP_VERSION);
}

if ($problems !== []) {
    fwrite(STDERR, sprintf(
        "bench-env: FAIL — NFR-06's measurement environment is not met: %s.\n"
        . "Every number this suite produces is conditional on that environment, so the run is\n"
        . "stopped rather than recorded against a baseline it cannot be compared with.\n",
        implode('; ', $problems),
    ));

    exit(1);
}

printf(
    "bench-env: OK — PHP %s, opcache.enable_cli=%s, opcache.jit=%s (spec NFR-06).\n",
    PHP_VERSION,
    var_export(ini_get('opcache.enable_cli'), true),
    var_export(ini_get('opcache.jit'), true),
);
