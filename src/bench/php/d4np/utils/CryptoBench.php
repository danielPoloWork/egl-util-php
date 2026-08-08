<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Security\Crypto;
use D4np\Utils\Security\SecretKey;
use PhpBench\Attributes as Bench;

/**
 * NFR-13: a 1 KiB `Crypto` encrypt+decrypt round-trip in ≤ 60 µs (roadmap item 12.5, RFC-0002).
 *
 * **The round trip, not the two halves separately.** The spec names one number for
 * encrypt-then-decrypt, and every prior instance of this pattern in this project (NFR-01's
 * hydration, NFR-09's gateway) budgets the operation a consumer actually performs rather than
 * a half nobody calls alone. `Crypto::decrypt()` cannot be measured without a real token to feed
 * it, so a round trip is also the only shape that measures decryption honestly — a hand-built or
 * cached ciphertext would skip the nonce generation and tag verification the real call pays for.
 *
 * **1 KiB is the size the spec names**, not a size chosen for convenience — GCM's cost is
 * dominated by AES-NI throughput once the fixed per-call overhead (nonce generation,
 * base64url encode/decode, tag slicing) is paid, so the ratio between those two costs is what a
 * different size would change, not a number this class controls.
 *
 * **No separate control subject.** Item 12.4's finding (ADR pending as item 12.6) was that a
 * run-wide runner slowdown moves *every* subject in one CI job together, and
 * `RowNormalizerBench::benchInlineTrimHundredRows` already serves that role for this benchmark
 * job — one control per job is what the finding calls for, not one per file. A second control
 * here would duplicate that signal without adding one.
 *
 * The ≤ 60 µs ceiling carries the same caveat as every other absolute budget in this project
 * (NFR-01's, NFR-03's): it is tied to spec NFR-06's reference machine, not asserted here as a
 * hard gate for that reason — `tools/bench_budget_gate.py` enforces it against CI hardware
 * instead, which is a **weaker** claim than the specification's and says so in its own output.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(10)]
#[Bench\Revs(1000)]
#[Bench\RetryThreshold(5)]
final class CryptoBench
{
    private Crypto $crypto;

    private string $plaintext = '';

    public function setUp(): void
    {
        // A fresh key per benchmark run, not per rev: SecretKey::generate() is deliberately not
        // part of what NFR-13 budgets — it is a one-time cost at wiring, not a per-message one.
        $this->crypto = new Crypto(SecretKey::generate());

        // Content is irrelevant to GCM's timing (it is not data-dependent at this level); the
        // length is what the spec names.
        $this->plaintext = \str_repeat('a', 1024);
    }

    /**
     * NFR-13's subject. Budget enforced by `tools/bench_budget_gate.py` in `ci.yml` and
     * `nightly.yml` (`--budget benchCryptoRoundTrip=60`) — one home for the number, following the
     * pattern items 12.3 and prior established rather than a `Bench\Assert` here.
     */
    public function benchCryptoRoundTrip(): void
    {
        $token = $this->crypto->encrypt($this->plaintext);
        $this->crypto->decrypt($token);
    }
}
