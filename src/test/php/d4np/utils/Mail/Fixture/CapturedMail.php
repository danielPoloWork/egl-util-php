<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Mail\Fixture;

/**
 * One message as Mailpit received and stored it — the wire-side counterpart to
 * {@see RecordingMailApi}'s view of `mail()`'s arguments (issue #101, ADR-0078).
 *
 * Three representations, because they answer different questions and are not interchangeable:
 *
 * - **`headers`** — Mailpit's parsed header map. What the assertions about `Content-Type`,
 *   `MIME-Version`, `From` and `Reply-To` read. Remember it is a *superset* of what PHP emitted:
 *   msmtp adds `From`, `Date` and `Message-ID` when absent (see {@see Mailpit}).
 * - **`detail`** — Mailpit's parsed message. The only source for two things the headers cannot give:
 *   the RFC 2047 **decoded** subject, and `ReturnPath`, which is the envelope sender that `mail()`'s
 *   fifth argument sets.
 * - **`raw`** — the stored source. Used for the body, because the encoding *is* the claim there:
 *   a base64 body compared against a decoded one proves nothing about what travelled.
 *
 * Header lookups are case-insensitive, per RFC 5322 §1.2.2 — a receiver is free to normalise
 * capitalisation, and a test that depends on `Content-Type` rather than `content-type` is asserting
 * about Mailpit rather than about this library.
 */
final class CapturedMail
{
    /** @var array<string, list<string>> lower-cased header name to its values */
    private readonly array $headers;

    /**
     * @param array<string, mixed> $detail  Mailpit's parsed message
     * @param array<string, mixed> $headers Mailpit's header map, name to list of values
     */
    public function __construct(
        private readonly array $detail,
        array $headers,
        public readonly string $raw,
    ) {
        $normalised = [];

        foreach ($headers as $name => $values) {
            $normalised[\strtolower($name)] = \is_array($values)
                ? \array_values(\array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $values))
                : [\is_string($values) ? $values : ''];
        }

        $this->headers = $normalised;
    }

    /**
     * The first value of a header, or `null` when it is absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[\strtolower($name)][0] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[\strtolower($name)]);
    }

    /**
     * The subject as a reader would see it — Mailpit has already decoded RFC 2047 encoded-words.
     *
     * This is the assertion `encodedSubject()` deserves and could not have at the seam: the unit
     * suite can prove the encoding is *well formed*, and only a receiver can prove it *decodes to
     * the subject that was written*.
     */
    public function subject(): string
    {
        $subject = $this->detail['Subject'] ?? null;

        return \is_string($subject) ? $subject : '';
    }

    /**
     * The envelope sender, which `mail()`'s fifth argument (`-f…`) sets on a `sendmail` host.
     *
     * ADR-0056 D4 documents that argument as reaching the `sendmail` command line and being a no-op
     * on the Windows SMTP transport. Until this leg existed, nothing had ever observed it arriving.
     */
    public function returnPath(): string
    {
        $path = $this->detail['ReturnPath'] ?? null;

        return \is_string($path) ? $path : '';
    }

    /**
     * @return list<string> the addresses in `To`, as Mailpit parsed them
     */
    public function to(): array
    {
        return $this->addresses('To');
    }

    /**
     * @return list<string> the addresses in `Cc`
     */
    public function cc(): array
    {
        return $this->addresses('Cc');
    }

    /**
     * The `Bcc` addresses — and the one accessor whose meaning is not what its name suggests.
     *
     * Mailpit reports here any envelope recipient the headers did not mention, having *prepended* a
     * synthetic `Bcc:` header to the stored copy. Since msmtp strips `Bcc:` on the wire, a delivered
     * bcc address arrives at Mailpit only in the envelope — so a non-empty list here is a
     * discriminating witness that the address reached `RCPT TO`. Had it not, the header would have
     * been stripped and Mailpit would have had nothing to add, leaving this empty.
     *
     * @return list<string>
     */
    public function bcc(): array
    {
        return $this->addresses('Bcc');
    }

    /**
     * @return list<string>
     */
    private function addresses(string $field): array
    {
        $entries = $this->detail[$field] ?? null;

        if (!\is_array($entries)) {
            return [];
        }

        $addresses = [];

        foreach ($entries as $entry) {
            // Mailpit shapes each as {Name, Address}; a malformed entry is dropped rather than
            // turned into an empty string that would satisfy a `contains` assertion by accident.
            if (\is_array($entry) && isset($entry['Address']) && \is_string($entry['Address']) && $entry['Address'] !== '') {
                $addresses[] = $entry['Address'];
            }
        }

        return $addresses;
    }
}
