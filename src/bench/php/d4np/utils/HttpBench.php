<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Http\ApiEnvelope;
use D4np\Utils\Http\Router;
use PhpBench\Attributes as Bench;

/**
 * NFR-11: `Router` dispatch against a 50-route table in ≤ 5 µs, and `ApiEnvelope` construction
 * in ≤ 2 µs.
 *
 * **The 50-route table is the workload NFR-11 names, not a guess at a realistic one.** Every
 * route carries one placeholder (`/resourceNN/{id}`), because a router that matched only literal
 * paths would report the cost of `preg_match()` against nothing worth capturing — the estate this
 * class replaced always had at least one path parameter per endpoint. The dispatched path is the
 * last route registered, which is the worst case a linear scan over a compiled table can produce:
 * every earlier pattern is tried and fails before the match. {@see Router} has no cache and no
 * index (ADR-0050's stated non-goal), so the *last* route is where a regression in per-pattern
 * cost would show first.
 *
 * **`ApiEnvelope::ok()` is the subject, not `jsonSerialize()`.** NFR-11 budgets *construction* —
 * the object a handler builds before a response is ever sent — and serialization is a separate,
 * unbudgeted step `Response` performs later. Measuring through `jsonSerialize()` would report the
 * two costs as one and make a change to either look like a change to both.
 *
 * Both ceilings carry the caveat every benchmark here carries (roadmap 3.5, ADR-0018): they are
 * tied to spec NFR-06's reference machine and methodology and are **not** asserted as a hard CI
 * gate for the same reason a slower runner should not fail a check that has nothing to do with a
 * regression. The nightly, same-runner-tracked check is roadmap item 7.1's job.
 */
#[Bench\Iterations(10)]
#[Bench\RetryThreshold(5)]
final class HttpBench
{
    private const ROUTE_COUNT = 50;

    private Router $router;

    /**
     * A 50-route table, one placeholder per route, none of them the one that gets dispatched
     * until the last.
     */
    public function setUpRouter(): void
    {
        $router = new Router();

        for ($i = 1; $i <= self::ROUTE_COUNT; $i++) {
            $router->get("/resource{$i}/{id}", static fn (): string => 'handler');
        }

        $this->router = $router;
    }

    /**
     * NFR-11's router half: ≤ 5 µs, dispatched against the last of 50 routes — the worst case a
     * linear scan over a compiled table produces, and therefore the honest number to budget.
     */
    #[Bench\BeforeMethods('setUpRouter')]
    #[Bench\Revs(1000)]
    #[Bench\Subject]
    public function benchDispatchLastOfFiftyRoutes(): void
    {
        $this->router->match('GET', '/resource' . self::ROUTE_COUNT . '/42');
    }

    /**
     * The same table, dispatched against the first route registered — the best case, kept
     * visible so the last-route figure above is not the only number on record. NFR-11 does not
     * budget this shape.
     */
    #[Bench\BeforeMethods('setUpRouter')]
    #[Bench\Revs(1000)]
    #[Bench\Subject]
    public function benchDispatchFirstOfFiftyRoutes(): void
    {
        $this->router->match('GET', '/resource1/42');
    }

    /**
     * NFR-11's envelope half: ≤ 2 µs for the outcome a handler reaches for most often — a
     * successful read carrying a small payload.
     */
    #[Bench\Revs(1000)]
    #[Bench\Subject]
    public function benchEnvelopeBuild(): void
    {
        ApiEnvelope::ok(['id' => 42, 'name' => 'Ada']);
    }

    /**
     * The variadic-message path, kept visible alongside the single-outcome figure above: an
     * envelope built with several caller-supplied messages does the same allocation plus one
     * `array_values()` over a longer list. NFR-11 does not budget this shape separately.
     */
    #[Bench\Revs(1000)]
    #[Bench\Subject]
    public function benchEnvelopeBuildWithMessages(): void
    {
        ApiEnvelope::invalid(['email is required', 'name must not be blank', 'age must be a number']);
    }
}
