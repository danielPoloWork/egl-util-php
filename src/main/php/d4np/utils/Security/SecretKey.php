<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\CryptoException;

/**
 * 32 bytes of key material for {@see Crypto}, and the only way to produce them (spec FR-40,
 * RFC-0002; ADR-0054).
 *
 * **Probed rather than assumed: `openssl_encrypt('aes-256-gcm', ...)` does not validate key
 * length at all.** 8, 16, 24, 32 and 40-byte keys were all accepted silently, with no warning,
 * producing different ciphertext for each length — OpenSSL is not quietly treating a short key
 * as AES-128 (checked directly: a 16-byte key does not decrypt via `aes-256-gcm` under
 * `aes-128-gcm`), it is simply not enforcing the 32 bytes the cipher name promises. A caller
 * passing a raw string as a "key" therefore gets no error and no security: `Crypto` never
 * accepts one, only this class, which is the single place the 32-byte invariant is checked.
 *
 * **`#[\SensitiveParameter]` on every constructor argument that carries key material.** Verified
 * on 8.3.1: an uncaught exception's trace redacts the argument to `Object(SensitiveParameterValue)`
 * rather than printing the key. On the 8.1 floor the attribute class does not exist, and PHP's own
 * attribute resolution is lazy — an attribute is only resolved when something reflects on it, so
 * an unresolved `\SensitiveParameter` is inert there rather than a fatal error; effective 8.2+.
 */
final class SecretKey
{
    /** AES-256 key material, in bytes — not related to the cipher's nonce or tag lengths. */
    private const KEY_BYTES = 32;

    private function __construct(
        #[\SensitiveParameter]
        private readonly string $bytes,
    ) {
    }

    /**
     * A fresh key from the CSPRNG. What every deployment should call to provision one.
     */
    public static function generate(): self
    {
        return new self(\random_bytes(self::KEY_BYTES));
    }

    /**
     * Reconstructs a key from its base64 storage form (an environment variable, a secrets
     * manager entry — plain base64, not base64url, since a key at rest is not a URL component).
     *
     * @throws CryptoException if the decoded material is not exactly 32 bytes
     */
    public static function fromBase64(
        #[\SensitiveParameter]
        string $encoded,
    ): self {
        $bytes = \base64_decode($encoded, true);

        if ($bytes === false) {
            throw new CryptoException('The provided key is not valid base64.');
        }

        return self::fromBytes($bytes);
    }

    /**
     * @throws CryptoException if `$bytes` is not exactly 32 bytes
     */
    public static function fromBytes(
        #[\SensitiveParameter]
        string $bytes,
    ): self {
        if (\strlen($bytes) !== self::KEY_BYTES) {
            throw new CryptoException(\sprintf(
                'A secret key must be exactly %d bytes; got %d. openssl_encrypt() does not '
                . 'validate this itself (probed: it silently accepts other lengths), so this '
                . 'class is where the length is the only place it is ever checked.',
                self::KEY_BYTES,
                \strlen($bytes),
            ));
        }

        return new self($bytes);
    }

    /**
     * The base64 storage form — the counterpart to {@see fromBase64()}.
     */
    public function toBase64(): string
    {
        return \base64_encode($this->bytes);
    }

    /**
     * @internal {@see Crypto}, {@see Hmac} and {@see SecretKeyRing} only. Exposing the raw bytes
     *           any wider would defeat the point of wrapping them. `Hmac` joined 2026-08-20 (spec
     *           r20 FR-48, ADR-0065) and does **not** use these bytes as its MAC key: it derives a
     *           domain-separated subkey with HKDF first, so one `SecretKey` reused for encryption
     *           and signing — the single-`APP_SECRET` deployment, which is the common one — never
     *           feeds the same bytes to two primitives. `SecretKeyRing` joined 2026-08-27 (spec r28
     *           FR-40b, ADR-0083) on the same terms: it reads them only to derive a key *id* under
     *           its own HKDF label, and that id is a PRF output that cannot be inverted back to
     *           these bytes.
     */
    public function bytes(): string
    {
        return $this->bytes;
    }
}
