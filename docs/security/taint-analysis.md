# Taint analysis

Source-to-sink dataflow analysis over the production tree, run nightly by
[`nightly.yml`](../../.github/workflows/nightly.yml)'s `taint` job. Decided in
[ADR-0073](../adr/0073-a-nightly-taint-job-and-the-one-annotation-that-made-it-see-anything.md);
issue [#103](https://github.com/danielPoloWork/egl-util-php/issues/103).

## Why a second analyser at all

PHPStan runs at max level on every PR and carries real security weight here — `SqlStatement::literal()`
takes a `literal-string`, which is a *proof* that no runtime value sits in that SQL text. What
PHPStan does not do is **taint tracking**. It can say "this string is a literal"; it cannot say
"this string came from `$_GET` and reached `PDO::prepare()` eleven calls later." Those are different
questions, and only the second one is about a flow.

This is deliberately **not** a second general-purpose type checker. The config is named
`psalm-taint.xml` rather than Psalm's default `psalm.xml`, `errorLevel` is set to Psalm's most
permissive value, and every invocation passes `--config` explicitly — all so that
`vendor/bin/psalm` never quietly becomes a second opinion on ordinary types that PHPStan already
gates.

## Running it locally

Psalm is installed **outside** this package's dependency graph — its own PHP floor is above this
library's 8.1 — the same arrangement ADR-0031 uses for the BC checker and ADR-0040 for Infection:

```bash
composer require --working-dir=/tmp/taint-tool --no-interaction "vimeo/psalm:^5.26"
php /tmp/taint-tool/vendor/bin/psalm --config=psalm-taint.xml --taint-analysis --no-cache --no-progress
```

Exit `0` means no flow outside the baseline. Any other code means the run found something, and the
output names the full source → sink chain.

## The baseline, and what is in it

[`psalm-taint-baseline.xml`](../../psalm-taint-baseline.xml) holds the findings that existed when
the job landed, so a **new** flow fails loudly instead of joining a wall of known noise. It is
generated, not hand-written:

```bash
php /tmp/taint-tool/vendor/bin/psalm --config=psalm-taint.xml --taint-analysis \
  --no-cache --no-progress --set-baseline=psalm-taint-baseline.xml
```

**Regenerating it is not how you deal with a finding.** `--set-baseline` accepts whatever it is
shown; a flow silenced that way is a flow nobody read. Add an entry only after writing its triage
into the table below, in the same PR.

`findUnusedBaselineEntry="true"` is set, so an entry that no longer matches anything **fails the
job** rather than lingering. A suppression that has outlived its finding is how a baseline stops
describing the code.

### Triage

Two entries, both the same sink line, both **true flows that Psalm traced correctly** — neither is
a mis-detection, and neither is a vulnerability.

| # | Issue | Site | Flow | Why it is baselined |
|---|-------|------|------|---------------------|
| 1 | `TaintedHtml` | `Errors/ExceptionHandler.php:165` — `echo Json::encode($document)` | `Throwable::getMessage()` / `getTraceAsString()` → `problem()`'s `detail`/`trace` keys → `Json::encode()` → `echo` | The response carries `Content-Type: application/problem+json` (set four lines above the sink), not HTML. `TaintedHtml` is a claim about an HTML sink; this one is not one. |
| 2 | `TaintedTextWithQuotes` | same line | same | `json_encode()` escapes `"` — it is the escaper for this sink, and Psalm has no way to know that `Json::encode()` wraps it. |

**The control both entries depend on, which Psalm cannot see, is a branch.**
`ExceptionHandler::problem()` reads:

```php
if (!$this->debug) {
    // The whole security property, in one branch.
    $document['detail'] = 'The request could not be completed. Quote the reference when reporting this.';

    return $document;
}
```

The throwable's message and trace enter the document **only** when `$debug` is true. `$debug`
defaults to `false`, `fromEnvironment()` defaults it to `false` when `APP_DEBUG` is absent, and
ADR-0029 is the decision behind both. Taint analysis does not model a boolean branch as a
sanitizer, so it reports the debug arm as though it were always live — which is the correct,
conservative thing for it to do, and the reason these two entries are read and kept rather than
argued away.

**What would invalidate this triage** — check these before assuming an entry still applies:

- the `Content-Type` header on that response changing to anything a browser renders;
- the `!$this->debug` early return disappearing or gaining an exception;
- a caller being given a way to set `debug` from request data rather than from the environment.

## The annotation that made this worth running

`SqlStatement::composed()` carries `@psalm-taint-sink sql $sql`. Without it, a tainted string
laundered through that value object reached `PDO::prepare()` **undetected** — verified by planting
exactly that flow and watching a clean run come back green (ADR-0073 records the experiment). With
it, the same plant is caught.

That method is the one documented door where `literal-string` is given up on purpose (ADR-0041):
its docblock asks the caller to assert that nothing from outside the program is in the text. The
annotation is what turns that human assertion into something a machine refuses.

## Known limits

- **Taint tracking is not a proof of absence.** Psalm follows the flows its stubs know about;
  a source it has no stub for is a source it does not see. A green run means "no flow I can
  follow," not "no flow."
- **The test and benchmark trees are excluded** on purpose. They feed hostile strings into the
  library deliberately — that is what the T-02/T-13 injection corpora *are* — and analysing them
  would report the suites doing their job, burying the one class of finding this exists for.
- **The nightly report artifact lists baselined findings too.** It is written before the baseline
  is applied, so it records the full picture rather than only the delta; the job's exit code, not
  the artifact's length, is the verdict.
