<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A rate-limit store could not be read or written (spec r22 FR-50, RFC-0003; ADR-0061 §3,
 * ADR-0067).
 *
 * **This exception exists so that a store failure can never become a decision.** The limiter
 * converts no store failure into "allowed" and none into "denied": it lets this propagate, and the
 * caller — the only party who knows whether *this* endpoint prefers lockout or exposure while the
 * backend is down — makes the availability-versus-security call at its own `catch`.
 *
 * Both silent versions are what ADR-0061 refuses. A limiter that quietly allows on store failure
 * has reproduced the deferral's nightmare exactly: protection that evaporates when infrastructure
 * degrades, which is when attacks are cheapest. One that quietly denies has decided an outage on
 * the caller's behalf. So the choice is the caller's, and the guidance is one sentence: **if an
 * endpoint chooses fail-open, it should do so loudly** — log at error, raise an alert — because a
 * `catch` that returns "allowed" and says nothing is the hole this type was created to keep open to
 * inspection.
 *
 * A denial is **not** this. Being rate-limited is a normal outcome and arrives as a
 * {@see \D4np\Utils\Security\RateLimitDecision}; this type is reserved for the store itself
 * failing.
 *
 * A plain leaf on {@see UtilsException}, like {@see CryptoException} and {@see MailException}: the
 * two failure kinds a store has — unreachable, and returning something that is not the state it
 * was given — are distinguished by their message, because no caller's `catch` would choose
 * differently between them.
 */
final class RateLimitStoreException extends UtilsException
{
}
