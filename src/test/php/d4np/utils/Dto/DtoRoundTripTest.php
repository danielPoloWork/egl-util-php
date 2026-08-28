<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto;

use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Tests\Dto\Fixture\BasketDto;
use D4np\Utils\Tests\Dto\Fixture\CompilableDto;
use D4np\Utils\Tests\Dto\Fixture\CustomerDto;
use D4np\Utils\Tests\Dto\Fixture\MixedDto;
use D4np\Utils\Tests\Dto\Fixture\NonDtoTypedDto;
use D4np\Utils\Tests\Dto\Fixture\OptionalsDto;
use D4np\Utils\Tests\Dto\Fixture\OrderDto;
use D4np\Utils\Tests\Dto\Fixture\ScalarsDto;
use D4np\Utils\Tests\Dto\Fixture\StatusesDto;
use D4np\Utils\Tests\Dto\Fixture\TagsDto;
use D4np\Utils\Tests\Dto\Fixture\TicketDto;
use D4np\Utils\Tests\Dto\Fixture\UserDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The FR-51 contract itself: `X::fromArray($x->toArray()) == $x` for every `$x` the hydrator
 * can produce, across the whole T-01 matrix (spec r32, ADR-0086 §1).
 *
 * The corpus deliberately includes the compiled-path classes ({@see ScalarsDto},
 * {@see CompilableDto}): export has no compiled variant, so these are the cases where compiled
 * hydration meets interpreted export and the two must still compose into the identity.
 */
#[Group('T-01')]
final class DtoRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<DataTransferObject>, array<string, mixed>}>
     */
    public static function matrix(): iterable
    {
        yield 'flat scalars' => [UserDto::class, ['email' => 'a@b.c', 'name' => 'Ada']];
        yield 'all builtin kinds, compiled-eligible' => [CompilableDto::class, [
            'name' => 'n', 'count' => 3, 'ratio' => 0.5, 'active' => true, 'note' => null,
        ]];
        yield 'scalars plus a plain array, passed untouched' => [ScalarsDto::class, [
            'i' => 1, 'f' => 2.5, 's' => 'x', 'b' => false, 'a' => ['nested' => ['deep' => 1]],
        ]];
        yield 'one level of nesting' => [CustomerDto::class, [
            'name' => 'Ada', 'address' => ['street' => 'Main 1', 'postcode' => '12345'],
        ]];
        yield 'two levels of nesting' => [OrderDto::class, [
            'reference' => 'R-1',
            'customer' => ['name' => 'Ada', 'address' => ['street' => 'Main 1', 'postcode' => '12345']],
        ]];
        yield 'a collection of nested DTOs' => [BasketDto::class, [
            'label' => 'route',
            'stops' => [['street' => 'A', 'postcode' => '1'], ['street' => 'B', 'postcode' => '2']],
        ]];
        yield 'an attribute-less collection of scalars' => [TagsDto::class, ['tags' => ['a', 'b', 'c']]];
        yield 'a collection of backed enums, hydrated from values' => [StatusesDto::class, [
            'statuses' => ['active', 'inactive'],
        ]];
        yield 'backed enums with defaults omitted' => [TicketDto::class, [
            'title' => 'T', 'status' => 'active',
        ]];
        yield 'backed enums with every optional supplied' => [TicketDto::class, [
            'title' => 'T', 'status' => 'inactive', 'priority' => 2, 'direction' => null,
        ]];
        yield 'optionals: nullable, defaulted, both' => [OptionalsDto::class, ['required' => 'r']];
        yield 'mixed holding a scalar' => [MixedDto::class, ['anything' => 42]];
        yield 'an opaque class instance' => [NonDtoTypedDto::class, [
            'at' => new \DateTimeImmutable('2026-08-28T12:00:00Z'),
        ]];
    }

    /**
     * @param class-string<DataTransferObject> $class
     * @param array<string, mixed>             $payload
     */
    #[DataProvider('matrix')]
    public function testFromArrayOfToArrayReconstructsTheObject(string $class, array $payload): void
    {
        $original = $class::fromArray($payload);
        $rebuilt = $class::fromArray($original->toArray());

        self::assertEquals($original, $rebuilt);
        self::assertSame($original::class, $rebuilt::class);
    }

    /**
     * The second application is a fixed point: once exported and rebuilt, exporting again yields
     * the identical array — so repeated round trips cannot accumulate drift.
     *
     * @param class-string<DataTransferObject> $class
     * @param array<string, mixed>             $payload
     */
    #[DataProvider('matrix')]
    public function testTheRoundTripIsAFixedPoint(string $class, array $payload): void
    {
        $original = $class::fromArray($payload);
        $exported = $original->toArray();

        self::assertEquals($exported, $class::fromArray($exported)->toArray());
    }
}
