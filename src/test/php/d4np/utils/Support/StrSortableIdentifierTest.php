<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\FrozenClock;
use D4np\Utils\Support\Str;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `Str::ulid()` and `Str::uuidV7()` — spec r18 FR-46 (RFC-0003), the time-sortable identifiers
 * (ADR-0063).
 *
 * Three things are worth pinning and one is worth pinning *as an absence*: the encodings conform
 * to their specifications, the timestamp survives a round trip, identifiers from different
 * milliseconds sort in time order — and identifiers from the *same* millisecond are **not**
 * guaranteed to, which is a decision rather than an oversight and therefore gets its own
 * assertions in both directions.
 */
final class StrSortableIdentifierTest extends TestCase
{
    /** The ULID specification's own worked example: this instant encodes to `01ARYZ6S41`. */
    private const SPEC_VECTOR_MS = 1469918176385;
    private const SPEC_VECTOR_PREFIX = '01ARYZ6S41';

    private const CROCKFORD_BASE32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function clockAt(int $milliseconds): FrozenClock
    {
        return new FrozenClock(
            (new DateTimeImmutable('@' . \intdiv($milliseconds, 1000)))
                ->modify('+' . ($milliseconds % 1000) . ' milliseconds'),
        );
    }

    /** The inverse of the production encoder — a decoder the production code does not own. */
    private function decodeUlidTimestamp(string $ulid): int
    {
        $milliseconds = 0;
        foreach (\str_split(\substr($ulid, 0, 10)) as $character) {
            $milliseconds = ($milliseconds << 5) | \strpos(self::CROCKFORD_BASE32, $character);
        }

        return $milliseconds;
    }

    private function decodeUuidV7Timestamp(string $uuid): int
    {
        return (int) \hexdec(\substr(\str_replace('-', '', $uuid), 0, 12));
    }

    // ---------------------------------------------------------------- format conformance

    public function testUlidIsTwentySixCrockfordCharacters(): void
    {
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', Str::ulid());
    }

    public function testUlidExcludesTheFourConfusableLetters(): void
    {
        // I, L, O and U are absent from Crockford's alphabet by design. Asserted over a corpus,
        // because any single identifier omits most letters by chance.
        $corpus = '';
        for ($i = 0; $i < 200; $i++) {
            $corpus .= Str::ulid();
        }

        foreach (['I', 'L', 'O', 'U'] as $excluded) {
            self::assertStringNotContainsString($excluded, $corpus);
        }
    }

    public function testUlidMatchesTheSpecificationsWorkedExample(): void
    {
        $ulid = Str::ulid($this->clockAt(self::SPEC_VECTOR_MS));

        self::assertSame(self::SPEC_VECTOR_PREFIX, \substr($ulid, 0, 10));
    }

    public function testUlidFirstCharacterNeverExceedsSeven(): void
    {
        // 26 characters carry 130 bits and a ULID is 128, so the leading two bits are always
        // zero — the property that makes '7ZZZZZZZZZ…' the largest well-formed ULID. A first
        // character above '7' would mean the timestamp had overflowed its 48 bits.
        $ulid = Str::ulid($this->clockAt(281474976710655));

        self::assertSame('7ZZZZZZZZZ', \substr($ulid, 0, 10));
    }

    public function testUuidV7CarriesVersionSevenAndTheRfcVariant(): void
    {
        $uuid = Str::uuidV7();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testUuidV7IsAcceptedWhereverAUuidIs(): void
    {
        // Same shape as Str::uuid()'s output: a consumer's UUID column, validator or cast cannot
        // tell the two apart, which is the whole reason to offer v7 alongside ULID.
        $shape = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

        self::assertMatchesRegularExpression($shape, Str::uuidV7());
        self::assertMatchesRegularExpression($shape, Str::uuid());
    }

    // ---------------------------------------------------------------- the timestamp survives

    public function testUlidTimestampRoundTrips(): void
    {
        $ulid = Str::ulid($this->clockAt(self::SPEC_VECTOR_MS));

        self::assertSame(self::SPEC_VECTOR_MS, $this->decodeUlidTimestamp($ulid));
    }

    public function testUuidV7TimestampRoundTrips(): void
    {
        $uuid = Str::uuidV7($this->clockAt(self::SPEC_VECTOR_MS));

        self::assertSame(self::SPEC_VECTOR_MS, $this->decodeUuidV7Timestamp($uuid));
    }

    public function testGeneratedTimestampsTrackTheSystemClock(): void
    {
        $before = (int) (\microtime(true) * 1000);
        $decoded = $this->decodeUlidTimestamp(Str::ulid());
        $after = (int) (\microtime(true) * 1000);

        // Bounded on both sides: a clock read from anything but the current time fails here,
        // and so does a timestamp field left at zero.
        self::assertGreaterThanOrEqual($before - 1000, $decoded);
        self::assertLessThanOrEqual($after + 1000, $decoded);
    }

    // ---------------------------------------------------------------- sortability

    public function testUlidsFromDifferentMillisecondsSortInTimeOrder(): void
    {
        $clock = $this->clockAt(self::SPEC_VECTOR_MS);

        $identifiers = [];
        for ($i = 0; $i < 25; $i++) {
            $identifiers[] = Str::ulid($clock);
            $clock->advance(new DateInterval('PT1S'));
        }

        $sorted = $identifiers;
        \sort($sorted, \SORT_STRING);

        // Lexicographic order equals generation order — the property the format exists for, and
        // the reason Crockford's alphabet must ascend in ASCII.
        self::assertSame($identifiers, $sorted);
    }

    public function testUuidV7sFromDifferentMillisecondsSortInTimeOrder(): void
    {
        $clock = $this->clockAt(self::SPEC_VECTOR_MS);

        $identifiers = [];
        for ($i = 0; $i < 25; $i++) {
            $identifiers[] = Str::uuidV7($clock);
            $clock->advance(new DateInterval('PT1S'));
        }

        $sorted = $identifiers;
        \sort($sorted, \SORT_STRING);

        self::assertSame($identifiers, $sorted);
    }

    public function testOneMillisecondIsEnoughToOrderTwoIdentifiers(): void
    {
        // Millisecond granularity, not second — the resolution the format actually stores, and
        // what the index-locality argument rests on. A second-resolution timestamp would pass
        // every other sorting test in this class and fail this one.
        $oneMillisecond = new DateInterval('PT0S');
        $oneMillisecond->f = 0.001;

        $clock = $this->clockAt(self::SPEC_VECTOR_MS);
        $first = Str::ulid($clock);
        $clock->advance($oneMillisecond);
        $second = Str::ulid($clock);

        self::assertLessThan($second, $first);
        self::assertNotSame(\substr($first, 0, 10), \substr($second, 0, 10));
    }

    // ------------------------------------------- the non-guarantee, pinned in both directions

    public function testIdentifiersInTheSameMillisecondShareTheirTimestampAndDifferAfterIt(): void
    {
        $clock = $this->clockAt(self::SPEC_VECTOR_MS);

        $identifiers = [];
        for ($i = 0; $i < 50; $i++) {
            $identifiers[] = Str::ulid($clock);
        }

        $prefixes = \array_unique(\array_map(static fn (string $u): string => \substr($u, 0, 10), $identifiers));
        self::assertCount(1, $prefixes, 'One frozen millisecond must produce one timestamp prefix.');

        // Distinct despite the shared prefix: 80 bits of entropy per identifier, so a collision
        // here would mean the random tail was not random.
        self::assertCount(50, \array_unique($identifiers));
    }

    public function testTheClassHoldsNoStateThatCouldMakeGenerationMonotonic(): void
    {
        // The DECISION, asserted as a mechanism (ADR-0027): intra-millisecond monotonicity is out
        // of scope, and guaranteeing it would require remembering the previous call. Behaviour
        // cannot see the difference — a monotonic implementation passes every other test in this
        // class — so the absence of state is what gets pinned. Adding a counter to make ULIDs
        // monotonic turns this red, which forces the spec change the decision would need.
        self::assertSame(
            [],
            (new ReflectionClass(Str::class))->getProperties(),
            'Str must remain stateless: a property here could carry monotonicity FR-46 excludes.',
        );
    }

    // ---------------------------------------------------------------- refusals

    public function testUlidRefusesAnInstantBeforeTheUnixEpoch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Str::ulid\(\)/');

        Str::ulid(new FrozenClock(new DateTimeImmutable('1969-12-31T23:59:59Z')));
    }

    public function testUuidV7RefusesAnInstantBeforeTheUnixEpoch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Str::uuidV7\(\)/');

        Str::uuidV7(new FrozenClock(new DateTimeImmutable('1969-12-31T23:59:59Z')));
    }

    public function testTheEpochItselfIsAccepted(): void
    {
        // The boundary is inclusive on the low side — 0 ms is representable, and refusing it
        // would be an off-by-one nobody would notice until a test fixture used it.
        self::assertSame('0000000000', \substr(Str::ulid($this->clockAt(0)), 0, 10));
    }

    public function testAnInstantBeyondFortyEightBitsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::ulid($this->clockAt(281474976710656));
    }

    public function testTheLastRepresentableInstantIsAccepted(): void
    {
        // The high boundary is inclusive too, and this is the case that proves the refusal above
        // is an overflow guard rather than an off-by-one.
        self::assertSame(
            281474976710655,
            $this->decodeUlidTimestamp(Str::ulid($this->clockAt(281474976710655))),
        );
    }

    // ---------------------------------------------------------------- the clock seam

    public function testPassingNoClockReadsTheSystemClock(): void
    {
        $frozen = $this->decodeUlidTimestamp(Str::ulid($this->clockAt(self::SPEC_VECTOR_MS)));
        $live = $this->decodeUlidTimestamp(Str::ulid());

        // The injected clock is honoured, and its absence is not silently the same thing.
        self::assertSame(self::SPEC_VECTOR_MS, $frozen);
        self::assertNotSame(self::SPEC_VECTOR_MS, $live);
    }
}
