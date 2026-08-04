<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto;

use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Tests\Dto\Fixture\Direction;
use D4np\Utils\Tests\Dto\Fixture\Priority;
use D4np\Utils\Tests\Dto\Fixture\Status;
use D4np\Utils\Tests\Dto\Fixture\TicketDto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hydrating enum-typed properties — the "enums" column of spec §7's T-01 matrix, the one case
 * the DTO/withers/collection items had not yet exercised.
 *
 * Only **backed** enums are hydrated from a scalar. A pure (`UnitEnum`) case has no backing
 * value to key from — `Status::cases()` gives names, not a lookup — so it stays instance-only,
 * which is already covered by the general "already the right object" pass-through.
 */
#[Group('T-01')]
final class EnumHydrationTest extends TestCase
{
    public function testAStringBackedEnumHydratesFromItsBackingValue(): void
    {
        $ticket = TicketDto::fromArray(['title' => 'Fix bug', 'status' => 'active']);

        self::assertSame(Status::Active, $ticket->status);
    }

    public function testAnIntBackedEnumHydratesFromItsBackingValue(): void
    {
        // A second backed type, so the claim rests on more than one fixture: proves the branch
        // checks BackedEnum generically rather than happening to work for strings.
        $ticket = TicketDto::fromArray(['title' => 'Fix bug', 'status' => 'active', 'priority' => 2]);

        self::assertSame(Priority::High, $ticket->priority);
    }

    public function testAnAlreadyConstructedEnumInstanceIsPassedThrough(): void
    {
        $ticket = TicketDto::fromArray(['title' => 'Fix bug', 'status' => Status::Inactive]);

        self::assertSame(Status::Inactive, $ticket->status);
    }

    public function testAnAbsentDefaultedEnumParameterKeepsItsDefault(): void
    {
        $ticket = TicketDto::fromArray(['title' => 'Fix bug', 'status' => 'active']);

        self::assertSame(Priority::Low, $ticket->priority);
    }

    public function testAnInvalidBackingValueIsATypeMismatchNamingTheValidOnes(): void
    {
        try {
            TicketDto::fromArray(['title' => 'Fix bug', 'status' => 'archived']);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('status', $e->path());
            self::assertStringContainsString('active', $e->getMessage());
            self::assertStringContainsString('inactive', $e->getMessage());
        }
    }

    public function testANonScalarValueForABackedEnumIsATypeMismatch(): void
    {
        $this->expectException(TypeMismatchException::class);

        TicketDto::fromArray(['title' => 'Fix bug', 'status' => ['active']]);
    }

    /**
     * The distinguishing case: a PURE enum given its backing-shaped value (a case name as a
     * string) is still rejected, because `Direction` has no `tryFrom()` to resolve it with —
     * only an already-constructed `Direction` instance is accepted.
     */
    public function testAPureEnumIsNotHydratedFromAStringAndStaysInstanceOnly(): void
    {
        try {
            TicketDto::fromArray(['title' => 'Fix bug', 'status' => 'active', 'direction' => 'Up']);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('direction', $e->path());
        }

        $ticket = TicketDto::fromArray(['title' => 'Fix bug', 'status' => 'active', 'direction' => Direction::Up]);
        self::assertSame(Direction::Up, $ticket->direction);
    }
}
