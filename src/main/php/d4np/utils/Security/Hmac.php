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
 * Key identifiers arrive the way ADR-0054 planned and this docblock promised — as a `v2.` grammar,
 * with `v1.` tokens still verifying. See the rotation section below.
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
 *
 * ## Key rotation: the `v2.` format (spec r29 FR-48b, issue #179, ADR-0085)
 *
 * `v1.` carries no key identifier, so rotating a signing key invalidates every outstanding signed
 * URL and webhook signature at once. Pass a {@see SecretKeyRing} instead of a bare
 * {@see SecretKey} and tokens gain one:
 *
 * ```php
 * $hmac  = new Hmac(SecretKeyRing::of($currentKey, $lastMonthsKey));
 * $token = $hmac->sign('/reports/42');       // "v2.<base64url>", under the CURRENT key
 * $hmac->verify('/reports/42', $lastMonthsToken);  // still verifies, under the previous key
 * ```
 *
 * This reuses ADR-0083's convention rather than inventing a second one — the same derived key id,
 * the same fixed-width-field-first layout, the same fail-closed reading of an unknown id. One part
 * is genuinely different, and it is the part that carries the security property:
 *
 * - **`v2.` is `base64url(keyId ‖ expiry ‖ mac)`** — the four-byte key id first, at a fixed
 *   offset, extending the same slicing discipline `v1.` already uses.
 * - **The MAC covers the key id**, because there is nowhere else to put it. {@see Crypto} binds its
 *   id with GCM's AAD; HMAC has no AAD, so the id goes *under* the MAC:
 *   `mac = hmac(keyId ‖ expiry ‖ message)`. Without that the id would be unauthenticated, and
 *   rewriting it to name a different key would be refused only by luck — which is the exact
 *   failure ADR-0083 §1 exists to prevent. It is also why a `v2.` body replayed as `v1.` does not
 *   verify: the bytes signed are not the bytes checked.
 * - **An unknown key id fails closed**, never retried against the ring's other keys. Retrying
 *   would make the id decorative and a retired key effectively still live.
 * - **A bare `SecretKey` still produces byte-identical `v1.` tokens.** A consumer who passed one
 *   has not asked for rotation, and their verifiers — possibly in another language, written
 *   against ADR-0065's published grammar — have not been told about a second format. `v2.` is
 *   opt-in by passing a ring, which is what keeps this additive under the 1.x freeze (ADR-0059).
 * - **A ring verifies `v1.` tokens too**, by trying each of its keys, so adopting a ring is a
 *   migration rather than a cutover.
 *
 * **The derived MAC keys are computed once, at construction — one per key in the ring.** The MAC
 * key is already an HKDF per key ({@see self::KEY_DOMAIN}), and a ring multiplies that by the
 * number of keys it holds. Deriving them inside {@see verify()} would pay those hashes per
 * message for values that cannot change; ADR-0083's first draft made precisely that mistake with
 * the key id and it cost a hash per message.
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

    /** The key-identified format (spec r29 FR-48b, ADR-0085); emitted only for a {@see SecretKeyRing}. */
    private const VERSION_PREFIX_KEYED = 'v2.';

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

    /**
     * Whether {@see sign()} emits `v2.`, which is true exactly when a ring was supplied.
     *
     * A single {@see SecretKey} keeps producing byte-identical `v1.` tokens: passing one is not a
     * request for rotation, and a verifier written against ADR-0065's grammar has not been told
     * about a second format.
     */
    private readonly bool $keyed;

    /**
     * The current key's raw id, derived **once** here. Empty for the `v1.` path, which has no id
     * and whose MAC therefore covers `expiry ‖ message` exactly as it always did.
     */
    private readonly string $currentKeyId;

    /**
     * The current key's domain-separated MAC key — never the caller's `SecretKey` bytes, and what
     * {@see sign()} signs under. {@see self::KEY_DOMAIN}
     */
    private readonly string $currentMacKey;

    /**
     * Raw key id => that key's derived MAC key, current first, for the verification paths.
     *
     * **One HKDF per key, all of them at construction.** `v2.` indexes straight into this by the
     * id it read from the token; `v1.` walks it in order, current first, because a `v1.` token
     * names no key. Deriving inside {@see verify()} instead would re-run a hash per message per
     * candidate key for a value fixed at construction.
     *
     * Typed `array` rather than `non-empty-array` for the reason {@see SecretKeyRing::$byKeyId}
     * gives: phpDocumentor's parser does not recognise `non-empty-array<K, V>` and fails the
     * api-docs gate on it. The non-emptiness is structural — a bare key becomes a ring of one.
     *
     * @var array<string, string>
     */
    private readonly array $macKeysByKeyId;

    private readonly ClockInterface $clock;

    /**
     * `ext-hash` needs no construction-time guard the way {@see Crypto} needs one for
     * `ext-openssl`: `hash_hmac()` has been part of PHP's always-available core since 7.4 and
     * cannot be compiled out, so there is no build where this class would exist and its primitive
     * would not. Said out loud because an absent guard beside a sibling class that has one reads
     * as an oversight otherwise.
     *
     * @param SecretKey|SecretKeyRing $key       a bare key keeps ADR-0065's `v1.` behaviour
     *                                           exactly; a ring signs under its current key as
     *                                           `v2.` and verifies anything the ring still holds
     * @param string                  $algorithm one of {@see self::ALGORITHMS}' keys
     *
     * @throws CryptoException if `$algorithm` is not on the allowlist
     */
    public function __construct(
        SecretKey|SecretKeyRing $key,
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
        $this->clock = $clock ?? new SystemClock();

        // A bare key becomes a ring of one, so there is a single verification path rather than two
        // that have to be kept in step — {@see Crypto}'s shape, deliberately. Only the emitted
        // prefix and whether the id is signed distinguish the two cases.
        $this->keyed = $key instanceof SecretKeyRing;
        $ring = $key instanceof SecretKeyRing ? $key : SecretKeyRing::of($key);

        // One HKDF per key, here and not per message. Current first, which is both the order
        // `SecretKeyRing::all()` guarantees and the order the `v1.` walk wants.
        $macKeysByKeyId = [];

        foreach ($ring->all() as $ringKey) {
            $macKeysByKeyId[SecretKeyRing::keyIdOf($ringKey)]
                = \hash_hkdf($algorithm, $ringKey->bytes(), 0, self::KEY_DOMAIN);
        }

        $this->macKeysByKeyId = $macKeysByKeyId;
        $this->currentKeyId = $this->keyed ? SecretKeyRing::keyIdOf($ring->current()) : '';
        $this->currentMacKey = $macKeysByKeyId[SecretKeyRing::keyIdOf($ring->current())];
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

        // The key id is prepended to the signed bytes, not merely to the token: HMAC has no AAD,
        // so this is the only place an id can be authenticated. `$currentKeyId` is empty on the
        // `v1.` path, which is what makes that path byte-identical to ADR-0065's grammar rather
        // than merely compatible with it.
        $mac = $this->mac($this->currentKeyId . $expiryBytes . $message, $this->currentMacKey);

        if (!$this->keyed) {
            return self::VERSION_PREFIX . Base64Url::encode($expiryBytes . $mac);
        }

        // `keyId ‖ expiry ‖ mac`, every field fixed-width at a fixed offset and the id first so it
        // can be read before anything is trusted — ADR-0083's layout, extended rather than
        // reinterpreted.
        return self::VERSION_PREFIX_KEYED . Base64Url::encode($this->currentKeyId . $expiryBytes . $mac);
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

        // The expected length is this instance's, from the allowlist — never strlen() of anything
        // the token supplied. A caller-influenced length is what ADR-0054 closed for GCM tags.
        // Exact rather than a minimum: unlike a ciphertext, every field here is fixed-width.
        $expectedBytes = ($keyed ? SecretKeyRing::KEY_ID_BYTES : 0) + self::EXPIRY_BYTES + $this->macBytes;

        if ($payload === false || \strlen($payload) !== $expectedBytes) {
            throw new CryptoException('The token is malformed or truncated.');
        }

        // `v2.` reads its key id first, before anything else in the token is trusted — but note
        // that reading it is not believing it: the id only selects a candidate key, and the MAC
        // below is what vouches for the id itself.
        $keyId = $keyed ? \substr($payload, 0, SecretKeyRing::KEY_ID_BYTES) : '';
        $body = $keyed ? \substr($payload, SecretKeyRing::KEY_ID_BYTES) : $payload;

        $expiryBytes = \substr($body, 0, self::EXPIRY_BYTES);
        $mac = \substr($body, self::EXPIRY_BYTES);
        $candidates = $this->candidateMacKeys($keyed, $keyId);

        // Authenticate first. Everything below the loop acts on bytes that are now known to have
        // been signed with one of this instance's keys; above it, they are an attacker's input.
        //
        // Both sides are named locals on purpose: `ConstantTimeComparisonTest` asserts that these
        // two are never handed to a variable-time comparison, and a registered path whose named
        // secrets do not exist in the method would make that assertion vacuously green — the
        // failure mode BUG-0001 is about, one layer up.
        //
        // The signed bytes begin with the key id, so on `v2.` a rewritten id changes what the MAC
        // must be. That is what makes the id authenticated with no AAD to put it in — and why the
        // refusal of a substituted id comes from this comparison rather than from a failed lookup.
        // On `v1.` the id is empty and these are ADR-0065's original bytes exactly.
        $verified = false;

        foreach ($candidates as $macKey) {
            $expected = $this->mac($keyId . $expiryBytes . $message, $macKey);

            if (\hash_equals($expected, $mac)) {
                $verified = true;
                break;
            }
        }

        // One uniform refusal whichever path arrived here, and whatever number of keys was tried:
        // a message that distinguished "no key matched" from "the one named key did not" would
        // report which ids a ring holds.
        if (!$verified) {
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
     * The derived MAC keys {@see verify()} should try, in order.
     *
     * For `v2.` that is exactly one — the key the token's id names — or **none, refused**: an id
     * this instance does not hold means the key has left the rotation window, or the token was
     * never ours. Falling back to trying every key anyway would make the id decorative and quietly
     * undo the point of retiring a key at all (ADR-0083 §3, applied unchanged).
     *
     * For `v1.` it is every key, current first, because a `v1.` token names none. That walk is
     * what lets a deployment adopt a ring without invalidating the tokens already in flight.
     *
     * @return non-empty-list<string>
     *
     * @throws CryptoException when a `v2.` token names a key id this instance does not hold
     */
    private function candidateMacKeys(bool $keyed, string $keyId): array
    {
        if (!$keyed) {
            return \array_values($this->macKeysByKeyId);
        }

        $macKey = $this->macKeysByKeyId[$keyId] ?? null;

        if ($macKey === null) {
            throw new CryptoException(\sprintf(
                'No key held here has id %s. The key that signed this token has left the rotation '
                . 'window, or the token came from another deployment. Refused rather than retried '
                . 'against the other keys, which would make the key id decorative and a retired '
                . 'key effectively still live.',
                \bin2hex($keyId),
            ));
        }

        return [$macKey];
    }

    /**
     * The raw MAC over `$signedBytes`, under `$macKey` and this instance's allowlisted algorithm.
     *
     * The key is a parameter because a ring holds several and `verify()` chooses which one this
     * call is about; the *algorithm* is not, and must not be — it is the instance's validated one,
     * which is the property {@see HmacTest} pins as a mechanism.
     *
     * `$binary = true`: the raw digest, not its hex form. Hex would double the token's payload for
     * no gain, and — the reason it matters more than size — a hex digest compared with a
     * case-insensitive comparison would accept two spellings of the same signature.
     */
    private function mac(string $signedBytes, string $macKey): string
    {
        return \hash_hmac($this->algorithm, $signedBytes, $macKey, true);
    }

    /**
     * An unsigned 64-bit big-endian instant.
     *
     * The codec itself is {@see Uint64}, shared with {@see RateLimiter}'s bucket state since item
     * 14.7 — one big-endian eight-byte format in this group rather than two hand-rolled copies that
     * can drift (item 10.4's lesson). These two wrappers stay because the domain meaning is worth a
     * name at the call site: `decodeExpiry()` says what the bytes *are*, and it is the name
     * `HmacTest`'s ordering assertion pins.
     */
    private static function encodeExpiry(int $expiry): string
    {
        return Uint64::encode($expiry);
    }

    private static function decodeExpiry(string $bytes): int
    {
        return Uint64::decode($bytes);
    }
}
