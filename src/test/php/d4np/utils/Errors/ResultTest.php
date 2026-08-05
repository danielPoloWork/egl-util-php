<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors;

use D4np\Utils\Errors\Result;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Support\UtilsException;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-16's `Result`.
 *
 * RFC-0001's reason for it: a `false` says nothing about what went wrong, a `null` cannot be told
 * apart from a legitimately absent value, and both are silently ignorable. So the assertions worth
 * making are the ones about what a `Result` refuses to let you ignore.
 */
final class ResultTest extends TestCase
{
    // ---- construction ----------------------------------------------------------------------------

    public function testASuccessHoldsItsValue(): void
    {
        $result = Result::success(42);

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->isFailure());
        self::assertSame(42, $result->orElseThrow());
        self::assertNull($result->error());
    }

    public function testAFailureHoldsItsThrowable(): void
    {
        $error = new UtilsException('nope');
        $result = Result::failure($error);

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->isFailure());
        self::assertSame($error, $result->error());
    }

    /**
     * `null` and `false` are ordinary success values. A `Result` says whether the operation
     * succeeded; it does not also opine on whether the answer was interesting — which is the exact
     * conflation it exists to remove.
     */
    public function testNullAndFalseAreLegitimateSuccessValues(): void
    {
        self::assertTrue(Result::success(null)->isSuccess());
        self::assertNull(Result::success(null)->orElseThrow());

        self::assertTrue(Result::success(false)->isSuccess());
        self::assertFalse(Result::success(false)->orElseThrow());
    }

    // ---- orElseThrow, and why a failure carries a Throwable --------------------------------------

    /**
     * **The reason a failure holds a throwable rather than an arbitrary error value.**
     *
     * `orElseThrow()` throws the *same instance* that was handed to `failure()`, so the trace still
     * points at where the operation failed. Manufacturing an exception at unwrap time would put the
     * trace in the accessor — the one place nobody needs it.
     */
    public function testOrElseThrowThrowsTheOriginalInstanceWithItsOriginalTrace(): void
    {
        $error = new DatabaseException('the real failure');
        $lineWhereItFailed = __LINE__ - 1;

        // Captured rather than guarded with a `fail()` after the call: PHPStan proves
        // `failure()->orElseThrow()` returns `never`, so a statement after it is unreachable. A null
        // `$caught` still fails the assertion if it somehow does not throw.
        $caught = null;

        try {
            Result::failure($error)->orElseThrow();
        } catch (DatabaseException $e) {
            $caught = $e;
        }

        self::assertSame($error, $caught, 'a different instance means a different trace');
        self::assertSame($lineWhereItFailed, $caught->getLine());
    }

    public function testOrElseReturnsTheDefaultOnlyForAFailure(): void
    {
        self::assertSame(42, Result::success(42)->orElse(0));
        self::assertSame(0, Result::failure(new UtilsException('x'))->orElse(0));
        self::assertNull(Result::success(null)->orElse('fallback'), 'null is a value, not an absence');
    }

    // ---- map --------------------------------------------------------------------------------------

    public function testMapTransformsASuccess(): void
    {
        self::assertSame(84, Result::success(42)->map(static fn (int $n): int => $n * 2)->orElseThrow());
    }

    public function testMapLeavesAFailureAlone(): void
    {
        $error = new UtilsException('nope');
        $called = false;

        $result = Result::failure($error)->map(static function () use (&$called): int {
            $called = true;

            return 1;
        });

        self::assertFalse($called, 'the mapper ran on a failure');
        self::assertSame($error, $result->error(), 'the original throwable must survive the map');
    }

    /**
     * **`map()` does not catch, and that is the decision.** A `Result` models an *expected* failure.
     * A mapper that throws has a defect, and turning a `TypeError` into a business failure would hide
     * it at the point it is cheapest to find.
     */
    public function testMapDoesNotConvertAThrowingMapperIntoAFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a bug, not an outcome');

        Result::success(1)->map(static function (): int {
            throw new \RuntimeException('a bug, not an outcome');
        });
    }

    // ---- flatMap ----------------------------------------------------------------------------------

    public function testFlatMapDoesNotNestResults(): void
    {
        $result = Result::success(2)->flatMap(static fn (int $n): Result => Result::success($n + 1));

        self::assertSame(3, $result->orElseThrow(), 'a Result<Result<int>> would fail here');
    }

    public function testFlatMapPropagatesAFailureFromItsCallable(): void
    {
        $error = new UtilsException('inner');

        $result = Result::success(2)->flatMap(static fn (): Result => Result::failure($error));

        self::assertSame($error, $result->error());
    }

    public function testFlatMapLeavesAFailureAlone(): void
    {
        $error = new UtilsException('outer');
        $called = false;

        $result = Result::failure($error)->flatMap(static function () use (&$called): Result {
            $called = true;

            return Result::success(1);
        });

        self::assertFalse($called);
        self::assertSame($error, $result->error());
    }

    // ---- try --------------------------------------------------------------------------------------

    /**
     * The one place catching happens, opt-in by name so a reader can see it was meant.
     */
    public function testTryCapturesAThrowAsAFailure(): void
    {
        $result = Result::try(static function (): int {
            throw new DatabaseException('connection refused');
        });

        self::assertTrue($result->isFailure());
        self::assertInstanceOf(DatabaseException::class, $result->error());
        self::assertSame('connection refused', $result->error()->getMessage());
    }

    public function testTryReturnsASuccessWhenNothingThrows(): void
    {
        self::assertSame(7, Result::try(static fn (): int => 7)->orElseThrow());
    }

    // ---- recover ----------------------------------------------------------------------------------

    public function testRecoverReplacesAFailureAndSeesWhyItFailed(): void
    {
        $result = Result::failure(new DatabaseException('offline'))
            ->recover(static fn (\Throwable $e): string => 'recovered from ' . $e->getMessage());

        self::assertSame('recovered from offline', $result->orElseThrow());
    }

    public function testRecoverLeavesASuccessAlone(): void
    {
        $called = false;

        $result = Result::success('kept')->recover(static function () use (&$called): string {
            $called = true;

            return 'replaced';
        });

        self::assertFalse($called);
        self::assertSame('kept', $result->orElseThrow());
    }

    // ---- chaining ---------------------------------------------------------------------------------

    /**
     * The point of the short-circuiting: error handling is stated once at the end rather than between
     * every step.
     */
    public function testAFailureMidChainSkipsEveryLaterStep(): void
    {
        $ran = [];

        $result = Result::success(1)
            ->map(static function (int $n) use (&$ran): int {
                $ran[] = 'first';

                return $n + 1;
            })
            ->flatMap(static function () use (&$ran): Result {
                $ran[] = 'second';

                return self::failingStep();
            })
            ->map(static function (int $n) use (&$ran): int {
                $ran[] = 'third';

                return $n * 10;
            });

        self::assertSame(['first', 'second'], $ran);
        self::assertSame('stop here', $result->error()?->getMessage());
        self::assertSame('fallback', $result->orElse('fallback'));
    }

    /**
     * A failing step whose *declared* element type is `int`.
     *
     * `Result::failure()` returns `Result<never>`, which is correct and makes every later step in a
     * chain unreachable to PHPStan — true, but it leaves the chain impossible to write with typed
     * closures. Stating the type on a method, which PHPStan honours, keeps the static types faithful
     * to how a real failing service reads while the runtime behaviour is unchanged.
     *
     * @return Result<int>
     */
    private static function failingStep(): Result
    {
        return Result::failure(new UtilsException('stop here'));
    }

    public function testAFullSuccessfulChainRunsEveryStep(): void
    {
        $result = Result::success(2)
            ->map(static fn (int $n): int => $n + 3)
            ->flatMap(static fn (int $n): Result => Result::success($n * 2))
            ->map(static fn (int $n): string => "value: {$n}");

        self::assertSame('value: 10', $result->orElseThrow());
    }
}
