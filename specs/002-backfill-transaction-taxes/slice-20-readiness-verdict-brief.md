# Slice 20 Brief — T076: Final Evidence-Gate / Readiness Verdict

**Status:** Draft — awaiting architect (user) sign-off before implementation.

## Task

T076 ([tasks.md](tasks.md#L234)), original wording:

> Replace T062's duplicate check: verify run-scoped inserted row ids equal the sum of audit-record reconstructed counts, and that the count of `transaction_pk IS NULL` rows in `transaction_taxes` is **zero** after the full run completes (revised 2026-08-11 — FR-015b now deletes the 216's orphans too, so "zero" is the correct post-run assertion, not an exception). The `GROUP BY (transaction_pk, tax_type)` check is demoted to a secondary signal compared against T075's baseline — payloads may legitimately repeat a `tax_type` (Architect F8)

**This brief implements a broader scope than that literal wording, per your explicit direction**: T076 is now the feature's **final evidence-gate slice** — it pulls together everything already built (Slice 16 pre/post-run structural evidence, Slice 19 materiality results, Slice 18 connection-identity evidence, T054 backup/restore readiness) into one read-only comparison and pass/fail readiness verdict for the rehearsal/live-run record. The original T062-replacement duplicate/null-count assertion is still in scope — it's one input among several, not the whole task anymore.

**Explicit scope boundary (your words, carried forward as the governing constraint for this whole slice)**: read-only evidence comparison and readiness verdict only. No backfill writes, no archive/delete, no aggregate refresh, no snapshot capture — this command tells the operator whether the run evidence is acceptable, it does not create new remediation behavior.

## Grounding (verified against current code, not inherited)

**A real tension exists between this scope boundary and Slice 16's own docblock — flagging before building either way.** `PreRunIntegrityCapture`'s docblock (`app/Models/PreRunIntegrityCapture.php:23-25`) says: *"`PHASE_POST_RUN` exists so T076 can record its own post-run capture through this same table without a schema change — this slice never writes it."* That was written assuming T076 would itself **invoke** the capture mechanism for the post-run phase. Your current instruction — "no snapshot capture unless explicitly referenced as inputs" — reads as the opposite: T076 should **read** a post-run capture that already exists, not create one. **Proposed resolution**: treat your current instruction as authoritative (it supersedes that docblock's assumption, the same way FR-012/FR-012a superseded T047's original "from audit records only" wording). The operator runbook gets one more explicit step — `php artisan transactions:capture-integrity-evidence --phase=post_run --apply` run **after** the backfill/reconcile/delete/refresh sequence, **before** invoking T076 — and T076 purely reads whatever that step produced. I'll correct `PreRunIntegrityCapture`'s docblock in Documentation Sync once this is confirmed. **Flagging for explicit sign-off** since it's a real reversal of a previously-stated design intent, not just an implementation detail.

**A genuine structured-data gap exists for the "zero `transaction_pk IS NULL`" check.** `pre_run_integrity_captures.duplicate_check_summary` (JSON) only has `total_duplicate_groups`/`total_duplicate_rows`/`sample` — confirmed by re-reading `CaptureIntegrityEvidence::runDuplicateCheck()`. The null/orphan **count** this check needs only exists inside `integrity_report`, the verbatim `txn:pk-integrity` **text** output (e.g. `transaction_taxes (taxes): total=44 nulls=13 (29.55%) orphans=13 (29.55%)`). Parsing that string to extract a number would violate this feature's own established principle (Slice 16: "never reimplemented, never parsed" — the report is captured verbatim specifically so nothing re-derives structured meaning from it). Two ways to close this gap, **need your decision**:

- **(B) Enrich `CaptureIntegrityEvidence`** with one new structured field (`transaction_taxes_null_count`, read-only `COUNT(*) WHERE transaction_pk IS NULL`, cheap and already indexed) alongside the existing `duplicate_check_summary`. A small, backward-compatible addition to an already-shipped command's output shape — not a new capture mechanism, and T076 itself still does zero live queries, purely reading the enriched capture.
- **(C) T076 runs one minimal, read-only `COUNT(*)` query itself** at verdict time, live, never persisted anywhere. Simpler (no change to Slice 16 at all), but a live query inside a command otherwise designed to have none.

**My recommendation: (B)** — it keeps T076 a genuinely pure reader (matching your scope boundary most literally) at the cost of one small, low-risk addition to Slice 16's already-shipped command. Say if you'd rather (C), or a third option.

**Materiality (Slice 19) is informational in the verdict, not a pass/fail gate.** A tenant crossing the FR-009a threshold is an expected, legitimate outcome of a real correction — not evidence something went wrong. T076 surfaces the materiality run's summary (population/compared/source_mismatch/flagged/total delta) for the record, but does not fail the verdict because tenants were flagged. It **does** treat "no completed materiality run found at all" as a WARN (missing evidence, not a structural failure).

**Connection-identity evidence (Slice 18) is read from `report_refresh_states`, not re-derived.** T076 checks that `server_id`/`database_name` are populated (non-null) on the `report_refresh_states` rows covering the window/tenant scope — proof the connection-identity gate actually ran and passed for this window, per Slice 18. It does not re-query `@@server_id`/`DATABASE()` itself (that live check is `RefreshDailyTransactionSummaries`'s own job, already done).

**Backup/restore readiness (T054) has no DB-queryable signal at all** — `rollback.md`'s drill evidence is a documentation artifact, not a database row. T076 cannot verify this fact computationally. **Proposed**: a `--backup-drill-confirmed` boolean flag the operator must pass explicitly, attesting they've reviewed `rollback.md`'s drill evidence and judge it still valid for this run. Omitting the flag surfaces this as an unresolved WARN item in the verdict — never silently assumed true, never blocking (it's an attestation, not something T076 can prove or disprove).

**Population/window scoping mirrors Slice 16's own convention**: `pre_run_integrity_captures` is whole-table, not window-scoped — `--from`/`--to` on this command are metadata (for the materiality/connection-identity portions, which genuinely are window/tenant-scoped), not filters on the integrity captures. T076 picks the most recent capture of each phase by default (`pre_run_integrity_captures` migration's own docblock: *"T076, later, is responsible for choosing the most recent `pre_run` capture as its comparison baseline"*), with explicit `--pre-run-capture-id=`/`--post-run-capture-id=` overrides for a real live-run record where precision matters more than convenience.

## Design

### New command (Command 7 in cli-contract.md)

```
transactions:tax-backfill-readiness-verdict
    {--from= : Window start (Y-m-d). Required. Metadata for the
               materiality/connection-identity checks, not a filter on the
               whole-table integrity captures.}
    {--to= : Window end, exclusive (Y-m-d). Required.}
    {--tenant= : Optional tenant id, narrows the materiality/connection-
                 identity checks to one tenant.}
    {--snapshot-run= : pre_backfill_snapshot_runs.id the materiality
                       evidence is keyed to. Required to include materiality
                       in the verdict — omit only if materiality genuinely
                       hasn't been run yet (surfaces as a WARN).}
    {--materiality-run= : tax_backfill_materiality_runs.id to read.
                          Defaults to the most recently completed one for
                          --snapshot-run.}
    {--pre-run-capture-id= : pre_run_integrity_captures.id (phase=pre_run).
                             Defaults to the most recent pre_run capture.}
    {--post-run-capture-id= : pre_run_integrity_captures.id (phase=post_run).
                              Defaults to the most recent post_run capture.}
    {--backup-drill-confirmed : Manual attestation that rollback.md's T054
                                drill evidence has been reviewed and is
                                still valid for this run.}
    {--json}
```

**No `--apply` flag, no persistence, no idempotency/locking machinery — this command has nothing to write.** Every invocation recomputes the verdict fresh from whatever evidence already exists; running it twice with the same inputs produces the same output by construction, with zero database writes either time. This is the narrowest, safest command shape in the whole feature, matching its role as a pure evidence rollup.

### Verdict logic

**FAIL (blocking — the run's evidence does not support proceeding as-is):**
- No `pre_run` capture found (explicit ID not found, or no capture exists at all).
- No `post_run` capture found.
- Post-run `transaction_taxes_null_count` (from the enriched capture, decision B above — or a live count, decision C) is not zero.
- Post-run duplicate-check (`total_duplicate_groups`/`total_duplicate_rows`) is non-zero **and** worse than (not merely different from) the pre-run baseline — demoted to a secondary signal per Architect F8, compared against the baseline rather than asserted as an absolute zero, matching T076's own original wording.
- No `report_refresh_states` rows with populated `server_id`/`database_name` exist for the window/tenant scope (connection-identity evidence missing — the Slice 18 gate never ran, or never completed, for this window).

**WARN (non-blocking — needs a human decision before sign-off, not a structural defect):**
- `--backup-drill-confirmed` not passed.
- No completed materiality run found for `--snapshot-run` (or `--snapshot-run` omitted entirely).
- Materiality run found but has `source_mismatch` records (some tenant/months couldn't be compared — informational, already handled correctly by Slice 19, but worth surfacing here too).

**PASS:** none of the above.

Overall status = `fail` if any FAIL condition is true, else `warn` if any WARN condition is true, else `pass`. Exit code: non-zero on `fail`, zero on `warn`/`pass` (a WARN is a decision point for the human authorizing the run, not a command failure — matches this feature's established distinction between "refused" and "flagged for review").

### Output shape

One result object (human table + `--json`, this feature's established one-object convention): overall verdict, then one block per evidence source (`pre_run_capture`, `post_run_capture`, `structural_check` [null-count + duplicate-check comparison], `materiality` [summary only, not full per-tenant rows — those are Command 3's job], `connection_identity` [row count / population coverage for the window], `backup_drill_attestation`), each with its own `status` (`pass`/`fail`/`warn`/`missing`) and a short human-readable reason.

### Not in scope

- T058-T061 (coverage/tenant/tax-type/totals validation) — separate, unbuilt tasks; not folded into this verdict.
- Any remediation action (retry, rollback, re-capture) — this command only reports; the runbook (`rollback.md`, `containment-plan.md`) governs what a human does with a `fail`/`warn` verdict.
- Notification dispatch — already out of scope per FR-009b, unaffected by this slice.

## Tests

- FAIL when no pre_run/post_run capture exists (each independently).
- FAIL when post-run null-count is non-zero (decision B or C, whichever is approved).
- FAIL when connection-identity evidence is missing for the window.
- WARN (not FAIL) when `--backup-drill-confirmed` omitted, verdict still computes and reports the rest.
- WARN when no materiality run found; PASS-relevant fields still populate correctly when one does exist, including a source_mismatch WARN.
- PASS end-to-end: seed pre_run + post_run captures (null-count zero, matching duplicate baseline), a completed materiality run, populated `report_refresh_states`, `--backup-drill-confirmed` passed — overall `pass`, exit 0.
- Explicit `--pre-run-capture-id=`/`--post-run-capture-id=`/`--materiality-run=` overrides are honored over the "most recent" defaults.
- Zero writes to any table, in every scenario (assert via row-count watermarks, matching this feature's established convention).

## Verification plan

Implement (plus the Slice 16 enrichment if decision B is chosen) → targeted tests → full `--filter=Backfill` regression → Pint → Code Reviewer → fix findings → re-verify → Architect drift-revalidation (high-risk: this is the go/no-go signal for a live financial-data run) → Documentation Sync (correct `PreRunIntegrityCapture`'s docblock per the Grounding section above; update `tasks.md`, `cli-contract.md` with Command 7, `live-run-readiness-plan.md`) → commit.
