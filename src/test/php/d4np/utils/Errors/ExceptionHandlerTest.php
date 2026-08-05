<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors;

use D4np\Utils\Errors\ExceptionHandler;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Support\HttpException;
use D4np\Utils\Tests\Errors\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-18's exception handler.
 *
 * **Nothing here calls `handle()`.** It writes a response, and `http_response_code()` inside PHPUnit
 * would warn that headers are already sent — the suite runs with `failOnWarning`, so a test doing
 * that would fail for a reason having nothing to do with the code. That constraint is exactly why
 * the document is a pure value: every decision worth asserting is reachable without emitting
 * anything.
 */
final class ExceptionHandlerTest extends TestCase
{
    // ---- the security property --------------------------------------------------------------------

    /**
     * The assertion this class exists for. A trace names every file, class and method on the path to
     * the failure, which is a map of the application handed to whoever provoked the error.
     */
    public function testProductionLeaksNoTrace(): void
    {
        $document = (new ExceptionHandler(debug: false))->problem(new \RuntimeException('boom'));

        self::assertArrayNotHasKey('trace', $document);
        self::assertArrayNotHasKey('file', $document);
        self::assertArrayNotHasKey('exception', $document);
    }

    /**
     * And no message either, which FR-18 does not name but which leaks just as effectively:
     * `SQLSTATE[42S02]: Base table or view not found: 'users_backup'` names a schema.
     */
    public function testProductionLeaksNoExceptionMessage(): void
    {
        $secret = "SQLSTATE[42S02]: Base table not found: 'users_backup'";

        $document = (new ExceptionHandler(debug: false))->problem(new DatabaseException($secret));

        self::assertStringNotContainsString('users_backup', json_encode($document, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('SQLSTATE', json_encode($document, JSON_THROW_ON_ERROR));
    }

    /**
     * Nothing anywhere in the production document may carry the throwable's own words — asserted
     * over the serialised whole rather than key by key, so a future field cannot reintroduce the leak
     * without failing this.
     */
    public function testNoProductionFieldCarriesTheThrowablesDetail(): void
    {
        $handler = new ExceptionHandler(debug: false);

        foreach ([
            new \RuntimeException('/srv/app/config/secrets.php'),
            new HttpException('token abc123 rejected'),
            new DatabaseException('pdo dsn mysql:host=10.0.0.5'),
        ] as $throwable) {
            $serialised = json_encode($handler->problem($throwable, 'ref-1'), JSON_THROW_ON_ERROR);

            self::assertStringNotContainsString($throwable->getMessage(), $serialised);
            self::assertStringNotContainsString($throwable::class, $serialised);
        }
    }

    public function testDebugIncludesTheMessageAndTrace(): void
    {
        $document = (new ExceptionHandler(debug: true))->problem(new \RuntimeException('boom'));

        self::assertSame('boom', $document['detail']);
        self::assertSame(\RuntimeException::class, $document['exception']);
        self::assertIsArray($document['trace']);
        self::assertNotEmpty($document['trace']);
    }

    /**
     * Debug is **off** unless the environment says otherwise, and a missing variable must not be
     * what exposes a trace.
     */
    public function testDebugDefaultsToOffAndAMissingVariableKeepsItOff(): void
    {
        self::assertArrayNotHasKey('trace', (new ExceptionHandler())->problem(new \RuntimeException('x')));

        $document = ExceptionHandler::fromEnvironment(variable: 'D4NP_ABSENT_DEBUG_FLAG')
            ->problem(new \RuntimeException('x'));

        self::assertArrayNotHasKey('trace', $document);
    }

    /**
     * `Env::get()` coerces `'false'` to `false` (FR-24), so the string that would otherwise be truthy
     * must not turn debug on.
     */
    public function testTheStringFalseDoesNotEnableDebug(): void
    {
        putenv('D4NP_TEST_DEBUG=false');

        try {
            $document = ExceptionHandler::fromEnvironment(variable: 'D4NP_TEST_DEBUG')
                ->problem(new \RuntimeException('x'));

            self::assertArrayNotHasKey('trace', $document, "'false' must not read as on");
        } finally {
            putenv('D4NP_TEST_DEBUG');
        }
    }

    public function testTheStringTrueEnablesDebug(): void
    {
        putenv('D4NP_TEST_DEBUG=true');

        try {
            $document = ExceptionHandler::fromEnvironment(variable: 'D4NP_TEST_DEBUG')
                ->problem(new \RuntimeException('x'));

            self::assertArrayHasKey('trace', $document);
        } finally {
            putenv('D4NP_TEST_DEBUG');
        }
    }

    // ---- the document ----------------------------------------------------------------------------

    public function testTheDocumentIsShapedLikeRfc7807(): void
    {
        $document = (new ExceptionHandler())->problem(new \RuntimeException('x'));

        self::assertSame('about:blank', $document['type']);
        self::assertSame('Internal Server Error', $document['title']);
        self::assertSame(500, $document['status']);
        self::assertIsString($document['detail']);
    }

    public function testAReferenceIsIncludedWhenGivenAndOmittedWhenNot(): void
    {
        $handler = new ExceptionHandler();

        self::assertSame('abc123', $handler->problem(new \RuntimeException('x'), 'abc123')['reference']);
        self::assertArrayNotHasKey('reference', $handler->problem(new \RuntimeException('x')));
    }

    // ---- status mapping ---------------------------------------------------------------------------

    public function testEverythingIsAFiveHundredUntilTheApplicationSaysOtherwise(): void
    {
        self::assertSame(500, (new ExceptionHandler())->statusFor(new HttpException('x')));
    }

    public function testTheStatusMapIsHonouredIncludingSubclasses(): void
    {
        $handler = new ExceptionHandler(statusMap: [HttpException::class => 400]);

        self::assertSame(400, $handler->statusFor(new HttpException('x')));
        self::assertSame(500, $handler->statusFor(new DatabaseException('x')));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, 'Bad Request'];
        yield '401' => [401, 'Unauthorized'];
        yield '403' => [403, 'Forbidden'];
        yield '404' => [404, 'Not Found'];
        yield '409' => [409, 'Conflict'];
        yield '422' => [422, 'Unprocessable Entity'];
        yield '429' => [429, 'Too Many Requests'];
        yield '500' => [500, 'Internal Server Error'];
        yield '503' => [503, 'Internal Server Error'];
        yield '418' => [418, 'Error'];
    }

    #[DataProvider('statuses')]
    public function testTheTitleFollowsTheStatus(int $status, string $expected): void
    {
        $handler = new ExceptionHandler(statusMap: [\RuntimeException::class => $status]);

        self::assertSame($expected, $handler->problem(new \RuntimeException('x'))['title']);
    }

    // ---- reporting --------------------------------------------------------------------------------

    /**
     * The reference is the only thread between a deliberately uninformative response and the full
     * detail in the log. If it is not in both, production failures become unanalysable.
     */
    public function testTheReferenceTiesTheResponseToTheLogRecord(): void
    {
        $logger = new RecordingLogger();
        $handler = new ExceptionHandler(debug: false, logger: $logger);
        $throwable = new \RuntimeException('the real reason');

        $reference = $handler->report($throwable);
        $document = $handler->problem($throwable, $reference);

        self::assertNotSame('', $reference);
        self::assertSame($reference, $document['reference']);
        self::assertSame($reference, $logger->records[0]['context']['reference']);
    }

    public function testTheLogRecordCarriesTheFullThrowableEvenInProduction(): void
    {
        $logger = new RecordingLogger();
        $throwable = new \RuntimeException('the real reason');

        (new ExceptionHandler(debug: false, logger: $logger))->report($throwable);

        self::assertSame($throwable, $logger->records[0]['context']['exception']);
        self::assertSame('the real reason', $logger->records[0]['context']['message']);
    }

    public function testReferencesAreNotPredictable(): void
    {
        $handler = new ExceptionHandler();
        $seen = [];

        for ($i = 0; $i < 50; $i++) {
            $seen[] = $handler->report(new \RuntimeException('x'));
        }

        self::assertCount(50, array_unique($seen));
    }

    public function testServerErrorsLogAtErrorAndClientErrorsAtWarning(): void
    {
        $logger = new RecordingLogger();
        $handler = new ExceptionHandler(logger: $logger, statusMap: [HttpException::class => 404]);

        $handler->report(new \RuntimeException('server'));
        $handler->report(new HttpException('client'));

        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('warning', $logger->records[1]['level']);
    }

    public function testReportingWithoutALoggerIsHarmless(): void
    {
        self::assertNotSame('', (new ExceptionHandler())->report(new \RuntimeException('x')));
    }

    // ---- fatal-error classification ---------------------------------------------------------------

    /**
     * A fatal error never reaches `set_exception_handler()`, so the shutdown route is the only one
     * that ever reports an `E_ERROR`. The closure itself cannot be tested — a real fatal takes the
     * process with it — so the predicate it branches on is pulled out and tested directly.
     */
    public function testFatalErrorsAreRecognisedAndOthersAreNot(): void
    {
        self::assertTrue(ExceptionHandler::isFatal(['type' => E_ERROR]));
        self::assertTrue(ExceptionHandler::isFatal(['type' => E_PARSE]));
        self::assertTrue(ExceptionHandler::isFatal(['type' => E_COMPILE_ERROR]));
        self::assertTrue(ExceptionHandler::isFatal(['type' => E_USER_ERROR]));

        self::assertFalse(ExceptionHandler::isFatal(['type' => E_WARNING]));
        self::assertFalse(ExceptionHandler::isFatal(['type' => E_NOTICE]));
        self::assertFalse(ExceptionHandler::isFatal(['type' => E_DEPRECATED]));
    }

    /**
     * `error_get_last()` returns `null` when nothing has gone wrong, which is the ordinary case on
     * every clean shutdown — so this branch runs on every single request.
     */
    public function testNoErrorIsNotFatal(): void
    {
        self::assertFalse(ExceptionHandler::isFatal(null));
        self::assertFalse(ExceptionHandler::isFatal([]));
        self::assertFalse(ExceptionHandler::isFatal(['type' => 'not an int']));
    }
}
