# 2026-08-26 — The analyser that found nothing, until it was told where to look

Issue **#103**, both criteria. Route `standard / medium`; session model Opus 5 — matched.
**ADR-0073** added.

The easy version of this item is: install Psalm in a throwaway project, run `--taint-analysis`,
commit whatever baseline falls out, done. That version would have shipped a job that is green
because it cannot see the thing it was added to watch — and I only know that because I tried to
break it before believing it.

## What the first honest run found

Two findings, both `Errors/ExceptionHandler.php:165` — `echo Json::encode($document)` — and both
**true flows Psalm traced correctly**. A throwable's message and trace really do reach output on
that line. Neither is a vulnerability, for three reasons the analyser structurally cannot see:

- the response is `Content-Type: application/problem+json`, set four lines above the sink, so the
  `TaintedHtml` sink is not the sink Psalm thinks it is;
- `json_encode()` escapes the quote, which is exactly what `TaintedTextWithQuotes` asks about;
- and above both, **the control is a branch.** `problem()` returns early with a fixed `detail`
  string unless `$debug` is true — the code's own comment on that line reads *"The whole security
  property, in one branch."* `$debug` defaults to false, `fromEnvironment()` defaults it to false
  when `APP_DEBUG` is absent, and ADR-0029 decided both.

Taint analysis does not model a boolean branch as a sanitiser, and it is right not to. So these two
are baselined **with their reasoning written down**, including the conditions that would invalidate
it — a Content-Type change, the early return disappearing, `debug` becoming settable from request
data. A baseline entry whose justification lives only in a commit message is one nobody re-reads.

## The part that actually mattered

I planted a vulnerability to check the job could fail. First attempt: `echo $throwable->getMessage()`.
Clean run. That turned out to be a bad plant — `getMessage()` is not a taint *source* in Psalm's
stubs — so I planted a real one instead:

```php
$name = $_GET['name'];
return $this->select(SqlStatement::composed('SELECT * FROM users WHERE name = ' . $name));
```

**Clean run.** A textbook SQL injection, through this library's own documented escape hatch, and
the job said nothing.

The sink itself was fine — `$this->pdo->prepare($_GET['name'])` written directly *was* caught,
`TaintedSql`, immediately. What defeated it was **laundering through `SqlStatement`**: the string
goes into a private constructor, becomes a property, and comes back out at
`prepare($statement->sql)` with Psalm's connection broken. The library's own value object is what
hid the flow.

`composed()` is the one door where `literal-string` is given up on purpose (ADR-0041). Its docblock
asks the caller to assert that nothing from outside the program is in that text — a promise a human
makes by choosing that method over `literal()`. One tag, `@psalm-taint-sink sql $sql`, turns that
assertion into something a machine refuses. With it, the same plant is caught. PHPStan ignores the
tag entirely, so it costs nothing at max level.

That is the whole difference between this job and a green tick, and it took planting a
vulnerability to find it.

## Four properties, each proved by experiment rather than assumed

1. Clean run against the baseline → exit `0`.
2. A new flow → reported, exit non-zero. (After the annotation. Before it: exit `0`, silently.)
3. A direct sink → caught out of the box, which is how I knew the sink worked and the *path* did not.
4. A stale baseline entry → `UnusedBaselineEntry`, red. `findUnusedBaselineEntry="true"` is on
   because a suppression that has outlived its finding is how a baseline stops describing the code.

## Two judgement calls worth flagging

**Psalm pinned to 5.26, not 6.x.** Psalm 6 needs PHP ≥ 8.3.16; the maintainer's machine runs 8.3.1.
Pinning 6 would mean a baseline generated in CI and never regenerated where somebody can read the
code beside it. A baseline nobody can reproduce locally rots into a list of suppressions nobody
re-reads, so 5.26 — which runs in both places — is what the committed baseline was generated with.

**"Advisory at first" vs "new flows fail loudly."** The issue asks for both in one sentence. I read
it as: advisory means it blocks no merge (it is scheduled, nothing waits on it), while a flow
outside the baseline still turns the job red. The other reading — `continue-on-error: true` —
produces a scheduled job that reports findings and never fails, which is the vacuous-green shape
this repository has now had to go back and fix six times. Flagged in the PR rather than assumed.

## Where this leaves the project

No production behaviour changed — the only edit under `src/main` is a docblock tag PHPStan does not
read. 3 191 tests, PHPStan max, deptrac all unmoved. One nightly job, one config, one baseline, one
triage document.

Open and worth stating: **taint tracking is not a proof of absence.** The `composed()` experiment is
direct evidence that a real flow can pass through this library's own abstractions unseen. A green
run means "no flow I can follow." There may be other value objects standing between a source and a
sink that nobody has thought to annotate yet — and the only way to find them is the way this one was
found, by planting something and watching whether anyone notices.
