<?php

declare(strict_types=1);

namespace D4np\Utils\Mail;

use D4np\Utils\Support\MailException;

/**
 * A message that is sendable by construction (spec FR-43, RFC-0002; ADR-0056).
 *
 * Every value that will land in a header is validated here, so a `MailMessage` which exists is one a
 * transport can hand over without re-checking anything. Addresses arrive as {@see EmailAddress} and
 * are therefore already free of header terminators; the subject is checked here, because it is the
 * one header value a caller supplies as free text.
 *
 * **Why the subject is refused rather than cleaned, and why waiting for PHP would not do.** Probed
 * against a real SMTP transport, PHP's own handling of `CR`/`LF` depends on *which argument* carries
 * them:
 *
 * | payload | what reached the wire |
 * |---|---|
 * | `CRLF` in `mail()`'s `$subject` | flattened to spaces — `Subject: a subject  Bcc: victim@…` |
 * | `CRLF` in `mail()`'s `$to` | flattened — and the envelope got `RCPT TO:<to@…  Bcc: victim@…>` |
 * | `CRLF` in an **array** header value | `ValueError`, nothing sent |
 * | `CRLF` in a **string** header block | **honoured** — a second `RCPT TO:<victim@…>` was issued |
 *
 * So PHP does three different things with the same bytes, and the third is a working Bcc injection.
 * Flattening is the interesting case: it is *safe* and it silently changes the caller's value, which
 * is the shape spec §1 rejects everywhere else in this library (ADR-0037's CSV guard, ADR-0019's
 * escaper). A message with a corrupted subject is not a message anyone asked to send.
 *
 * **Non-goals, stated so nobody looks for them:** no attachments, no arbitrary custom headers, no
 * display names (see {@see EmailAddress}), no priority or read-receipt flags. Attachments and
 * custom headers are where a MIME builder becomes a project; a consumer who needs them has outgrown
 * this class and wants a mailer library behind {@see Mailer}.
 */
final class MailMessage
{
    /** RFC 2047 allows an encoded-word of at most 75 characters, delimiters included. */
    private const ENCODED_WORD_LIMIT = 75;

    /**
     * @param list<EmailAddress> $to
     * @param list<EmailAddress> $cc
     * @param list<EmailAddress> $bcc
     */
    private function __construct(
        public readonly EmailAddress $from,
        public readonly array $to,
        public readonly string $subject,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly ?EmailAddress $replyTo,
    ) {
    }

    /**
     * @param EmailAddress|list<EmailAddress> $to
     * @param list<EmailAddress>              $cc
     * @param list<EmailAddress>              $bcc
     *
     * @throws MailException if the subject carries a header terminator, if there is no recipient, or
     *                       if the message has no body at all
     */
    public static function create(
        EmailAddress $from,
        EmailAddress|array $to,
        string $subject,
        ?string $text = null,
        ?string $html = null,
        array $cc = [],
        array $bcc = [],
        ?EmailAddress $replyTo = null,
    ): self {
        $recipients = $to instanceof EmailAddress ? [$to] : \array_values($to);

        if ($recipients === []) {
            throw new MailException(
                'A message needs at least one recipient. An empty list would be handed to a '
                . 'transport that reports success for delivering nothing to nobody.',
            );
        }

        foreach (EmailAddress::FORBIDDEN as $character) {
            if (\str_contains($subject, $character)) {
                throw new MailException(\sprintf(
                    'A subject may not contain %s. This is refused rather than stripped: PHP would '
                    . 'flatten it to spaces on one transport path, throw on another and honour the '
                    . 'injected header on a third (ADR-0056), and none of those is the message the '
                    . 'caller wrote.',
                    match ($character) {
                        "\r" => 'a carriage return',
                        "\n" => 'a line feed',
                        default => 'a NUL byte',
                    },
                ));
            }
        }

        if (($text === null || $text === '') && ($html === null || $html === '')) {
            throw new MailException(
                'A message needs a text body, an HTML body, or both. A message with neither is a '
                . 'header set, and a transport would deliver it as an empty email rather than fail.',
            );
        }

        return new self($from, $recipients, $subject, $text, $html, \array_values($cc), \array_values($bcc), $replyTo);
    }

    /**
     * Every address that must receive the message — `to`, `cc` and `bcc` together.
     *
     * A transport needs this list for the envelope, and it is derived here so no transport has to
     * remember that `bcc` is a delivery instruction rather than a header.
     *
     * @return non-empty-list<EmailAddress>
     */
    public function recipients(): array
    {
        /** @var non-empty-list<EmailAddress> $all */
        $all = [...$this->to, ...$this->cc, ...$this->bcc];

        return $all;
    }

    /**
     * The subject as a header value: unchanged when it is 7-bit ASCII, RFC 2047 encoded-words when
     * it is not.
     *
     * A header is 7-bit by RFC 5322, so a non-ASCII subject cannot be sent literally — it arrives as
     * mojibake or is mangled by a relay. The encoding is hand-rolled base64 encoded-words rather
     * than `mb_encode_mimeheader()`, because `mbstring` is not a declared dependency of this library
     * and ADR-0019 already refused to acquire one for a job PCRE could do.
     *
     * Long subjects are split across several encoded-words: RFC 2047 caps one at 75 characters
     * including its delimiters, and a 30-character accented subject already produces 92. The split
     * is on **byte boundaries of the decoded text**, chosen so no multi-byte character is cut in
     * half — a cut character is the classic way an encoded subject renders as a replacement glyph.
     */
    public function encodedSubject(): string
    {
        if (\preg_match('/^[\x20-\x7E]*$/', $this->subject) === 1) {
            return $this->subject;
        }

        // 'B' encoding, so the payload is base64: 4 output characters per 3 input bytes, and the
        // wrapper '=?UTF-8?B?' + '?=' costs 12. Solve for the largest multiple of 3 that fits.
        $budget = self::ENCODED_WORD_LIMIT - 12;
        $chunk = (int) (\intdiv($budget, 4) * 3);

        $words = [];
        foreach (self::splitOnCharacterBoundaries($this->subject, $chunk) as $part) {
            $words[] = '=?UTF-8?B?' . \base64_encode($part) . '?=';
        }

        // Folded with CRLF + a space: the one place this class emits CRLF, and it is the RFC 5322
        // folding sequence rather than a value a caller supplied.
        return \implode("\r\n ", $words);
    }

    /**
     * @return non-empty-list<string>
     */
    private static function splitOnCharacterBoundaries(string $text, int $limit): array
    {
        $parts = [];
        $current = '';

        // Walks UTF-8 sequences rather than bytes: a continuation byte (10xxxxxx) never starts a
        // part, so no character is split across two encoded-words.
        foreach (\preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($current !== '' && \strlen($current) + \strlen($character) > $limit) {
                $parts[] = $current;
                $current = '';
            }

            $current .= $character;
        }

        $parts[] = $current;

        /** @var non-empty-list<string> $parts */
        return $parts;
    }
}
