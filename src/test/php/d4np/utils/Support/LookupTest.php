<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Lookup;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

/**
 * `Lookup` — spec r3 FR-30 (RFC-0002).
 *
 * The contract is one policy split three ways: `label()` throws on a missing code,
 * `labelOr()` substitutes, `tryLabel()` returns `null` — none of the three invents a
 * sentinel *string*, which is the defect this class replaces (the surveyed estate's
 * `"missing: {$key}"` placeholder, indistinguishable from real data once it reaches a UI
 * or a CSV export).
 */
final class LookupTest extends TestCase
{
    private function make(): Lookup
    {
        return Lookup::fromArray([
            'BA1' => 'Bay 1',
            'BA2' => 'Bay 2 — generic',
        ]);
    }

    public function testLabelReturnsTheMappedValue(): void
    {
        self::assertSame('Bay 1', $this->make()->label('BA1'));
    }

    public function testLabelThrowsOnAMissingCode(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('No label for code "ZZZ".');

        $this->make()->label('ZZZ');
    }

    public function testLabelOrReturnsTheMappedValueWhenPresent(): void
    {
        self::assertSame('Bay 1', $this->make()->labelOr('BA1', 'fallback'));
    }

    public function testLabelOrReturnsTheDefaultWhenAbsent(): void
    {
        self::assertSame('fallback', $this->make()->labelOr('ZZZ', 'fallback'));
    }

    public function testTryLabelReturnsTheMappedValueWhenPresent(): void
    {
        self::assertSame('Bay 1', $this->make()->tryLabel('BA1'));
    }

    public function testTryLabelReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->make()->tryLabel('ZZZ'));
    }

    public function testHasIsTrueForAPresentCode(): void
    {
        self::assertTrue($this->make()->has('BA1'));
    }

    public function testHasIsFalseForAnAbsentCode(): void
    {
        self::assertFalse($this->make()->has('ZZZ'));
    }

    public function testCodesReturnsEveryCodeInInsertionOrder(): void
    {
        self::assertSame(['BA1', 'BA2'], $this->make()->codes());
    }

    public function testToArrayReturnsTheFullMap(): void
    {
        self::assertSame(
            ['BA1' => 'Bay 1', 'BA2' => 'Bay 2 — generic'],
            $this->make()->toArray(),
        );
    }

    public function testEmptyMapHasNoCodesAndAlwaysMisses(): void
    {
        $lookup = Lookup::fromArray([]);

        self::assertSame([], $lookup->codes());
        self::assertFalse($lookup->has('anything'));
        self::assertNull($lookup->tryLabel('anything'));
    }

    public function testAMappedEmptyStringLabelIsNotConfusedWithAbsence(): void
    {
        // array_key_exists(), not isset() / ??  — a code deliberately mapped to '' must be
        // distinguishable from a code that is not in the map at all.
        $lookup = Lookup::fromArray(['EMPTY' => '']);

        self::assertTrue($lookup->has('EMPTY'));
        self::assertSame('', $lookup->label('EMPTY'));
        self::assertSame('', $lookup->tryLabel('EMPTY'));
    }

    public function testConstructorIsEquivalentToFromArray(): void
    {
        self::assertSame('Bay 1', (new Lookup(['BA1' => 'Bay 1']))->label('BA1'));
    }
}
