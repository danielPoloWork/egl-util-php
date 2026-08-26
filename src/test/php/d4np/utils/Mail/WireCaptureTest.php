<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Mail;

use D4np\Utils\Mail\EmailAddress;
use D4np\Utils\Mail\MailMessage;
use D4np\Utils\Mail\NativeMailApi;
use D4np\Utils\Mail\NativeMailer;
use D4np\Utils\Support\MailException;
use D4np\Utils\Tests\Mail\Fixture\HeaderInjectionPayloads;
use D4np\Utils\Tests\Mail\Fixture\Mailpit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-10's fourth leg: what actually leaves on the wire (issue #101, ADR-0078).
 *
 * The other three legs stop at a seam, deliberately and correctly — {@see NativeMailerTest} asserts
 * the array-header *mechanism* against {@see Fixture\RecordingMailApi}, which is the only place that
 * property is visible. What no seam can witness is SMTP: whether a `Bcc` recipient is actually
 * delivered, whether an RFC 2047 subject *decodes back to the subject that was written*, whether the
 * envelope sender arrives at all. ADR-0056 answered those by probing a real transport by hand and
 * writing the results into prose. This suite is the same questions, asked by something that runs.
 *
 * **Configured, or skipped.** Mailpit is a container; unlike T-03's and T-07's `php -S` it cannot be
 * provisioned by a bare checkout, so with no {@see Mailpit::BASE_URL} the class skips. Configured,
 * every failure to use it is red — including a skip, which CI turns into a failure with
 * `--fail-on-skipped`. That is ADR-0071's fork, for its reason.
 *
 * **Read {@see Mailpit}'s notes on the receiver before adding an assertion here.** Mailpit rewrites
 * the message it stores: it prepends a synthetic `Bcc:` header naming any envelope recipient the
 * headers omit. The naive assertion — "no `Bcc:` header survived" — therefore fails against a
 * pipeline that is working perfectly, and the correct test is its inverse.
 */
#[CoversClass(NativeMailer::class)]
#[CoversClass(NativeMailApi::class)]
#[Group('T-10')]
#[Group('mail-wire')]
final class WireCaptureTest extends TestCase
{
    private const FROM = 'from@example.com';

    private const TO = 'to@example.com';

    /** The reachability verdict, probed once rather than once per test. `null` until first asked. */
    private static ?string $unusable = null;

    /**
     * **The guard lives here, in `setUp()`, and not in `setUpBeforeClass()` — measured, not assumed.**
     *
     * The obvious home for a whole-class precondition is `setUpBeforeClass()`, and that is where this
     * was written first. It is wrong, for a reason that is invisible until you check the exit code:
     * a skip raised from `setUpBeforeClass()` becomes a skipped *test suite* with **zero executed
     * tests**, and `phpunit --group mail-wire --fail-on-skipped` then exits **0**. So does
     * `--fail-on-empty-test-suite`. All four combinations were run; all four were green.
     *
     * That matters because `--fail-on-skipped` is precisely the guard ADR-0071 leans on for the
     * database leg, where skips are raised per test from `enginePdo()`. Kept in
     * `setUpBeforeClass()`, the flag would have been **inert here**, and a CI job whose
     * `EGL_TEST_MAILPIT_URL` never arrived would have reported a green wire leg having sent no mail
     * at all — the exact failure issue #101's second criterion was written against.
     *
     * Raised per test, the skip is a skipped *test*, which `--fail-on-skipped` does see.
     */
    protected function setUp(): void
    {
        if (!Mailpit::isConfigured()) {
            self::markTestSkipped(\sprintf(
                'T-10\'s wire leg needs a Mailpit instance and no %s is configured. Unlike T-03 and '
                . 'T-07, which spawn a `php -S` that ships with PHP, this leg cannot provision its '
                . 'own receiver, so an unconfigured run skips rather than pretending. CI runs with '
                . '--fail-on-skipped, where this message is a red leg.',
                Mailpit::BASE_URL,
            ));
        }

        // '' is a valid verdict meaning "usable", so the coalesce only re-probes while it is null.
        self::$unusable ??= Mailpit::unusable();

        if (self::$unusable !== '') {
            self::fail("T-10's wire leg is configured but cannot run: " . self::$unusable);
        }

        // Every test sends exactly one message into an empty mailbox, which is what lets the fixture
        // read `latest` rather than thread an id through a listing.
        Mailpit::purge();
    }

    private static function address(string $value): EmailAddress
    {
        return EmailAddress::of($value);
    }

    /**
     * @param list<EmailAddress> $cc
     * @param list<EmailAddress> $bcc
     */
    private static function message(
        string $subject = 'a wire subject',
        ?string $text = 'a text body',
        ?string $html = null,
        array $cc = [],
        array $bcc = [],
        ?EmailAddress $replyTo = null,
    ): MailMessage {
        return MailMessage::create(
            self::address(self::FROM),
            self::address(self::TO),
            $subject,
            $text,
            $html,
            $cc,
            $bcc,
            $replyTo,
        );
    }

    /**
     * The baseline, and the vacuity guard every negative assertion below depends on: if this fails,
     * "nothing arrived" elsewhere means the pipeline is broken rather than the defence working.
     */
    public function testALegitimateMessageArrivesWithItsHeadersAndItsBody(): void
    {
        (new NativeMailer())->send(self::message(replyTo: self::address('reply@example.com')));

        $mail = Mailpit::awaitOne();

        self::assertSame('a wire subject', $mail->subject());
        self::assertSame([self::TO], $mail->to());
        self::assertStringContainsString(self::FROM, (string) $mail->header('From'));
        self::assertStringContainsString('reply@example.com', (string) $mail->header('Reply-To'));
        self::assertSame('1.0', $mail->header('MIME-Version'));
        self::assertSame('text/plain; charset=UTF-8', $mail->header('Content-Type'));
        self::assertSame('base64', $mail->header('Content-Transfer-Encoding'));

        // Asserted against the raw source rather than a decoded body, because the encoding is the
        // claim: ADR-0056 D6 chose base64 so no relay could fold or mangle a long UTF-8 line.
        self::assertStringContainsString(\base64_encode('a text body'), $mail->raw);
    }

    /**
     * **ADR-0056 D3's delivery claim, finally witnessed.** The decision records that `Bcc` goes
     * through as an array header because PHP "issues a `RCPT TO` for it and omits the header from
     * the message it sends" — probed by hand, never tested.
     *
     * The half that is observable here is the one a consumer cares about: the bcc recipient is
     * delivered. The witness is indirect and worth stating precisely, because it looks backwards.
     * msmtp strips `Bcc:` on the wire, so the address reaches Mailpit **only** in the envelope;
     * Mailpit then re-adds a `Bcc:` header naming exactly the envelope recipients the headers did
     * not mention. So a non-empty `bcc()` can only have come from `RCPT TO` — had the address never
     * been an envelope recipient, the header would have been stripped and Mailpit would have had
     * nothing to put back.
     *
     * The other half — that the header did not travel — is **not observable through this receiver**
     * at all, and is recorded as a known limit in ADR-0078 rather than asserted weakly.
     */
    public function testABccRecipientIsDeliveredAsAnEnvelopeRecipient(): void
    {
        (new NativeMailer())->send(self::message(
            cc: [self::address('cc@example.com')],
            bcc: [self::address('bcc@example.com')],
        ));

        $mail = Mailpit::awaitOne();

        self::assertContains('bcc@example.com', $mail->bcc(), 'the bcc address never reached RCPT TO');

        // And it is hidden from the disclosed recipient fields, which is the property `Bcc` exists
        // for. Both are asserted: the address being *somewhere* is not the claim.
        self::assertSame([self::TO], $mail->to());
        self::assertSame(['cc@example.com'], $mail->cc());
    }

    /**
     * **The subject assertion the seam could never make.** {@see NativeMailerTest} proves
     * `encodedSubject()` produces well-formed encoded-words; only a receiver can prove they decode
     * back to the subject that was written.
     *
     * The corpus deliberately spans **byte widths and lengths**, which is ADR-0056's own hard-won
     * lesson: its planted-defect campaign found that splitting the subject on bytes instead of
     * characters passed the whole suite, because every test subject used three-byte characters and
     * the 45-byte payload budget is a multiple of three — every split landed on a boundary by
     * arithmetic. *A corpus whose members all share one width cannot test a boundary computed in
     * that width.*
     *
     * The long entries matter for a second reason this leg is the first to reach. Over 45 bytes,
     * `encodedSubject()` folds into several encoded-words joined by `CRLF` and a space — and
     * ADR-0056's own probe table records that PHP **flattens `CRLF` in `mail()`'s `$subject` to
     * spaces**. Nothing had ever sent a folded subject through a real `mail()`, so whether the fold
     * survives, is flattened harmlessly (RFC 2047 §6.2 ignores whitespace between adjacent
     * encoded-words), or is refused outright was unknown before this suite ran.
     */
    #[DataProvider('subjects')]
    public function testAnEncodedSubjectDecodesToWhatWasWritten(string $subject): void
    {
        (new NativeMailer())->send(self::message($subject));

        self::assertSame($subject, Mailpit::awaitOne()->subject());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function subjects(): iterable
    {
        yield 'pure ASCII' => ['a plain ascii subject'];
        yield 'two-byte, one encoded-word' => ['Résumé'];
        yield 'three-byte, one encoded-word' => ['日本語'];
        yield 'four-byte, one encoded-word' => ['🎉 done'];

        // Each of these exceeds the 45-byte payload budget, so each folds into several
        // encoded-words. One per width, plus a mixed one, per the rule quoted above.
        yield 'two-byte, folded' => [\str_repeat('é', 40)];
        yield 'three-byte, folded' => [\str_repeat('日', 30)];
        yield 'four-byte, folded' => [\str_repeat('🎉', 20)];
        yield 'mixed widths, folded' => [\str_repeat('aé日🎉', 10)];
    }

    /**
     * ADR-0056 D4: the envelope sender is `mail()`'s fifth argument, reaching the `sendmail` command
     * line. Documented as a no-op on the Windows SMTP transport — and until this leg, never observed
     * arriving anywhere either.
     */
    public function testTheEnvelopeSenderReachesTheWire(): void
    {
        (new NativeMailer(self::address('bounce@example.com')))->send(self::message());

        self::assertStringContainsString('bounce@example.com', Mailpit::awaitOne()->returnPath());
    }

    public function testBothBodiesArriveAsMultipartAlternative(): void
    {
        (new NativeMailer())->send(self::message(text: 'the text part', html: '<p>the html part</p>'));

        $mail = Mailpit::awaitOne();

        self::assertStringStartsWith('multipart/alternative;', (string) $mail->header('Content-Type'));
        self::assertStringContainsString('text/plain; charset=UTF-8', $mail->raw);
        self::assertStringContainsString('text/html; charset=UTF-8', $mail->raw);
        self::assertStringContainsString(\base64_encode('the text part'), $mail->raw);
        self::assertStringContainsString(\base64_encode('<p>the html part</p>'), $mail->raw);
    }

    /**
     * The whole T-10 corpus through the public API, asserted on the wire.
     *
     * Every payload is refused by {@see MailMessage::create()} or {@see EmailAddress::of()}, so the
     * claim is that **no SMTP conversation happens at all** — the strongest form, and one the seam
     * can only approximate. Sent in one test with a single settle rather than one test per payload:
     * nineteen payloads each waiting to be sure nothing arrived would cost a minute for one
     * assertion.
     */
    public function testNoInjectionPayloadEverReachesTheWire(): void
    {
        $attempted = 0;

        foreach (HeaderInjectionPayloads::all() as $template) {
            $payload = \str_replace('%s', 'a subject', $template);
            $asAddress = \str_replace('%s', 'user@example.com', $template);

            foreach ([
                static fn (): MailMessage => self::message($payload),
                static fn (): MailMessage => MailMessage::create(self::address($asAddress), self::address(self::TO), 's', 'b'),
                static fn (): MailMessage => MailMessage::create(self::address(self::FROM), self::address($asAddress), 's', 'b'),
                static fn (): MailMessage => MailMessage::create(self::address(self::FROM), self::address(self::TO), 's', 'b', null, [], [self::address($asAddress)]),
            ] as $build) {
                $attempted++;

                try {
                    (new NativeMailer())->send($build());
                } catch (MailException) {
                    // Expected: refused before a transport was ever reached.
                }
            }
        }

        self::assertGreaterThan(0, $attempted, 'the corpus was empty, so the assertion below is vacuous');
        self::assertSame(0, Mailpit::countAfterSettling(), 'an injection payload reached the wire');
    }

    /**
     * The array header form, at the transport rather than at the seam: PHP raises `ValueError` on a
     * terminator in an array header value, {@see NativeMailApi} converts that to `false`, and
     * **nothing is sent**.
     *
     * This bypasses {@see MailMessage} on purpose. Upstream validation makes the array form defence
     * in *depth* (ADR-0056 D3), and the only way to exercise a defence in depth is to stand where
     * the first defence has already been passed — here, the failure mode D3 was chosen for: a future
     * edit that loosens the construction-time refusal.
     */
    public function testTheArrayHeaderFormRefusesATerminatorAndSendsNothing(): void
    {
        $refused = (new NativeMailApi())->send(
            self::TO,
            'a subject',
            'a body',
            ['From' => self::FROM, 'X-Injected' => "value\r\nBcc: victim@example.com"],
            '',
        );

        self::assertFalse($refused, 'PHP accepted a terminator in an array header value');
        self::assertSame(0, Mailpit::countAfterSettling(), 'a message went out despite the refusal');
    }

    /**
     * **The counterfactual that makes ADR-0056 D3 a decision rather than a preference.**
     *
     * D3 hands `mail()` an array because the array is the form PHP validates; alternative 3 — the
     * string header block almost all legacy code passes — was rejected on a hand-probe showing an
     * injected `Bcc` *delivered*. That is the load-bearing claim of the whole group, and nothing has
     * ever tested it, because no test that stays inside this library's API can: the library cannot
     * build a string block.
     *
     * So this calls `mail()` directly, exactly as the rejected alternative would, and asserts the
     * injection **succeeds** — a passing test here is the evidence that the array form is load
     * bearing. If this ever starts failing, PHP's behaviour has changed and D3's justification needs
     * re-reading; that is a finding, not a regression.
     *
     * Safe by construction: the payload is an RFC 2606 example domain and the only MTA in reach is
     * this job's throwaway sink.
     */
    public function testTheRejectedStringHeaderBlockWouldDeliverAnInjectedBcc(): void
    {
        $block = 'From: ' . self::FROM . "\r\nBcc: victim@example.com";

        self::assertTrue(
            @\mail(self::TO, 'a subject', 'a body', $block),
            'the string header block was refused, so the assertion below cannot show what D3 rejected',
        );

        $mail = Mailpit::awaitOne();

        // The smuggled address became a real envelope recipient: the string form does not validate,
        // it *parses*. This is the bug class D3 makes unreachable through this library's API.
        self::assertContains('victim@example.com', $mail->bcc(), 'the injected Bcc was not delivered, so D3 rests on a claim this leg cannot reproduce');
    }
}
