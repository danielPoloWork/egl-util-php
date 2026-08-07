<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use OutOfBoundsException;

/**
 * An immutable code → label map with an **explicit** missing-key policy (spec r3 FR-30,
 * RFC-0002).
 *
 * The pattern this replaces: the surveyed estate's enum-backed dictionaries returned a
 * placeholder string — `"missing: {$key}"` — for an absent code, indistinguishable from a
 * real label once it reaches a UI or a CSV export. `Lookup` makes the caller choose, at the
 * call site, which failure mode it wants: {@see self::label()} throws, {@see self::labelOr()}
 * substitutes a caller-supplied default, {@see self::tryLabel()} returns `null`. None of the
 * three invents a sentinel *string* that could be mistaken for data.
 */
final class Lookup
{
    /**
     * @var array<string, string>
     */
    private readonly array $entries;

    /**
     * @param array<string, string> $entries code => label pairs
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * @param array<string, string> $entries code => label pairs
     */
    public static function fromArray(array $entries): self
    {
        return new self($entries);
    }

    /**
     * The label for `$code`.
     *
     * @throws OutOfBoundsException if `$code` is not in the map — the explicit failure this
     *                              class exists to force; catch it, or use {@see self::labelOr()}
     *                              / {@see self::tryLabel()} for a tolerant read instead.
     */
    public function label(string $code): string
    {
        if (!\array_key_exists($code, $this->entries)) {
            throw new OutOfBoundsException(\sprintf('No label for code "%s".', $code));
        }

        return $this->entries[$code];
    }

    /**
     * The label for `$code`, or `$default` when `$code` is absent.
     */
    public function labelOr(string $code, string $default): string
    {
        return $this->entries[$code] ?? $default;
    }

    /**
     * The label for `$code`, or `null` when `$code` is absent.
     */
    public function tryLabel(string $code): ?string
    {
        return $this->entries[$code] ?? null;
    }

    /**
     * Whether `$code` has a label in this map.
     */
    public function has(string $code): bool
    {
        return \array_key_exists($code, $this->entries);
    }

    /**
     * Every code this map carries, in insertion order.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return \array_keys($this->entries);
    }

    /**
     * The full code => label map, as a plain array — for a caller that genuinely needs to
     * iterate or serialize the whole set rather than look up one code.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->entries;
    }
}
