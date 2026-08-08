<?php

declare(strict_types=1);

namespace D4np\Utils\Errors;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * PSR-3's eight levels as a type, with the ordering PSR-3 leaves out (spec FR-41, RFC-0002).
 *
 * PSR-3 defines the level *names* and says nothing about their relative severity, yet every
 * filtering decision needs exactly that. The order below is RFC 5424's, which is where PSR-3's
 * names come from.
 *
 * **The case values are literals, and `LevelTest` is what stops them drifting.** Backing each case
 * with PSR-3's own `LogLevel::` constant would make the two impossible to disagree, and it is what
 * this class did until CI rejected it: **PHP 8.1 refuses a class constant as an enum case value**
 * (*"Enum case value must be compile-time evaluatable"*), while 8.2 and 8.3 accept it — so the
 * elegant version is unavailable on this library's floor. A probe had said otherwise because the
 * probe ran on 8.3; the constraint is recorded in ADR-0055 D1 as a measurement, not as a
 * preference. The drift guarantee moved from the compiler to a test that asserts the two sets equal
 * in both directions, which is weaker in timing (a test run, not a compile) and identical in effect.
 *
 * The `RANK` map below *does* reference {@see LogLevel}: an ordinary class constant is evaluated on
 * first access rather than at compile time, which is the difference the 8.1 restriction turns on.
 *
 * **The ordering lives here once.** Before this enum, {@see Logger} carried a private severity map
 * of its own; a second copy in the filtering decorator would have been the failure this project
 * has already met once — two copies of one rule, the newer one weaker (ADR-0015's allowlist, split
 * across two builders until item 10.5 found it). {@see Logger} now consumes this enum, and
 * `LevelTest` asserts every case has a rank so a ninth case cannot be added without one.
 *
 * **`rank()` reads a map rather than running a `match`, and that is measured, not stylistic.**
 * With OPcache off — which is how NFR-06 pins every benchmark — `match ($this)` over the eight
 * cases cost **0.564 µs** against **0.246 µs** for a const-map lookup through the backing value,
 * ~2.3× on the same box in the same run. NFR-14 budgets a suppressed record at 0.5 µs in total, so
 * the difference is most of the budget.
 *
 * **{@see rankOf()} exists so the hot path never hydrates a case.** `AbstractLogger::debug()` hands
 * `log()` a *string*, so a decorator that converted it to a case per record would pay
 * `tryFrom()` (~0.147 µs) for a value it only wants an integer from. Validation and ranking are one
 * array lookup instead: a `null` return means "not a level", which is the only validation PSR-3
 * asks for.
 */
enum Level: string
{
    // Literals by necessity, not by choice — see the class docblock and ADR-0055 D1. Every value
    // here is asserted equal to its `Psr\Log\LogLevel` counterpart by `LevelTest`.
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';

    /**
     * Severity as a comparable integer, **most severe first** — so "passes a floor" is `<=`.
     *
     * Ascending-by-severity is the direction RFC 5424 numbers its own severities, and inverting it
     * to "bigger is worse" would read more naturally in a comparison while disagreeing with every
     * external reference a reader might check.
     *
     * @var array<string, int>
     */
    private const RANK = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    /**
     * This level's severity: `0` for {@see self::Emergency}, `7` for {@see self::Debug}.
     */
    public function rank(): int
    {
        return self::RANK[$this->value];
    }

    /**
     * Whether a logger with `$this` as its floor should let `$record` through.
     *
     * Reads as the question a filter asks: `Level::Warning->includes(Level::Error)` is `true`
     * (an error is worse than a warning), `->includes(Level::Debug)` is `false`.
     */
    public function includes(self $record): bool
    {
        return $record->rank() <= $this->rank();
    }

    /**
     * The severity of whatever a PSR-3 caller passed as a level, or `null` if it is not one.
     *
     * Accepts the string PSR-3 specifies **first**, because that is what every
     * `AbstractLogger::warning()`-style call produces and therefore the only shape the hot path
     * sees; a {@see self} instance is accepted after it, as a convenience that costs the string
     * path nothing.
     *
     * `null` rather than a throw: the caller decides whether an unknown level is a failure — for a
     * PSR-3 logger it always is, and {@see self::invalid()} builds that exception.
     */
    public static function rankOf(mixed $level): ?int
    {
        if (\is_string($level)) {
            return self::RANK[$level] ?? null;
        }

        return $level instanceof self ? $level->rank() : null;
    }

    /**
     * The exception PSR-3 requires for an unknown level, with every accepted name in the message.
     *
     * Returned rather than thrown, so the `throw` stays visible at the call site and static
     * analysis can see the method never falls through.
     */
    public static function invalid(mixed $level): InvalidArgumentException
    {
        return new InvalidArgumentException(\sprintf(
            '"%s" is not a PSR-3 log level. Expected one of: %s.',
            \is_scalar($level) || $level === null ? \var_export($level, true) : \get_debug_type($level),
            \implode(', ', \array_keys(self::RANK)),
        ));
    }

    /**
     * Every level name, most severe first — the vocabulary, for error messages and config docs.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return \array_keys(self::RANK);
    }
}
