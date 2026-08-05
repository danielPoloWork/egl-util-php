<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Bench\Fixture\AutowiredGraph;
use D4np\Utils\Bench\Fixture\GraphLeaf;
use D4np\Utils\Container\Container;
use PhpBench\Attributes as Bench;

/**
 * NFR-02: a singleton resolve in ≤ 2 µs warm, and a first autowired resolve in ≤ 30 µs.
 *
 * **The two halves measure different things, and keeping them apart took reading phpbench's
 * runner.** Its generated script calls `beforeMethods` **once per iteration** and then loops the
 * subject over every revolution — verified in `Executor/Benchmark/template/remote.template`. So a
 * cold subject set up in a `BeforeMethods` hook and run at 1000 revs would measure **one** cold
 * resolve and 999 warm ones, and report a "cold" number roughly an order of magnitude better than
 * the truth. The cold subject therefore builds its own container **inside** the subject, where per
 * revolution means per revolution.
 *
 * What that includes, stated plainly: `new Container()` allocates two objects, which is charged to
 * the cold measurement. Against a ~30 µs budget it is noise, and the alternative — trusting a hook
 * that does not run when it needs to — is not a trade.
 *
 * What "cold" honestly means here is **a cold metadata cache**, not a cold PHP. The classes are
 * loaded and linked after the first revolution no matter what this file does, and that is the right
 * subject anyway: `ReflectionCache` pays reflection once per process, so NFR-02's "first" is about
 * the cache, and the *warm* half is what a running application pays on every request thereafter.
 * `ContainerTest::testAClassIsReflectedOnlyOnce()` proves the once-per-process half deterministically,
 * which no benchmark can.
 *
 * Both ceilings carry the caveat every benchmark here carries (roadmap 3.5, ADR-0018): they are
 * tied to spec NFR-06's reference machine and are **not** asserted as a hard CI gate, since a
 * slower runner would fail for reasons unrelated to a regression. The nightly baseline-tracked
 * check is roadmap item 7.1.
 */
#[Bench\Iterations(10)]
#[Bench\RetryThreshold(5)]
final class ContainerBench
{
    private Container $warm;

    /**
     * A container with the graph already resolved, so the subjects below measure the lookup alone.
     */
    public function setUpWarm(): void
    {
        $this->warm = new Container();
        $this->warm->instance('config.dsn', 'sqlite::memory:');
        $this->warm->get(AutowiredGraph::class);
    }

    /**
     * NFR-02's warm half: ≤ 2 µs. An autowired graph, resolved again.
     */
    #[Bench\BeforeMethods('setUpWarm')]
    #[Bench\Revs(1000)]
    #[Bench\Subject]
    public function benchWarmSingletonResolve(): void
    {
        $this->warm->get(AutowiredGraph::class);
    }

    /**
     * The cheapest path the container has — a plain registered value — which is the floor the warm
     * budget is measured against.
     */
    #[Bench\BeforeMethods('setUpWarm')]
    #[Bench\Revs(1000)]
    #[Bench\Subject]
    public function benchWarmInstanceResolve(): void
    {
        $this->warm->get('config.dsn');
    }

    /**
     * Load and link the graph's classes, without warming any metadata cache the subject will use.
     *
     * **This hook is the fix for a measurement error, and the error is worth recording.** Without
     * it the subject's first revolution paid Composer's autoloading — four `stat`s and four
     * `include`s — and phpbench divides the total by the revolution count, so that one-time cost
     * was smeared across every revolution. It reported **93 µs** at 200 revs and **26 µs** at 2000,
     * for identical work: a number that moves with the revolution count is measuring the harness,
     * not the subject. Directly timed, the same resolve is **~17.8 µs**.
     *
     * With the classes loaded here, each revolution below still constructs a fresh `Container` with
     * a fresh, empty `ReflectionCache` — which is exactly what NFR-02's "first autowired resolve"
     * means, since class loading happens once per process no matter what any container does.
     */
    public function warmTheClassLoader(): void
    {
        (new Container())->get(AutowiredGraph::class);
    }

    /**
     * NFR-02's cold half: ≤ 30 µs for the first autowired resolve of a four-class graph.
     *
     * The container is constructed inside the subject, not in a hook, because phpbench runs hooks
     * once per *iteration* — see the class docblock.
     */
    #[Bench\BeforeMethods('warmTheClassLoader')]
    #[Bench\Revs(1000)]
    #[Bench\Subject]
    public function benchFirstAutowiredResolve(): void
    {
        (new Container())->get(AutowiredGraph::class);
    }

    /**
     * The same, for a single dependency-free class — the per-class floor, so the graph number above
     * can be read as "four classes' worth" rather than as an unexplained total.
     */
    #[Bench\BeforeMethods('warmTheClassLoader')]
    #[Bench\Revs(1000)]
    #[Bench\Subject]
    public function benchFirstAutowiredResolveOfASingleClass(): void
    {
        (new Container())->get(GraphLeaf::class);
    }
}
