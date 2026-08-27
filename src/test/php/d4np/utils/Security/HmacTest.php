<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Base64Url;
use D4np\Utils\Security\Hmac;
use D4np\Utils\Security\SecretKey;
use D4np\Utils\Security\SecretKeyRing;
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

    /**
     * An overlong payload is refused **by the length check**, and the message is what pins that.
     *
     * Asserting only `CryptoException` here was not enough, and a planted defect proved it: with
     * the length compared as `< $expectedBytes` instead of `!==`, an overlong payload sails past
     * the check and is refused further down anyway, because `hash_equals()` compares lengths before
     * bytes. Same outcome, different reason — and the reason is the point. The docblock on
     * {@see testACorrectMacPrefixIsRefused()} already claimed the structural property ("rather than
     * a consequence of which comparator happens to be in use") that nothing was enforcing.
     *
     * The truncated direction cannot distinguish the two, since a short payload fails either
     * comparison. Only an overlong one can.
     */
    public function testAnOverlongPayloadIsRefusedByTheLengthCheck(): void
    {
        $hmac = self::hmac();
        $payload = (string) Base64Url::decode(\substr($hmac->sign(self::MESSAGE), 3));

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('malformed or truncated');
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

    // -----------------------------------------------------------------------------------------
    // Key rotation: the `v2.` format (spec r29 FR-48b, issue #179, ADR-0085)
    //
    // Every tamper test below carries a live control — the untampered token verifying in the same
    // test. ADR-0054's version-prefix tests were once vacuous for exactly this reason: a token the
    // test believed was valid was not, so the refusal it asserted proved nothing about the tamper.
    // -----------------------------------------------------------------------------------------

    /** A fixed key per label, so a ring's membership is reproducible across tests. */
    private static function keyFor(string $label): SecretKey
    {
        return SecretKey::fromBytes(\str_pad($label, 32, '.'));
    }

    private static function ring(string $current, string ...$previous): SecretKeyRing
    {
        return SecretKeyRing::of(
            self::keyFor($current),
            ...\array_map(static fn (string $label): SecretKey => self::keyFor($label), $previous),
        );
    }

    /**
     * `[keyId, expiryBytes, mac]` — the three `v2.` fields, sliced the way the format defines.
     *
     * @return array{string, string, string}
     */
    private static function fieldsOf(string $token): array
    {
        $payload = Base64Url::decode(\substr($token, \strlen('v2.')));
        self::assertIsString($payload);

        return [
            \substr($payload, 0, SecretKeyRing::KEY_ID_BYTES),
            \substr($payload, SecretKeyRing::KEY_ID_BYTES, 8),
            \substr($payload, SecretKeyRing::KEY_ID_BYTES + 8),
        ];
    }

    public function testARingSignsUnderTheCurrentKeyAsV2(): void
    {
        $hmac = new Hmac(self::ring('current', 'previous'));

        self::assertStringStartsWith('v2.', $hmac->sign(self::MESSAGE));
    }

    public function testABareKeyStillSignsAsV1(): void
    {
        self::assertStringStartsWith('v1.', (new Hmac(self::keyFor('only')))->sign(self::MESSAGE));
    }

    public function testTheV2KeyIdIsTheCurrentKeysAndNotAPreviousOnes(): void
    {
        $hmac = new Hmac(self::ring('current', 'previous'));
        [$keyId] = self::fieldsOf($hmac->sign(self::MESSAGE));

        self::assertSame(SecretKeyRing::keyIdOf(self::keyFor('current')), $keyId);
        self::assertNotSame(SecretKeyRing::keyIdOf(self::keyFor('previous')), $keyId);
    }

    public function testARingVerifiesItsOwnV2Token(): void
    {
        $hmac = new Hmac(self::ring('current', 'previous'));

        $hmac->verify(self::MESSAGE, $hmac->sign(self::MESSAGE));

        $this->expectNotToPerformAssertions();
    }

    /**
     * The rotation window itself: a token signed before the rotation still verifies after it.
     *
     * This is the whole point of the issue. Without a ring, promoting a new signing key
     * invalidates every outstanding signed URL and webhook signature at the moment of the deploy.
     */
    public function testATokenSignedByANowPreviousKeyStillVerifies(): void
    {
        $beforeRotation = new Hmac(self::ring('previous'));
        $token = $beforeRotation->sign(self::MESSAGE);

        $afterRotation = new Hmac(self::ring('current', 'previous'));
        $afterRotation->verify(self::MESSAGE, $token);

        $this->expectNotToPerformAssertions();
    }

    /**
     * And the window closing: once the old key leaves the ring, its tokens stop verifying.
     *
     * The complement of the test above, and the one that proves retiring a key does something. A
     * ring that kept accepting a dropped key's tokens would make rotation cosmetic.
     */
    public function testATokenSignedByAKeyNoLongerInTheRingIsRefused(): void
    {
        $beforeRotation = new Hmac(self::ring('previous'));
        $token = $beforeRotation->sign(self::MESSAGE);

        // Live control: the token IS valid — it verifies against a ring that still holds its key.
        (new Hmac(self::ring('current', 'previous')))->verify(self::MESSAGE, $token);

        $narrowed = new Hmac(self::ring('current'));

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('has left the rotation window');
        $narrowed->verify(self::MESSAGE, $token);
    }

    public function testAnUnknownKeyIdFailsClosedRatherThanTryingTheOtherKeys(): void
    {
        $hmac = new Hmac(self::ring('current', 'previous'));
        $token = $hmac->sign(self::MESSAGE);
        [, $expiryBytes, $mac] = self::fieldsOf($token);

        // Live control: untampered, it verifies.
        $hmac->verify(self::MESSAGE, $token);

        $forged = 'v2.' . Base64Url::encode("\x00\x00\x00\x00" . $expiryBytes . $mac);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('No key held here has id 00000000');
        $hmac->verify(self::MESSAGE, $forged);
    }

    /**
     * **The security property of this format.** A key id substituted for another id the ring
     * genuinely holds is refused — and refused *by the MAC*, not by a failed lookup.
     *
     * {@see \D4np\Utils\Security\Crypto} binds its key id with GCM's AAD; HMAC has no AAD, so the
     * id is covered by putting it inside the signed bytes. If it were merely a prefix on the
     * token, this test would pass only by luck: the lookup would succeed, the MAC would be
     * computed over bytes that never included the id, and it would still match.
     *
     * The distinction from {@see testAnUnknownKeyIdFailsClosedRatherThanTryingTheOtherKeys()} is
     * the reason both exist. That one's id resolves to nothing, so a refusal proves only that the
     * lookup failed. This one's id resolves to a real key, so the lookup *succeeds* and the
     * refusal can only have come from the comparison.
     */
    public function testASubstitutedKeyIdNamingAnotherHeldKeyIsRefusedByTheMac(): void
    {
        $hmac = new Hmac(self::ring('current', 'previous'));
        $token = $hmac->sign(self::MESSAGE);
        [$keyId, $expiryBytes, $mac] = self::fieldsOf($token);

        // Live control: untampered, it verifies.
        $hmac->verify(self::MESSAGE, $token);

        $otherKeyId = SecretKeyRing::keyIdOf(self::keyFor('previous'));
        self::assertNotSame($keyId, $otherKeyId, 'the substituted id must be a different one');
        self::assertNotNull(
            self::ring('current', 'previous')->findByKeyId($otherKeyId),
            'the substituted id must name a key the ring genuinely holds — otherwise this test '
            . 'would be satisfied by the fail-closed lookup and would say nothing about the MAC',
        );

        $substituted = 'v2.' . Base64Url::encode($otherKeyId . $expiryBytes . $mac);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $hmac->verify(self::MESSAGE, $substituted);
    }

    /**
     * A `v2.` body relabelled `v1.` does not verify, because the bytes signed are not the bytes
     * checked: `v2.`'s MAC covers `keyId ‖ expiry ‖ message` and `v1.`'s covers `expiry ‖ message`.
     *
     * Note the lengths line up exactly — stripping the four-byte id leaves precisely the eight
     * expiry bytes plus a 32-byte MAC, which is a well-formed `v1.` payload. So the length check
     * passes and the refusal has to come from the MAC. A format that appended the id instead of
     * signing it would accept this.
     */
    public function testAV2BodyReplayedAsV1IsRefused(): void
    {
        $ring = self::ring('current', 'previous');
        $hmac = new Hmac($ring);
        $token = $hmac->sign(self::MESSAGE);
        [, $expiryBytes, $mac] = self::fieldsOf($token);

        // Live control: untampered, it verifies.
        $hmac->verify(self::MESSAGE, $token);

        $downgraded = 'v1.' . Base64Url::encode($expiryBytes . $mac);
        self::assertSame(8 + 32, \strlen($expiryBytes . $mac), 'the downgrade must be well-formed');

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $hmac->verify(self::MESSAGE, $downgraded);
    }

    /**
     * A ring verifies `v1.` tokens as well, which is what makes adopting one a migration rather
     * than a cutover — including tokens signed by a key that is no longer current.
     */
    public function testARingVerifiesV1TokensIncludingOnesFromAPreviousKey(): void
    {
        $current = (new Hmac(self::keyFor('current')))->sign(self::MESSAGE);
        $previous = (new Hmac(self::keyFor('previous')))->sign(self::MESSAGE);

        $ring = new Hmac(self::ring('current', 'previous'));
        $ring->verify(self::MESSAGE, $current);
        $ring->verify(self::MESSAGE, $previous);

        $this->expectNotToPerformAssertions();
    }

    public function testEditingTheExpiryBreaksAV2Signature(): void
    {
        $hmac = new Hmac(self::ring('current', 'previous'), self::clockAt());
        $token = $hmac->sign(self::MESSAGE, new DateInterval('PT15M'));
        [$keyId, , $mac] = self::fieldsOf($token);

        // Live control: untampered, it verifies.
        $hmac->verify(self::MESSAGE, $token);

        $extended = 'v2.' . Base64Url::encode($keyId . \str_repeat("\x7f", 8) . $mac);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $hmac->verify(self::MESSAGE, $extended);
    }

    /**
     * The key id in a token is a PRF output over the key, not a window onto it.
     *
     * ADR-0083's property, re-asserted here because this format puts the id on the wire in a place
     * ADR-0065's grammar never did. Four bytes of HKDF cannot be inverted, and — the failure that
     * would actually be embarrassing — must not simply be a prefix of the key material.
     */
    public function testTheKeyIdOnTheWireIsNotKeyMaterial(): void
    {
        $key = self::keyFor('current');
        [$keyId] = self::fieldsOf((new Hmac(self::ring('current')))->sign(self::MESSAGE));

        self::assertSame(SecretKeyRing::KEY_ID_BYTES, \strlen($keyId));
        self::assertStringNotContainsString($keyId, $key->bytes(), 'the id must not be a slice of the key');
        self::assertNotSame(
            \substr($key->bytes(), 0, SecretKeyRing::KEY_ID_BYTES),
            $keyId,
            'the id must not be the key\'s leading bytes',
        );
    }

    /**
     * A conformance vector for the `v2.` grammar, the counterpart of the `v1.` one above.
     *
     * Pins the field order (id first), the four-byte id width, the eight-byte big-endian expiry,
     * the raw digest, unpadded base64url — and, invisibly to any round trip, that the MAC is taken
     * over `keyId ‖ expiry ‖ message`. Recomputing this value from the primitives independently is
     * what the assertion below its literal does.
     */
    public function testTheV2FormatMatchesItsConformanceVector(): void
    {
        $current = SecretKey::fromBase64('KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKio=');
        $token = (new Hmac(SecretKeyRing::of($current)))->sign('/reports/42');

        $keyId = \hash_hkdf('sha256', $current->bytes(), 4, 'egl/utils:keyid:v1');
        $macKey = \hash_hkdf('sha256', $current->bytes(), 0, 'egl/utils:hmac:v1');
        $expiry = \str_repeat("\0", 8);

        self::assertSame(
            'v2.' . Base64Url::encode(
                $keyId . $expiry . \hash_hmac('sha256', $keyId . $expiry . '/reports/42', $macKey, true),
            ),
            $token,
            'the v2. grammar is keyId ‖ expiry ‖ hmac(keyId ‖ expiry ‖ message), base64url-encoded',
        );
    }

    /**
     * The same length discipline on the `v2.` path, which has one more fixed-width field to get
     * wrong — and the expected width must include the key id rather than only the `v1.` fields.
     */
    public function testAnOverlongV2PayloadIsRefusedByTheLengthCheck(): void
    {
        $hmac = new Hmac(self::ring('current', 'previous'));
        $token = $hmac->sign(self::MESSAGE);

        // Live control: untampered, it verifies.
        $hmac->verify(self::MESSAGE, $token);

        $payload = (string) Base64Url::decode(\substr($token, 3));

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('malformed or truncated');
        $hmac->verify(self::MESSAGE, 'v2.' . Base64Url::encode($payload . 'x'));
    }

    /**
     * A `v2.` payload one field short — the key id present, the MAC truncated — is refused, and
     * again by the length check rather than by whatever happens downstream.
     */
    public function testATruncatedV2PayloadIsRefusedByTheLengthCheck(): void
    {
        $hmac = new Hmac(self::ring('current', 'previous'));
        $token = $hmac->sign(self::MESSAGE);

        // Live control: untampered, it verifies.
        $hmac->verify(self::MESSAGE, $token);

        $payload = (string) Base64Url::decode(\substr($token, 3));

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('malformed or truncated');
        $hmac->verify(self::MESSAGE, 'v2.' . Base64Url::encode(\substr($payload, 0, -1)));
    }

    /**
     * The per-key MAC keys are derived at construction, not per call.
     *
     * A mechanism assertion (ADR-0027): behaviour cannot see it. An implementation that ran
     * `hash_hkdf()` inside `verify()` would produce identical tokens and identical refusals — it
     * would just pay one HKDF per candidate key per message. ADR-0083's first draft made exactly
     * that mistake with the key id, so this is a regression guard for a known error.
     */
    public function testTheMacKeysAreDerivedAtConstructionRatherThanPerCall(): void
    {
        self::assertStringContainsString(
            'hash_hkdf(',
            self::sourceOf('__construct'),
            'the derivation belongs at construction, where the ring is already immutable',
        );

        foreach (['sign', 'verify', 'mac', 'candidateMacKeys'] as $method) {
            self::assertStringNotContainsString(
                'hash_hkdf(',
                self::sourceOf($method),
                \sprintf('%s() must not re-derive a key that cannot have changed', $method),
            );
        }
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
