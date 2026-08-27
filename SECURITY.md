# Security Policy

## Supported versions

`egl-util-php` reached `v1.0.0` on 2026-08-09. Security fixes land on the **latest release
of the current MAJOR line**, and reach you by upgrading to it — the 1.x API freeze
([ADR-0059](docs/adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md))
is what makes that upgrade safe to take. Older releases are not patched in place. The full
window, including what happens when a `2.0.0` line opens, is in
[`docs/workflow/maintenance.md`](docs/workflow/maintenance.md) § *Supported versions*.

| Version | Supported |
|---------|-----------|
| latest `1.x` | ✅ |
| older `1.x` | ❌ — upgrade within 1.x; the freeze guarantees no break |
| `0.x` | ❌ — none was ever published |

See [`docs/upgrading.md`](docs/upgrading.md) for the deprecation lifecycle and this table's
reasoning, in consumer terms.

## Reporting a vulnerability

**Do not open a public issue or PR for a security problem.** Report it privately via
[GitHub private vulnerability reporting](https://docs.github.com/code-security/security-advisories)
on this repository (**Security** tab → *Report a vulnerability*), to `danielPoloWork`.

Please include:

- the affected version(s) and platform/toolchain;
- a minimal reproduction (a failing test is ideal);
- the observed impact and, if known, the root cause.

## What to expect

1. **Acknowledgement** of the report.
2. **Triage & fix under embargo** on a private branch / draft advisory; the SemVer level of
   the fix is assessed by the decision tree in
   [`docs/workflow/maintenance.md`](docs/workflow/maintenance.md).
3. **Coordinated release**: the fix ships, then the advisory is published. The fix is
   recorded in `CHANGELOG.md` under a **Security** entry with the advisory / CVE reference.
4. **Backport** to every still-supported release line.

### Response-time targets

This is a **solo-maintained** project ([`docs/workflow/maintenance.md`](docs/workflow/maintenance.md)
§ *Supported versions*), so these are **targets, not a guarantee** — best-effort commitments sized
to what one person can actually sustain, not a promise a report will never sit longer. Honest beats
impressive.

| Milestone | Target |
|---|---|
| **Acknowledgement** (step 1 above) | within **5 business days** of the report |
| **Triage verdict** — confirmed + severity, or declined, or "still investigating" with a reason | within **15 business days** of acknowledgement |

**If the window passes:** reply on the same private advisory thread first — a bump costs the
reporter nothing and is the fastest way to surface a report that slipped through. If **30 calendar
days** pass from the original report with no acknowledgement at all, treat the report as
unacknowledged: there is no second private channel here, and coordinated disclosure only works when
both sides are actually coordinating. At that point you are released from any implied embargo and
may proceed under your own disclosure timeline — industry-standard practice (e.g. a 90-day
disclose-regardless deadline) is a reasonable default if you have none of your own.

Thank you for reporting responsibly.
