<?php

declare(strict_types=1);

namespace D4np\Utils\Mail;

/**
 * {@see MailApi} over PHP's own `mail()` — the only place in this library that calls it.
 *
 * `mail()` reports a refusal two ways, and both are turned into `false` here so that
 * {@see NativeMailer} has one thing to check: it returns `false` when the transport declines, and it
 * **throws `ValueError`** when it considers an argument malformed (probed: a `CR`/`LF` or NUL in an
 * array header value, a NUL in the subject, an invalid header name). The `ValueError` should be
 * unreachable — {@see MailMessage} and {@see EmailAddress} refuse those bytes long before this point
 * — and it is caught rather than left to escape, because "unreachable" is a claim about today's
 * validation, while a `ValueError` from a logging or notification path is a fatal error in
 * production.
 */
final class NativeMailApi implements MailApi
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $to, string $subject, string $message, array $headers, string $parameters): bool
    {
        try {
            // @ suppresses the warning `mail()` emits when it cannot reach the MTA; the boolean is
            // the signal, and NativeMailer turns it into an exception that names the recipients.
            return $parameters === ''
                ? @\mail($to, $subject, $message, $headers)
                : @\mail($to, $subject, $message, $headers, $parameters);
        } catch (\ValueError) {
            return false;
        }
    }
}
