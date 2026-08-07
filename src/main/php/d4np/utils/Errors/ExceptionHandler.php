<?php

declare(strict_types=1);

namespace D4np\Utils\Errors;

use D4np\Utils\Support\Env;
use D4np\Utils\Support\Json;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Turns an uncaught throwable into a JSON problem document, and never leaks a trace in production
 * (spec FR-18, ADR-0029).
 *
 * **The document is a pure value.** {@see problem()} is a function of the throwable and the debug
 * flag, with no I/O — which is what makes the security property testable. The alternative is a class
 * whose only observable behaviour is a response nobody can inspect without a live server, and
 * "production hides the trace" is precisely the assertion that must not wait for an integration
 * suite. The same split as ADR-0026 §1 and ADR-0022, for the same reason.
 *
 * **Production withholds the message as well as the trace.** FR-18 names traces, and a message leaks
 * just as effectively: `SQLSTATE[42S02]: Base table or view not found: 'users_backup'` names a
 * schema, `failed to open stream: /srv/app/config/secrets.php` names a path. Since nothing in a
 * throwable says whether its message was written for an end user, the safe reading of "never leaks"
 * is to withhold both and emit a **reference** instead — logged alongside the full detail, so an
 * operator can join the two in one grep. An application that wants a particular message shown should
 * catch that exception and say so itself, which is a decision it can make and this class cannot.
 */
final class ExceptionHandler
{
    /**
     * PHP error levels that end the process, and therefore only ever arrive via the shutdown
     * handler.
     */
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    /**
     * @param bool                      $debug     whether to include the message and trace. **Never `true` in production.**
     * @param LoggerInterface|null      $logger    where the full detail goes, including in production
     * @param array<class-string, int>  $statusMap throwable class to HTTP status; anything unlisted is a 500
     */
    public function __construct(
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $statusMap = [],
    ) {
    }

    /**
     * Read the debug flag from the environment, defaulting to **off**.
     *
     * A separate constructor because the safe value must not depend on a variable being present:
     * a missing `APP_DEBUG` yields production behaviour, so forgetting to set it cannot be what
     * exposes a trace. `Env::get()` already coerces `'false'` correctly (spec FR-24).
     *
     * @param array<class-string, int> $statusMap
     */
    public static function fromEnvironment(
        ?LoggerInterface $logger = null,
        array $statusMap = [],
        string $variable = 'APP_DEBUG',
    ): self {
        return new self(Env::get($variable, false) === true, $logger, $statusMap);
    }

    /**
     * The problem document for `$throwable` — a pure function of it and the debug flag.
     *
     * Shaped after RFC 7807's `application/problem+json`: `type`, `title`, `status`, and `detail`.
     *
     * @return array<string, mixed>
     */
    public function problem(Throwable $throwable, string $reference = ''): array
    {
        $status = $this->statusFor($throwable);

        $document = [
            'type' => 'about:blank',
            'title' => self::titleFor($status),
            'status' => $status,
        ];

        if ($reference !== '') {
            $document['reference'] = $reference;
        }

        if (!$this->debug) {
            // The whole security property, in one branch.
            $document['detail'] = 'The request could not be completed. Quote the reference when '
                . 'reporting this.';

            return $document;
        }

        $document['detail'] = $throwable->getMessage();
        $document['exception'] = $throwable::class;
        $document['file'] = $throwable->getFile() . ':' . $throwable->getLine();
        $document['trace'] = \explode("\n", $throwable->getTraceAsString());

        return $document;
    }

    /**
     * The HTTP status for `$throwable`: whatever the map says, otherwise 500.
     *
     * The map is explicit and starts empty because nothing in a library can know that an
     * application's `UserNotFound` means 404 — guessing from an exception's `getCode()` would be
     * worse, since a code is as likely to be 0 or a driver's `SQLSTATE` as an HTTP status.
     */
    public function statusFor(Throwable $throwable): int
    {
        foreach ($this->statusMap as $class => $status) {
            if ($throwable instanceof $class) {
                return $status;
            }
        }

        return 500;
    }

    /**
     * Log the throwable in full and return the reference that ties the log line to the response.
     *
     * Separated from {@see handle()} so the reporting half — which is all of the behaviour worth
     * asserting — can be tested without writing a response.
     */
    public function report(Throwable $throwable): string
    {
        $reference = \bin2hex(\random_bytes(8));

        $this->logger?->log(
            $this->statusFor($throwable) >= 500 ? LogLevel::ERROR : LogLevel::WARNING,
            'Uncaught {class}: {message}',
            [
                'class' => $throwable::class,
                'message' => $throwable->getMessage(),
                'reference' => $reference,
                'exception' => $throwable,
            ],
        );

        return $reference;
    }

    /**
     * Report the throwable and write the problem document as the response.
     *
     * The only method here that performs I/O, and it is kept to the four statements that cannot be
     * anything else. Its behaviour against a real server belongs with the integration suites; the
     * decisions it acts on are all covered above.
     */
    public function handle(Throwable $throwable): void
    {
        $reference = $this->report($throwable);
        $document = $this->problem($throwable, $reference);
        $status = $this->statusFor($throwable);

        if (!\headers_sent()) {
            \http_response_code($status);
            \header('Content-Type: application/problem+json');
        }

        echo Json::encode($document);
    }

    /**
     * Install this handler for uncaught throwables and for fatal errors.
     *
     * Fatal errors never reach `set_exception_handler()`, so the shutdown function is not
     * belt-and-braces — it is the only route by which an `E_ERROR` is ever reported.
     */
    public function register(): void
    {
        \set_exception_handler(function (Throwable $throwable): void {
            $this->handle($throwable);
        });

        \register_shutdown_function(function (): void {
            $error = \error_get_last();

            if (self::isFatal($error)) {
                /** @var array{type: int, message: string, file: string, line: int} $error */
                $this->handle(new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line'],
                ));
            }
        });
    }

    /**
     * Whether `error_get_last()` describes an error that ended the process.
     *
     * A pure predicate rather than a condition inline in the shutdown closure, because the closure
     * itself cannot be reached from a test — a real fatal error takes the process with it — and this
     * is the part of it that can be wrong. Verified that a fatal `require` surfaces here as
     * `E_ERROR`.
     *
     * @param array<string, mixed>|null $error
     */
    public static function isFatal(?array $error): bool
    {
        return $error !== null
            && \is_int($error['type'] ?? null)
            && ($error['type'] & self::FATAL) !== 0;
    }

    private static function titleFor(int $status): string
    {
        return match (true) {
            $status === 400 => 'Bad Request',
            $status === 401 => 'Unauthorized',
            $status === 403 => 'Forbidden',
            $status === 404 => 'Not Found',
            $status === 409 => 'Conflict',
            $status === 422 => 'Unprocessable Entity',
            $status === 429 => 'Too Many Requests',
            $status >= 500 => 'Internal Server Error',
            default => 'Error',
        };
    }
}
