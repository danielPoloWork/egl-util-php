<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\CryptoException;
use D4np\Utils\Support\SystemClock;
use DateInterval;
use Psr\Clock\ClockInterface;

/**
 * Keyed message authentication for signed URLs and webhook signatures (spec r20 FR-48, RFC-0003;
 * ADR-0065).
 *
 * The two things this replaces are the most-copied security snippets in enterprise PHP, and both
 * fail the same two ways: `sha1($secret . $message)` for the digest, and `$expected === $actual`
 * for the check. The first is a keyed-hash construction whose weaknesses HMAC exists to remove;
 * the second short-circuits on the first differing byte, which leaks the matching prefix length
 * through timing and is enough to reconstruct a signature byte by byte given enough attempts.
 *
 * **The token is a detached signature, not a container.** `sign()` returns
 * `"v1." . base64url(expiry ‖ mac)` and the message stays where it already lives — a URL's path
 * and query, a webhook's request body. That is what both use cases actually need: a webhook
 * signature travels in a header beside a body the library never sees, and a signed URL carries its
 * signature as one query parameter rather than re-encoding the whole URL inside itself. The shape
 * of the prefix and payload is ADR-0054's, established for {@see Crypto} and reused deliberately
 * so this group has one token grammar rather than two.
 *
 * **The MAC covers the expiry as well as the message.** An implementation that signed only the
 * message would leave the expiry bytes unauthenticated, and extending a signed URL's life would be
 * a matter of editing them — the signature would still check out, because it never covered them.
 * The expiry is a fixed eight bytes at a fixed offset, so `expiry ‖ message` needs no delimiter:
 * with a variable-width prefix, `1 ‖ "23"` and `12 ‖ "3"` would produce the same bytes to sign and
 * therefore the same MAC, which is the canonicalization bug that has broken more than one
 * signing scheme.
 *
 * **The MAC key is derived, not the caller's key.** `hash_hkdf()` separates it by the domain label
 * {@see self::KEY_DOMAIN}, so a deployment with one `APP_SECRET` behind both {@see Crypto} and this
 * class never hands the same bytes to two primitives. It is not a break anyone has demonstrated,
 * but the fix costs one hash at construction and removes the question instead of documenting it.
 *
 * **The algorithm is never read from the token.** It is chosen at construction from
 * {@see self::ALGORITHMS} and lives on the instance. This is the one design point worth stating as
 * a prohibition rather than a preference: a token format that names its own algorithm hands the
 * attacker the choice of how their forgery will be checked, which is the JWT `alg` confusion class
 * of vulnerability. The `v1.` prefix is a *format* version, not an algorithm field, and a
 * deployment that changes algorithm invalidates its outstanding tokens rather than trusting them.
 * Key identifiers, when the `SecretKeyRing` issue lands, arrive the same way ADR-0054 planned —
 * as a `v2.` grammar, while `v1.` tokens keep verifying.
 *
 * **`verify()` authenticates before it reads.** The MAC is checked first, and only then is the
 * expiry decoded and compared. Reversing that order would mean acting on bytes an attacker
 * supplied and nothing has vouched for yet, and would let the failure message distinguish
 * "expired" from "forged" for a token whose MAC was never valid. As written, reaching the expiry
 * check at all is proof the token was signed with this key.
 *
 * **`verify()` returns `void` and throws on every failure** — an unrecognised prefix, malformed
 * base64url, a payload that is not exactly the pinned length, a bad MAC, or an expired token.
 * Never `bool`, which RFC-0002 named as the anti-requirement for {@see Crypto::decrypt()} and
 * which applies identically here: `if ($hmac->verify(...))` is a check a caller can forget to
 * write, and a caught exception is not.
 *
 * ```php
 * $hmac  = new Hmac(SecretKey::generate());
 * $token = $hmac->sign('/reports/42', new DateInterval('PT15M'));
 * $hmac->verify('/reports/42', $token);   // returns void, or throws CryptoException
 * ```
 */
final class Hmac
{
    /**
     * The algorithms this class will use, mapped to the exact byte length of their raw output.
     *
     * An allowlist, so an algorithm this library has not vetted cannot be reached by passing its
     * name — `hash_hmac()` itself accepts `md5`, `crc32b` and every other registered algorithm
     * without comment. The lengths are here rather than derived at runtime because they are what
     * pins the payload size in {@see verify()}: ADR-0054 found that OpenSSL's GCM tag check
     * accepts a *correct prefix* of a real tag at any length down to one byte, and a token format
     * that let its authenticator's length vary would hand back exactly the lever that finding
     * closed. The length a token gets checked against is this table's, never the token's own.
     *
     * @var array<string, positive-int>
     */
    private const ALGORITHMS = [
        'sha256' => 32,
        'sha384' => 48,
        'sha512' => 64,
    ];

    private const DEFAULT_ALGORITHM = 'sha256';

    private const VERSION_PREFIX = 'v1.';

    /** The fixed width of the encoded expiry, in bytes — an unsigned 64-bit big-endian instant. */
    private const EXPIRY_BYTES = 8;

    /** The expiry value meaning "this token does not expire"; refused as a real expiry in {@see sign()}. */
    private const NEVER_EXPIRES = 0;

    /**
     * HKDF's `info` label, which separates this class's key from every other use of the same
     * {@see SecretKey}.
     *
     * The deployment this protects is the common one: a single `APP_SECRET` wired into everything.
     * Handing identical bytes to AES-256-GCM and to HMAC is not a break anyone has demonstrated,
     * but "no published attack" is a weaker property than "the two primitives never see the same
     * key", and the second one costs a single hash at construction. So the MAC key is
     * `hash_hkdf(algorithm, secret, 0, 'egl/utils:hmac:v1')` and the caller's own bytes are never
     * the MAC key. The alternative — documenting "do not reuse a SecretKey between Crypto and
     * Hmac" — puts a correctness requirement on the caller, which is what this library refuses to
     * do everywhere else.
     *
     * The label is part of the `v1.` format. A verifier in another language needs it, along with
     * the payload layout, to interoperate — which it needed anyway, since `expiry ‖ mac` is not a
     * bare HMAC either.
     */
    private const KEY_DOMAIN = 'egl/utils:hmac:v1';

    private readonly string $algorithm;

    /** @var positive-int */
    private readonly int $macBytes;

    /** The domain-separated MAC key — never the caller's `SecretKey` bytes. {@see self::KEY_DOMAIN} */
    private readonly string $macKey;

    private readonly ClockInterface $clock;

    /**
     * `ext-hash` needs no construction-time guard the way {@see Crypto} needs one for
     * `ext-openssl`: `hash_hmac()` has been part of PHP's always-available core since 7.4 and
     * cannot be compiled out, so there is no build where this class would exist and its primitive
     * would not. Said out loud because an absent guard beside a sibling class that has one reads
     * as an oversight otherwise.
     *
     * @param string $algorithm one of {@see self::ALGORITHMS}' keys
     *
     * @throws CryptoException if `$algorithm` is not on the allowlist
     */
    public function __construct(
        SecretKey $key,
        ?ClockInterface $clock = null,
        string $algorithm = self::DEFAULT_ALGORITHM,
    ) {
        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new CryptoException(\sprintf(
                'Unsupported HMAC algorithm "%s". This class accepts only %s — hash_hmac() itself '
                . 'would accept md5, crc32b and every other registered algorithm without comment, '
                . 'so the allowlist is where that is refused.',
                $algorithm,
                \implode(', ', \array_keys(self::ALGORITHMS)),
            ));
        }

        $this->algorithm = $algorithm;
        $this->macBytes = self::ALGORITHMS[$algorithm];
        $this->macKey = \hash_hkdf($algorithm, $key->bytes(), 0, self::KEY_DOMAIN);
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Signs `$message`, optionally until `$ttl` from now.
     *
     * A `null` TTL produces a token that never expires — the right answer for a webhook signature,
     * where the expiry is the delivery attempt's own timeout and not a property of the signature.
     * A TTL that does not move time forward is **refused rather than honoured**: an inverted or
     * zero `DateInterval` would mint a token already past its expiry, which is a caller defect that
     * shows up later as an inexplicable verification failure.
     *
     * @throws CryptoException if `$ttl` does not move the expiry forward, or lands at or before
     *                         the Unix epoch — the value {@see self::NEVER_EXPIRES} reserves, so a
     *                         clock set before 1970 cannot silently mint an eternal token
     */
    public function sign(string $message, ?DateInterval $ttl = null): string
    {
        $expiry = self::NEVER_EXPIRES;

        if ($ttl !== null) {
            $now = $this->clock->now();
            $expiresAt = $now->add($ttl);

            if ($expiresAt <= $now) {
                throw new CryptoException(
                    'A time-to-live must move the expiry forward. An inverted or zero DateInterval '
                    . 'would sign a token that is already expired, which fails at the verifier as '
                    . 'though the signature were wrong.',
                );
            }

            $expiry = $expiresAt->getTimestamp();

            if ($expiry <= self::NEVER_EXPIRES) {
                throw new CryptoException(\sprintf(
                    'The expiry resolves to timestamp %d, at or before the Unix epoch, which is '
                    . 'the value reserved for "never expires". Refused rather than encoded, '
                    . 'because encoding it would turn a bounded token into an eternal one.',
                    $expiry,
                ));
            }
        }

        $expiryBytes = self::encodeExpiry($expiry);

        return self::VERSION_PREFIX . Base64Url::encode($expiryBytes . $this->mac($expiryBytes . $message));
    }

    /**
     * Verifies that `$token` was produced by {@see sign()} over `$message` with this key, and has
     * not expired.
     *
     * @throws CryptoException on every failure — an unrecognised version prefix, malformed
     *                         base64url, a payload that is not exactly the length this instance's
     *                         algorithm pins, a MAC that does not match, or an expired token. A
     *                         wrong key and a tampered message are indistinguishable here by
     *                         design: both are simply a MAC that does not match
     */
    public function verify(string $message, string $token): void
    {
        if (!\str_starts_with($token, self::VERSION_PREFIX)) {
            throw new CryptoException(\sprintf(
                'Unrecognised token version. Expected the "%s" prefix.',
                self::VERSION_PREFIX,
            ));
        }

        $payload = Base64Url::decode(\substr($token, \strlen(self::VERSION_PREFIX)));

        // The expected length is this instance's, from the allowlist — never strlen() of anything
        // the token supplied. A caller-influenced length is what ADR-0054 closed for GCM tags.
        if ($payload === false || \strlen($payload) !== self::EXPIRY_BYTES + $this->macBytes) {
            throw new CryptoException('The token is malformed or truncated.');
        }

        $expiryBytes = \substr($payload, 0, self::EXPIRY_BYTES);
        $mac = \substr($payload, self::EXPIRY_BYTES);

        // Authenticate first. Everything below this line acts on bytes that are now known to have
        // been signed with this key; above it, they are an attacker's input.
        //
        // Both sides are named locals on purpose: `ConstantTimeComparisonTest` asserts that these
        // two are never handed to a variable-time comparison, and a registered path whose named
        // secrets do not exist in the method would make that assertion vacuously green — the
        // failure mode BUG-0001 is about, one layer up.
        $expected = $this->mac($expiryBytes . $message);

        if (!\hash_equals($expected, $mac)) {
            throw new CryptoException(
                'Signature verification failed: wrong key, or the message or token was tampered with.',
            );
        }

        $expiry = self::decodeExpiry($expiryBytes);

        if ($expiry !== self::NEVER_EXPIRES && $this->clock->now()->getTimestamp() >= $expiry) {
            throw new CryptoException(\sprintf(
                'The token expired at %d. Expiry is inclusive: a token is no longer valid at the '
                . 'instant it names, following RFC 7519 exp semantics.',
                $expiry,
            ));
        }
    }

    /**
     * The raw MAC over `$signedBytes`, under this instance's key and allowlisted algorithm.
     *
     * `$binary = true`: the raw digest, not its hex form. Hex would double the token's payload for
     * no gain, and — the reason it matters more than size — a hex digest compared with a
     * case-insensitive comparison would accept two spellings of the same signature.
     */
    private function mac(string $signedBytes): string
    {
        return \hash_hmac($this->algorithm, $signedBytes, $this->macKey, true);
    }

    /**
     * An unsigned 64-bit big-endian instant.
     *
     * Hand-rolled rather than `pack('J', …)`/`unpack('J', …)` so that both directions are visibly
     * symmetric and the decoded value is an `int` to the analyser without an annotation —
     * `unpack()` returns `array|false`, and the `false` branch would be a guard that provably
     * cannot fire on an eight-byte input, which is the dead defensive code ADR-0022 removed from
     * {@see Hash} and item 12.1 removed from {@see Crypto}.
     */
    private static function encodeExpiry(int $expiry): string
    {
        $bytes = '';

        for ($shift = (self::EXPIRY_BYTES - 1) * 8; $shift >= 0; $shift -= 8) {
            $bytes .= \chr(($expiry >> $shift) & 0xFF);
        }

        return $bytes;
    }

    private static function decodeExpiry(string $bytes): int
    {
        $expiry = 0;

        for ($index = 0; $index < self::EXPIRY_BYTES; $index++) {
            $expiry = ($expiry << 8) | \ord($bytes[$index]);
        }

        return $expiry;
    }
}
