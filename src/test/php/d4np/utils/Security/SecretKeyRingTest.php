<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Crypto;
use D4np\Utils\Security\SecretKey;
use D4np\Utils\Security\SecretKeyRing;
use D4np\Utils\Support\CryptoException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-09's rotation leg: key identifiers and the `v2.` token format (spec r28 FR-40b, issue #114;
 * ADR-0083).
 *
 * ADR-0054's `v1.` token versions the *format* and carries no key identifier, so rotating a key
 * invalidated every outstanding token. This suite covers the three things issue #114 asks be
 * pinned — old-key tokens decrypt during the window, unknown key ids fail closed, and the key id
 * never leaks key material — plus the two the design added and which are the security-relevant
 * ones: a **substituted key id cannot verify** (the id is GCM's AAD, so the tag covers it), and a
 * `v2.` body **cannot be replayed as a `v1.` token** to shed the binding.
 *
 * The `v1.` compatibility direction is asserted here too rather than left to `CryptoTest`: the
 * claim that a bare `SecretKey` still produces byte-identical `v1.` tokens is what makes this
 * change additive under the 1.x freeze (ADR-0059), and a claim about *not* changing is exactly the
 * kind that needs its own test.
 */
#[Group('T-09')]
final class SecretKeyRingTest extends TestCase
{
    // ---- The rotation window ---------------------------------------------------------------------

    public function testARingEncryptsUnderItsCurrentKeyAsV2(): void
    {
        $crypto = new Crypto(SecretKeyRing::of(SecretKey::generate(), SecretKey::generate()));
        $token = $crypto->encrypt('rotated');

        self::assertStringStartsWith('v2.', $token);
        self::assertSame('rotated', $crypto->decrypt($token));
    }

    /**
     * Issue #114's first acceptance criterion: a token minted under the key that has since become
     * "previous" still reads. This is the whole point of the ring — without it, rotation is a
     * mass invalidation.
     */
    public function testATokenFromThePreviousKeyStillDecryptsDuringTheWindow(): void
    {
        $lastMonth = SecretKey::generate();
        $today = SecretKey::generate();

        $before = new Crypto(SecretKeyRing::of($lastMonth));
        $token = $before->encrypt('minted before the rotation');

        $after = new Crypto(SecretKeyRing::of($today, $lastMonth));

        self::assertSame('minted before the rotation', $after->decrypt($token));
    }

    /**
     * And the window genuinely closes. Dropping a key from the ring must stop its tokens reading —
     * otherwise "retiring" a key is a comment rather than an act, and a compromised key would go
     * on working for as long as its tokens were presented.
     */
    public function testDroppingAKeyFromTheRingRetiresItsTokens(): void
    {
        $retired = SecretKey::generate();
        $token = (new Crypto(SecretKeyRing::of($retired)))->encrypt('signed by the old key');

        $narrowed = new Crypto(SecretKeyRing::of(SecretKey::generate()));

        $this->expectException(CryptoException::class);
        $narrowed->decrypt($token);
    }

    /**
     * Issue #114's second acceptance criterion. The refusal must be a refusal — never a fallback
     * that tries the ring's other keys anyway, which would make the id decorative and undo the
     * test above.
     */
    public function testAnUnknownKeyIdFailsClosedRatherThanFallingBack(): void
    {
        $stranger = (new Crypto(SecretKeyRing::of(SecretKey::generate())))->encrypt('not ours');

        $ring = new Crypto(SecretKeyRing::of(SecretKey::generate(), SecretKey::generate()));

        try {
            $ring->decrypt($stranger);
            self::fail('a token whose key id this ring does not hold must be refused');
        } catch (CryptoException $e) {
            // The message names the id, because an operator debugging a rotation needs to know
            // *which* key is missing rather than only that one is.
            self::assertStringContainsString('No key in this ring has id', $e->getMessage());
        }
    }

    // ---- The key id itself -----------------------------------------------------------------------

    /**
     * Issue #114's third acceptance criterion. The id is `hash_hkdf(...)` over the key — a PRF
     * output — so it is stable per key and reveals nothing about the bytes behind it.
     */
    public function testAKeyIdNeverLeaksKeyMaterial(): void
    {
        $key = SecretKey::generate();
        $ring = SecretKeyRing::of($key);

        $keyId = $ring->currentKeyId();
        $raw = SecretKeyRing::keyIdOf($key);

        self::assertSame(SecretKeyRing::KEY_ID_BYTES, \strlen($raw));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $keyId, 'the hex form is what a log line gets');

        // The id is not a slice of the key, in either encoding — the failure mode a "just take the
        // first four bytes" implementation would have had, and the reason HKDF is here at all.
        self::assertStringNotContainsString($raw, $key->bytes(), 'the raw id appears inside the key material');
        self::assertStringNotContainsString($keyId, $key->toBase64(), 'the hex id appears inside the stored key');
    }

    public function testAKeyIdIsStableForTheSameKeyAndDiffersAcrossKeys(): void
    {
        $key = SecretKey::generate();
        $same = SecretKey::fromBase64($key->toBase64());

        self::assertSame(
            SecretKeyRing::keyIdOf($key),
            SecretKeyRing::keyIdOf($same),
            'the same key material must derive the same id in any process, or a ring rebuilt from '
            . 'the environment would not recognise its own tokens',
        );
        self::assertNotSame(SecretKeyRing::keyIdOf($key), SecretKeyRing::keyIdOf(SecretKey::generate()));
    }

    /**
     * A ring that cannot tell two of its keys apart would decrypt with the wrong one and report
     * the failure as a tampered token. In practice this catches the same key listed twice, which
     * is the likelier operator error by a wide margin.
     */
    public function testARingRefusesTwoKeysWithTheSameId(): void
    {
        $key = SecretKey::generate();

        try {
            SecretKeyRing::of($key, $key);
            self::fail('a ring holding the same key twice must be refused');
        } catch (CryptoException $e) {
            self::assertStringContainsString('derive the same key id', $e->getMessage());
        }
    }

    public function testTheCurrentKeyIsTheOneEncryptionUses(): void
    {
        $current = SecretKey::generate();
        $ring = SecretKeyRing::of($current, SecretKey::generate());

        self::assertSame($current, $ring->current());
        self::assertSame(\bin2hex(SecretKeyRing::keyIdOf($current)), $ring->currentKeyId());
        self::assertCount(2, $ring->all());
        self::assertSame($current, $ring->all()[0], 'current must be first, so decrypt tries it first');
    }

    // ---- The attacks the AAD binding exists to stop ----------------------------------------------

    /**
     * **The security-relevant assertion of this whole change.** The key id travels in the clear, so
     * an attacker can edit it. It is GCM's AAD, so the authentication tag covers it — rewriting the
     * id to name a *different key the ring actually holds* must not produce a token that verifies.
     *
     * Without the AAD binding the id would be unauthenticated metadata, and the only thing between
     * a substituted id and a decrypt attempt under the wrong key would be luck about which key it
     * named.
     */
    public function testASubstitutedKeyIdCannotVerify(): void
    {
        $first = SecretKey::generate();
        $second = SecretKey::generate();
        $crypto = new Crypto(SecretKeyRing::of($first, $second));

        $token = $crypto->encrypt('confidential');
        $body = \substr($this->decode($token), SecretKeyRing::KEY_ID_BYTES);

        // Point the token at the ring's OTHER key: an id the ring resolves, so the refusal can
        // only come from the tag, never from a failed lookup.
        $forged = 'v2.' . $this->encode(SecretKeyRing::keyIdOf($second) . $body);

        $this->expectException(CryptoException::class);
        $crypto->decrypt($forged);
    }

    /**
     * And the same binding blocks the downgrade: stripping the key id and presenting the remainder
     * as a `v1.` token. `v1.` decrypts with an empty AAD, so the tag — computed over the id —
     * cannot verify. A pleasant consequence of binding the id rather than merely prefixing it,
     * asserted because nothing else would notice if it stopped holding.
     */
    public function testAV2BodyCannotBeReplayedAsAV1Token(): void
    {
        $key = SecretKey::generate();
        $crypto = new Crypto(SecretKeyRing::of($key));

        $token = $crypto->encrypt('confidential');
        $body = \substr($this->decode($token), SecretKeyRing::KEY_ID_BYTES);

        $this->expectException(CryptoException::class);
        $crypto->decrypt('v1.' . $this->encode($body));
    }

    /**
     * The vacuity guard for both attacks above: the untouched token from the same construction
     * *does* decrypt, so "it threw" means the tampering was caught rather than that nothing in
     * this suite could ever have worked.
     */
    public function testTheUntamperedTokenFromTheSameSetupStillDecrypts(): void
    {
        $crypto = new Crypto(SecretKeyRing::of(SecretKey::generate(), SecretKey::generate()));

        self::assertSame('confidential', $crypto->decrypt($crypto->encrypt('confidential')));
    }

    public function testATruncatedV2TokenIsRefusedRatherThanSlicedIntoNonsense(): void
    {
        $crypto = new Crypto(SecretKeyRing::of(SecretKey::generate()));

        // Shorter than key id + nonce + tag: the length floor `decrypt()` widened for `v2.`.
        $this->expectException(CryptoException::class);
        $crypto->decrypt('v2.' . $this->encode(\random_bytes(SecretKeyRing::KEY_ID_BYTES + 8)));
    }

    // ---- Additive under the freeze ---------------------------------------------------------------

    /**
     * A bare `SecretKey` must keep producing `v1.` — unchanged, because a consumer who passed one
     * has not asked for rotation and their verifiers were written against ADR-0054's grammar. This
     * is the assertion that makes the change additive under ADR-0059 rather than a format break.
     */
    public function testABareKeyStillProducesV1Tokens(): void
    {
        $crypto = new Crypto(SecretKey::generate());
        $token = $crypto->encrypt('unchanged');

        self::assertStringStartsWith('v1.', $token);
        self::assertSame('unchanged', $crypto->decrypt($token));
    }

    /**
     * And adopting a ring is a migration rather than a cutover: the `v1.` tokens already in flight
     * when the ring arrives must still read, which a ring does by trying each of its keys.
     */
    public function testARingStillReadsV1TokensMintedBeforeIt(): void
    {
        $key = SecretKey::generate();
        $legacy = (new Crypto($key))->encrypt('minted before the ring existed');

        $migrated = new Crypto(SecretKeyRing::of(SecretKey::generate(), $key));

        self::assertSame('minted before the ring existed', $migrated->decrypt($legacy));
    }

    public function testAV1TokenIsStillRefusedWhenNoKeyInTheRingFits(): void
    {
        $legacy = (new Crypto(SecretKey::generate()))->encrypt('someone else\'s');

        $ring = new Crypto(SecretKeyRing::of(SecretKey::generate(), SecretKey::generate()));

        $this->expectException(CryptoException::class);
        $ring->decrypt($legacy);
    }

    public function testAnUnrecognisedPrefixNamesBothAcceptedFormats(): void
    {
        $crypto = new Crypto(SecretKeyRing::of(SecretKey::generate()));

        try {
            $crypto->decrypt('v3.' . $this->encode(\random_bytes(40)));
            self::fail('an unknown version prefix must be refused');
        } catch (CryptoException $e) {
            self::assertStringContainsString('v1.', $e->getMessage());
            self::assertStringContainsString('v2.', $e->getMessage());
        }
    }

    // ---- Helpers ----------------------------------------------------------------------------------

    private function decode(string $token): string
    {
        $encoded = \substr($token, 3);
        $padded = \str_pad($encoded, \strlen($encoded) + ((4 - \strlen($encoded) % 4) % 4), '=');
        $decoded = \base64_decode(\strtr($padded, '-_', '+/'), true);

        self::assertIsString($decoded, 'the token under test must itself be well-formed');

        return $decoded;
    }

    private function encode(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }
}
