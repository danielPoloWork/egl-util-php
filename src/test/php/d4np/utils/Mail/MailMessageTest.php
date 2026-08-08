<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Mail;

use D4np\Utils\Mail\EmailAddress;
use D4np\Utils\Mail\MailMessage;
use D4np\Utils\Support\MailException;
use D4np\Utils\Tests\Mail\Fixture\HeaderInjectionPayloads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Message construction (spec FR-43), and T-10's second leg — the subject, which is the one header
 * value a caller supplies as free text.
 */
#[CoversClass(MailMessage::class)]
#[Group('T-10')]
final class MailMessageTest extends TestCase
{
    private function address(string $value = 'to@example.com'): EmailAddress
    {
        return EmailAddress::of($value);
    }

    private function message(string $subject = 'a subject', ?string $text = 'a body', ?string $html = null): MailMessage
    {
        return MailMessage::create($this->address('from@example.com'), $this->address(), $subject, $text, $html);
    }

    public function testAMessageCarriesWhatItWasGiven(): void
    {
        $message = MailMessage::create(
            $this->address('from@example.com'),
            [$this->address('one@example.com'), $this->address('two@example.com')],
            'a subject',
            'a text body',
            '<p>an html body</p>',
            [$this->address('cc@example.com')],
            [$this->address('bcc@example.com')],
            $this->address('reply@example.com'),
        );

        self::assertSame('from@example.com', $message->from->value);
        self::assertSame(['one@example.com', 'two@example.com'], \array_map(
            static fn (EmailAddress $a): string => $a->value,
            $message->to,
        ));
        self::assertSame('a subject', $message->subject);
        self::assertSame('a text body', $message->text);
        self::assertSame('<p>an html body</p>', $message->html);
        self::assertSame('cc@example.com', $message->cc[0]->value);
        self::assertSame('bcc@example.com', $message->bcc[0]->value);
        self::assertSame('reply@example.com', $message->replyTo?->value);
    }

    public function testASingleRecipientNeedsNoArray(): void
    {
        self::assertCount(1, $this->message()->to);
    }

    public function testRecipientsAreToPlusCcPlusBcc(): void
    {
        $message = MailMessage::create(
            $this->address('from@example.com'),
            $this->address('to@example.com'),
            's',
            'b',
            null,
            [$this->address('cc@example.com')],
            [$this->address('bcc@example.com')],
        );

        // The envelope needs all three; that bcc is a delivery instruction rather than a header is
        // the transport's business, and deriving the list here means no transport has to remember it.
        self::assertSame(
            ['to@example.com', 'cc@example.com', 'bcc@example.com'],
            \array_map(static fn (EmailAddress $a): string => $a->value, $message->recipients()),
        );
    }

    public function testAMessageWithNoRecipientIsRefused(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('at least one recipient');

        MailMessage::create($this->address('from@example.com'), [], 's', 'b');
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function bodylessMessages(): iterable
    {
        yield 'both null' => [null, null];
        yield 'both empty' => ['', ''];
        yield 'text empty, html null' => ['', null];
        yield 'text null, html empty' => [null, ''];
    }

    #[DataProvider('bodylessMessages')]
    public function testAMessageWithNoBodyIsRefused(?string $text, ?string $html): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('needs a text body, an HTML body, or both');

        MailMessage::create($this->address('from@example.com'), $this->address(), 's', $text, $html);
    }

    public function testEitherBodyAloneIsEnough(): void
    {
        self::assertSame('only text', $this->message('s', 'only text')->text);
        self::assertSame('<p>only html</p>', $this->message('s', null, '<p>only html</p>')->html);
    }

    /**
     * **T-10's second surface.** Every payload in the corpus, applied to the subject — the field PHP
     * would silently flatten to spaces rather than refuse (probed against a real SMTP transport).
     */
    #[DataProvider('injectionPayloads')]
    public function testNoInjectionPayloadCanBecomeASubject(string $payload): void
    {
        $this->expectException(MailException::class);

        $this->message($payload);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionPayloads(): iterable
    {
        yield from HeaderInjectionPayloads::appliedTo('a subject');
    }

    public function testTheSubjectRefusalExplainsWhyItIsNotStripped(): void
    {
        try {
            $this->message("a subject\r\nBcc: victim@example.com");
            self::fail('a subject with CRLF must be refused');
        } catch (MailException $e) {
            // The message is the documentation a caller actually reads.
            self::assertStringContainsString('refused rather than stripped', $e->getMessage());
        }
    }

    /**
     * A body may contain anything at all: it is not a header, and the transport encodes it. Asserted
     * so that a future tightening of the subject rule is not copy-pasted onto the body, where it
     * would refuse ordinary multi-line text.
     */
    public function testABodyMayContainNewlines(): void
    {
        $message = $this->message('s', "line one\r\nline two\n\nline four");

        self::assertStringContainsString('line two', (string) $message->text);
    }

    public function testAnAsciiSubjectIsNotEncoded(): void
    {
        self::assertSame('a plain subject', $this->message('a plain subject')->encodedSubject());
    }

    public function testANonAsciiSubjectBecomesRfc2047EncodedWords(): void
    {
        $subject = 'Résumé';
        $encoded = $this->message($subject)->encodedSubject();

        self::assertStringStartsWith('=?UTF-8?B?', $encoded);
        self::assertStringEndsWith('?=', $encoded);
        // A header must be 7-bit per RFC 5322; that is the whole reason for the encoding.
        self::assertMatchesRegularExpression('/^[\x20-\x7E\r\n]*$/', $encoded);
        self::assertSame($subject, \base64_decode(\substr($encoded, 10, -2), true));
    }

    public function testALongNonAsciiSubjectIsSplitIntoSeveralEncodedWords(): void
    {
        // 60 accented characters: one encoded-word would be 92 characters against RFC 2047's 75.
        $subject = \str_repeat('è', 60);
        $encoded = $this->message($subject)->encodedSubject();

        $words = \explode("\r\n ", $encoded);
        self::assertGreaterThan(1, \count($words), 'a long subject must fold into several words');

        $decoded = '';
        foreach ($words as $word) {
            self::assertLessThanOrEqual(75, \strlen($word), "encoded-word over RFC 2047's limit: {$word}");
            self::assertStringStartsWith('=?UTF-8?B?', $word);
            $decoded .= (string) \base64_decode(\substr($word, 10, -2), true);
        }

        self::assertSame($subject, $decoded, 'the folded words must reassemble to the subject');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multiByteSubjects(): iterable
    {
        // The widths matter, and getting this wrong is how the test was vacuous on its first run.
        // An encoded-word's payload is 45 bytes here (75 minus 12 of delimiters, rounded down to a
        // multiple of 3 so base64 does not pad mid-word). 45 is divisible by 3, so a subject made
        // only of 3-byte characters lands on a character boundary *by arithmetic* — and a byte-wise
        // split passes. Two-byte and four-byte characters are what actually exercise the boundary.
        yield 'two-byte (Latin-1 supplement)' => [\str_repeat('è', 60)];
        yield 'four-byte (emoji)' => [\str_repeat('😀', 30)];
        yield 'three-byte (aligns with the chunk size — kept as the easy case)' => [\str_repeat('日本語の件名', 12)];
        yield 'mixed widths' => [\str_repeat('aè日😀', 20)];
    }

    /**
     * The failure this split exists to avoid: a multi-byte character cut across two encoded-words
     * decodes to a replacement glyph in every client.
     */
    #[DataProvider('multiByteSubjects')]
    public function testNoMultiByteCharacterIsSplitAcrossWords(string $subject): void
    {
        $words = \explode("\r\n ", $this->message($subject)->encodedSubject());
        $decoded = '';

        foreach ($words as $word) {
            $part = (string) \base64_decode(\substr($word, 10, -2), true);
            // `//u` fails to compile against invalid UTF-8, which is the check — and it needs no
            // mbstring, an extension this library has never declared (ADR-0019).
            self::assertSame(1, \preg_match('//u', $part), 'a word decoded to invalid UTF-8');
            $decoded .= $part;
        }

        // Reassembly alone would NOT catch a byte-wise split — concatenating byte chunks returns the
        // same bytes — so it is asserted here as a companion to the per-word check, not instead of it.
        self::assertSame($subject, $decoded);
    }

    public function testAnEmptySubjectIsAllowed(): void
    {
        // Unusual but legal, and not this class's business to police: a subject-less notification is
        // a real thing, while a *corrupted* subject is not.
        self::assertSame('', $this->message('')->encodedSubject());
    }
}
