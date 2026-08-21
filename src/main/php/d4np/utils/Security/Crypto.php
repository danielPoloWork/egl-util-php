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
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    /** GCM's standard nonce length; `openssl_cipher_iv_length(self::CIPHER)` confirms 12. */
    private const NONCE_BYTES = 12;

    /** The full authentication tag — never a shorter, caller- or token-supplied length. */
    private const TAG_BYTES = 16;

    private const VERSION_PREFIX = 'v1.';

    /**
     * @throws CryptoException if `ext-openssl` is not loaded
     */
    public function __construct(private readonly SecretKey $key)
    {
        if (!\extension_loaded('openssl')) {
            throw new CryptoException(
                'ext-openssl is not loaded. Crypto has no fallback: a security-relevant '
                . 'primitive degraded to a weaker one instead of failing would be a worse '
                . 'defect than refusing outright, so this refuses at construction rather than '
                . 'at the first encrypt() or decrypt() call.',
            );
        }
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

        $ciphertext = \openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key->bytes(),
            \OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
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

        return self::VERSION_PREFIX . Base64Url::encode($nonce . $ciphertext . $tag);
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
        if (!\str_starts_with($token, self::VERSION_PREFIX)) {
            throw new CryptoException(\sprintf(
                'Unrecognised token version. Expected the "%s" prefix.',
                self::VERSION_PREFIX,
            ));
        }

        $payload = Base64Url::decode(\substr($token, \strlen(self::VERSION_PREFIX)));
        $minimumBytes = self::NONCE_BYTES + self::TAG_BYTES;

        if ($payload === false || \strlen($payload) < $minimumBytes) {
            throw new CryptoException('The token is malformed or truncated.');
        }

        $nonce = \substr($payload, 0, self::NONCE_BYTES);
        $tag = \substr($payload, -self::TAG_BYTES);
        $ciphertext = \substr($payload, self::NONCE_BYTES, -self::TAG_BYTES);

        $plaintext = \openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key->bytes(),
            \OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new CryptoException(
                'Decryption failed: wrong key, or the token was tampered with.',
            );
        }

        return $plaintext;
    }
}
