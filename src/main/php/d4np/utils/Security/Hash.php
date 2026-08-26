<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\UtilsException;
use Psr\Log\LoggerInterface;

/**
 * Password hashing with an Argon2id default and an explicit fallback policy (spec FR-11,
 * ADR-0022).
 *
 * **This is an instance, not a static helper, and that is a deliberate break from the rest of the
 * `Security` group.** {@see Escaper} and {@see Sanitizer} are pure functions of their input, so
 * static suits them. Hashing is not: it carries a *policy* (what to do when Argon2id is
 * unavailable) and a *collaborator* (the logger that policy has to announce itself through).
 * Threading both through static calls would mean either global mutable configuration or repeating
 * them at every call site, and configuration that can be changed halfway through a request is
 * exactly the wrong shape for a security decision.
 *
 * **`PASSWORD_DEFAULT` is not used, and cannot be.** It is `bcrypt` on every PHP release to date —
 * verified on 8.3, where it evaluates to `'2y'` even though Argon2id is available. Code that
 * reaches for `PASSWORD_DEFAULT` expecting "whatever is best" silently gets the weaker algorithm,
 * which is precisely why FR-11 names Argon2id rather than deferring to PHP.
 *
 * **Availability is `defined('PASSWORD_ARGON2ID')`,** the check FR-11 specifies. Argon2 support is
 * a compile-time option (`--with-password-argon2`), so the constant's absence is the honest signal
 * that this build cannot do it. Passing an algorithm identifier PHP does not know raises a bare
 * `ValueError` — outside ADR-0004's exception family — so the check happens *before* that, not as
 * a rescue afterwards.
 *
 * **The fallback decision is made once, at construction, not per call.** With
 * `bcryptFallback: false` an unavailable Argon2id raises immediately, so a misconfigured
 * deployment fails while it is being wired rather than the first time a user tries to register.
 * With the default `true` it logs one WARNING at construction rather than one per hash, which
 * would bury the signal in the noise it generates.
 *
 * **Three ways to build one, and only one of them is silent.** Reach for the first two.
 *
 * ```php
 * $hash = Hash::strict();                        // Argon2id or refuse to construct
 * $hash = new Hash(logger: $psrLogger);          // Argon2id, bcrypt fallback, WARNING logged
 * $hash = new Hash();                            // Argon2id, bcrypt fallback, NOTHING SAID
 *
 * $stored = $hash->make($password);
 * if ($hash->verify($password, $stored) && $hash->needsRehash($stored)) {
 *     $stored = $hash->make($password);          // upgrade on login (FR-11)
 * }
 * ```
 *
 * **The third line is a real hazard and is named as one** (issue #102, ADR-0079). On a build
 * without Argon2id it hashes with bcrypt and says so nowhere: no logger to warn through, and
 * {@see self::algorithm()} only tells a caller who thought to ask. The 1.0 surface is frozen
 * (ADR-0059), so the permissive default cannot be inverted before a MAJOR — what this class can do
 * is make the safe form a named constructor rather than a boolean nobody discovers, which is what
 * {@see self::strict()} is for. **A deployment that cares should either call `strict()` or assert
 * `algorithm()` in a health check**; one that does neither has chosen bcrypt without deciding to.
 */
final class Hash
{
    /**
     * The algorithm this instance will actually use — `argon2id` or `2y` (bcrypt).
     *
     * PHP types these constants as `string` since 7.4.
     */
    private readonly string $algorithm;

    /**
     * @param bool $bcryptFallback whether to fall back to bcrypt when Argon2id is unavailable.
     *                             `true` (the FR-11 default) degrades and logs; `false` refuses to
     *                             construct at all
     * @param LoggerInterface|null $logger where the fallback WARNING goes. Optional, because
     *                             requiring a PSR-3 implementation to hash a password would be a
     *                             heavy demand for a utility — but see {@see algorithm()}: the
     *                             fallback is queryable as a value precisely so that a deployment
     *                             without a logger can still *detect* it rather than only be told
     *                             about it
     *
     * @throws UtilsException when Argon2id is unavailable and `$bcryptFallback` is `false`
     */
    public function __construct(
        private readonly bool $bcryptFallback = true,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->algorithm = self::selectAlgorithm(
            \defined('PASSWORD_ARGON2ID'),
            $this->bcryptFallback,
            $this->logger,
        );
    }

    /**
     * Argon2id or nothing: a `Hash` that **refuses to construct** rather than degrade to bcrypt.
     *
     * Equivalent to `new self(bcryptFallback: false)`, and added because that is not the same thing
     * as being reachable (issue #102, ADR-0079). The fail-closed choice was a boolean argument a
     * caller had to know existed, while the permissive behaviour was what `new Hash()` gave you;
     * this makes the safe form a named, discoverable entry point that shows up in the class's own
     * API listing next to `make()` and `verify()`.
     *
     * **It does not change what `new Hash()` does.** The 1.0 surface is frozen (ADR-0059), so the
     * constructor's `bcryptFallback: true` default stays — and a `Hash` built with no logger still
     * degrades quietly, with {@see self::algorithm()} as the only signal. That residual is real,
     * recorded in ADR-0079, and a candidate for the next MAJOR rather than something this method
     * pretends to fix.
     *
     * @throws UtilsException when Argon2id is unavailable in this build
     */
    public static function strict(): self
    {
        return new self(bcryptFallback: false);
    }

    /**
     * The fallback policy itself, as a pure function of "is Argon2id available".
     *
     * **Separated from the constructor so it can be tested.** The constructor's own check —
     * `defined('PASSWORD_ARGON2ID')` — is a compile-time fact that no test can vary: a constant
     * cannot be un-defined, so on any build with Argon2 support the entire fallback branch is
     * unreachable. Left inline, the most security-relevant decision in this class would have been
     * unexecuted by the suite, and its coverage would have had to be waived rather than earned.
     *
     * Note what this does *not* expose: there is no way to make {@see make()} hash with bcrypt on
     * a build that supports Argon2id. The seam is the **decision**, not the weak algorithm — which
     * is the difference between making a policy testable and making it configurable.
     *
     * `@internal` because the availability argument has exactly one honest value in production,
     * and that value is supplied by the constructor.
     *
     * @internal
     *
     * @throws UtilsException when Argon2id is unavailable and the fallback is disabled
     */
    public static function selectAlgorithm(
        bool $argon2idAvailable,
        bool $bcryptFallback,
        ?LoggerInterface $logger = null,
    ): string {
        if ($argon2idAvailable) {
            return PASSWORD_ARGON2ID;
        }

        if (!$bcryptFallback) {
            throw new UtilsException(
                'Argon2id is not available in this PHP build (PASSWORD_ARGON2ID is not defined), '
                . 'and bcryptFallback is disabled, so this Hash refuses to construct rather than '
                . 'silently hashing with a weaker algorithm. Either rebuild PHP with '
                . '--with-password-argon2, or construct with bcryptFallback: true and accept '
                . 'bcrypt deliberately.',
            );
        }

        // Once, at construction, rather than once per make(): a warning repeated on every password
        // hash is one nobody reads.
        $logger?->warning(
            'Argon2id is unavailable in this PHP build; falling back to bcrypt for password '
            . 'hashing. Rebuild PHP with --with-password-argon2 to use the stronger algorithm, '
            . 'or construct Hash with bcryptFallback: false to make this a hard failure.',
            ['algorithm' => PASSWORD_BCRYPT],
        );

        return PASSWORD_BCRYPT;
    }

    /**
     * The algorithm identifier in use — `argon2id`, or `2y` when the fallback is engaged.
     *
     * Exists so the fallback is a value a caller can assert on, not only a line in a log a caller
     * may not have configured. A health check can compare it against what it expects.
     */
    public function algorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Hash a password.
     *
     * The cost parameters are PHP's own defaults, deliberately: they are chosen per release by
     * people tracking hardware, and spec NFR-05 budgets the result at 50–200 ms on the reference
     * machine — *slowness is the feature*. Overriding them here would mean this library, rather
     * than PHP, owning a number that has to keep moving.
     *
     * The result is **self-describing**: `$argon2id$v=19$m=…` or `$2y$10$…` carries the algorithm
     * and its parameters, which is what lets {@see verify()} and {@see needsRehash()} work on a
     * stored hash without a separate column recording how it was made.
     *
     */
    public function make(string $password): string
    {
        // No defensive check on the result: on PHP 8 `password_hash()` returns a non-empty string
        // or throws — it lost its `false` return in PHP 8.0. A guard here was written and then
        // removed, because PHPStan at max level correctly reported the comparison as dead: the
        // return type is `non-empty-string`. Dead defensive code is worse than none, since it
        // implies a failure mode that does not exist.
        return \password_hash($password, $this->algorithm);
    }

    /**
     * Whether `$password` matches `$hash`.
     *
     * Delegates to `password_verify()`, which compares in constant time — a hand-rolled `===` here
     * would leak the length of the matching prefix through timing.
     *
     * **Works across algorithms**, which is what makes upgrade-on-login possible: a bcrypt hash
     * stored before this deployment still verifies against an Argon2id-configured instance,
     * because the algorithm is read from the hash rather than assumed. A malformed or empty stored
     * hash returns `false` rather than raising (verified) — the honest answer, since an
     * unparseable hash matches nothing.
     */
    public function verify(string $password, string $hash): bool
    {
        return \password_verify($password, $hash);
    }

    /**
     * Whether `$hash` was made with something other than this instance's current settings.
     *
     * The upgrade-on-login half of FR-11. It is `true` for a hash made with a *different
     * algorithm* (a bcrypt hash under an Argon2id instance) and also for one made with the same
     * algorithm at *weaker parameters* — PHP compares the cost factors recorded in the hash
     * against the current defaults, so a deployment that moves to a newer PHP with stronger
     * defaults re-hashes on next login without any change here.
     *
     * Only meaningful after {@see verify()} has returned `true`: rehashing requires the plaintext,
     * and the only moment it legitimately exists is the login that just succeeded.
     *
     * A malformed hash reports `true` (verified). That is the safe direction — it cannot be
     * upgraded in place, but it also cannot be left looking current.
     */
    public function needsRehash(string $hash): bool
    {
        return \password_needs_rehash($hash, $this->algorithm);
    }
}
