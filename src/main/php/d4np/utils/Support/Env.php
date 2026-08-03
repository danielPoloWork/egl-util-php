<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * Environment variable reads with correct boolean coercion (spec §2 item 24).
 *
 * The bug this exists to fix: `getenv('FEATURE_X')` can return the **string** `"false"`, and
 * `if ($value)` is `true` for that string — a feature flag set to disable something silently
 * enables it instead. `Env::get()` recognises boolean-shaped values and returns a real `bool`.
 */
final class Env
{
    private function __construct()
    {
        // Static-only: no instances.
    }

    /**
     * The value of environment variable `$key`, with boolean-shaped values coerced to `bool`.
     *
     * Built on `filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` — PHP's own
     * boolean-recognition primitive, not a hand-rolled token list — which accepts (case-
     * insensitively, trimmed): `"true"`/`"1"`/`"yes"`/`"on"` as `true` and
     * `"false"`/`"0"`/`"no"`/`"off"` as `false`. A value it does not recognise is returned as the
     * raw string unchanged; `Env::get()` never invents a coercion `filter_var` does not already
     * define.
     *
     * **One deliberate exception**: an environment variable explicitly set to the empty string
     * is returned as `''`, not coerced. `filter_var('', FILTER_VALIDATE_BOOLEAN, …)` returns
     * `false` for empty input — verified directly — which would silently turn `FOO=""`
     * (a real pattern: an intentionally blank placeholder) into the boolean `false`. Boolean
     * coercion is for recognising yes/no-shaped words, not for treating "unset-looking" as
     * "negative".
     *
     * `$default` is returned only when the variable is **unset**. `getenv()` itself is how that
     * is distinguished from "set to the empty string": it returns `false` (the bool) when unset
     * and `''` (the string) when set-but-empty — two different values this method must not
     * conflate, which is why the unset check comes first and compares with `===`.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = getenv($key);
        if ($raw === false) {
            return $default;
        }

        if ($raw === '') {
            return '';
        }

        $coerced = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $coerced ?? $raw;
    }
}
