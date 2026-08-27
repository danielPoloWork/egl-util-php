<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\CryptoException;

/**
 * A current key plus the previous ones still worth accepting — the rotation window (spec r28
 * FR-40b; issue #114, ADR-0083).
 *
 * **The problem this exists for.** ADR-0054's `v1.` token carries a *format* version and no key
 * identifier. Rotating a key after a suspected compromise therefore invalidates every outstanding
 * token at once, or pushes the consumer into hand-rolling a try-each-key loop around a library
 * whose whole posture is that security mechanisms are explicit and not the caller's to assemble.
 *
 * **A key id is derived, never assigned.** `keyIdOf()` is
 * `hash_hkdf('sha256', bytes, 4, 'egl/utils:keyid:v1')` — four bytes of a PRF over the key
 * material. Two properties follow, and both matter:
 *
 * - **It cannot be inverted to key material.** HKDF is a PRF, so a token's key id tells an
 *   observer which key signed it and nothing whatsoever about the bytes of that key. The
 *   alternative — a caller-assigned label — would have worked too, and was rejected because it
 *   makes correct rotation depend on the caller keeping a numbering scheme straight across
 *   deployments, which is the class of bookkeeping this library takes on rather than delegates.
 * - **It is stable and self-consistent.** The same key always yields the same id, in any process,
 *   with no registry to keep in step. A ring rebuilt from the same environment variables in a
 *   different order still recognises its own tokens.
 *
 * **Four bytes, and a collision is refused rather than resolved.** 32 bits is far more than a
 * handful of keys needs, but "unlikely" is not "checked": two keys whose ids collided would make
 * {@see findByKeyId()} return the wrong one, and a wrong key in an AEAD is an authentication
 * failure that reads as a tampered token. So construction refuses a collision outright. The same
 * check catches the likelier operator error underneath it — the same key listed twice.
 *
 * **What a key id deliberately reveals.** It is a stable public label for a key, visible in every
 * token, so an observer can group tokens by key and see when a rotation happened. That is inherent
 * to key identification rather than a weakness of this construction, and it is what makes the
 * rotation window work at all; stated here so it is a known property rather than a surprise.
 *
 * ```php
 * $ring = SecretKeyRing::of(
 *     SecretKey::fromBase64($_ENV['APP_KEY_CURRENT']),
 *     SecretKey::fromBase64($_ENV['APP_KEY_PREVIOUS']),
 * );
 *
 * $crypto = new Crypto($ring);          // encrypts under the current key, as `v2.`
 * $crypto->decrypt($tokenFromLastWeek); // still decrypts, under the previous key
 * ```
 *
 * Once no token signed by the previous key can still be in flight, drop it from the ring and the
 * window closes — the rotation is complete at that point, not at the moment the new key arrived.
 */
final class SecretKeyRing
{
    /** The width of a derived key id, in bytes. Fixed, like every other length in this group. */
    public const KEY_ID_BYTES = 4;

    /**
     * HKDF's `info` label for key-id derivation, separating it from every other use of the same
     * key material — {@see Hmac::KEY_DOMAIN} is the sibling label for MAC keys.
     *
     * Part of the `v2.` format: a verifier in another language needs it to compute the same ids.
     */
    private const KEY_ID_DOMAIN = 'egl/utils:keyid:v1';

    /** @var non-empty-array<string, SecretKey> raw key id => key, current first */
    private readonly array $byKeyId;

    /**
     * @param non-empty-array<string, SecretKey> $byKeyId
     */
    private function __construct(
        private readonly SecretKey $current,
        array $byKeyId,
    ) {
        $this->byKeyId = $byKeyId;
    }

    /**
     * A ring with `$current` as the encryption key and `$previous` still accepted for decryption.
     *
     * Order is meaningful only for `$current`: it is the one {@see Crypto::encrypt()} uses. The
     * previous keys are a set, tried by id rather than in sequence, so their order is irrelevant
     * and no caller has to think about it.
     *
     * @throws CryptoException if two keys derive the same id — in practice, the same key listed
     *                         twice
     */
    public static function of(SecretKey $current, SecretKey ...$previous): self
    {
        $byKeyId = [];

        foreach ([$current, ...$previous] as $position => $key) {
            $keyId = self::keyIdOf($key);

            if (isset($byKeyId[$keyId])) {
                throw new CryptoException(\sprintf(
                    'Two keys in this ring derive the same key id (%s) — the key at position %d '
                    . 'collides with an earlier one. Almost always this is the same key listed '
                    . 'twice; a genuine %d-byte collision between distinct keys is refused for the '
                    . 'same reason, because a ring that cannot tell its keys apart would decrypt '
                    . 'with the wrong one and report the failure as a tampered token.',
                    \bin2hex($keyId),
                    $position,
                    self::KEY_ID_BYTES,
                ));
            }

            $byKeyId[$keyId] = $key;
        }

        /** @var non-empty-array<string, SecretKey> $byKeyId */
        return new self($current, $byKeyId);
    }

    /**
     * The key new tokens are encrypted under.
     */
    public function current(): SecretKey
    {
        return $this->current;
    }

    /**
     * The key with this raw id, or `null` when the ring does not hold it.
     *
     * `null` rather than an exception, so the caller decides what an unrecognised id means —
     * {@see Crypto::decrypt()} turns it into a refusal, which is the only sound reading for a
     * token whose key has already left the window.
     */
    public function findByKeyId(string $keyId): ?SecretKey
    {
        return $this->byKeyId[$keyId] ?? null;
    }

    /**
     * Every key in the ring, current first.
     *
     * The one caller is {@see Crypto::decrypt()}'s `v1.` path: a `v1.` token carries no key id, so
     * the only way to read one during a rotation is to try each key. Distinct from
     * {@see findByKeyId()} on purpose — the id-addressed lookup is what `v2.` buys, and this is the
     * fallback that keeps pre-rotation tokens readable while it is being adopted.
     *
     * @return non-empty-list<SecretKey>
     */
    public function all(): array
    {
        /** @var non-empty-list<SecretKey> $keys */
        $keys = \array_values($this->byKeyId);

        return $keys;
    }

    /**
     * The current key's id, hex-encoded — for a health check or a log line.
     *
     * Safe to print, and useful precisely because it is: it answers "which key is this deployment
     * encrypting under?" without exposing any key material, the same reason
     * {@see Hash::algorithm()} exists as a queryable value rather than only a log message.
     */
    public function currentKeyId(): string
    {
        return \bin2hex(self::keyIdOf($this->current()));
    }

    /**
     * The raw four-byte id a key derives to.
     *
     * @internal {@see Crypto} and {@see SecretKeyRing} only — the encoded form belongs in a token,
     *           and {@see self::currentKeyId()} is the safe hex spelling for anything else
     */
    public static function keyIdOf(SecretKey $key): string
    {
        return \hash_hkdf('sha256', $key->bytes(), self::KEY_ID_BYTES, self::KEY_ID_DOMAIN);
    }
}
