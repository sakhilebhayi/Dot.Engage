# Dependency-Patch Approval Gate — Design Spec

**Status:** Approved by user, ready for implementation planning.
**Platform:** Dot.Engage
**Date:** 2026-08-09

## Context

`Dot.Brain/platforms/dot-engage.md`'s Autonomy Classification audit (§Level 1, §Level 2,
2026-08-08) is unusual relative to most platforms audited this program: Dot.Engage already has
real, working Level 1 automation — `dotengage:clean-expired-sessions` (hourly),
`dotengage:retry-failed-uploads` (every 15 minutes, not mentioned by the audit but confirmed real
via `routes/console.php`), and `dotengage:team-activity-report` (monthly) — all verified real via
`routes/console.php` and their command classes in `app/Console/Commands/`.

The audit found **zero** Level 2 processes and named its own concrete suggestion for the first one:
"dependency/CVE patching (`composer audit`/`npm audit` results already exist as raw material)
surfaced as a Context → Evidence → Risk → Recommendation → Proposed Action proposal that
[the operator] approves before it's applied, rather than the current fully-manual pass."

This exact gap was already closed once this program, for Dot.Press
(`app/Models/DependencyPatchProposal.php`, `app/Console/Commands/ScanDependencyAdvisories.php`,
`app/Jobs/ApplyDependencyPatchJob.php`, `app/Http/Controllers/DependencyPatchController.php`) —
verified by reading that implementation directly. This spec reuses that proven shape for the
model, scan command, and apply job nearly verbatim, since the underlying problem (parse real
`composer audit`/`npm audit` JSON, propose a patch command, apply only on explicit approval) is
identical. The review UI differs: Press is a Vue/Inertia application; Dot.Engage's own codebase
(`app/Livewire/Video/*`, `app/Livewire/Chat/*`) is Livewire-only, so this spec builds a Livewire
review component instead of copying Press's Vue page — following *this* repo's own established
frontend convention rather than the sibling repo's.

Verified directly against the real code before proceeding:

- `grep -rln "DependencyPatch\|composer audit\|npm audit" app` — zero matches. No prior attempt at
  this gate exists in Dot.Engage.
- `is_platform_operator` does not exist on the `users` table or in `User::$fillable` yet.
- `composer.json` confirms `livewire/livewire` `^3.6.4` is this repo's only frontend framework
  dependency — no Vue/Inertia.

## Goal

Give Dot.Engage its first real Level 2 process: a weekly scan of `composer audit`/`npm audit`
output that proposes a patch command per package manager, and an operator-gated Livewire review
screen where an explicit Approve queues the real patch command for execution, or Reject records
why — never auto-applying a dependency update without a human decision first.

## 1. `is_platform_operator` flag

Same shape as every prior platform's operator flag this program (ChartSense, Ehail, Emall, Files,
Forms, Press, Sheet, Tutor): a `boolean` column on `users`, default `false`, **excluded from
`$fillable`** so no request payload can ever set it regardless of field name, cast to `boolean` in
`casts()`.

## 2. `DependencyPatchProposal` model

Identical fields to Dot.Press's implementation:

| Field | Type | Notes |
|---|---|---|
| `manager` | string | `composer` \| `npm` |
| `advisories` | array (cast) | `[{package, severity, title, identifier}, ...]` |
| `risk_summary` | string | e.g. "3 advisories: 2 high, 1 moderate" |
| `proposed_command` | string | e.g. `composer update --with-dependencies` |
| `status` | string | `pending_approval` \| `approved` \| `rejected` \| `applied` \| `failed` |
| `rejected_reason` | text, nullable | required when rejecting |
| `reviewed_by` | FK users, nullable | |
| `reviewed_at` | datetime, nullable | |
| `applied_log` | text, nullable | captured process output |
| `applied_at` | datetime, nullable | |

## 3. `dependency:scan` command

Reuses Dot.Press's `ScanDependencyAdvisories` logic verbatim: shells out to `composer audit
--format=json` and `npm audit --json` via `Symfony\Component\Process\Process`, parses each
manager's real JSON output into a normalized advisory array, computes a severity-count
`risk_summary`, and creates one `DependencyPatchProposal` per manager — skipping a manager
entirely if it already has an `pending_approval` proposal open (no duplicate spam) or if the audit
run itself fails (logged, not fatal to the other manager's scan). Scheduled `->weekly()`, matching
Press's own cadence — dependency advisories don't need daily polling.

**Deviation from Press:** the schedule adds `->withoutOverlapping()`, which Press's own line lacks.
This session's own newly-built scan commands (Billing, Central, Design, Sheet) have consistently
paired a scheduled scan with `->withoutOverlapping()` as cheap, no-downside safety — a slow
`composer audit`/`npm audit` run that's still in flight when the next weekly tick fires shouldn't
be allowed to start a second overlapping process. It doesn't change any behavior in the normal
case. This is a deliberate, minor improvement over the Press original, not a functional change to
the reused logic itself.

## 4. `ApplyDependencyPatchJob`

Reuses Dot.Press's job verbatim: a queued job that runs `proposal->proposed_command` for real via
`Process::fromShellCommandline()`, and — regardless of success or a thrown exception — always
resolves the proposal to a terminal `applied` or `failed` status with the captured output in
`applied_log`. Never left stuck in `approved` with no recorded outcome.

## 5. Review gate

`EnsurePlatformOperator` middleware (identical shape to every prior platform), aliased `operator`
in `bootstrap/app.php`'s `withMiddleware()` closure (currently empty).

A new Livewire component (not a Vue page, per Context above) lists `pending_approval` proposals
plus a short recent-history tail, gated by the `operator` middleware on its route. Two actions,
matching Press's controller exactly in behavior:

- **Approve** — only from `pending_approval`; sets `status = approved`, `reviewed_by`,
  `reviewed_at`; dispatches `ApplyDependencyPatchJob`.
- **Reject** — only from `pending_approval`; requires a non-empty reason; sets `status =
  rejected`, `rejected_reason`, `reviewed_by`, `reviewed_at`.

Both actions guard against acting on a non-`pending_approval` proposal (a second admin already
acted, or it's already been applied) — matching Press's own `if ($proposal->status !==
'pending_approval')` guard.

## Out of Scope

- Any change to Dot.Engage's existing three Level 1 commands — untouched.
- Auto-applying a patch without approval, under any condition — the entire point of this spec is
  that a human decides every time.
- A generalized "review any risky action" framework — this gate is specific to dependency patches,
  matching the audit's own narrow, concrete suggestion.

## Testing Notes

- `dependency:scan`: real advisory JSON (fixture-injected via the same `runAudit()` override
  pattern Press's command already uses, so tests never actually shell out) produces one proposal
  per manager with a correct `risk_summary`; an empty/clean audit result creates nothing; a
  manager that already has a pending proposal is skipped, no duplicate; one manager's audit
  failing doesn't block the other manager's scan.
- `ApplyDependencyPatchJob`: a successful command resolves to `applied` with output captured; a
  failing command (non-zero exit) resolves to `failed` with output *and* the exception message
  captured, never left at `approved`.
- Review gate: a non-operator gets 403 on the review route; approve on a `pending_approval`
  proposal transitions it and dispatches the job; approve on an already-`approved`/`applied`
  proposal is rejected with a status guard error, no double-dispatch; reject without a reason is
  rejected by validation; reject with a reason transitions to `rejected` and records who/when/why.
