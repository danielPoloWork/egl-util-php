<?php

declare(strict_types=1);

namespace D4np\Utils\Mail;

use D4np\Utils\Support\MailException;

/**
 * A validated email address (spec FR-43, RFC-0002; ADR-0056).
 *
 * The type exists so that "this string is an address" is established **once**, at a boundary, rather
 * than re-asserted by every function that accepts one. Everything downstream — {@see MailMessage},
 * {@see NativeMailer} — takes this type and therefore never validates an address again.
 *
 * **The rules are `filter_var()`'s, and they are stricter than RFC 5321 in one visible way.**
 * Probed: `FILTER_VALIDATE_EMAIL` rejects `user@example` — a bare hostname with no dot, which the
 * RFC permits and which real intranet addresses use. It also rejects quoted local parts
 * (`"user name"@example.com`), non-ASCII local parts, and the display-name form
 * (`User <user@example.com>`), while accepting IP literals (`user@[127.0.0.1]`). Those are the
 * accepted trade-offs of not hand-rolling an RFC 5322 parser, and they are written here rather than
 * discovered by a consumer whose address is refused.
 *
 * **CR, LF and NUL are refused explicitly, before `filter_var()` is asked.** `filter_var()` happens
 * to reject all three today (probed), so this check is redundant *as validation* — and it is kept
 * because it is not redundant as a **statement**: the one property this class must never lose is
 * that no instance can carry a header terminator, and a test that asserts it against this class,
 * rather than against `filter_var()`'s current rule set, is a test about this library.
 *
 * The display name is deliberately **not** part of this type: a name is free text that has to be
 * RFC 2047-encoded and quoted, and mixing it into the address value is how
 * `From: "Foo\r\nBcc: x" <a@b>` gets built. {@see MailMessage} carries no display names for the same
 * reason — an address is an address.
 */
final class EmailAddress implements \Stringable
{
    /**
     * Characters that terminate or split a header. The whole `Mail` group's safety rests on none of
     * these reaching a header value, and this is the one place the set is written down.
     */
    public const FORBIDDEN = ["\r", "\n", "\0"];

    private function __construct(public readonly string $value)
    {
    }

    /**
     * @throws MailException if `$address` is not a usable address
     */
    public static function of(string $address): self
    {
        foreach (self::FORBIDDEN as $character) {
            if (\str_contains($address, $character)) {
                throw new MailException(\sprintf(
                    'An email address may not contain %s. Refused here rather than at send time: '
                    . 'what PHP does with a header terminator depends on which argument carries it '
                    . 'and which transport is configured (ADR-0056), so the only reliable answer is '
                    . 'that an address carrying one never becomes an address at all.',
                    self::describe($character),
                ));
            }
        }

        // Trimming first would accept " user@example.com" by silently changing it, and an address
        // the caller did not write is worse than a refusal they can read.
        if (\filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException(\sprintf(
                '"%s" is not a valid email address. Note that the check is filter_var()\'s, which is '
                . 'stricter than RFC 5321 in one respect worth knowing: a bare hostname with no dot '
                . '(user@example) is refused.',
                $address,
            ));
        }

        return new self($address);
    }

    /**
     * The same address, or `null` where a caller has a legitimate reason to treat an invalid one as
     * absent — reading an optional configuration value, filtering a user-supplied list.
     *
     * Named so the tolerant path is visible at the call site: the surveyed estate's helpers returned
     * `false` from everything, so no reader could tell which calls had decided to be tolerant.
     */
    public static function tryOf(string $address): ?self
    {
        try {
            return self::of($address);
        } catch (MailException) {
            return null;
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * The domain part, lower-cased — the half that is case-insensitive per RFC 5321. The local part
     * is deliberately not normalised: it is case-**sensitive** by the same RFC, and lower-casing it
     * is a widespread habit that quietly rewrites addresses on hosts which honour the distinction.
     */
    public function domain(): string
    {
        return \strtolower(\substr($this->value, \strrpos($this->value, '@') + 1));
    }

    private static function describe(string $character): string
    {
        return match ($character) {
            "\r" => 'a carriage return',
            "\n" => 'a line feed',
            default => 'a NUL byte',
        };
    }
}
