<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\ApiEnvelope;
use D4np\Utils\Http\Outcome;
use D4np\Utils\Support\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `ApiEnvelope` — spec r3 **FR-39** (RFC-0002), ADR-0051.
 *
 * The envelope's value is that a client can rely on its shape, so the shape is what these tests
 * pin: every key present on every outcome, `data` never dropped for being `null`, and the
 * outcome→status mapping asserted case by case rather than trusted to a `match` nobody reads.
 */
final class ApiEnvelopeTest extends TestCase
{
    /**
     * The whole point of an envelope: four keys, always, whatever happened.
     */
    #[DataProvider('everyOutcome')]
    public function testTheShapeIsTheSameForEveryOutcome(ApiEnvelope $envelope): void
    {
        $serialized = $envelope->jsonSerialize();

        self::assertSame(['status', 'code', 'messages', 'data'], \array_keys($serialized));
        self::assertIsString($serialized['status']);
        self::assertIsInt($serialized['code']);
        self::assertIsArray($serialized['messages']);
    }

    /**
     * @return iterable<string, array{ApiEnvelope}>
     */
    public static function everyOutcome(): iterable
    {
        yield 'ok' => [ApiEnvelope::ok(['id' => 1])];
        yield 'ok with no data' => [ApiEnvelope::ok()];
        yield 'created' => [ApiEnvelope::created(['id' => 1])];
        yield 'updated' => [ApiEnvelope::updated(['id' => 1])];
        yield 'deleted' => [ApiEnvelope::deleted()];
        yield 'empty' => [ApiEnvelope::empty()];
        yield 'invalid' => [ApiEnvelope::invalid(['email is required'])];
        yield 'not found' => [ApiEnvelope::notFound('no such order')];
        yield 'failed' => [ApiEnvelope::failed('the warehouse refused it')];
        yield 'caught' => [ApiEnvelope::caught('ref-123')];
    }

    /**
     * `null` data must survive serialization as an explicit `null`, not vanish.
     *
     * This is the assertion that stops someone "tidying up" the payload with a filter: the key
     * disappearing is invisible in PHP and breaks every client that reads `payload.data`
     * without a guard.
     */
    public function testNullDataStaysInTheJsonAsNull(): void
    {
        $json = Json::encode(ApiEnvelope::notFound('no such order'));

        self::assertStringContainsString('"data":null', $json);
        self::assertSame(
            ['status' => 'notFound', 'code' => 404, 'messages' => ['no such order'], 'data' => null],
            Json::decode($json),
        );
    }

    public function testAnEmptyMessageListIsStillAList(): void
    {
        // [] must encode as `[]`, not `{}` — a client typed against an array of strings breaks
        // on an object, and PHP's empty array is ambiguous until something pins it.
        self::assertStringContainsString('"messages":[]', Json::encode(ApiEnvelope::ok()));
    }

    // ---- the outcome → status mapping, case by case ------------------------------------------

    #[DataProvider('statuses')]
    public function testEachOutcomeCarriesItsHttpStatus(Outcome $outcome, int $expected, bool $successful): void
    {
        self::assertSame($expected, $outcome->httpStatus());
        self::assertSame($successful, $outcome->isSuccessful());
    }

    /**
     * @return iterable<string, array{Outcome, int, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'ok' => [Outcome::Ok, 200, true];
        yield 'created' => [Outcome::Created, 201, true];
        yield 'updated' => [Outcome::Updated, 200, true];
        yield 'deleted' => [Outcome::Deleted, 200, true];
        yield 'empty is a successful search, not a 404' => [Outcome::Empty, 200, true];
        yield 'invalid is 422, not 400' => [Outcome::Invalid, 422, false];
        yield 'not found' => [Outcome::NotFound, 404, false];
        yield 'failed' => [Outcome::Failed, 409, false];
        yield 'caught' => [Outcome::Caught, 500, false];
    }

    public function testTheEnvelopeReportsTheStatusOfItsOutcome(): void
    {
        self::assertSame(201, ApiEnvelope::created()->status());
        self::assertTrue(ApiEnvelope::created()->isSuccessful());
        self::assertSame(422, ApiEnvelope::invalid(['bad'])->status());
        self::assertFalse(ApiEnvelope::invalid(['bad'])->isSuccessful());
    }

    /**
     * Every case must appear in both `match` expressions, or PHP throws `UnhandledMatchError`
     * at run time for the one that was forgotten. Iterating the enum is what makes adding a
     * tenth outcome fail here rather than in production.
     */
    public function testEveryEnumCaseIsMappedByBothMatches(): void
    {
        // Calling each accessor for every case is the assertion: a case missing from either
        // `match` raises `UnhandledMatchError` here. What is *asserted* is the partition, not the
        // return type — `assertIsBool()` on a `bool` return is a tautology PHPStan can decide
        // (and did), while the 5/4 split is a fact about the taxonomy that a wrong edit changes.
        $successful = \array_filter(Outcome::cases(), static fn (Outcome $c): bool => $c->isSuccessful());
        $failing = \array_filter(Outcome::cases(), static fn (Outcome $c): bool => !$c->isSuccessful());

        self::assertCount(5, $successful, 'ok, created, updated, deleted, empty');
        self::assertCount(4, $failing, 'invalid, notFound, failed, caught');

        foreach (Outcome::cases() as $case) {
            self::assertGreaterThanOrEqual(200, $case->httpStatus());
        }

        self::assertCount(9, Outcome::cases(), 'the taxonomy is FR-39\'s, and adding to it is a spec change');
    }

    // ---- what the envelope must not do -------------------------------------------------------

    /**
     * ADR-0051's security decision, asserted as a **mechanism**: `caught()` must not be able to
     * accept a `Throwable`, because an envelope built from one would put `getMessage()` on the
     * wire — and a message names schemas and paths as readily as a trace does (ADR-0029).
     *
     * Asserted on the signature rather than on behaviour, because behaviour cannot see an
     * overload that does not exist: a future `caught(Throwable $e)` would pass every other test
     * in this file.
     */
    public function testCaughtCannotBeHandedAThrowable(): void
    {
        $parameters = (new ReflectionMethod(ApiEnvelope::class, 'caught'))->getParameters();

        self::assertSame('reference', $parameters[0]->getName());
        self::assertSame('string', (string) $parameters[0]->getType());

        foreach ($parameters as $parameter) {
            $type = (string) $parameter->getType();
            self::assertStringNotContainsString('Throwable', $type);
            self::assertStringNotContainsString('Exception', $type);
        }
    }

    public function testCaughtCarriesTheReferenceAndAGenericMessage(): void
    {
        $envelope = ApiEnvelope::caught('ref-abc');

        self::assertSame(['reference' => 'ref-abc'], $envelope->data);
        self::assertSame(['An unexpected error occurred.'], $envelope->messages);
        self::assertSame(500, $envelope->status());
    }

    public function testCaughtLetsTheCallerReplaceTheWording(): void
    {
        // The library supplies no catalogue; a localized application overrides the default.
        self::assertSame(['Errore imprevisto.'], ApiEnvelope::caught('ref', 'Errore imprevisto.')->messages);
    }

    /**
     * The envelope is a value: no setters, and the constructor is private so every instance
     * comes from a named outcome rather than an arbitrary combination.
     */
    public function testItIsAValueWithNoOtherWayIn(): void
    {
        $reflected = new ReflectionClass(ApiEnvelope::class);

        self::assertTrue($reflected->getConstructor()?->isPrivate());
        self::assertTrue($reflected->isFinal());

        foreach ($reflected->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertStringStartsNotWith('set', $method->getName());
        }
    }

    public function testTheLibrarySuppliesNoWordingOfItsOwnExceptForTheCaughtFallback(): void
    {
        // Localization is the application's (FR-39). The only string this library writes is the
        // one a caught() with no message would otherwise leave empty, and it says nothing about
        // the failure.
        self::assertSame([], ApiEnvelope::ok()->messages);
        self::assertSame([], ApiEnvelope::deleted()->messages);
        self::assertSame([], ApiEnvelope::notFound()->messages);
        self::assertSame(['An unexpected error occurred.'], ApiEnvelope::caught('r')->messages);
    }
}
