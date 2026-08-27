<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\CryptoException;

/**
 * Authenticated encryption for compact, URL-safe tokens (spec FR-40, RFC-0002; ADR-0054).
 *
 * The surveyed estate's cipher helper used **AES-256-CBC with no authentication tag at all** —
 * malleable by construction: an attacker who can intercept a token can flip bits in it and
 * produce a *different, still-decryptable* plaintext, with nothing to detect the tampering.
 * `Crypto` replaces it with **AES-256-GCM**, which authenticates the ciphertext as part of
 * decrypting it — {@see decrypt()} cannot succeed on tampered input, because there is no
 * "decrypt" step that does not also verify.
 *
 * **The token format is `"v1." . base64url(nonce ‖ ciphertext ‖ tag)`** ({@see Base64Url}), nonce
 * and tag at fixed lengths (12 and 16 bytes) sliced from fixed positions — never a length the token itself
 * states. That is not a style choice: probed, `openssl_decrypt()`'s tag check is only as strong
 * as the tag length it is given, and a **correct prefix** of a real tag — even one byte of it —
 * is accepted. A token format that let the tag's length vary would hand an attacker exactly the
 * lever GCM is supposed to remove: shrink the tag, then brute-force it. Pinning the lengths
 * structurally is what keeps that door shut regardless of what a future change does elsewhere in
 * this class.
 *
 * **`decrypt()` throws on every failure — wrong key, tampered tag, tampered ciphertext, wrong
 * nonce, a malformed or truncated token — never `bool|string`.** The estate's cipher returned
 * `bool|string` from `decrypt()`, which is the shape that lets a caller's `if ($decrypted)`
 * treat `false` and `'0'` alike, or skip the check outright. A caught exception cannot be
 * skipped by accident.
 *
 * **`ext-openssl` is suggested, not required** (NFR-08's dependency policy) — refused at
 * construction, following {@see Hash}'s precedent (ADR-0022 §4): a build without it fails while
 * being wired, not the first time something needs encrypting.
 *
 * ```php
 * $crypto = new Crypto(SecretKey::generate());
 * $token = $crypto->encrypt('some plaintext');   // "v1.<base64url>"
 * $plain = $crypto->decrypt($token);              // throws CryptoException on any failure
 * ```
 *
 * ## Key rotation: the `v2.` format (spec r28 FR-40b, issue #114, ADR-0083)
 *
 * `v1.` versions the *format* and carries no key identifier, so rotating a key invalidates every
 * outstanding token at once. Pass a {@see SecretKeyRing} instead of a bare {@see SecretKey} and
 * tokens gain one:
 *
 * ```php
 * $crypto = new Crypto(SecretKeyRing::of($currentKey, $lastMonthsKey));
 * $token = $crypto->encrypt('some plaintext');   // "v2.<base64url>", under the CURRENT key
 * $crypto->decrypt($tokenFromLastMonth);          // still readable, under the previous key
 * ```
 *
 * - **`v2.` is `base64url(keyId ‖ nonce ‖ ciphertext ‖ tag)`** — the four-byte key id first, at a
 *   fixed offset, the same fixed-width slicing `v1.` uses.
 * - **The key id is GCM's AAD**, so the tag authenticates it. An attacker who rewrites the id to
 *   name a different key cannot produce a token that verifies — without this the id would be
 *   unauthenticated metadata.
 * - **An unknown key id fails closed.** It is never retried against the ring's other keys, because
 *   that would make retiring a key change nothing.
 * - **A bare `SecretKey` still produces byte-identical `v1.` tokens.** A consumer who passed one
 *   has not asked for rotation, and their verifiers — possibly in another language, written against
 *   ADR-0054's published grammar — have not been told about a second format. `v2.` is opt-in by
 *   passing a ring, which is what keeps this additive under the 1.x freeze (ADR-0059).
 * - **Both formats decrypt either way round.** A ring reads `v1.` tokens by trying each of its keys,
 *   which is what makes adopting the ring a migration rather than a cutover.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    /** GCM's standard nonce length; `openssl_cipher_iv_length(self::CIPHER)` confirms 12. */
    private const NONCE_BYTES = 12;

    /** The full authentication tag — never a shorter, caller- or token-supplied length. */
    private const TAG_BYTES = 16;

    private const VERSION_PREFIX = 'v1.';

    /** The key-identified format (spec r28 FR-40b, ADR-0083); emitted only for a {@see SecretKeyRing}. */
    private const VERSION_PREFIX_KEYED = 'v2.';

    private readonly SecretKeyRing $ring;

    /**
     * Whether {@see encrypt()} emits `v2.`, which is true exactly when a ring was supplied.
     *
     * A single {@see SecretKey} keeps producing byte-identical `v1.` tokens, because a consumer who
     * passed one has not asked for rotation and their verifiers — possibly in another language,
     * against ADR-0054's published grammar — have not been told about a second format. Opting in
     * is passing a ring.
     */
    private readonly bool $keyed;

    /**
     * The current key's raw id, derived **once** here rather than per call.
     *
     * `SecretKeyRing::keyIdOf()` is an HKDF, and the ring is immutable after construction — so
     * computing it inside {@see encrypt()} would pay a hash per message for a value that cannot
     * change. NFR-13 budgets a 1 KiB round trip at 60 µs, which is not a budget to spend on
     * re-deriving a constant. Empty for the `v1.` path, which has no id and whose AAD is empty.
     */
    private readonly string $currentKeyId;

    /**
     * @param SecretKey|SecretKeyRing $key a bare key keeps ADR-0054's `v1.` behaviour exactly; a
     *                                     ring encrypts under its current key as `v2.` and decrypts
     *                                     anything the ring still holds
     *
     * @throws CryptoException if `ext-openssl` is not loaded
     */
    public function __construct(SecretKey|SecretKeyRing $key)
    {
        if (!\extension_loaded('openssl')) {
            throw new CryptoException(
                'ext-openssl is not loaded. Crypto has no fallback: a security-relevant '
                . 'primitive degraded to a weaker one instead of failing would be a worse '
                . 'defect than refusing outright, so this refuses at construction rather than '
                . 'at the first encrypt() or decrypt() call.',
            );
        }

        // A bare key becomes a ring of one, so there is a single decryption path rather than two
        // that have to be kept in step. Only the emitted prefix distinguishes the two cases.
        $this->keyed = $key instanceof SecretKeyRing;
        $this->ring = $key instanceof SecretKeyRing ? $key : SecretKeyRing::of($key);
        $this->currentKeyId = $this->keyed ? SecretKeyRing::keyIdOf($this->ring->current()) : '';
    }

    /**
     * Encrypts `$plaintext` into a compact, URL-safe token.
     *
     * A fresh nonce from the CSPRNG on every call — never derived from the plaintext, a
     * counter, or anything else that could repeat. Reusing a GCM nonce under the same key does
     * not just weaken confidentiality; it breaks the authentication tag's guarantee for every
     * message that shared it, which is why generating one is this method's first action and
     * never a caller's responsibility.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = \random_bytes(self::NONCE_BYTES);
        $tag = '';
        $key = $this->ring->current();

        // The key id is the AAD, not just a prefix on the token, and that is the security-relevant
        // half of the `v2.` format. AAD is authenticated but not encrypted, so GCM's tag covers the
        // id without hiding it — which means an attacker who edits the id to point at a different
        // key cannot produce a token that verifies. Probed: the same ciphertext and tag with a
        // different or empty AAD returns `false` from `openssl_decrypt()`. Without this the id
        // would be unauthenticated metadata, and the only thing standing between a substituted id
        // and a successful decrypt would be luck about which key it named.
        $aad = $this->currentKeyId;

        $ciphertext = \openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key->bytes(),
            \OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TAG_BYTES,
        );

        // openssl_encrypt() returns false only for a bad cipher name or IV length, both fixed
        // constants above and never a defect this class's own callers can trigger — probed on
        // every key length from 8 to 40 bytes, encryption never failed. Kept as a guard rather
        // than asserted away, because "cannot happen" is not the same claim as "verified to not
        // happen on every input this method can receive."
        if ($ciphertext === false) {
            throw new CryptoException('Encryption failed.');
        }

        if (!$this->keyed) {
            return self::VERSION_PREFIX . Base64Url::encode($nonce . $ciphertext . $tag);
        }

        // `keyId ‖ nonce ‖ ciphertext ‖ tag`, every fixed-width field at a fixed offset and the
        // id first so it can be read before anything else is trusted — ADR-0054's slicing
        // discipline, extended rather than reinterpreted.
        return self::VERSION_PREFIX_KEYED . Base64Url::encode($aad . $nonce . $ciphertext . $tag);
    }

    /**
     * Decrypts a token produced by {@see encrypt()}.
     *
     * @throws CryptoException on any failure: an unrecognised version prefix, malformed
     *                         base64url, a token shorter than a nonce plus a tag, a wrong key, or
     *                         a tampered nonce/ciphertext/tag — GCM's decrypt step authenticates
     *                         before it returns plaintext, so tampering and a wrong key produce
     *                         the same `false` from `openssl_decrypt()` and the same exception
     *                         here; distinguishing them would require decrypting first to find
     *                         out, which is not a thing GCM allows
     */
    public function decrypt(string $token): string
    {
        $keyed = \str_starts_with($token, self::VERSION_PREFIX_KEYED);

        if (!$keyed && !\str_starts_with($token, self::VERSION_PREFIX)) {
            throw new CryptoException(\sprintf(
                'Unrecognised token version. Expected the "%s" or "%s" prefix.',
                self::VERSION_PREFIX,
                self::VERSION_PREFIX_KEYED,
            ));
        }

        $prefix = $keyed ? self::VERSION_PREFIX_KEYED : self::VERSION_PREFIX;
        $payload = Base64Url::decode(\substr($token, \strlen($prefix)));
        $minimumBytes = self::NONCE_BYTES + self::TAG_BYTES
            + ($keyed ? SecretKeyRing::KEY_ID_BYTES : 0);

        if ($payload === false || \strlen($payload) < $minimumBytes) {
            throw new CryptoException('The token is malformed or truncated.');
        }

        if ($keyed) {
            $keyId = \substr($payload, 0, SecretKeyRing::KEY_ID_BYTES);
            $key = $this->ring->findByKeyId($keyId);

            // Fail closed. An id this ring does not hold means the key has left the rotation
            // window (or the token was never ours), and there is nothing to try: the alternative —
            // falling back to trying every key anyway — would make the id decorative and quietly
            // undo the point of retiring a key at all.
            if ($key === null) {
                throw new CryptoException(\sprintf(
                    'No key in this ring has id %s. The key that produced this token has left the '
                    . 'rotation window, or the token was signed by another deployment. Refused '
                    . 'rather than retried against the other keys, which would make the key id '
                    . 'decorative and a retired key effectively still live.',
                    \bin2hex($keyId),
                ));
            }

            $body = \substr($payload, SecretKeyRing::KEY_ID_BYTES);

            return self::open($body, $key, $keyId);
        }

        // `v1.` carries no key id, so the only way to read one mid-rotation is to try each key.
        // Current first, so the common case is the first attempt. Every failure is the same
        // uniform refusal below, so the number of attempts leaks nothing a caller could use.
        foreach ($this->ring->all() as $key) {
            try {
                return self::open($payload, $key, '');
            } catch (CryptoException) {
                continue;
            }
        }

        throw new CryptoException(
            'Decryption failed: wrong key, or the token was tampered with.',
        );
    }

    /**
     * GCM's open step against one key, with `$aad` bound in.
     *
     * @throws CryptoException when the tag does not verify under this key
     */
    private static function open(string $payload, SecretKey $key, string $aad): string
    {
        $nonce = \substr($payload, 0, self::NONCE_BYTES);
        $tag = \substr($payload, -self::TAG_BYTES);
        $ciphertext = \substr($payload, self::NONCE_BYTES, -self::TAG_BYTES);

        $plaintext = \openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key->bytes(),
            \OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
        );

        if ($plaintext === false) {
            throw new CryptoException(
                'Decryption failed: wrong key, or the token was tampered with.',
            );
        }

        return $plaintext;
    }
}
