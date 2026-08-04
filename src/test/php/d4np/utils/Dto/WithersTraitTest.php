<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto;

use D4np\Utils\Support\HydrationException;
use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Support\UnknownKeyException;
use D4np\Utils\Tests\Dto\Fixture\AddressDto;
use D4np\Utils\Tests\Dto\Fixture\WitherNestedDto;
use D4np\Utils\Tests\Dto\Fixture\WitherPrivateDto;
use D4np\Utils\Tests\Dto\Fixture\WitherUnreadableDto;
use D4np\Utils\Tests\Dto\Fixture\WitherUserDto;
use D4np\Utils\Tests\Dto\Fixture\WitherValidatingDto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `WithersTrait` — spec FR-02, ADR-0009. Part of **T-01**, the hydration matrix spec §7 names
 * (its "wither clones" column).
 *
 * The load-bearing property is immutability: `with()` must produce a *new* object and leave the
 * receiver untouched. Everything else follows from rebuilding through the constructor.
 */
#[Group('T-01')]
final class WithersTraitTest extends TestCase
{
    public function testWithReturnsANewObjectAndLeavesTheOriginalUntouched(): void
    {
        $user = WitherUserDto::fromArray(['email' => 'ada@example.com', 'name' => 'Ada']);

        $renamed = $user->with(name: 'Grace');

        self::assertNotSame($user, $renamed, 'with() must not mutate in place');
        self::assertSame('Ada', $user->name, 'the receiver is unchanged');
        self::assertSame('Grace', $renamed->name);
    }

    public function testUnnamedPropertiesKeepTheirCurrentValues(): void
    {
        $user = WitherUserDto::fromArray(['email' => 'ada@example.com', 'name' => 'Ada', 'age' => 36]);

        $renamed = $user->with(name: 'Grace');

        self::assertSame('ada@example.com', $renamed->email);
        self::assertSame(36, $renamed->age, 'a non-default current value survives, rather than resetting to the default');
    }

    public function testSeveralPropertiesCanChangeAtOnce(): void
    {
        $user = WitherUserDto::fromArray(['email' => 'ada@example.com', 'name' => 'Ada']);

        $changed = $user->with(name: 'Grace', email: 'grace@example.com');

        self::assertSame('Grace', $changed->name);
        self::assertSame('grace@example.com', $changed->email);
    }

    public function testWithNoChangesProducesAnEqualButDistinctObject(): void
    {
        $user = WitherUserDto::fromArray(['email' => 'ada@example.com', 'name' => 'Ada']);

        $copy = $user->with();

        self::assertNotSame($user, $copy);
        self::assertEquals($user, $copy);
    }

    public function testTheResultIsItselfWitherableSoCallsChain(): void
    {
        $user = WitherUserDto::fromArray(['email' => 'ada@example.com', 'name' => 'Ada']);

        $final = $user->with(name: 'Grace')->with(age: 45);

        self::assertSame('Grace', $final->name);
        self::assertSame(45, $final->age);
        self::assertSame('ada@example.com', $final->email);
    }

    public function testAPrivatePromotedPropertyIsReadBackCorrectly(): void
    {
        // The reason the current value is read through reflection rather than a property access:
        // the hydrator is not in the DTO's scope, and a promoted property may be private.
        $dto = WitherPrivateDto::fromArray(['secret' => 's3cret', 'label' => 'first']);

        $relabelled = $dto->with(label: 'second');

        self::assertSame('s3cret', $relabelled->secret(), 'the private value survived the rebuild');
        self::assertSame('second', $relabelled->label);
    }

    public function testANestedDtoIsCarriedAcrossUnchanged(): void
    {
        $dto = WitherNestedDto::fromArray([
            'name' => 'Ada',
            'address' => ['street' => '1 Main St', 'postcode' => 'AB1 2CD'],
        ]);

        $renamed = $dto->with(name: 'Grace');

        self::assertSame($dto->address, $renamed->address, 'the child object is carried, not rebuilt');
        self::assertSame('Grace', $renamed->name);
    }

    public function testANestedDtoCanItselfBeReplaced(): void
    {
        $dto = WitherNestedDto::fromArray([
            'name' => 'Ada',
            'address' => ['street' => '1 Main St', 'postcode' => 'AB1 2CD'],
        ]);
        $moved = AddressDto::fromArray(['street' => '2 Other Rd', 'postcode' => 'XY9 8ZW']);

        $result = $dto->with(address: $moved);

        self::assertSame($moved, $result->address);
        self::assertSame('Ada', $result->name);
    }

    /**
     * The argument for rebuilding over cloning that stands independently of PHP versions: the
     * constructor runs, so a DTO that validates there validates the wither's result too. A
     * clone-based wither bypasses the constructor and can produce an object the class itself
     * would have refused to build.
     */
    public function testConstructorValidationStillAppliesToTheResult(): void
    {
        $valid = WitherValidatingDto::fromArray(['email' => 'ada@example.com']);

        $this->expectException(\InvalidArgumentException::class);
        $valid->with(email: 'not-an-email');
    }

    public function testAnUndeclaredPropertyNameIsRejected(): void
    {
        $user = WitherUserDto::fromArray(['email' => 'ada@example.com', 'name' => 'Ada']);

        try {
            $user->with(role: 'admin');
            self::fail('expected an UnknownKeyException');
        } catch (UnknownKeyException $e) {
            self::assertSame('role', $e->path());
        }
    }

    public function testAWrongTypeIsRejected(): void
    {
        // with() is not a way around the type system: the same checks hydration applies, apply.
        $user = WitherUserDto::fromArray(['email' => 'ada@example.com', 'name' => 'Ada']);

        try {
            $user->with(age: 'not an int');
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('age', $e->path());
        }
    }

    public function testAParameterWithNoMatchingPropertyIsRefusedWithAnExplanation(): void
    {
        // Rebuilding requires every constructor parameter to be recoverable from the object.
        // A non-promoted parameter stored under a different name is not, and saying so beats
        // failing later with a confusing missing-key error.
        $dto = WitherUnreadableDto::fromArray(['incoming' => 'abc']);

        try {
            $dto->with();
            self::fail('expected a HydrationException');
        } catch (HydrationException $e) {
            self::assertStringContainsString('no property of the same name', $e->getMessage());
            self::assertSame('incoming', $e->path());
        }
    }

    // Not asserted here: that assigning to a readonly property throws. It is the constraint the
    // whole design works within, and it is worth stating why there is no test for it.
    //
    // PHPStan at max level rejects such an assignment wherever it appears
    // (`property.readOnlyAssignOutOfClass`), for every call site, permanently — a strictly
    // stronger guarantee than one runtime assertion. Writing the test needs the linter either
    // suppressed or dodged with a dynamic property name, and PHPStan resolves that dodge anyway.
    // Both were tried; both amount to disabling a real check to assert something it already
    // proves. The same reasoning retired an `assertNotInstanceOf` in item 2.1.
}
