<?php

declare(strict_types=1);

namespace D4np\Utils\Mail;

use D4np\Utils\Support\MailException;

/**
 * The transport contract (spec FR-44, RFC-0002; ADR-0056).
 *
 * One method, no return value: a message was handed over or an exception says why not. There is no
 * boolean, because the surveyed estate's mailer returned `false` for a malformed address and for an
 * unreachable MTA alike, and a caller could act on neither.
 *
 * **This interface, not {@see NativeMailer}, is what application code should depend on.** It is the
 * seam that makes the group's stated non-goal survivable: this library ships no SMTP client, so a
 * consumer who needs authenticated submission, attachments or a queue implements `Mailer` over the
 * library of their choice and changes no calling code. The seam is also what lets a test assert what
 * *would* have been sent without a mail server — see `Fixture\RecordingMailer`.
 *
 * **"Handed over" is the whole promise.** A transport that returns without throwing has passed the
 * message to something that accepted responsibility for it; whether it is ultimately delivered is
 * not knowable at this boundary, by anyone, and an interface that implied otherwise would be lying.
 */
interface Mailer
{
    /**
     * @throws MailException if the message could not be handed to the transport
     */
    public function send(MailMessage $message): void;
}
