<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto;

use D4np\Utils\Dto\HydrationCompiler;
use D4np\Utils\Dto\Hydrator;
use D4np\Utils\Support\HydrationException;
use D4np\Utils\Support\ReflectionCache;
use D4np\Utils\Tests\Dto\Fixture\CompilableDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The compiled fast path (ADR-0013) and the interpreter must be indistinguishable from outside.
 *
 * This is the test that makes the fast path safe to have at all. Item 3.7 bought NFR-01's budget
 * by giving the hydrator a *second* implementation for one narrow shape; the standing risk of any
 * such split is that the two drift and a payload starts behaving differently depending on which
 * one claimed it. So rather than testing the compiled path against hand-written expectations —
 * which would only prove it matches what the author of *this* file believed — every case below is
 * run through both paths and the two results are compared with each other.
 *
 * The interpreter is reached by handing {@see Hydrator} a compiler constructed with
 * `enabled: false`, which declines every class.
 */
#[Group('T-01')]
final class HydrationParityTest extends TestCase
{
    private function compiling(): Hydrator
    {
        return new Hydrator(new ReflectionCache(), new HydrationCompiler(true));
    }

    private function interpreting(): Hydrator
    {
        return new Hydrator(new ReflectionCache(), new HydrationCompiler(false));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function payloads(): iterable
    {
        $valid = ['name' => 'a', 'count' => 1, 'ratio' => 1.5, 'active' => true, 'note' => 'n'];

        yield 'all present, strict' => [$valid, false];
        yield 'all present, lenient' => [$valid, true];
        yield 'explicit null in nullable' => [['name' => 'a', 'count' => 1, 'ratio' => 1.5, 'active' => true, 'note' => null], false];
        yield 'nullable key absent (R-4)' => [['name' => 'a', 'count' => 1, 'ratio' => 1.5, 'active' => true], false];
        yield 'int widening into float' => [['name' => 'a', 'count' => 1, 'ratio' => 2, 'active' => true, 'note' => null], false];

        // Failure cases: the two paths must fail the same way, not merely both fail.
        yield 'missing required key' => [['count' => 1, 'ratio' => 1.5, 'active' => true, 'note' => null], false];
        yield 'wrong scalar type' => [['name' => 'a', 'count' => 'not-an-int', 'ratio' => 1.5, 'active' => true, 'note' => null], false];
        yield 'null into non-nullable' => [['name' => null, 'count' => 1, 'ratio' => 1.5, 'active' => true, 'note' => null], false];
        yield 'unknown key, strict' => [$valid + ['extra' => 1], false];
        yield 'unknown key, lenient' => [$valid + ['extra' => 1], true];
        yield 'unknown key AND missing key' => [['count' => 1, 'ratio' => 1.5, 'active' => true, 'note' => null, 'extra' => 1], false];
        yield 'float given for int' => [['name' => 'a', 'count' => 1.5, 'ratio' => 1.5, 'active' => true, 'note' => null], false];
        yield 'string given for bool' => [['name' => 'a', 'count' => 1, 'ratio' => 1.5, 'active' => 'yes', 'note' => null], false];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloads')]
    public function testCompiledAndInterpretedAgree(array $payload, bool $lenient): void
    {
        $compiled = self::outcomeOf(fn (): object => $this->compiling()->hydrate(CompilableDto::class, $payload, $lenient));
        $interpreted = self::outcomeOf(fn (): object => $this->interpreting()->hydrate(CompilableDto::class, $payload, $lenient));

        self::assertSame(
            $interpreted,
            $compiled,
            'the compiled fast path and the interpreter disagreed on this payload',
        );
    }

    /**
     * A comparable description of what a hydration did — the constructed state, or the exception
     * class, message and path. Compared as data so a difference in *any* of those shows up as a
     * failed assertion rather than only a difference in success/failure.
     *
     * @param callable(): object $run
     *
     * @return array<string, mixed>
     */
    private static function outcomeOf(callable $run): array
    {
        try {
            $dto = $run();
        } catch (Throwable $e) {
            return [
                'outcome' => 'threw',
                'class' => $e::class,
                'message' => $e->getMessage(),
                'path' => $e instanceof HydrationException ? $e->path() : null,
            ];
        }

        self::assertInstanceOf(CompilableDto::class, $dto);

        return [
            'outcome' => 'built',
            'name' => $dto->name,
            'count' => $dto->count,
            'ratio' => $dto->ratio,
            'active' => $dto->active,
            'note' => $dto->note,
        ];
    }

    public function testTheFixtureIsActuallyOnTheCompiledPath(): void
    {
        // Without this, every assertion above could be comparing the interpreter with itself and
        // still pass — the test would be green and prove nothing.
        $meta = (new ReflectionCache())->for(CompilableDto::class);

        self::assertNotNull(
            (new HydrationCompiler(true))->compile($meta),
            'CompilableDto is no longer eligible for compilation, so the parity cases above are '
            . 'comparing the interpreter with itself and are no longer testing anything.',
        );
    }

    public function testDisabledCompilerDeclinesAnEligibleClass(): void
    {
        $meta = (new ReflectionCache())->for(CompilableDto::class);

        self::assertNull((new HydrationCompiler(false))->compile($meta));
    }
}
