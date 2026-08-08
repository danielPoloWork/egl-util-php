<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Crypto;
use D4np\Utils\Security\SecretKey;
use D4np\Utils\Support\CryptoException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Spec §7's **T-09**: `Crypto`/`SecretKey` vectors (spec FR-40, RFC-0002; ADR-0054).
 *
 * Every vector spec §7 names for T-09 — tamper, wrong key, truncation, nonce uniqueness across
 * 10^5 tokens, version-prefix handling — plus the two probe findings that shaped the design:
 * `openssl_decrypt()` accepts a **correct prefix** of a real tag at any length (a forged one is
 * still rejected), and `openssl_encrypt('aes-256-gcm', ...)` does not validate key length at
 * all. Neither is directly reachable through this class's public API — the tag length and key
 * length are both fixed constants, never parsed from a token or accepted as an arbitrary string
 * — which is the point: the vectors below tamper *within* those fixed boundaries because that is
 * the only thing an attacker who does not hold the key can do.
 *
 * `ext-openssl`'s absence is refused at construction ({@see Crypto::__construct()}), following
 * `Hash`'s precedent — but, like `Sanitizer::richText()`'s missing-package branch (ADR-0021),
 * this is **probe-verified rather than test-executed**: `ext-openssl` is not a dev-only optional
 * dependency that can be uninstalled for a single test run the way `symfony/html-sanitizer` can,
 * so the refusal is a documented, verified fact rather than a covered line. Probed directly
 * (not asserted from memory) at the point this class was designed:
 * `extension_loaded('openssl')` returns `true` on every PHP build this project targets.
 */
#[Group('T-09')]
final class CryptoTest extends TestCase
{
    private function crypto(): Crypto
    {
        return new Crypto(SecretKey::generate());
    }

    // ---- The happy path, across shapes worth naming ------------------------------------------

    public function testARoundTripReturnsTheOriginalPlaintext(): void
    {
        $crypto = $this->crypto();

        self::assertSame('hello, world', $crypto->decrypt($crypto->encrypt('hello, world')));
    }

    public function testEmptyPlaintextRoundTrips(): void
    {
        // The minimum-length token: a nonce and a tag with zero bytes of ciphertext between
        // them. Worth its own test because the slicing arithmetic in decrypt() has an
        // off-by-one temptation exactly at this boundary.
        $crypto = $this->crypto();

        self::assertSame('', $crypto->decrypt($crypto->encrypt('')));
    }

    public function testBinaryPlaintextContainingNulBytesRoundTrips(): void
    {
        $crypto = $this->crypto();
        $binary = "\x00\x01\xFF" . \random_bytes(64) . "\x00";

        self::assertSame($binary, $crypto->decrypt($crypto->encrypt($binary)));
    }

    public function testTheTokenCarriesTheVersionPrefixAndIsUrlSafe(): void
    {
        $token = $this->crypto()->encrypt('payload');

        self::assertStringStartsWith('v1.', $token);
        self::assertMatchesRegularExpression(
            '/^v1\.[A-Za-z0-9\-_]+$/',
            $token,
            'a URL-safe token must not contain "+", "/" or "=" — those are exactly what make base64 unsafe as a URL component',
        );
    }

    public function testTwoEncryptionsOfTheSamePlaintextProduceDifferentTokens(): void
    {
        // If this ever failed it would mean the nonce stopped being random, which is the
        // single most damaging regression this class could suffer under GCM.
        $crypto = $this->crypto();

        self::assertNotSame($crypto->encrypt('same input'), $crypto->encrypt('same input'));
    }

    // ---- Tamper: T-09's named vector, in both places a token can be altered ------------------

    public function testTamperingWithTheCiphertextIsDetected(): void
    {
        $crypto = $this->crypto();
        $tampered = $this->flipOneBodyByte($crypto->encrypt('untouched'));

        $this->expectException(CryptoException::class);
        $crypto->decrypt($tampered);
    }

    public function testTamperingWithTheTagIsDetected(): void
    {
        // Distinct from the ciphertext case: this flips a byte in the trailing 16 bytes GCM
        // authenticates against, which is the exact region the probed "short tag" finding is
        // about. A single flipped bit here must still be rejected — this class never lets the
        // tag be anything other than the full, fixed length.
        $crypto = $this->crypto();
        $token = $crypto->encrypt('untouched');
        $decoded = $this->decodeToken($token);
        $decoded[\strlen($decoded) - 1] = \chr(\ord($decoded[\strlen($decoded) - 1]) ^ 1);

        $this->expectException(CryptoException::class);
        $crypto->decrypt('v1.' . $this->encodeBase64Url($decoded));
    }

    // ---- Wrong key: T-09's named vector -------------------------------------------------------

    public function testDecryptingWithADifferentKeyFails(): void
    {
        $token = $this->crypto()->encrypt('secret');
        $otherKey = new Crypto(SecretKey::generate());

        $this->expectException(CryptoException::class);
        $otherKey->decrypt($token);
    }

    // ---- Truncation: T-09's named vector ------------------------------------------------------

    public function testATruncatedTokenIsRejected(): void
    {
        $token = $this->crypto()->encrypt('a full message');

        $this->expectException(CryptoException::class);
        $this->crypto()->decrypt(\substr($token, 0, (int) (\strlen($token) / 2)));
    }

    public function testATokenShorterThanANonceAndATagIsRejectedNotWarnedAbout(): void
    {
        // Below the 12+16-byte floor: decrypt() must reject this before ever calling
        // openssl_decrypt(), not hand it an empty or partial nonce/tag and let a PHP warning be
        // the only signal.
        $crypto = $this->crypto();
        $tooShort = 'v1.' . $this->encodeBase64Url(\random_bytes(10));

        $this->expectException(CryptoException::class);
        $crypto->decrypt($tooShort);
    }

    #[TestWith([''])]
    #[TestWith(['not-a-token-at-all'])]
    #[TestWith(['v1.'])]
    public function testMalformedInputIsRejectedNotWarnedAbout(string $malformed): void
    {
        $this->expectException(CryptoException::class);
        $this->crypto()->decrypt($malformed);
    }

    public function testInvalidBase64UrlCharactersAreRejected(): void
    {
        // "+" and "/" are valid base64 but not base64url; this token's alphabet is exactly
        // base64url's, so plain base64 output is exactly the kind of "looks similar" input this
        // must not silently accept.
        $this->expectException(CryptoException::class);
        $this->crypto()->decrypt('v1.not+valid/base64url==');
    }

    // ---- Version-prefix handling: T-09's named vector -----------------------------------------

    public function testAMissingVersionPrefixIsRejected(): void
    {
        // The same instance encrypts and decrypts, deliberately: with two different keys, a
        // dropped prefix and a wrong key would both throw for the same underlying reason
        // (authentication failure) and the test would pass without the prefix check ever
        // running at all.
        $crypto = $this->crypto();
        $withoutPrefix = \substr($crypto->encrypt('payload'), 3);

        $this->expectException(CryptoException::class);
        $crypto->decrypt($withoutPrefix);
    }

    public function testAnUnknownVersionPrefixIsRejected(): void
    {
        // Same key, same reason as above — and here it is load-bearing rather than defensive:
        // "v2." is exactly as long as "v1.", so a decrypt() that stripped a fixed number of
        // characters instead of checking the prefix would strip "v2." right back off and
        // decrypt the real payload underneath it, successfully, under the correct key. That
        // defect is invisible unless this test reuses the key the token was actually made with.
        $crypto = $this->crypto();
        $wrongVersion = 'v2.' . \substr($crypto->encrypt('payload'), 3);

        $this->expectException(CryptoException::class);
        $crypto->decrypt($wrongVersion);
    }

    // ---- Nonce uniqueness: T-09's named vector, at the scale it names -------------------------

    public function testNonceUniquenessAcrossOneHundredThousandTokens(): void
    {
        // One assertion at the end, not one per iteration. PHPUnit's own assertion machinery
        // accumulates per-call bookkeeping that is unnoticeable at ordinary test sizes and
        // exhausts the default 128 MiB memory limit well before 100 000 calls — found by
        // running this loop first as plain PHP (instant) and then through
        // `PHPUnit\Framework\Assert` directly (OOM at a few thousand), isolating PHPUnit's own
        // overhead as the cause rather than anything in `Crypto`. The loop below therefore does
        // its own bookkeeping with a plain array and `isset()`, and asserts once.
        $crypto = $this->crypto();
        $seen = [];
        $firstDuplicateAt = null;

        for ($i = 0; $i < 100_000; $i++) {
            $encoded = \substr($crypto->encrypt('x'), 3);
            $padded = \str_pad($encoded, \strlen($encoded) + ((4 - \strlen($encoded) % 4) % 4), '=');
            $decoded = \base64_decode(\strtr($padded, '-_', '+/'), true);
            $nonce = \substr((string) $decoded, 0, 12);

            if (isset($seen[$nonce])) {
                $firstDuplicateAt = $i;

                break;
            }

            $seen[$nonce] = true;
        }

        self::assertNull(
            $firstDuplicateAt,
            \sprintf(
                'a nonce repeated at iteration %s — a repeated GCM nonce under the same key breaks confidentiality and authenticity for every message that shares it',
                $firstDuplicateAt,
            ),
        );
        self::assertCount(100_000, $seen);
    }

    // ---- SecretKey: the only door into a key, and the length invariant it enforces -----------

    public function testGeneratedKeysAreThirtyTwoBytes(): void
    {
        self::assertSame(32, \strlen($this->keyBytesViaRoundTrip(SecretKey::generate())));
    }

    public function testTwoGeneratedKeysDiffer(): void
    {
        self::assertNotSame(
            $this->keyBytesViaRoundTrip(SecretKey::generate()),
            $this->keyBytesViaRoundTrip(SecretKey::generate()),
        );
    }

    #[TestWith([8])]
    #[TestWith([16])]
    #[TestWith([24])]
    #[TestWith([31])]
    #[TestWith([33])]
    #[TestWith([64])]
    public function testAKeyOfAnyLengthOtherThanThirtyTwoBytesIsRefused(int $length): void
    {
        // openssl_encrypt() itself does not enforce this (probed: 8, 16, 24, 32 and 40-byte
        // keys were all silently accepted) — this class is the only place the check happens,
        // so every wrong length worth naming is asserted here rather than trusted to the cipher.
        // Every #[TestWith] value above is a positive literal; the guard below is real control
        // flow PHPStan's engine narrows on directly (not a phpdoc claim it is configured not to
        // trust), so random_bytes() below sees a provably positive length.
        if ($length < 1) {
            self::fail('this test only names positive lengths');
        }

        $this->expectException(CryptoException::class);
        SecretKey::fromBytes(\random_bytes($length));
    }

    public function testFromBase64RoundTripsWithToBase64(): void
    {
        $key = SecretKey::generate();

        self::assertSame(
            $this->keyBytesViaRoundTrip($key),
            $this->keyBytesViaRoundTrip(SecretKey::fromBase64($key->toBase64())),
        );
    }

    public function testFromBase64RejectsInvalidBase64(): void
    {
        $this->expectException(CryptoException::class);
        SecretKey::fromBase64('not valid base64!!!');
    }

    public function testFromBase64RejectsTheWrongDecodedLength(): void
    {
        $this->expectException(CryptoException::class);
        SecretKey::fromBase64(\base64_encode(\random_bytes(16)));
    }

    public function testAKeyStoredAsBase64AndReconstructedLaterStillDecrypts(): void
    {
        // The realistic deployment shape: a key generated once, stored as base64, and
        // reconstructed by a different process for every request thereafter.
        $original = SecretKey::generate();
        $token = (new Crypto($original))->encrypt('stored key round trip');

        $reconstructed = SecretKey::fromBase64($original->toBase64());

        self::assertSame('stored key round trip', (new Crypto($reconstructed))->decrypt($token));
    }

    // ---- Helpers --------------------------------------------------------------------------------

    private function flipOneBodyByte(string $token): string
    {
        $decoded = $this->decodeToken($token);
        // Byte 0 is inside the nonce-then-ciphertext region for any plaintext of at least one
        // byte, which every call site above supplies.
        $decoded[0] = \chr(\ord($decoded[0]) ^ 1);

        return 'v1.' . $this->encodeBase64Url($decoded);
    }

    private function decodeToken(string $token): string
    {
        $encoded = \substr($token, 3);
        $padded = \str_pad($encoded, \strlen($encoded) + ((4 - \strlen($encoded) % 4) % 4), '=');
        $decoded = \base64_decode(\strtr($padded, '-_', '+/'), true);

        self::assertIsString($decoded, 'the token under test must itself be well-formed');

        return $decoded;
    }

    private function encodeBase64Url(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * `SecretKey::bytes()` is public but documented `@internal` to `Crypto` — this test file is
     * the one other legitimate caller, comparing key material directly rather than through an
     * encrypt/decrypt round trip.
     */
    private function keyBytesViaRoundTrip(SecretKey $key): string
    {
        return $key->bytes();
    }
}
