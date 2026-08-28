<?php

declare(strict_types=1);

namespace D4np\Utils\Dto;

use D4np\Utils\Support\HydrationException;
use D4np\Utils\Support\ReflectionCache;
use JsonSerializable;

/**
 * Base class for typed, immutable data transfer objects (spec FR-01, ADR-0008).
 *
 * A DTO declares its shape as a promoted `readonly` constructor, and gains hydration from an
 * array:
 *
 * ```php
 * final class UserDto extends DataTransferObject
 * {
 *     public function __construct(
 *         public readonly string $email,
 *         public readonly string $name,
 *     ) {}
 * }
 *
 * $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'Ada']);   // strict (default)
 * $user = UserDto::lenient()->fromArray($wider);                        // ignores extra keys
 * ```
 *
 * **Strict is the default, deliberately.** A key the target does not declare is rejected rather
 * than dropped: silently discarding one is how a typo becomes a field that was never assigned,
 * and how a mass-assignment attempt becomes invisible. `lenient()` is the per-call opt-out for
 * callers who genuinely receive wider payloads than they map.
 *
 * The inverse exists too (spec r32 FR-51, ADR-0086): {@see self::toArray()} turns the DTO back
 * into the array `fromArray()` accepts — `X::fromArray($x->toArray()) == $x` is a tested law,
 * not a convention — and `json_encode($dto)` serializes that same array, so the two ways of
 * writing a DTO to a wire cannot disagree.
 */
abstract class DataTransferObject implements JsonSerializable
{
    /**
     * The process-wide hydrator, holding the one reflection cache imported ADR-001 commits to.
     *
     * Static because {@see self::fromArray()} is, and a static entry point has no instance to
     * be injected into — the constraint ADR-0006 deliberately declined to guess at before it
     * existed. It needs no reset hook: the cache memoises *immutable* facts (a class's
     * constructor cannot change while the process runs), so there is no stale state a test
     * could observe. That is what makes the shared instance safe rather than merely convenient.
     */
    private static ?Hydrator $hydrator = null;

    /**
     * Hydrate this class from `$data`, rejecting keys it does not declare.
     *
     * @param array<string, mixed> $data
     *
     * @throws HydrationException on an unknown key, a missing required key, or a value whose
     *                            type the declaration cannot accept — each naming the path at
     *                            which it went wrong
     */
    public static function fromArray(array $data): static
    {
        /** @var static */
        return self::hydrator()->hydrate(static::class, $data, false);
    }

    /**
     * A hydration for this class that ignores keys it does not declare.
     *
     * @return Hydration<static>
     */
    public static function lenient(): Hydration
    {
        /** @var Hydration<static> */
        return new Hydration(static::class, self::hydrator());
    }

    /**
     * The plain-array form of this DTO — the exact inverse of {@see self::fromArray()}
     * (spec r32 FR-51, ADR-0086).
     *
     * Conversion follows each constructor parameter's declaration, mirroring hydration branch
     * for branch: nested DTOs recurse, backed enums become their backing value, a
     * `#[CollectionOf]` collection becomes a list (DTO elements as arrays, backed-enum elements
     * as values), and everything hydration passes through untouched — builtins, `mixed`, plain
     * arrays, instances of classes outside the conversion vocabulary — comes back as it is.
     * `X::fromArray($x->toArray()) == $x` holds for every `$x` this class can hydrate.
     *
     * @return array<string, mixed>
     *
     * @throws HydrationException at any position declared as a pure (non-backed) enum — no
     *                            backing value exists to represent it as data, and the refusal
     *                            names the path; back the enum or keep it out of exported DTOs
     */
    public function toArray(): array
    {
        return self::hydrator()->extract($this);
    }

    /**
     * What `json_encode($dto)` writes: exactly {@see self::toArray()}'s array, so the JSON form
     * and the array form of one DTO cannot drift apart.
     *
     * @return array<string, mixed>
     *
     * @throws HydrationException
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Protected rather than private so {@see WithersTrait} can reach it — the trait declares it
     * abstract, which is what makes "this trait belongs on a DataTransferObject" a compile-time
     * requirement instead of a comment. Sharing this one instance is also what keeps imported
     * ADR-001's *one* metadata cache true: a trait building its own would quietly make two.
     */
    protected static function hydrator(): Hydrator
    {
        return self::$hydrator ??= new Hydrator(new ReflectionCache());
    }
}
