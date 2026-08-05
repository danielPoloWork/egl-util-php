<?php

declare(strict_types=1);

namespace D4np\Utils\Errors;

use Throwable;

/**
 * The outcome of an operation that was expected to be able to fail (spec FR-16, ADR-0029).
 *
 * RFC-0001 puts it plainly: *"service-level outcomes use `Result` instead of boolean/null
 * returns"*. A `false` says nothing about what went wrong and a `null` is indistinguishable from a
 * legitimately absent value, so both push the caller into guessing — and both are silently
 * ignorable, which is the actual failure mode.
 *
 * **A failure carries a `Throwable`, not an arbitrary error value.** {@see orElseThrow()} has to
 * throw *something*, and manufacturing an exception at the moment someone unwraps would put the
 * stack trace in the accessor rather than where the operation actually failed — losing the only
 * part of a trace anyone reads. Constructing the throwable at the failure site keeps it, and the
 * library already has a hierarchy for the purpose (ADR-0004).
 *
 * **`map()` does not catch.** A `Result` models an *expected* failure; a mapper that throws has a
 * defect, and quietly converting a `TypeError` into a business failure would hide it exactly where
 * it is cheapest to fix. {@see try()} is the explicit opt-in for turning a throwing call into a
 * `Result`, so the catching happens where a reader can see it was meant.
 *
 * @template-covariant T
 */
final class Result
{
    /**
     * @param T|null $value
     */
    private function __construct(
        private readonly bool $isSuccess,
        private readonly mixed $value,
        private readonly ?Throwable $error,
    ) {
    }

    /**
     * @template U
     *
     * @param U $value
     *
     * @return self<U>
     */
    public static function success(mixed $value): self
    {
        return new self(true, $value, null);
    }

    /**
     * @return self<never>
     */
    public static function failure(Throwable $error): self
    {
        /** @var self<never> */
        return new self(false, null, $error);
    }

    /**
     * Run `$operation`, capturing a throw as a failure.
     *
     * The one place catching happens, and it is opt-in by name so the intent is legible at the call
     * site. Use it at the boundary where a throwing API meets code that wants outcomes.
     *
     * @template U
     *
     * @param callable(): U $operation
     *
     * @return self<U>
     */
    public static function try(callable $operation): self
    {
        try {
            return self::success($operation());
        } catch (Throwable $e) {
            return self::failure($e);
        }
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function isFailure(): bool
    {
        return !$this->isSuccess;
    }

    /**
     * Transform the value, if there is one.
     *
     * A failure passes through untouched, which is what makes a chain readable: the error-handling
     * is stated once at the end rather than between every step.
     *
     * @template U
     *
     * @param callable(T): U $fn
     *
     * @return self<U>
     */
    public function map(callable $fn): self
    {
        // Rebuilt rather than returned as `$this`, because a failure's element type must change to
        // `U` for the caller and PHP cannot re-type `$this`. A `Result` is a value, so identity
        // carries no meaning and the throwable — the only thing a failure holds — is passed along.
        if ($this->error !== null) {
            return self::failure($this->error);
        }

        /** @var T $value */
        $value = $this->value;

        return self::success($fn($value));
    }

    /**
     * Like {@see map()}, for an operation that itself returns a `Result` — so chaining does not
     * produce a `Result<Result<T>>`.
     *
     * @template U
     *
     * @param callable(T): self<U> $fn
     *
     * @return self<U>
     */
    public function flatMap(callable $fn): self
    {
        if ($this->error !== null) {
            return self::failure($this->error);
        }

        /** @var T $value */
        $value = $this->value;

        // No `instanceof` guard for a callable that forgets to return a Result: the native `: self`
        // return type already refuses it, with `Return value must be of type Result, string
        // returned` — verified. A hand-written check would restate that less clearly and, being
        // unreachable from any analysed caller, would sit uncovered forever.
        return $fn($value);
    }

    /**
     * Recover from a failure by supplying a value.
     *
     * The failure's throwable is handed over so the recovery can depend on *what* went wrong rather
     * than merely that something did.
     *
     * @template U
     *
     * @param callable(Throwable): U $fn
     *
     * @return self<T|U>
     */
    public function recover(callable $fn): self
    {
        if ($this->isSuccess) {
            return $this;
        }

        /** @var Throwable $error */
        $error = $this->error;

        return self::success($fn($error));
    }

    /**
     * The value, or the failure's throwable thrown.
     *
     * @return T
     */
    public function orElseThrow(): mixed
    {
        if ($this->isSuccess) {
            /** @var T */
            return $this->value;
        }

        /** @var Throwable $error */
        $error = $this->error;

        throw $error;
    }

    /**
     * The value, or `$default` if this is a failure.
     *
     * @template U
     *
     * @param U $default
     *
     * @return T|U
     */
    public function orElse(mixed $default): mixed
    {
        /** @var T|U */
        return $this->isSuccess ? $this->value : $default;
    }

    /**
     * The failure's throwable, or `null` on success.
     *
     * Exists so a caller can inspect a failure without unwrapping it into a `throw` — logging it,
     * for instance, which is the common thing to do with one.
     */
    public function error(): ?Throwable
    {
        return $this->error;
    }
}
