# Slice 8 Architecture Brief — Wire `--apply` into the CLI

**Date**: 2026-08-11 · **Status**: Approved to implement against this brief

## Why this brief exists

Slices 1-7 built and hardened `TaxBackfillRunner::apply()` (day-scoped, idempotent, per-transaction atomic, retry-safe, throttleable, kill-switchable, resumable) without ever exposing it to an operator. This slice wires it into `transactions:backfill-taxes`, crossing the boundary from "reviewed library code" to "something a human can trigger against real data." Per explicit architecture direction: production-facing defaults must be conservative at the command layer even though the runner's own defaults stay permissive (`null`/off) for tests and direct service use.

## Scope contract

```text
Allowed:
- Wire --apply in app/Console/Commands/BackfillTransactionTaxes.php to
  actually call TaxBackfillRunner::apply() instead of rejecting the flag.
- Require --day (not --from/--to) whenever --apply is given; reject
  whole-window --apply outright, before the runner is ever invoked.
- New --throttle= (ms) and --kill-switch-path= CLI options, passed
  explicitly to apply() — never inherit the runner's null default for a
  real --apply invocation.
- Extend output (human + --json) to clearly distinguish completed vs.
  stopped vs. interrupted vs. failed vs. quarantined-only-clean.

Not allowed:
- Any change to dry-run behavior (--from/--to path) — must remain
  byte-for-byte what Slice 4 shipped.
- Orphan archive/reconcile/delete (T066-T072).
- Aggregate refresh, materiality (T039, T046-T051).
- T097's schema/index/FK pre-flight assertions (idx_tx_taxes_pk,
  fk_tx_taxes_pk + its ON DELETE action, transaction_pk nullability) —
  see "Explicit gap" below. Do not attempt to fold T097 into this slice.
```

## Explicit gap this slice does NOT close — read before treating this as production-ready

`contracts/cli-contract.md`'s Command 1 guarantee table requires: *"Pre-flight | MUST validate required columns, `idx_tx_taxes_pk`, `fk_tx_taxes_pk` (+ its `ON DELETE` action) and `transaction_pk` nullability, recording all in the run record; fail non-zero before any mutation."* That's T097 — a distinct, not-yet-built task, deliberately sequenced later in `tasks.md`'s Implementation Order (step 8, after this slice's dry-run/apply plumbing). This slice wires `--apply` structurally sound and safe against the invariants already proven in Slices 1-7 (idempotency, atomicity, retry-safety, day-scoping) — but it does **not** independently verify the target schema's index/FK/nullability state before writing. **A real, live, production `--apply` invocation must not be run until T097 lands or someone has manually confirmed the schema preconditions.** This is a "stop and ask" gate independent of whatever this slice ships — landing this slice makes `--apply` *possible* to invoke correctly against a correct schema, not a green light to invoke it against production today.

## Design decisions this brief makes

1. **`--day` becomes mandatory the moment `--apply` is present.** Extend `validateInput()`: if `--apply` is set, reject if `--from`/`--to` were given instead of `--day` (with a clear message), and reject if `--day` is missing entirely. This structurally forbids whole-window apply — there's no code path where `--apply` reaches the runner without a single resolved day. Dry-run's existing `--day`-or-`--from`/`--to` flexibility is untouched.

2. **New CLI options**: `--throttle=` (integer milliseconds, **default `500`** when `--apply` is used and the flag is omitted — chosen as a conservative middle ground: enough to yield to live traffic between chunks without making a ~87-tenant day's worth of transactions impractically slow to apply, given no established throttle convention exists elsewhere in this codebase to match). `--kill-switch-path=` (string, optional, no default — kill-switch stays opt-in; forcing every run to watch a fixed sentinel path by default risks a stray leftover file silently halting future runs). Both are irrelevant and unused on the dry-run path.

3. **Exit codes, refined for the five possible run statuses**: `0` = `completed` with zero divergent state. Use **distinct non-zero codes** for `failed` / `interrupted` / `stopped` (e.g. `1`/`2`/`3` — implementer's choice of exact mapping, but they must be distinct from each other, not collapsed into one generic "non-zero" as today) so a calling script can tell "the operator meant to stop this" apart from "something broke" without parsing output text. This is in addition to, not instead of, making the human/JSON output text say the status plainly (which `buildResult()`/`render()` mostly already do via `$run->status` — verify and adjust wording, e.g. the current hardcoded "Tax backfill dry run #%d" header must reflect apply vs. dry-run mode).

4. **Everything else generic**: `--tenant`, `--chunk`, `--limit`, `--json` behave identically for `--apply` as they already do for dry-run — no special-casing needed. `buildResult()`/`breakdownByTenant()`/`breakdownByDay()`/`pivotByOutcome()` already operate generically over any `TaxBackfillRun`/`TaxBackfillRecord` set regardless of mode; confirm this holds for apply-mode runs rather than assuming it without checking.

## Test plan

- `--apply` with `--from`/`--to` (no `--day`) is rejected before the runner runs — zero `TaxBackfillRun` created.
- `--apply` with `--day` and a clean fixture actually writes real `transaction_taxes` rows, with correct output (human + `--json`) reflecting mode=apply and status=completed.
- `--apply` with `--day` but no `--throttle` given uses the conservative default (assert the runner was actually invoked with `500`, not `null`).
- `--apply --throttle=<N>` overrides the default explicitly.
- `--apply --kill-switch-path=<path>` wired through correctly; a run stopped this way produces distinctly-coded, distinctly-worded output — not conflated with `failed`.
- Dry-run regression: every existing Slice 4 dry-run test still passes unchanged (prove nothing in this slice altered that path).
- Whole-window `--apply` (both `--from`/`--to` given alongside `--apply`) is rejected with a clear message naming `--day` as the required alternative.

## What's explicitly deferred

- T097 schema/index/FK pre-flight (see "Explicit gap" above).
- Orphan archive/delete, aggregate refresh, materiality — unrelated, already-tracked later work.
- Any actual live/production invocation of `--apply` — requires separate, explicit user authorization regardless of this slice's completion, per this repo's Remote/production action-gate norms.
