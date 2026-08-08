<?php

declare(strict_types=1);

namespace D4np\Utils\Mail;

/**
 * The seam over PHP's `mail()` (ADR-0056, following ADR-0026's `SessionApi`).
 *
 * It exists for one reason: the central decision in {@see NativeMailer} is *which shape of headers
 * it hands to `mail()`*, and that decision is invisible to every behavioural test. Both shapes send
 * a working email; only one of them refuses an injected `Bcc:` (probed — the array form throws a
 * `ValueError`, the string form issues a second `RCPT TO`). A property no observable behaviour
 * distinguishes has to be asserted as a mechanism, which needs a seam to assert against
 * (ADR-0027's rule).
 *
 * It also keeps the unit suite off the network: `mail()` with no MTA blocks on a connection attempt
 * and then fails, which would make every test slow and its failure mode indistinguishable from a
 * real defect.
 */
interface MailApi
{
    /**
     * @param array<string, string> $headers the array form, deliberately — see the interface docblock
     */
    public function send(string $to, string $subject, string $message, array $headers, string $parameters): bool;
}
