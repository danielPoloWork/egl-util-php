<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

/**
 * What happened, as a closed set (spec r3 FR-39, RFC-0002; ADR-0051).
 *
 * The surveyed estate expressed the same nine answers three different ways, once per envelope
 * implementation, each with its own spelling and its own idea of which HTTP status went with
 * which outcome. An enum is the fix for the same reason {@see SameSite} and
 * {@see \D4np\Utils\Database\Sort} are one (ADR-0015): a closed vocabulary that reaches a
 * consumer-visible payload should be decided by the type system, not validated at run time.
 *
 * **Each case owns its HTTP status**, so the mapping exists once. A caller that disagrees for a
 * given endpoint sends a different status itself — {@see ApiEnvelope} is a payload, not a
 * response — but it will not disagree by accident.
 */
enum Outcome: string
{
    /** A read succeeded and carries data. */
    case Ok = 'ok';

    /** A resource was created. */
    case Created = 'created';

    /** An existing resource was modified. */
    case Updated = 'updated';

    /** A resource was removed. */
    case Deleted = 'deleted';

    /**
     * The request was valid and matched nothing — an empty list, not an error.
     *
     * `200` rather than `404`, deliberately: a search with no results is a successful search,
     * and the estate's habit of returning `404` for an empty collection is what makes clients
     * treat "no rows" as a failure to retry.
     */
    case Empty = 'empty';

    /** The input did not validate. The messages say what was wrong with it. */
    case Invalid = 'invalid';

    /** The addressed resource does not exist. */
    case NotFound = 'notFound';

    /** The operation was understood and did not succeed. */
    case Failed = 'failed';

    /**
     * An unhandled throwable reached the boundary.
     *
     * Distinct from {@see self::Failed} because the two mean different things to whoever is
     * on call: a `failed` is a refusal the code anticipated, a `caught` is a defect.
     */
    case Caught = 'caught';

    /**
     * The HTTP status this outcome is sent with.
     *
     * `422` for invalid input rather than `400`: the request itself was well-formed and
     * understood, which is exactly the distinction RFC 4918 §11.2 draws, and it lets a client
     * tell a malformed request from a rejected one without reading the body.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Ok, self::Empty => 200,
            self::Created => 201,
            self::Updated, self::Deleted => 200,
            self::Invalid => 422,
            self::NotFound => 404,
            self::Failed => 409,
            self::Caught => 500,
        };
    }

    /**
     * Whether this outcome represents the operation having done what was asked.
     */
    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Ok, self::Created, self::Updated, self::Deleted, self::Empty => true,
            self::Invalid, self::NotFound, self::Failed, self::Caught => false,
        };
    }
}
