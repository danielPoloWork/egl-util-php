<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

/**
 * An immutable pairing of SQL text and the parameters bound to it (spec r3 FR-33, RFC-0002;
 * ADR-0039, amended by ADR-0041).
 *
 * **The guarantee is mechanical, not organizational.** The surveyed estate's query factories
 * interpolated request values into SQL text at 199 sites while binding none, and a signature
 * of `(string $sql, array $parameters = [])` cannot tell "there is nothing to bind" from "I
 * forgot to bind" — every one of those 199 sites would type-check against it. So the text
 * this class accepts is constrained by *type*: {@see self::literal()} takes a
 * `literal-string`, which PHPStan at max level — a gate this repository already runs on every
 * PR — **refuses** for an interpolated `"… {$value} …"` or a `'…' . $value` concatenation,
 * while accepting hand-written SQL with placeholders, which is exactly what FR-33 exists to
 * allow.
 *
 * There are four ways in, and which one a call site uses is the review signal:
 *
 * | constructor | what it promises | who checks |
 * |---|---|---|
 * | {@see self::literal()} | the text is a compile-time literal | **PHPStan** |
 * | {@see self::fromQueryBuilder()} | the text came from {@see QueryBuilder} | that class's allowlist (ADR-0015) |
 * | {@see self::fromMutation()} | the text came from {@see MutationBuilder} | the same allowlist, via {@see Identifier} (ADR-0044) |
 * | {@see self::composed()} | the caller asserts the assembled text holds no untrusted value | **a human, at review** |
 *
 * `composed()` has **zero uses inside this library**, deliberately — that is what makes
 * `grep composed(` a list of exactly the places a person had to think, rather than a list
 * diluted by the library's own routine calls.
 *
 * The constructor is private so that no fourth, unconstrained way in exists. That is also what
 * lets the named constructors above widen the type with **no suppression of any kind** — no
 * analyser-ignore comment, no inline type override, both of which this project forbids: the
 * private constructor's own parameter is a plain `string`, and each public entry point states
 * in its signature what it is promising.
 *
 * (Naming an ignore-comment tag verbatim in this docblock is not possible, incidentally —
 * PHPStan parses one wherever it appears, including inside prose, and fails on it. Found by
 * writing this sentence the obvious way first.)
 */
final class SqlStatement
{
    /**
     * @param array<string|int, mixed> $parameters bound as parameters — never interpolated
     */
    private function __construct(
        public readonly string $sql,
        public readonly array $parameters,
    ) {
    }

    /**
     * A statement whose text is written out in the source, with `?` or `:name` placeholders
     * for every value.
     *
     * **The normal way to build one.** `$sql` is a `literal-string`: PHPStan accepts a quoted
     * string and the concatenation of quoted strings, and rejects any text that a runtime
     * value reached — so the mistake this class exists to prevent is a static-analysis
     * failure rather than a convention. Dialect SQL that {@see QueryBuilder} does not model is
     * welcome here; being hand-written is not the problem, being *assembled from values* is.
     *
     * @param literal-string           $sql
     * @param array<string|int, mixed> $parameters bound as parameters — never interpolated
     */
    public static function literal(string $sql, array $parameters = []): self
    {
        return new self($sql, $parameters);
    }

    /**
     * The statement a {@see QueryBuilder} represents.
     *
     * Takes the builder rather than its `toSql()` string on purpose: no SQL text crosses this
     * boundary as a bare argument, so the library's own composed SQL needs no escape hatch and
     * {@see self::composed()} keeps its zero in-library usage count. The text is safe for
     * ADR-0015's reason — identifiers pass the allowlist and are driver-quoted, values are
     * bound — which is a property of `QueryBuilder`, asserted by its own suite, not of this
     * method.
     */
    public static function fromQueryBuilder(QueryBuilder $builder): self
    {
        return new self($builder->toSql(), $builder->bindings());
    }

    /**
     * The statement a {@see MutationBuilder} represents.
     *
     * The write-side twin of {@see self::fromQueryBuilder()}, and it exists for the same reason:
     * the library's own composed SQL should not have to knock on the escape hatch. Added at item
     * 10.4 (ADR-0044) when {@see \D4np\Utils\Persistence\TableGateway} needed `INSERT`, `UPDATE`
     * and `DELETE` — verbs `QueryBuilder` does not have — and takes the builder rather than its
     * text for the same reason: no SQL crosses this boundary as a bare argument, so
     * {@see self::composed()} keeps its zero in-library usage count and `grep composed(` keeps
     * meaning "a human checked this one".
     *
     * The safety here is `MutationBuilder`'s property, asserted by its own suite, not this
     * method's: identifiers pass {@see Identifier}'s allowlist and every value is bound.
     */
    public static function fromMutation(MutationBuilder $builder): self
    {
        return new self($builder->toSql(), $builder->bindings());
    }

    /**
     * A statement whose text was assembled at runtime.
     *
     * **The escape hatch, and the only one.** It exists because some legitimate SQL cannot be
     * a literal: a bulk `INSERT … VALUES (?,?),(?,?),…` or an `IN (?,?,?)` whose placeholder
     * count depends on the data has to be built with `implode()`, and `implode()`'s result is
     * not a `literal-string` however safe its inputs were.
     *
     * Calling this is an assertion **by the caller** that the assembled text contains no value
     * from outside the program — that everything variable in it is a placeholder, and every
     * value travels in `$parameters`. PHPStan cannot check that, which is the entire reason
     * this method is named rather than being the constructor: `grep composed(` is the review
     * list.
     *
     * Deliberately **not** called `unsafe*()`. Nothing here is unsafe by construction — values
     * still bind through {@see DatabaseConnection}'s real prepares (ADR-0014) — and a name that
     * cries wolf on every legitimate bulk insert is a name that gets ignored on the one call
     * that deserved attention.
     *
     * @param array<string|int, mixed> $parameters bound as parameters — never interpolated
     */
    public static function composed(string $sql, array $parameters = []): self
    {
        return new self($sql, $parameters);
    }
}
