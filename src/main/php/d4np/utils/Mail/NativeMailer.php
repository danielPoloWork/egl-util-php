<?php

declare(strict_types=1);

namespace D4np\Utils\Mail;

use D4np\Utils\Support\MailException;

/**
 * A {@see Mailer} over PHP's `mail()` (spec FR-44, RFC-0002; ADR-0056).
 *
 * **Headers are handed over as an array, never as a string block, and that is the security decision
 * in this class.** Probed against a real SMTP transport: with the array form, PHP throws a
 * `ValueError` on a `CR`/`LF` in a header value; with the string form it *parses* the injected header
 * and issues a second `RCPT TO`, so a `Bcc:` smuggled into a `From:` value is delivered. This
 * library validates before it ever gets there ({@see MailMessage}, {@see EmailAddress}), which makes
 * the array form defence in depth rather than the defence — and defence in depth is exactly what one
 * wants for the failure mode where a *future* edit loosens a validation.
 *
 * **Nothing global is mutated.** The surveyed estate configured its mailer with `ini_set()` calls at
 * send time — `SMTP`, `sendmail_from` — which change the behaviour of every other `mail()` call in
 * the process, including ones made by code that never asked. Where the MTA lives is deployment
 * configuration (`php.ini`), and this class reads none of it: the only thing it configures is the
 * **envelope sender**, and that is a constructor argument.
 *
 * **The envelope sender is a `sendmail` option and a no-op on some platforms.** It is passed as
 * `mail()`'s fifth argument, which reaches the `sendmail` command line — so it applies where PHP is
 * configured with `sendmail_path` and is ignored by the Windows SMTP transport. Stated because a
 * silent no-op that only manifests on one platform is worse than a documented limit. It is safe to
 * put on a command line for the reason it is typed: an {@see EmailAddress} cannot contain a space,
 * a quote, a semicolon or a newline.
 *
 * **Bodies are base64-encoded**, and both bodies together become `multipart/alternative`. Base64
 * rather than raw 8-bit because a header is 7-bit by RFC 5322 and a *body* line is capped at 998
 * octets: a long UTF-8 paragraph sent raw is a message a relay may fold, mangle or reject, and the
 * encoding removes the question rather than hoping about it.
 */
final class NativeMailer implements Mailer
{
    private readonly MailApi $api;

    public function __construct(
        private readonly ?EmailAddress $envelopeSender = null,
        ?MailApi $api = null,
    ) {
        $this->api = $api ?? new NativeMailApi();
    }

    /**
     * @throws MailException if the transport declined the message
     */
    public function send(MailMessage $message): void
    {
        [$body, $contentType] = $this->render($message);

        $headers = [
            'From' => $message->from->value,
            'MIME-Version' => '1.0',
            'Content-Type' => $contentType,
            'Content-Transfer-Encoding' => 'base64',
        ];

        if ($message->replyTo !== null) {
            $headers['Reply-To'] = $message->replyTo->value;
        }

        if ($message->cc !== []) {
            $headers['Cc'] = self::join($message->cc);
        }

        // Bcc as a header of the array form is a *delivery instruction*: probed, PHP issues a
        // `RCPT TO` for it and omits the header from the message it sends, which is the behaviour
        // RFC 5322 asks for and the reason this is not done by hand.
        if ($message->bcc !== []) {
            $headers['Bcc'] = self::join($message->bcc);
        }

        $sent = $this->api->send(
            self::join($message->to),
            $message->encodedSubject(),
            $body,
            $headers,
            $this->envelopeSender === null ? '' : '-f' . $this->envelopeSender->value,
        );

        if (!$sent) {
            throw new MailException(\sprintf(
                'The transport declined the message to %s. PHP\'s mail() reports a refused message '
                . 'and an unreachable MTA identically, so this covers both: check the mail '
                . 'configuration (sendmail_path, or SMTP and smtp_port) before the message.',
                self::join($message->recipients()),
            ));
        }
    }

    /**
     * @return array{string, string} the encoded body and the `Content-Type` that describes it
     *
     * @throws MailException
     */
    private function render(MailMessage $message): array
    {
        $text = $message->text;
        $html = $message->html;

        if ($text !== null && $text !== '' && $html !== null && $html !== '') {
            $boundary = self::boundary();

            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . self::encode($text) . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . self::encode($html) . "\r\n"
                . "--{$boundary}--\r\n";

            // The outer part is the multipart container, which is not itself base64 — so the
            // envelope's Content-Transfer-Encoding is overridden here rather than left to describe
            // a body it does not describe.
            return [$body, \sprintf('multipart/alternative; boundary="%s"', $boundary)];
        }

        if ($html !== null && $html !== '') {
            return [self::encode($html), 'text/html; charset=UTF-8'];
        }

        // MailMessage::create() refuses a message with neither body, so this branch has one.
        return [self::encode((string) $text), 'text/plain; charset=UTF-8'];
    }

    /**
     * A part separator that cannot occur in either body.
     *
     * **There is deliberately no check that it does not.** A body is attacker-controlled in any
     * application that mails user-supplied text, so the obvious defensive move is to search both
     * bodies for the boundary and refuse a collision — and that check would be unreachable code.
     * The boundary is drawn from a CSPRNG *after* the bodies exist, so placing it in one requires
     * guessing 128 bits that have not been generated yet; and being unreachable, the check could
     * never be tested, only asserted about. ADR-0022 and item 12.1 removed guards a probe proved
     * inert for the same reason: a defence nothing can trigger is documentation with a runtime cost,
     * and it reads as though the surrounding code needed it.
     */
    private static function boundary(): string
    {
        return '=_' . \bin2hex(\random_bytes(16));
    }

    private static function encode(string $body): string
    {
        // RFC 2045 caps an encoded line at 76 characters; chunk_split() is exactly that rule.
        return \rtrim(\chunk_split(\base64_encode($body), 76, "\r\n"), "\r\n");
    }

    /**
     * @param list<EmailAddress> $addresses
     */
    private static function join(array $addresses): string
    {
        return \implode(', ', \array_map(static fn (EmailAddress $a): string => $a->value, $addresses));
    }
}
