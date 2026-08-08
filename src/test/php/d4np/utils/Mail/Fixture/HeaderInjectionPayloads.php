<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Mail\Fixture;

/**
 * The T-10 corpus: the ways a header terminator is smuggled into a mail field.
 *
 * Shared by every leg of the suite so that a payload added here is exercised against **all** the
 * surfaces at once — item 10.5's lesson, where two suites carried two corpora and the newer one was
 * weaker (`MutationBuilderTest`'s ten identifier payloads against `QueryBuilderTest`'s nineteen).
 *
 * The list is not a fuzzer: every entry is a shape published in an injection cheat sheet or observed
 * in the wild, and each is named so a failure says which shape got through.
 */
final class HeaderInjectionPayloads
{
    /**
     * Payloads that must never be accepted anywhere a header value is built. `%s` marks where a
     * legitimate value goes, so one payload can be applied to an address, a subject or a name.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'CRLF then Bcc' => "%s\r\nBcc: victim@example.com",
            'CRLF then To' => "%s\r\nTo: victim@example.com",
            'CRLF then Cc' => "%s\r\nCc: victim@example.com",
            'bare LF then Bcc' => "%s\nBcc: victim@example.com",
            'bare CR then Bcc' => "%s\rBcc: victim@example.com",
            'double CRLF then a body' => "%s\r\n\r\nan injected body",
            'CRLF then Content-Type' => "%s\r\nContent-Type: text/html",
            'CRLF then MIME-Version' => "%s\r\nMIME-Version: 1.0",
            'CRLF with folding whitespace' => "%s\r\n Bcc: victim@example.com",
            'CRLF with a tab' => "%s\r\n\tBcc: victim@example.com",
            'leading CRLF' => "\r\nBcc: victim@example.com%s",
            'NUL then Bcc' => "%s\0Bcc: victim@example.com",
            'NUL alone' => "%s\0",
            'CR alone' => "%s\r",
            'LF alone' => "%s\n",
            'CRLF alone' => "%s\r\n",
            'many CRLFs' => "%s\r\n\r\n\r\nBcc: victim@example.com",
            'CRLF then a From override' => "%s\r\nFrom: spoofed@example.com",
            'CRLF then Return-Path' => "%s\r\nReturn-Path: bounce@example.com",
        ];
    }

    /**
     * The corpus applied to one legitimate value.
     *
     * @return iterable<string, array{string}>
     */
    public static function appliedTo(string $legitimate): iterable
    {
        foreach (self::all() as $label => $template) {
            yield $label => [\str_replace('%s', $legitimate, $template)];
        }
    }

    /**
     * The strings a smuggled header would produce, for asserting absence at the transport boundary.
     *
     * @return list<string>
     */
    public static function forbiddenAtTheBoundary(): array
    {
        return ['victim@example.com', 'spoofed@example.com', 'bounce@example.com', 'an injected body'];
    }
}
