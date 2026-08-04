<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Security\Hash;
use PhpBench\Attributes as Bench;

/**
 * NFR-05: `Hash::make` with Argon2id defaults, **50–200 ms** on the reference machine —
 * *"deliberately slow; documented for capacity planning"*.
 *
 * **This budget is a range, and that makes it unlike every other NFR in this project.** For
 * NFR-01 and NFR-03, faster is better and only the ceiling matters. Here, being *under* the floor
 * is the more serious failure: a password hash that completes in 5 ms means the work factor is
 * inadequate, and no amount of fast is good.
 *
 * That asymmetry is why the **security** half of NFR-05 is not asserted here at all. Wall-clock
 * time depends on the CPU, the memory bandwidth and whatever else is running; the *work factor*
 * does not. `HashMatrixTest::testTheArgon2idWorkFactorMeetsTheOwaspFloor()` asserts
 * `memory_cost` and `time_cost` against OWASP's published minimums, which is the machine-
 * independent statement of "deliberately slow" and the assertion that would still catch a future
 * PHP lowering its defaults. What is left for this benchmark is NFR-05's *other* stated purpose:
 * producing a number for **capacity planning**.
 *
 * Nothing is asserted here. The absolute range is tied to spec NFR-06's reference machine (a Ryzen
 * 7 5800X), and gating on it from arbitrary CI hardware would fail for reasons unrelated to any
 * regression — the same reasoning items 3.5 and 4.5 recorded, with roadmap item **7.1** owning
 * baseline-tracked enforcement.
 *
 * `Revs(1)` is deliberate. One hash *is* the unit NFR-05 budgets, and at a few hundred
 * milliseconds each, averaging many per iteration would only make the run slow without making the
 * number better.
 */
#[Bench\Iterations(5)]
#[Bench\Revs(1)]
#[Bench\RetryThreshold(20)]
final class HashBench
{
    private Hash $hash;

    public function setUpHash(): void
    {
        $this->hash = new Hash();
    }

    /**
     * The measured figure on the development machine is well **above** NFR-05's 200 ms ceiling —
     * see `docs/benchmarks/` for the number and the analysis. That is a capacity-planning finding
     * (login latency), not a security one: the cost parameters are PHP's defaults and clear
     * OWASP's floor, so the work factor is right and the duration is what that work factor costs
     * on this hardware.
     */
    #[Bench\BeforeMethods('setUpHash')]
    public function benchMakeArgon2id(): void
    {
        $this->hash->make('correct horse battery staple');
    }

    /**
     * The fallback algorithm, measured for the same capacity-planning reason: a deployment that
     * lands on bcrypt should know what it costs, and the two differ enough to matter.
     */
    public function benchMakeBcrypt(): void
    {
        password_hash('correct horse battery staple', PASSWORD_BCRYPT);
    }

    /**
     * Verification is on the login path for *every* user, where hashing is only on registration
     * and upgrade — so its cost is the one that scales with traffic.
     */
    #[Bench\BeforeMethods('setUpVerify')]
    public function benchVerifyArgon2id(): void
    {
        $this->hash->verify('correct horse battery staple', $this->stored);
    }

    private string $stored = '';

    public function setUpVerify(): void
    {
        $this->hash = new Hash();
        $this->stored = $this->hash->make('correct horse battery staple');
    }
}
