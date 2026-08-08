<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Mail;

use D4np\Utils\Mail\EmailAddress;
use D4np\Utils\Mail\Mailer;
use D4np\Utils\Mail\MailMessage;
use D4np\Utils\Mail\NativeMailer;
use D4np\Utils\Support\MailException;
use D4np\Utils\Tests\Mail\Fixture\HeaderInjectionPayloads;
use D4np\Utils\Tests\Mail\Fixture\RecordingMailApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The transport boundary (spec FR-44), and T-10's third leg — what reaches PHP's `mail()`.
 */
#[CoversClass(NativeMailer::class)]
#[Group('T-10')]
final class NativeMailerTest extends TestCase
{
    private RecordingMailApi $api;

    protected function setUp(): void
    {
        $this->api = new RecordingMailApi();
    }

    private function address(string $value): EmailAddress
    {
        return EmailAddress::of($value);
    }

    /**
     * @param list<EmailAddress> $cc
     * @param list<EmailAddress> $bcc
     */
    private function message(
        string $subject = 'a subject',
        ?string $text = 'a text body',
        ?string $html = null,
        array $cc = [],
        array $bcc = [],
        ?EmailAddress $replyTo = null,
    ): MailMessage {
        return MailMessage::create(
            $this->address('from@example.com'),
            $this->address('to@example.com'),
            $subject,
            $text,
            $html,
            $cc,
            $bcc,
            $replyTo,
        );
    }

    public function testIsAMailer(): void
    {
        self::assertInstanceOf(Mailer::class, new NativeMailer());
    }

    public function testTheEnvelopeCarriesTheRecipientsAndTheHeadersTheSender(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message());

        $call = $this->api->lastCall();
        self::assertSame('to@example.com', $call['to']);
        self::assertSame('a subject', $call['subject']);
        self::assertSame('from@example.com', $call['headers']['From']);
    }

    public function testCcAndBccBecomeHeadersAndReplyToIsOptional(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message(
            cc: [$this->address('cc@example.com')],
            bcc: [$this->address('bcc@example.com')],
            replyTo: $this->address('reply@example.com'),
        ));

        $headers = $this->api->lastCall()['headers'];
        // Bcc goes through as a header of the ARRAY form on purpose: probed, PHP issues a `RCPT TO`
        // for it and omits it from the message it sends, which is what RFC 5322 asks for.
        self::assertSame('cc@example.com', $headers['Cc']);
        self::assertSame('bcc@example.com', $headers['Bcc']);
        self::assertSame('reply@example.com', $headers['Reply-To']);
    }

    public function testNoReplyToHeaderWhenNoneWasGiven(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message());

        self::assertArrayNotHasKey('Reply-To', $this->api->lastCall()['headers']);
        self::assertArrayNotHasKey('Cc', $this->api->lastCall()['headers']);
        self::assertArrayNotHasKey('Bcc', $this->api->lastCall()['headers']);
    }

    /**
     * **The mechanism assertion this class exists for.** Both header shapes send a working email, and
     * only the array form makes PHP validate: probed, an injected `CR`/`LF` in an array value throws
     * a `ValueError`, while the same bytes in a string header block are *parsed* and a second
     * `RCPT TO` is issued. No behavioural test can see the difference, so the shape is asserted
     * directly (ADR-0027's rule).
     */
    public function testHeadersAreHandedOverAsAnArrayNeverAsAStringBlock(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message());

        $headers = $this->api->lastCall()['headers'];
        self::assertIsArray($headers);
        self::assertNotSame([], $headers);

        foreach ($headers as $name => $value) {
            self::assertIsString($name, 'an array header must be keyed by its name');
            self::assertStringNotContainsString(':', $name, "header name {$name} carries a colon");
            self::assertDoesNotMatchRegularExpression('/[\r\n]/', $value, "header {$name} carries a terminator");
        }

        // And the seam's own signature keeps it that way: a `string` here would let a future edit
        // build a header block by hand without any test noticing.
        $parameter = (new ReflectionMethod($this->api, 'send'))->getParameters()[3];
        self::assertSame('array', (string) $parameter->getType());
    }

    /**
     * T-10's third surface: the corpus reaching the transport. Every payload is refused upstream, so
     * the assertion is that **no call happens at all** — the strongest form, since a refusal after
     * the transport was invoked would already have sent something.
     */
    #[DataProvider('injectionPayloads')]
    public function testNoInjectionPayloadEverReachesTheTransport(string $payload): void
    {
        $mailer = new NativeMailer(null, $this->api);

        // Through the subject...
        try {
            $mailer->send($this->message($payload));
        } catch (MailException) {
            // expected
        }

        // ...and through every address field.
        foreach (['from', 'to', 'cc', 'bcc', 'replyTo'] as $field) {
            try {
                $address = EmailAddress::of(\str_replace('a subject', 'user@example.com', $payload));
                $mailer->send(match ($field) {
                    'from' => MailMessage::create($address, $this->address('to@example.com'), 's', 'b'),
                    'to' => MailMessage::create($this->address('from@example.com'), $address, 's', 'b'),
                    'cc' => MailMessage::create($this->address('from@example.com'), $this->address('to@example.com'), 's', 'b', null, [$address]),
                    'bcc' => MailMessage::create($this->address('from@example.com'), $this->address('to@example.com'), 's', 'b', null, [], [$address]),
                    default => MailMessage::create($this->address('from@example.com'), $this->address('to@example.com'), 's', 'b', null, [], [], $address),
                });
            } catch (MailException) {
                // expected
            }
        }

        self::assertSame([], $this->api->calls, 'a payload reached the transport');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionPayloads(): iterable
    {
        yield from HeaderInjectionPayloads::appliedTo('a subject');
    }

    /**
     * The vacuity guard for the test above: with a legitimate message the transport *is* called, so
     * "no call happened" means something.
     */
    public function testALegitimateMessageDoesReachTheTransport(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message());

        self::assertCount(1, $this->api->calls);
        foreach (HeaderInjectionPayloads::forbiddenAtTheBoundary() as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->api->lastAsText());
        }
    }

    public function testATextOnlyMessageIsBase64EncodedAndDeclaredAsSuch(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message('s', 'a text body'));

        $call = $this->api->lastCall();
        self::assertSame('text/plain; charset=UTF-8', $call['headers']['Content-Type']);
        self::assertSame('base64', $call['headers']['Content-Transfer-Encoding']);
        self::assertSame('1.0', $call['headers']['MIME-Version']);
        self::assertSame('a text body', \base64_decode(\str_replace("\r\n", '', $call['message']), true));
    }

    public function testAnHtmlOnlyMessageDeclaresTextHtml(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message('s', null, '<p>hello</p>'));

        $call = $this->api->lastCall();
        self::assertSame('text/html; charset=UTF-8', $call['headers']['Content-Type']);
        self::assertSame('<p>hello</p>', \base64_decode(\str_replace("\r\n", '', $call['message']), true));
    }

    public function testBothBodiesBecomeMultipartAlternativeWithBothPartsIntact(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message('s', 'the text', '<p>the html</p>'));

        $call = $this->api->lastCall();
        self::assertMatchesRegularExpression(
            '#^multipart/alternative; boundary="=_[0-9a-f]{32}"$#',
            $call['headers']['Content-Type'],
        );

        // Sliced rather than captured: the assertion above already pinned the header's exact shape,
        // and a `preg_match()` capture would leave PHPStan unable to prove the offset exists.
        $boundary = \substr($call['headers']['Content-Type'], \strlen('multipart/alternative; boundary="'), -1);

        self::assertStringContainsString("--{$boundary}\r\n", $call['message']);
        self::assertStringContainsString("--{$boundary}--", $call['message']);
        self::assertStringContainsString('text/plain; charset=UTF-8', $call['message']);
        self::assertStringContainsString('text/html; charset=UTF-8', $call['message']);

        // Both parts must survive the encoding, or the alternative is one body and a mystery.
        self::assertStringContainsString(\base64_encode('the text'), $call['message']);
        self::assertStringContainsString(\base64_encode('<p>the html</p>'), $call['message']);
    }

    public function testTheBoundaryIsFreshEveryTime(): void
    {
        $mailer = new NativeMailer(null, $this->api);
        $mailer->send($this->message('s', 'a', '<p>b</p>'));
        $mailer->send($this->message('s', 'a', '<p>b</p>'));

        self::assertNotSame(
            $this->api->calls[0]['headers']['Content-Type'],
            $this->api->calls[1]['headers']['Content-Type'],
        );
    }

    public function testEncodedBodyLinesRespectRfc2045sSeventySixCharacterLimit(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message('s', \str_repeat('a body that is long. ', 40)));

        foreach (\explode("\r\n", $this->api->lastCall()['message']) as $line) {
            self::assertLessThanOrEqual(76, \strlen($line));
        }
    }

    public function testTheEnvelopeSenderIsPassedAsASendmailOptionOnlyWhenConfigured(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message());
        self::assertSame('', $this->api->lastCall()['parameters']);

        (new NativeMailer($this->address('bounce@example.com'), $this->api))->send($this->message());
        self::assertSame('-fbounce@example.com', $this->api->lastCall()['parameters']);
    }

    /**
     * The envelope sender reaches a command line on a sendmail host, so it is worth asserting that
     * nothing shell-significant can appear in it. It cannot, because it is an `EmailAddress` — this
     * pins the reason rather than the mechanism.
     */
    public function testTheEnvelopeSenderCannotCarryShellSignificantCharacters(): void
    {
        foreach (['a@b.com; rm -rf /', 'a@b.com`id`', 'a@b.com $(id)', "a@b.com\nX: y", 'a@b.com|tee'] as $hostile) {
            self::assertNull(EmailAddress::tryOf($hostile), "an EmailAddress must refuse: {$hostile}");
        }
    }

    public function testADeclinedMessageThrowsAndNamesEveryRecipient(): void
    {
        $api = new RecordingMailApi(succeeds: false);

        try {
            (new NativeMailer(null, $api))->send($this->message(
                cc: [$this->address('cc@example.com')],
                bcc: [$this->address('bcc@example.com')],
            ));
            self::fail('a declined message must throw');
        } catch (MailException $e) {
            self::assertStringContainsString('to@example.com', $e->getMessage());
            self::assertStringContainsString('cc@example.com', $e->getMessage());
            self::assertStringContainsString('bcc@example.com', $e->getMessage());
            // The two indistinguishable causes are named, since mail() cannot tell them apart.
            self::assertStringContainsString('sendmail_path', $e->getMessage());
        }
    }

    public function testANonAsciiSubjectIsHandedOverEncoded(): void
    {
        (new NativeMailer(null, $this->api))->send($this->message('Résumé'));

        $subject = $this->api->lastCall()['subject'];
        self::assertStringStartsWith('=?UTF-8?B?', $subject);
        self::assertSame('Résumé', \base64_decode(\substr($subject, 10, -2), true));
    }

    public function testMultipleRecipientsAreCommaSeparated(): void
    {
        (new NativeMailer(null, $this->api))->send(MailMessage::create(
            $this->address('from@example.com'),
            [$this->address('one@example.com'), $this->address('two@example.com')],
            's',
            'b',
        ));

        self::assertSame('one@example.com, two@example.com', $this->api->lastCall()['to']);
    }
}
