<?php

declare(strict_types=1);

namespace D4np\Utils\Dto;

use D4np\Utils\Support\HydrationException;

/**
 * A hydration bound to one DTO class, configured to ignore unknown keys.
 *
 * The object {@see DataTransferObject::lenient()} returns, so the opt-out reads as the spec's
 * example writes it:
 *
 * ```php
 * $dto = UserDto::lenient()->fromArray($payload);
 * ```
 *
 * It exists as its own type rather than as a boolean argument to `fromArray()` because the
 * call site then says which policy is in force without the reader having to remember what a
 * bare `true` meant.
 *
 * @template T of DataTransferObject
 */
final class Hydration
{
    /**
     * @param class-string<T> $class
     */
    public function __construct(
        private readonly string $class,
        private readonly Hydrator $hydrator,
    ) {
    }

    /**
     * Hydrate the bound class, ignoring keys it does not declare.
     *
     * Lenient relaxes what may *arrive*, not what must be *present*: a required key that is
     * absent is still a {@see \D4np\Utils\Support\MissingKeyException} here, exactly as in
     * strict mode (RFC-0001 R-4).
     *
     * @param array<string, mixed> $data
     *
     * @return T
     *
     * @throws HydrationException
     */
    public function fromArray(array $data): DataTransferObject
    {
        return $this->hydrator->hydrate($this->class, $data, true);
    }
}
