<?php

declare(strict_types=1);

namespace D4np\Utils\Dto;

use Closure;
use D4np\Utils\Support\ClassMetadata;
use D4np\Utils\Support\MissingKeyException;
use D4np\Utils\Support\ParameterMetadata;
use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Support\UnknownKeyException;

/**
 * Generates a per-class hydration closure for the shape NFR-01 measures (ADR-0013).
 *
 * NFR-01 budgets DTO hydration at **≤ 3× manual constructor assignment**. Four approaches were
 * measured against that budget before this class was written (roadmap item 3.7; the numbers and
 * the environment are recorded in `docs/benchmarks/`):
 *
 * | approach                                  | µs/op | ratio  |
 * |-------------------------------------------|-------|--------|
 * | interpreted (the {@see Hydrator} loop)     | 13.86 | 16.6×  |
 * | interpreted, all avoidable waste removed   |  3.97 |  4.80× |
 * | one pre-built closure per parameter        |  3.31 |  4.00× |
 * | **generated closure (this class)**         |  1.93 |  2.28× |
 *
 * Every option that does not generate code lands above the budget, and those prototypes were
 * *optimistic* — none carried path building, exception construction, or the nested-DTO branches
 * production needs. The budget is not reachable by tuning an interpreted loop; that is a measured
 * conclusion, not a preference.
 *
 * **What is compiled is deliberately narrow.** Only the shape NFR-01 actually names — *"10 scalar
 * props"* — is eligible: every constructor parameter a non-variadic builtin `int`/`float`/
 * `string`/`bool`, with no declared default. Anything else (nested DTOs, `Collection`, enums,
 * unions, `mixed`, variadics, defaults) is **not compiled** and runs on {@see Hydrator}'s
 * interpreter exactly as before. That boundary is the point: a compiled path is only worth having
 * if it is small enough to be obviously equivalent to the path it replaces, and
 * `HydrationParityTest` asserts the two agree case by case rather than trusting that claim.
 *
 * **Why "no default" is part of eligibility.** The generated call passes arguments *positionally*,
 * which is what makes it fast — named-argument spread (`new $c(...$named)`) costs roughly twice a
 * positional call, measured. A positional call cannot skip an argument, so a parameter whose
 * default PHP would otherwise apply cannot be omitted. Rather than generate branching that
 * reconstructs named-argument semantics — and give the fast path a second way to disagree with the
 * interpreter — a class with any defaulted parameter simply is not eligible. Nullable parameters
 * *are* eligible: RFC-0001 R-4 passes `null` explicitly for them, so the argument is always
 * present and the position never shifts.
 *
 * **On `eval()`.** Generating source and evaluating it is the mechanism, and it has a real cost:
 * OPcache does not cache eval'd code, so the closure is built once per class *per process* rather
 * than once per deployment. Measured at ≈67 µs against a saving of ≈12 µs per hydration, the
 * break-even is **≈5.5 hydrations of one class in one process** — comfortably paid back by any
 * bulk mapping (an API page, a result set), and a small net loss for a process that hydrates one
 * or two objects of a class and exits. That trade was quantified and accepted deliberately
 * (ADR-0013), not assumed away.
 */
final class HydrationCompiler
{
    /**
     * The builtin types the generated code knows how to check, mapped to the check itself.
     *
     * `float` accepts an `int` because PHP performs that widening even under `strict_types=1` —
     * the same rule {@see Hydrator::satisfiesBuiltin()} applies, kept identical here on purpose:
     * two spellings of one rule is exactly how the paths would drift.
     */
    private const CHECKS = [
        'int' => 'is_int(%s)',
        'float' => '(is_float(%s) || is_int(%s))',
        'string' => 'is_string(%s)',
        'bool' => 'is_bool(%s)',
    ];

    /**
     * @param bool $enabled whether to generate anything at all. Disabling it makes
     *                      {@see compile()} decline every class, so {@see Hydrator} runs entirely
     *                      on its interpreter — the behavior is identical, only slower.
     *
     *                      This exists for two reasons, and only one of them is testing.
     *                      `HydrationParityTest` uses it to run the same fixtures down both paths
     *                      and assert they agree, which is what makes the fast path trustworthy.
     *                      It is also the honest escape hatch for anyone who would rather their
     *                      dependencies did not evaluate generated source in their process: that
     *                      is a legitimate position, and the cost of respecting it is a documented
     *                      constructor argument rather than a fork.
     */
    public function __construct(
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * A closure hydrating `$metadata`'s class, or `null` when the class is not eligible.
     *
     * The returned closure has the signature
     * `static function (array $data, string $prefix, bool $lenient): object`, and throws the same
     * exceptions, carrying the same paths, that {@see Hydrator} would for the same payload.
     *
     * @return Closure(array<string, mixed>, string, bool): object|null
     */
    public function compile(ClassMetadata $metadata): ?Closure
    {
        if (!$this->isEligible($metadata)) {
            return null;
        }

        /** @var Closure(array<string, mixed>, string, bool): object */
        return eval($this->sourceFor($metadata));
    }

    /**
     * Whether every constructor parameter is one the generated code can handle on its own.
     *
     * A class with no constructor parameters is *not* eligible — there is nothing to speed up, and
     * the interpreter already handles it in one step.
     */
    private function isEligible(ClassMetadata $metadata): bool
    {
        if (!$this->enabled || !$metadata->isInstantiable || $metadata->parameters === []) {
            return false;
        }

        foreach ($metadata->parameters as $parameter) {
            if ($parameter->isVariadic || $parameter->hasDefault || !$parameter->isBuiltin) {
                return false;
            }

            if ($parameter->type === null || !isset(self::CHECKS[$parameter->type])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The PHP source of the closure for this class.
     *
     * Written as source rather than assembled from smaller closures because that is where the
     * measured win comes from: the generated body is straight-line code with no per-parameter
     * call, no loop, and no array of metadata to walk.
     */
    private function sourceFor(ClassMetadata $metadata): string
    {
        $class = $metadata->className;
        $body = [];

        // Strict mode rejects an undeclared key before anything else, which is the order
        // Hydrator::hydrateAt() uses — unknown keys are reported ahead of missing ones, and a
        // payload that is both wrong in both ways must report the same one it reports today.
        $allowed = [];
        foreach ($metadata->parameters as $parameter) {
            $allowed[] = var_export($parameter->name, true) . ' => true';
        }

        // `array_diff_key` answers "is any key undeclared?" in one C call, where the equivalent
        // PHP-level `foreach` + `isset` costs a loop iteration and a string cast per key — the
        // single largest remaining cost in the generated body, measured.
        //
        // The tempting shortcut here — `count($data) !== count($allowed)` — is *wrong*, and the
        // parity suite has the case that proves it: a payload carrying one undeclared key while
        // also missing one declared key has the expected count, so the shortcut skips the scan and
        // the hydration goes on to report the missing key. Today's interpreter reports the unknown
        // key, because it rejects undeclared keys before it looks at parameters. `array_diff_key`
        // keeps that order without the loop.
        $body[] = sprintf(
            'if (!$lenient) { static $allowed = [%s]; $extra = array_diff_key($data, $allowed); '
            . 'if ($extra !== []) { $name = (string) array_key_first($extra); throw \\%s::forKey('
            . '$prefix === \'\' ? $name : $prefix . \'.\' . $name, %s); } }',
            implode(', ', $allowed),
            UnknownKeyException::class,
            var_export($class, true),
        );

        $arguments = [];
        foreach ($metadata->parameters as $index => $parameter) {
            $variable = '$v' . $index;
            $arguments[] = $variable;
            $body[] = $this->parameterSource($parameter, $variable, $class);
        }

        return sprintf(
            'return static function (array $data, string $prefix, bool $lenient) { %s return new \\%s(%s); };',
            implode(' ', $body),
            $class,
            implode(', ', $arguments),
        );
    }

    /**
     * The generated statements that resolve one parameter into `$variable`.
     */
    private function parameterSource(ParameterMetadata $parameter, string $variable, string $class): string
    {
        $name = var_export($parameter->name, true);
        $path = sprintf('($prefix === \'\' ? %s : $prefix . \'.\' . %s)', $name, $name);

        /** @var string $type */
        $type = $parameter->type;
        $check = str_replace('%s', $variable, self::CHECKS[$type]);
        $declared = var_export($parameter->declaredType ?? $type, true);

        // Absence, per RFC-0001 R-4. Eligibility already excluded defaults, so there are only two
        // cases left: nullable becomes an explicit null (PHP treats nullable-without-default as
        // required, so it must be passed), and anything else is a missing key.
        $absent = $parameter->allowsNull
            ? sprintf('%s = null;', $variable)
            : sprintf('throw \\%s::forKey(%s, %s);', MissingKeyException::class, $path, var_export($class, true));

        // A nullable parameter accepts an explicit null in the payload as well as an absent key.
        $guard = $parameter->allowsNull
            ? sprintf('%s !== null && !%s', $variable, $check)
            : sprintf('!%s', $check);

        return sprintf(
            'if (!array_key_exists(%s, $data)) { %s } else { %s = $data[%s]; if (%s) { '
            . 'throw \\%s::at(%s, %s, get_debug_type(%s)); } }',
            $name,
            $absent,
            $variable,
            $name,
            $guard,
            TypeMismatchException::class,
            $path,
            $declared,
            $variable,
        );
    }
}
