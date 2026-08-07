<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use JsonSerializable;

/**
 * One JSON shape for every answer an API gives (spec r3 FR-39, RFC-0002; ADR-0051).
 *
 * The surveyed estate carried **three** envelope implementations — one per application, plus a
 * vendored copy — with 232+ construction sites between them and no two agreeing on their field
 * names. A client written against one could not read another. This is that envelope, once:
 *
 * ```json
 * {"status": "invalid", "code": 422, "messages": ["email is required"], "data": null}
 * ```
 *
 * **The shape is fixed**: all four keys are present on every response, including when `data` is
 * `null` and `messages` is empty. A client can therefore read `payload.data` without first
 * checking that the key exists — which is the entire value of having an envelope, and is lost
 * the moment nulls start being omitted to save bytes.
 *
 * **Message strings are the caller's.** The library supplies no wording and no catalogue:
 * localization belongs to the application, which is the only place that knows the request's
 * locale. `Outcome` carries the machine-readable half, so a client never needs to parse prose.
 *
 * **Mapping a `Result` to an envelope is deliberately not here.** `Errors\Result` lives in
 * another group and RFC-0001's layering rule forbids `Http` importing it; the three-line
 * adapter belongs in the application, and
 * [`docs/patterns/endpoint-kernel.md`](../../../../../../docs/patterns/endpoint-kernel.md)
 * shows where it goes.
 *
 * This is a **payload, not a response**: it carries the status code its outcome implies, and
 * sending it is {@see Response}'s job.
 */
final class ApiEnvelope implements JsonSerializable
{
    /**
     * @param list<string> $messages human-readable, caller-supplied, possibly empty
     * @param mixed        $data     the payload, or `null` — the key is present either way
     */
    private function __construct(
        public readonly Outcome $outcome,
        public readonly array $messages,
        public readonly mixed $data,
    ) {
    }

    /**
     * A read that succeeded.
     */
    public static function ok(mixed $data = null, string ...$messages): self
    {
        return new self(Outcome::Ok, \array_values($messages), $data);
    }

    public static function created(mixed $data = null, string ...$messages): self
    {
        return new self(Outcome::Created, \array_values($messages), $data);
    }

    public static function updated(mixed $data = null, string ...$messages): self
    {
        return new self(Outcome::Updated, \array_values($messages), $data);
    }

    public static function deleted(mixed $data = null, string ...$messages): self
    {
        return new self(Outcome::Deleted, \array_values($messages), $data);
    }

    /**
     * A valid request that matched nothing.
     *
     * `data` defaults to an empty **array** rather than `null`: the caller asked for a
     * collection and got a collection, and a client iterating the result should not have to
     * special-case the empty case into a type change.
     */
    public static function empty(mixed $data = [], string ...$messages): self
    {
        return new self(Outcome::Empty, \array_values($messages), $data);
    }

    /**
     * Input that did not validate. The messages are what was wrong with it.
     *
     * @param list<string> $messages
     */
    public static function invalid(array $messages, mixed $data = null): self
    {
        return new self(Outcome::Invalid, \array_values($messages), $data);
    }

    public static function notFound(string ...$messages): self
    {
        return new self(Outcome::NotFound, \array_values($messages), null);
    }

    /**
     * An operation the code anticipated could fail, and did.
     */
    public static function failed(string ...$messages): self
    {
        return new self(Outcome::Failed, \array_values($messages), null);
    }

    /**
     * An unhandled throwable reached the boundary.
     *
     * **This takes a reference, not a `Throwable`, and that is the security decision here**
     * (ADR-0051, applying ADR-0029's stance). An envelope built from an exception would put
     * `$e->getMessage()` on the wire by default, and a message names schemas, file paths and
     * query fragments as readily as a stack trace does. The correlation reference is what the
     * client gets; the exception itself belongs in the log, where
     * {@see \D4np\Utils\Errors\ExceptionHandler} already puts it under the same reference.
     *
     * @param string $reference the correlation id that also appears in the log record
     */
    public static function caught(string $reference, string ...$messages): self
    {
        return new self(
            Outcome::Caught,
            $messages === [] ? ['An unexpected error occurred.'] : \array_values($messages),
            ['reference' => $reference],
        );
    }

    /**
     * The HTTP status this envelope's outcome implies.
     */
    public function status(): int
    {
        return $this->outcome->httpStatus();
    }

    public function isSuccessful(): bool
    {
        return $this->outcome->isSuccessful();
    }

    /**
     * The fixed shape — every key, every time.
     *
     * @return array{status: string, code: int, messages: list<string>, data: mixed}
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->outcome->value,
            'code' => $this->outcome->httpStatus(),
            'messages' => $this->messages,
            'data' => $this->data,
        ];
    }
}
