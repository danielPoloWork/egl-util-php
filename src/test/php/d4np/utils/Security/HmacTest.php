<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Base64Url;
use D4np\Utils\Security\Hmac;
use D4np\Utils\Security\SecretKey;
use D4np\Utils\Support\CryptoException;
use D4np\Utils\Support\FrozenClock;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec r20 FR-48 (RFC-0003), ADR-0065: keyed authentication for signed URLs and webhook signatures.
 *
 * Two assertions here are **mechanisms** per ADR-0027, because behaviour cannot observe either:
 * that the algorithm handed to `hash_hmac()` comes from the allowlist rather than from a caller's
 * string, and that the MAC's expected length is the allowlist's rather than the token's. The
 * comparator assertion — that `hash_equals()` is what compares the two MACs — lives in
 * {@see ConstantTimeComparisonTest}, which owns that registry for the whole library.
 *
 * The security property with the most teeth is {@see testEditingTheExpiryBreaksTheSignature()}: it
 * is the only test that fails if the MAC is computed over the message alone, and a MAC that did not
 * cover the expiry would make extending a signed URL's lifetime a matter of editing eight bytes.
 */
final class HmacTest extends TestCase
{
    private const MESSAGE = '/reports/42?format=csv';

    private static function hmac(?FrozenClock $clock = null, string $algorithm = 'sha256'): Hmac
    {
        return new Hmac(SecretKey::generate(), $clock, $algorithm);
    }

    private static function clockAt(string $instant = '2026-08-20 12:00:00'): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable($instant));
    }

    // -----------------------------------------------------------------------------------------
    // The round trip, and the token's shape
    // -----------------------------------------------------------------------------------------

    public function testAValidTokenVerifies(): void
    {
        $hmac = self::hmac();

        $hmac->verify(self::MESSAGE, $hmac->sign(self::MESSAGE));

        // verify() returns void: reaching this line without an exception IS the assertion.
        $this->expectNotToPerformAssertions();
    }

    public function testTheTokenCarriesTheVersionPrefixCryptoEstablished(): void
    {
        self::assertStringStartsWith('v1.', self::hmac()->sign(self::MESSAGE));
    }

    public function testTheTokenIsUrlSafeAndUnpadded(): void
    {
        $token = \substr(self::hmac()->sign(self::MESSAGE), 3);

        self::assertMatchesRegularExpression(
            '/\A[A-Za-z0-9_-]+\z/',
            $token,
            'a signed URL carries this in a query parameter; "+", "/" and "=" would each need '
            . 'escaping, and an unescaped "+" decodes to a space',
        );
    }

    public function testSigningIsDeterministicWithoutAnExpiry(): void
    {
        $hmac = self::hmac();

        self::assertSame(
            $hmac->sign(self::MESSAGE),
            $hmac->sign(self::MESSAGE),
            'unlike Crypto, which draws a fresh nonce per call, an HMAC over fixed input is fixed '
            . '— which is what lets a webhook receiver compare a stored signature at all',
        );
    }

    public function testDifferentMessagesProduceDifferentTokens(): void
    {
        $hmac = self::hmac();

        self::assertNotSame($hmac->sign('/a'), $hmac->sign('/b'));
    }

    public function testAnEmptyMessageIsSignableAndVerifiable(): void
    {
        $hmac = self::hmac();

        $hmac->verify('', $hmac->sign(''));

        $this->expectNotToPerformAssertions();
    }

    public function testABinaryMessageRoundTrips(): void
    {
        $hmac = self::hmac();
        $message = \random_bytes(64) . "\0\n\r";

        $hmac->verify($message, $hmac->sign($message));

        $this->expectNotToPerformAssertions();
    }

    // -----------------------------------------------------------------------------------------
    // Every way verification must fail
    // -----------------------------------------------------------------------------------------

    public function testADifferentMessageIsRefused(): void
    {
        $hmac = self::hmac();
        $token = $hmac->sign(self::MESSAGE);

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE . '&admin=1', $token);
    }

    public function testAWrongKeyIsRefused(): void
    {
        $token = self::hmac()->sign(self::MESSAGE);

        $this->expectException(CryptoException::class);
        self::hmac()->verify(self::MESSAGE, $token);
    }

    public function testASingleFlippedBitInTheMacIsRefused(): void
    {
        $hmac = self::hmac();
        $payload = (string) Base64Url::decode(\substr($hmac->sign(self::MESSAGE), 3));

        // Flip the lowest bit of the last MAC byte — the smallest possible tamper.
        $payload[\strlen($payload) - 1] = \chr(\ord($payload[-1]) ^ 1);

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, 'v1.' . Base64Url::encode($payload));
    }

    public function testAnUnrecognisedVersionPrefixIsRefused(): void
    {
        $hmac = self::hmac();
        $token = $hmac->sign(self::MESSAGE);

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, 'v2.' . \substr($token, 3));
    }

    public function testAMissingVersionPrefixIsRefused(): void
    {
        $hmac = self::hmac();
        $token = $hmac->sign(self::MESSAGE);

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, \substr($token, 3));
    }

    public function testMalformedBase64IsRefused(): void
    {
        $this->expectException(CryptoException::class);
        self::hmac()->verify(self::MESSAGE, 'v1.not valid base64url!!');
    }

    public function testATruncatedTokenIsRefused(): void
    {
        $hmac = self::hmac();
        $payload = (string) Base64Url::decode(\substr($hmac->sign(self::MESSAGE), 3));

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, 'v1.' . Base64Url::encode(\substr($payload, 0, -1)));
    }

    /**
     * A correct MAC *prefix* must not be accepted at a shorter length.
     *
     * ADR-0054 found that OpenSSL's GCM tag check accepts a correct prefix of a real tag at any
     * length down to one byte. `hash_equals()` compares lengths first and so does not have that
     * flaw, but the payload-length check is what makes the property structural here rather than a
     * consequence of which comparator happens to be in use.
     */
    public function testACorrectMacPrefixIsRefused(): void
    {
        $hmac = self::hmac();
        $payload = (string) Base64Url::decode(\substr($hmac->sign(self::MESSAGE), 3));

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, 'v1.' . Base64Url::encode(\substr($payload, 0, 8 + 16)));
    }

    public function testAnOverlongPayloadIsRefused(): void
    {
        $hmac = self::hmac();
        $payload = (string) Base64Url::decode(\substr($hmac->sign(self::MESSAGE), 3));

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, 'v1.' . Base64Url::encode($payload . 'x'));
    }

    public function testAnEmptyTokenIsRefused(): void
    {
        $this->expectException(CryptoException::class);
        self::hmac()->verify(self::MESSAGE, '');
    }

    // -----------------------------------------------------------------------------------------
    // Expiry, through the clock seam — no test sleeps
    // -----------------------------------------------------------------------------------------

    public function testATokenVerifiesBeforeItsExpiry(): void
    {
        $clock = self::clockAt();
        $hmac = self::hmac($clock);
        $token = $hmac->sign(self::MESSAGE, new DateInterval('PT15M'));

        $clock->advance(new DateInterval('PT14M59S'));
        $hmac->verify(self::MESSAGE, $token);

        $this->expectNotToPerformAssertions();
    }

    public function testATokenIsRefusedAfterItsExpiry(): void
    {
        $clock = self::clockAt();
        $hmac = self::hmac($clock);
        $token = $hmac->sign(self::MESSAGE, new DateInterval('PT15M'));

        $clock->advance(new DateInterval('PT15M1S'));

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, $token);
    }

    /**
     * The boundary is inclusive-expired, following RFC 7519's `exp` semantics: valid *before* the
     * instant it names, not at it. Pinned because either answer is defensible and an unpinned
     * boundary drifts.
     */
    public function testATokenIsAlreadyExpiredAtExactlyItsExpiryInstant(): void
    {
        $clock = self::clockAt();
        $hmac = self::hmac($clock);
        $token = $hmac->sign(self::MESSAGE, new DateInterval('PT15M'));

        $clock->advance(new DateInterval('PT15M'));

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, $token);
    }

    public function testATokenWithoutATtlNeverExpires(): void
    {
        $clock = self::clockAt();
        $hmac = self::hmac($clock);
        $token = $hmac->sign(self::MESSAGE);

        $clock->advance(new DateInterval('P100Y'));
        $hmac->verify(self::MESSAGE, $token);

        $this->expectNotToPerformAssertions();
    }

    /**
     * **The security property with the most teeth in this class.**
     *
     * The expiry travels in the token, so it must be covered by the MAC. If it were not, extending
     * a signed URL's life would be a matter of rewriting eight bytes and re-encoding — the
     * signature would still verify, because it never spoke for them. This is the only test in the
     * suite that fails when the MAC is computed over the message alone.
     */
    public function testEditingTheExpiryBreaksTheSignature(): void
    {
        $clock = self::clockAt();
        $hmac = self::hmac($clock);
        $payload = (string) Base64Url::decode(\substr($hmac->sign(self::MESSAGE, new DateInterval('PT1M')), 3));

        // Rewrite the eight expiry bytes to the year 2500, leaving the MAC untouched.
        $forged = \substr($payload, 8);
        $expiry = (new DateTimeImmutable('2500-01-01'))->getTimestamp();
        $bytes = '';
        for ($shift = 56; $shift >= 0; $shift -= 8) {
            $bytes .= \chr(($expiry >> $shift) & 0xFF);
        }

        $this->expectException(CryptoException::class);
        $hmac->verify(self::MESSAGE, 'v1.' . Base64Url::encode($bytes . $forged));
    }

    public function testAnInvertedTtlIsRefusedRatherThanSigned(): void
    {
        $ttl = new DateInterval('PT15M');
        $ttl->invert = 1;

        $this->expectException(CryptoException::class);
        self::hmac(self::clockAt())->sign(self::MESSAGE, $ttl);
    }

    public function testAZeroTtlIsRefusedRatherThanSigned(): void
    {
        $this->expectException(CryptoException::class);
        self::hmac(self::clockAt())->sign(self::MESSAGE, new DateInterval('PT0S'));
    }

    /**
     * A clock before the epoch cannot mint an eternal token by arithmetic.
     *
     * Timestamp 0 is the sentinel for "never expires", so an expiry that lands at or below it must
     * be refused rather than encoded — otherwise a bounded token silently becomes unbounded, which
     * is the "refuse, never clamp" rule this library applies to every other unvalidated value.
     */
    public function testAnExpiryAtOrBeforeTheEpochIsRefused(): void
    {
        $hmac = self::hmac(new FrozenClock(new DateTimeImmutable('1969-01-01')));

        $this->expectException(CryptoException::class);
        $hmac->sign(self::MESSAGE, new DateInterval('PT1S'));
    }

    // -----------------------------------------------------------------------------------------
    // The algorithm allowlist
    // -----------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function allowlistedAlgorithms(): iterable
    {
        yield 'sha256' => ['sha256', 32];
        yield 'sha384' => ['sha384', 48];
        yield 'sha512' => ['sha512', 64];
    }

    #[DataProvider('allowlistedAlgorithms')]
    public function testEveryAllowlistedAlgorithmRoundTrips(string $algorithm, int $macBytes): void
    {
        $hmac = self::hmac(null, $algorithm);
        $token = $hmac->sign(self::MESSAGE);

        $hmac->verify(self::MESSAGE, $token);

        self::assertSame(
            8 + $macBytes,
            \strlen((string) Base64Url::decode(\substr($token, 3))),
            'the payload is the fixed expiry width plus this algorithm\'s raw digest length',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedAlgorithms(): iterable
    {
        yield 'md5 is registered with hash_hmac but not vetted here' => ['md5'];
        yield 'sha1 is the estate default this class replaces' => ['sha1'];
        yield 'crc32b is not a cryptographic hash at all' => ['crc32b'];
        yield 'an unknown name' => ['not-an-algorithm'];
        yield 'the empty string' => [''];
        yield 'a case variant of an allowlisted name' => ['SHA256'];
    }

    #[DataProvider('refusedAlgorithms')]
    public function testAnAlgorithmOffTheAllowlistIsRefusedAtConstruction(string $algorithm): void
    {
        $this->expectException(CryptoException::class);
        new Hmac(SecretKey::generate(), null, $algorithm);
    }

    /**
     * Changing algorithm invalidates outstanding tokens rather than trusting them.
     *
     * This is the *consequence* of keeping the algorithm out of the token, and the reason that
     * trade is worth making: a token format that named its own algorithm would let an attacker
     * choose how their forgery gets checked, which is the JWT `alg`-confusion class of flaw.
     */
    public function testATokenDoesNotVerifyUnderADifferentAllowlistedAlgorithm(): void
    {
        $key = SecretKey::generate();
        $token = (new Hmac($key, null, 'sha256'))->sign(self::MESSAGE);

        $this->expectException(CryptoException::class);
        (new Hmac($key, null, 'sha512'))->verify(self::MESSAGE, $token);
    }

    // -----------------------------------------------------------------------------------------
    // Key derivation, and the format the v1. prefix promises
    // -----------------------------------------------------------------------------------------

    /**
     * The MAC key is **not** the `SecretKey`'s bytes.
     *
     * Observable, and therefore a behavioural test rather than a mechanism one: computing the plain
     * HMAC over the same signed bytes with the same raw key produces a different token, and it must.
     * A deployment with one `APP_SECRET` behind both {@see \D4np\Utils\Security\Crypto} and this
     * class would otherwise feed identical bytes to AES-256-GCM and to HMAC.
     */
    public function testTheMacKeyIsDerivedRatherThanTheSecretKeyItself(): void
    {
        $key = SecretKey::fromBytes(\str_repeat("\x2a", 32));
        $signedBytes = \str_repeat("\0", 8) . self::MESSAGE;

        $undomained = 'v1.' . Base64Url::encode(
            \str_repeat("\0", 8) . \hash_hmac('sha256', $signedBytes, $key->bytes(), true),
        );

        self::assertNotSame(
            $undomained,
            (new Hmac($key))->sign(self::MESSAGE),
            'the token must not be a plain HMAC under the caller\'s own key material',
        );
    }

    public function testTheDerivationIsDeterministicAcrossInstances(): void
    {
        $key = SecretKey::fromBytes(\str_repeat("\x2a", 32));

        self::assertSame(
            (new Hmac($key))->sign(self::MESSAGE),
            (new Hmac($key))->sign(self::MESSAGE),
            'a receiver constructs its own Hmac from the same secret; if derivation were not '
            . 'deterministic across instances, nothing would ever verify',
        );
    }

    /**
     * A conformance vector, pinning the whole `v1.` grammar against a fixed key and message.
     *
     * The format is a compatibility promise the prefix makes: the HKDF label, the eight-byte
     * big-endian expiry, its position ahead of the MAC, the raw (not hex) digest, and unpadded
     * base64url. Every one of those is invisible to a round-trip test, which passes for any
     * self-consistent format. This is what makes a silent change to the grammar — the kind a
     * refactor makes without meaning to — a failing test rather than a fleet of tokens that stop
     * verifying after a deploy.
     */
    public function testTheV1FormatMatchesItsConformanceVector(): void
    {
        $key = SecretKey::fromBase64('KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKio=');

        self::assertSame(
            'v1.AAAAAAAAAACUAiUs-Ahev2YXclrvSGHc_CkiFoc9uW8SDii9EIcpiQ',
            (new Hmac($key))->sign('/reports/42'),
        );
    }

    // -----------------------------------------------------------------------------------------
    // Mechanism assertions (ADR-0027) — properties no behavioural test can observe
    // -----------------------------------------------------------------------------------------

    /**
     * The algorithm reaching `hash_hmac()` is the instance's validated one, never a raw parameter.
     *
     * Behaviour cannot see this. An implementation that passed its constructor argument straight
     * through would satisfy every test above — the allowlist check would still reject `md5` at
     * construction, and every accepted algorithm would still round-trip. What would be gone is the
     * guarantee that the *only* value able to reach the primitive is one the allowlist produced.
     */
    public function testTheAlgorithmHandedToThePrimitiveComesFromTheInstance(): void
    {
        $source = self::sourceOf('mac');

        self::assertStringContainsString('hash_hmac(', $source, 'the primitive must be hash_hmac()');
        self::assertStringContainsString(
            'hash_hmac($this->algorithm',
            $source,
            'hash_hmac() must be called with the property the constructor validated, not with a '
            . 'parameter — an allowlist consulted once at construction and then bypassed at the '
            . 'call site is not an allowlist',
        );
    }

    /**
     * `verify()` derives the expected payload length from the allowlist, never from the token.
     *
     * ADR-0054's finding: OpenSSL accepts a correct *prefix* of a GCM tag at any length, so a
     * format whose authenticator length is attacker-influenced hands back the lever the fixed
     * lengths closed. The equivalent mistake here is `strlen()` on token-derived bytes deciding
     * how much of the payload is MAC.
     */
    public function testTheExpectedPayloadLengthComesFromTheAllowlistNotTheToken(): void
    {
        $source = self::sourceOf('verify');

        self::assertStringContainsString(
            '$this->macBytes',
            $source,
            'the MAC length must come from the ALGORITHMS table via the constructor',
        );
        self::assertDoesNotMatchRegularExpression(
            '/strlen\(\s*\$(payload|mac|token|expiryBytes)\s*\)\s*[-+]/',
            $source,
            'a length arithmetic rooted in the token itself is how a variable-length '
            . 'authenticator gets in',
        );
    }

    /**
     * The MAC is verified before the expiry is decoded.
     *
     * Invisible to behaviour: every input produces the same outcome under either order, because a
     * token that fails both checks throws either way. What changes is whether the class ever acts
     * on bytes nothing has vouched for — and whether the failure message can tell an attacker
     * "expired" for a token whose signature was never valid.
     */
    public function testTheMacIsCheckedBeforeTheExpiryIsRead(): void
    {
        $source = self::sourceOf('verify');
        $comparison = \strpos($source, 'hash_equals(');
        $expiryRead = \strpos($source, 'decodeExpiry(');

        self::assertIsInt($comparison);
        self::assertIsInt($expiryRead);
        self::assertLessThan(
            $expiryRead,
            $comparison,
            'the expiry is attacker-supplied until the MAC has vouched for it; reading it first '
            . 'means acting on unauthenticated input',
        );
    }

    private static function sourceOf(string $method): string
    {
        $reflected = new \ReflectionMethod(Hmac::class, $method);
        $lines = \file((string) $reflected->getFileName());
        self::assertIsArray($lines);

        return \implode('', \array_slice(
            $lines,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));
    }
}
