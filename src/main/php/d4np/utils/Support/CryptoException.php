<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * {@see \D4np\Utils\Security\Crypto} could not encrypt or decrypt (spec FR-40, RFC-0002;
 * ADR-0054).
 *
 * Covers every failure `Crypto`/`SecretKey` can produce: a missing `ext-openssl`, a malformed
 * or truncated token, an unrecognised version prefix, and — indistinguishably, because GCM does
 * not allow telling them apart without decrypting first — a wrong key or a tampered token.
 *
 * A plain leaf on {@see UtilsException}; unlike {@see HttpClientException}'s extension point,
 * nothing in the `Security` group needed a second failure kind to unify with.
 */
final class CryptoException extends UtilsException
{
}
