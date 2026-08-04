<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto;

use D4np\Utils\Support\HydrationException;
use D4np\Utils\Support\MissingKeyException;
use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Support\UnknownKeyException;
use D4np\Utils\Support\UtilsThrowable;
use D4np\Utils\Tests\Dto\Fixture\AddressDto;
use D4np\Utils\Tests\Dto\Fixture\CustomerDto;
use D4np\Utils\Tests\Dto\Fixture\MixedDto;
use D4np\Utils\Tests\Dto\Fixture\NoConstructorDto;
use D4np\Utils\Tests\Dto\Fixture\NonDtoTypedDto;
use D4np\Utils\Tests\Dto\Fixture\OptionalsDto;
use D4np\Utils\Tests\Dto\Fixture\OrderDto;
use D4np\Utils\Tests\Dto\Fixture\ScalarsDto;
use D4np\Utils\Tests\Dto\Fixture\UserDto;
use D4np\Utils\Tests\Dto\Fixture\VariadicDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `DataTransferObject` — spec FR-01, and **T-01**, the hydration matrix spec §7 names:
 * nested DTOs, nullables, strict/lenient behaviour, and the missing-key cases RFC-0001 R-4
 * added at review.
 *
 * `#[Group('T-01')]` makes that suite runnable as the unit the spec names:
 * `vendor/bin/phpunit --group T-01`.
 */
#[Group('T-01')]
final class DataTransferObjectTest extends TestCase
{
    // ------------------------------------------------------------- the happy path

    public function testTheSpecExampleHydrates(): void
    {
        $user = UserDto::fromArray(['email' => 'ada@example.com', 'name' => 'Ada']);

        self::assertSame('ada@example.com', $user->email);
        self::assertSame('Ada', $user->name);
    }

    public function testKeyOrderInThePayloadDoesNotMatter(): void
    {
        // Construction goes through named arguments, so declaration order is irrelevant to the
        // caller — asserted rather than assumed, since a positional implementation would fail
        // here while passing every other test in this file.
        $user = UserDto::fromArray(['name' => 'Ada', 'email' => 'ada@example.com']);

        self::assertSame('ada@example.com', $user->email);
        self::assertSame('Ada', $user->name);
    }

    public function testAClassWithNoConstructorHydratesFromAnEmptyPayload(): void
    {
        self::assertInstanceOf(NoConstructorDto::class, NoConstructorDto::fromArray([]));
    }

    // -------------------------------------------------------------- strict mode

    public function testStrictModeRejectsAnUndeclaredKey(): void
    {
        try {
            UserDto::fromArray(['email' => 'a@b.c', 'name' => 'Ada', 'role' => 'admin']);
            self::fail('expected an UnknownKeyException');
        } catch (UnknownKeyException $e) {
            self::assertSame('role', $e->path());
            self::assertStringContainsString('role', $e->getMessage());
            self::assertStringContainsString('lenient()', $e->getMessage(), 'the message names the opt-out');
        }
    }

    public function testStrictModeIsTheDefault(): void
    {
        // The mass-assignment guarantee: nobody has to opt IN to rejection.
        $this->expectException(UnknownKeyException::class);

        UserDto::fromArray(['email' => 'a@b.c', 'name' => 'Ada', 'isAdmin' => true]);
    }

    // ------------------------------------------------------------- lenient mode

    public function testLenientModeIgnoresUndeclaredKeys(): void
    {
        $user = UserDto::lenient()->fromArray([
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'role' => 'admin',
            'extra' => ['deeply', 'nested'],
        ]);

        self::assertSame('ada@example.com', $user->email);
        self::assertSame('Ada', $user->name);
    }

    /**
     * Lenient relaxes what may *arrive*, not what must be *present* (RFC-0001 R-4). This is the
     * assertion that stops `lenient()` from quietly becoming "skip all validation".
     */
    public function testLenientModeStillRequiresDeclaredKeys(): void
    {
        $this->expectException(MissingKeyException::class);

        UserDto::lenient()->fromArray(['email' => 'a@b.c', 'ignored' => 1]);
    }

    public function testLenientModeStillTypeChecks(): void
    {
        $this->expectException(TypeMismatchException::class);

        UserDto::lenient()->fromArray(['email' => 'a@b.c', 'name' => 42, 'extra' => true]);
    }

    public function testLenientIsPerCallAndDoesNotLeakIntoLaterStrictCalls(): void
    {
        UserDto::lenient()->fromArray(['email' => 'a@b.c', 'name' => 'Ada', 'role' => 'x']);

        // The shared hydrator is process-wide; the *mode* must not be.
        $this->expectException(UnknownKeyException::class);
        UserDto::fromArray(['email' => 'a@b.c', 'name' => 'Ada', 'role' => 'x']);
    }

    // ------------------------------------------------- RFC-0001 R-4: optionality

    public function testAMissingRequiredKeyThrows(): void
    {
        try {
            OptionalsDto::fromArray([]);
            self::fail('expected a MissingKeyException');
        } catch (MissingKeyException $e) {
            self::assertSame('required', $e->path());
            self::assertStringContainsString('lenient', $e->getMessage());
        }
    }

    public function testAnAbsentNullableHydratesToNull(): void
    {
        // PHP treats a nullable parameter WITHOUT a default as required — verified directly —
        // so R-4's "hydrates to null" only holds because null is passed explicitly.
        $dto = OptionalsDto::fromArray(['required' => 'x']);

        self::assertNull($dto->nullable);
    }

    public function testAnAbsentDefaultedParameterKeepsItsDeclaredDefault(): void
    {
        $dto = OptionalsDto::fromArray(['required' => 'x']);

        self::assertSame(42, $dto->defaulted, 'PHP applies the default because the argument is omitted');
        self::assertSame('preset', $dto->nullableAndDefaulted);
    }

    public function testAnExplicitNullBeatsADeclaredDefault(): void
    {
        // Present-and-null is not the same as absent: the caller said null, so null it is.
        $dto = OptionalsDto::fromArray(['required' => 'x', 'nullableAndDefaulted' => null]);

        self::assertNull($dto->nullableAndDefaulted);
    }

    public function testNullForANonNullableParameterIsATypeMismatchNotAMissingKey(): void
    {
        try {
            OptionalsDto::fromArray(['required' => null]);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('required', $e->path());
            self::assertStringContainsString('null', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ nesting

    public function testANestedDtoHydratesFromANestedArray(): void
    {
        $customer = CustomerDto::fromArray([
            'name' => 'Ada',
            'address' => ['street' => '1 Main St', 'postcode' => 'AB1 2CD'],
        ]);

        self::assertInstanceOf(AddressDto::class, $customer->address);
        self::assertSame('AB1 2CD', $customer->address->postcode);
    }

    public function testAnAlreadyBuiltNestedDtoIsPassedThrough(): void
    {
        // A caller assembling a graph by hand should not have to take it apart into arrays.
        $address = AddressDto::fromArray(['street' => '1 Main St', 'postcode' => 'AB1 2CD']);

        $customer = CustomerDto::fromArray(['name' => 'Ada', 'address' => $address]);

        self::assertSame($address, $customer->address);
    }

    /**
     * The reason `HydrationException` carries a path at all: in a graph, "expected string, got
     * int" is not actionable and `customer.address.postcode` is.
     */
    public function testAFailureDeepInTheGraphNamesTheFullPath(): void
    {
        try {
            OrderDto::fromArray([
                'reference' => 'ORD-1',
                'customer' => [
                    'name' => 'Ada',
                    'address' => ['street' => '1 Main St', 'postcode' => 12345],
                ],
            ]);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('customer.address.postcode', $e->path());
            self::assertStringContainsString('customer.address.postcode', $e->getMessage());
        }
    }

    public function testAMissingKeyDeepInTheGraphNamesTheFullPath(): void
    {
        try {
            OrderDto::fromArray([
                'reference' => 'ORD-1',
                'customer' => ['name' => 'Ada', 'address' => ['street' => '1 Main St']],
            ]);
            self::fail('expected a MissingKeyException');
        } catch (MissingKeyException $e) {
            self::assertSame('customer.address.postcode', $e->path());
        }
    }

    public function testAnUnknownKeyDeepInTheGraphNamesTheFullPath(): void
    {
        try {
            OrderDto::fromArray([
                'reference' => 'ORD-1',
                'customer' => [
                    'name' => 'Ada',
                    'address' => ['street' => 'S', 'postcode' => 'P', 'county' => 'X'],
                ],
            ]);
            self::fail('expected an UnknownKeyException');
        } catch (UnknownKeyException $e) {
            self::assertSame('customer.address.county', $e->path());
        }
    }

    public function testLeniencyPropagatesIntoNestedDtos(): void
    {
        $customer = CustomerDto::lenient()->fromArray([
            'name' => 'Ada',
            'unknownAtRoot' => 1,
            'address' => ['street' => 'S', 'postcode' => 'P', 'unknownNested' => 2],
        ]);

        self::assertSame('P', $customer->address->postcode);
    }

    public function testANestedDtoGivenAScalarIsATypeMismatch(): void
    {
        try {
            CustomerDto::fromArray(['name' => 'Ada', 'address' => 'not an array']);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('address', $e->path());
            self::assertStringContainsString('string', $e->getMessage());
        }
    }

    // -------------------------------------------------------------- type checks

    public function testScalarsHydrateWhenTheTypesMatch(): void
    {
        $dto = ScalarsDto::fromArray([
            'i' => 1, 'f' => 2.5, 's' => 'x', 'b' => true, 'a' => [1, 2],
        ]);

        self::assertSame(1, $dto->i);
        self::assertSame(2.5, $dto->f);
        self::assertTrue($dto->b);
    }

    public function testAnIntSatisfiesAFloatParameter(): void
    {
        // PHP widens int to float even under strict_types (verified), so refusing it here would
        // be stricter than the language and would reject a payload the constructor accepts.
        $dto = ScalarsDto::fromArray(['i' => 1, 'f' => 3, 's' => 'x', 'b' => false, 'a' => []]);

        self::assertSame(3.0, $dto->f);
    }

    public function testAFloatDoesNotSatisfyAnIntParameter(): void
    {
        // The other direction loses information, and PHP does not perform it under strict_types.
        $this->expectException(TypeMismatchException::class);

        ScalarsDto::fromArray(['i' => 1.5, 'f' => 1.0, 's' => 'x', 'b' => false, 'a' => []]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function mismatchedScalars(): iterable
    {
        $ok = ['i' => 1, 'f' => 1.0, 's' => 'x', 'b' => true, 'a' => []];

        yield 'string for int' => [['i' => '1'] + $ok, 'i'];
        yield 'int for string' => [['s' => 1] + $ok, 's'];
        yield 'string for bool' => [['b' => 'yes'] + $ok, 'b'];
        yield 'int for bool' => [['b' => 1] + $ok, 'b'];
        yield 'string for array' => [['a' => 'nope'] + $ok, 'a'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('mismatchedScalars')]
    public function testAMismatchedScalarNamesThePathAndBothTypes(array $payload, string $expectedPath): void
    {
        try {
            ScalarsDto::fromArray($payload);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame($expectedPath, $e->path());
        }
    }

    public function testAMixedParameterAcceptsAnything(): void
    {
        self::assertSame('s', MixedDto::fromArray(['anything' => 's'])->anything);
        self::assertSame([1], MixedDto::fromArray(['anything' => [1]])->anything);
        self::assertNull(MixedDto::fromArray(['anything' => null])->anything);
    }

    public function testANonDtoClassParameterAcceptsAnInstanceButNotAnArray(): void
    {
        $at = new \DateTimeImmutable('2026-08-04 10:00:00');

        self::assertSame($at, NonDtoTypedDto::fromArray(['at' => $at])->at);

        // An arbitrary class cannot be built from an array — only a DataTransferObject can.
        $this->expectException(TypeMismatchException::class);
        NonDtoTypedDto::fromArray(['at' => ['date' => '2026-08-04']]);
    }

    // ---------------------------------------------------------------- refusals

    public function testAVariadicConstructorIsRefusedRatherThanGuessedAt(): void
    {
        try {
            VariadicDto::fromArray(['rest' => ['a', 'b']]);
            self::fail('expected a HydrationException');
        } catch (HydrationException $e) {
            self::assertStringContainsString('variadic', $e->getMessage());
        }
    }

    public function testAVariadicConstructorHydratesWhenTheKeyIsAbsent(): void
    {
        self::assertSame([], VariadicDto::fromArray([])->rest);
    }

    // ------------------------------------------------------- exception contract

    public function testEveryHydrationFailureIsCatchableThroughTheLibraryMarker(): void
    {
        // ADR-0004's contract, and the reason construct() converts a bare TypeError: a consumer
        // catching everything this library raises must catch hydration failures too.
        foreach ([
            static fn () => UserDto::fromArray(['email' => 'a', 'name' => 'b', 'x' => 1]),
            static fn () => UserDto::fromArray(['email' => 'a']),
            static fn () => UserDto::fromArray(['email' => 'a', 'name' => 1]),
        ] as $call) {
            try {
                $call();
                self::fail('expected a hydration failure');
            } catch (UtilsThrowable $e) {
                self::assertInstanceOf(HydrationException::class, $e);
            }
        }
    }
}
