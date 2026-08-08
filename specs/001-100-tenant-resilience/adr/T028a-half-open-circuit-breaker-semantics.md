# ADR-T028a: Half-Open Circuit Breaker Semantics

## Status

**Accepted — decision finalized.** `tasks.md` T028a is marked complete and cites this document as the approved decision. This is no longer a menu of alternatives: **Alternative 2 (bounded concurrent probes)** is the chosen design, with the specific parameters and semantics below. Alternatives 1 and 3 are retained further down as "Considered and Rejected" for historical record only — they are not live options.

## Context

*(Historical — describes the pre-decision behavior that motivated this ADR, not the current implementation. See "Final implemented atomicity" and "Consequences (as implemented)" below for what actually shipped.)*

Before this decision, `App\Services\CircuitBreaker` (the real, Redis-backed breaker wired into ingestion via `CircuitBreakerMiddleware` and the official/batch ingestion routes) implemented half-open as follows:

- `isAvailable()`: when `state === 'open'` and `resetTimeoutSeconds` had elapsed since `opened_at`, it called `transitionToHalfOpen()` and returned `true` for *this* request. On every *subsequent* call while `state === 'half-open'`, `isAvailable()` fell through to a final unconditional `return true` — i.e. **every concurrent request was treated identically to closed-state traffic**. There was no probe counter, no single-request gate, and no distinction between "the probe" and ordinary traffic.
- `recordSuccess()`: if `state !== 'closed'`, a single success called `reset()`, which set `state = 'closed'` and `failure_count = 0` atomically. One success closed the breaker regardless of how many other requests were concurrently in flight or what they would report.
- `transitionToHalfOpen()`: set `state = 'half-open'` but did **not** reset `failure_count`. Because half-open let unlimited concurrent traffic through, and `recordFailure()` reopened the breaker once `failure_count >= failureThreshold`, a single failure among many concurrently-admitted half-open requests could immediately reopen the breaker — even if most of the concurrent requests would have succeeded, and even though those requests weren't gated as "probes" at all.

This creates two concrete production risks under 100-tenant load:

1. **Thundering herd on recovery**: the moment the reset timeout elapses, *all* queued/retrying traffic across every app instance simultaneously sees `state === 'half-open'` and is let through unthrottled, potentially re-saturating a dependency that has only just started recovering.
2. **Premature reopen**: because `failure_count` isn't reset on entering half-open and every concurrent request is admitted, one unlucky failure (e.g. one slow request timing out) among many successes can flip the breaker back to `open`, even though the dependency is actually healthy.

Neither `CircuitBreakerMiddleware.php` nor any ingestion code path today assumes anything more sophisticated than "isAvailable() is a boolean gate" — but the interface *does* need to widen slightly (see "Interface Changes" below) to carry the admitted probe's generation from admission through to outcome recording.

## Decision

Adopt **bounded concurrent probes, scoped per circuit key** (i.e. per `serviceKey` / per Redis hash `tsms:circuit-breaker:{serviceKey}` — not global across all breakers, not per-tenant within a breaker), with these fixed parameters:

- **N = 3** maximum concurrent probes admitted per half-open generation.
- **Close on 2 successes** (of the up to 3 probes in the generation).
- **Reopen on 2 failures** (of the up to 3 probes in the generation).

Because the decision resolves as soon as either threshold is hit, the third probe (whichever is still outstanding when the 2nd success or 2nd failure lands) may still be in flight when the state transitions — its eventual outcome is handled by the generation-safety rule below, not by waiting for it.

### Generation field and lifecycle

A new Redis hash field, **`half_open_generation`**, is a monotonically incremented integer counter:

- It starts at `0` (or unset, treated as `0`) in `closed`/`open` state.
- It is incremented by exactly 1 every time `transitionToHalfOpen()` runs — including when a stale/expired half-open cycle is superseded (see "Expired half-open cycles" below), so each half-open *episode* has its own unique generation number, distinct from the previous one even if the breaker cycles open → half-open → open → half-open repeatedly.

### Admission carries generation forward

`isAvailable()` is responsible for admission. When it admits a request as a probe (state is `half-open` and the current probe count is below N), it reads (or, on transition, writes) `half_open_generation` and makes that value available to the caller so it can be threaded through to the eventual `recordSuccess()`/`recordFailure()` call. The probe's generation is fixed at admission time — it does not get "corrected" mid-flight even if the breaker transitions again before the probe resolves; that mismatch is precisely what the discard rule below detects and handles.

### Stale/mismatched outcomes are discarded

`recordSuccess()`/`recordFailure()`, when called with a non-null generation, must first re-read the breaker's current `state` and `half_open_generation` from Redis. If either has changed since the probe was admitted (the circuit already closed, already reopened, or already moved on to a newer half-open generation), the outcome is **ignored**: no counter mutation (`half_open_successes`/`half_open_failures`/`failure_count`), no state transition, no side effect beyond an optional debug-level log line. This is what makes late results safe:

- A **late failure** arriving after the circuit has already closed (2 successes already recorded, state back to `closed`) must not reopen the breaker.
- A **late success** arriving after the circuit has already reopened (2 failures already recorded, state back to `open`) must not close the breaker.
- A **late outcome** from a superseded generation (the half-open window expired and a new generation started before this probe resolved) must not be applied to the new generation's counters.

The check-then-mutate sequence must happen inside a single atomic Redis operation (Lua script or `MULTI`/`EXEC` transaction, consistent with the existing `recordFailure()`/`reset()` pattern) so two workers cannot race between the read and the write. **As implemented**, this is `CircuitBreaker::HALF_OPEN_OUTCOME_SCRIPT` — see "Final implemented atomicity" below.

### Abandoned probes record no outcome

If a probe's client disconnects, the request never completes, or it times out before either `recordSuccess()` or `recordFailure()` is called, **no outcome is recorded at all** — there is no separate "abandoned" bookkeeping call. The probe simply leaves no trace beyond having occupied one of the N admission slots for that generation. This is intentional: it keeps the interface minimal (no new "abandon" method to wire through the middleware/exception paths) and is safe because of how expiry is handled next.

### Expired half-open cycles start a new generation

If a half-open episode does not resolve (does not reach 2 successes or 2 failures) within `resetTimeoutSeconds` of `half_open_started_at` — for example because one or more probes were abandoned and never reported, or because fewer than N probes were ever admitted — the episode is treated as **expired**, not reset in place. The next caller to observe the stale window (via `isAvailable()` noticing `now - half_open_started_at >= resetTimeoutSeconds` while still in `half-open`) drives the breaker back through `open` (bumping `opened_at`) and lets the existing open → half-open transition path re-enter half-open once the timeout elapses again, which bumps `half_open_generation` and resets `half_open_probe_count`/`half_open_successes`/`half_open_failures` to `0` for the fresh generation. Reusing the same generation's counters in place is explicitly rejected — it would let a probe admitted under the stale window still count toward the new window's decision.

### Final implemented atomicity: three Lua scripts, not two

A first implementation pass made admission (`isAvailable()`'s half-open branch) and outcome recording (`recordSuccess()`/`recordFailure()`) each atomic via their own Lua script, but left the **open → half-open transition itself** (`transitionToHalfOpen()`) as a separate, unguarded sequence: read state, then `HINCRBY half_open_generation`, then a separate write of the reset counters and `state = 'half-open'`. A subsequent review round found this was a real, un-fixed race of the exact same shape this ADR exists to eliminate: two concurrent callers could both observe `state === 'open'` and expired, both bump `half_open_generation` (getting two different values), and whichever caller's counter-reset write executed second would silently clobber the first caller's already-admitted probe — the first caller's real, legitimately-admitted probe would then have its eventual outcome discarded as stale-generation, and the breaker would end up admitting more real concurrent traffic than N=3 during that handoff window.

This was closed by adding a **third** atomic Redis Lua script, `CircuitBreaker::HALF_OPEN_TRANSITION_SCRIPT`, so the final implementation has three atomic operations, not two:

- **`HALF_OPEN_TRANSITION_SCRIPT`** (`transitionToHalfOpen()`): guards on `state == 'open'` and the reset timeout having elapsed, and — only when that guard passes — atomically increments `half_open_generation`, resets `failure_count`/`half_open_probe_count`/`half_open_successes`/`half_open_failures`, sets `half_open_started_at`, admits the *initiating* caller as probe #1 of the new generation, and writes `state = 'half-open'`, all in one Redis-side execution. A concurrent caller whose script execution runs after the winner's simply observes `state` is no longer `open` and performs **zero mutation** — it does not re-initialize, does not bump the generation again, and does not touch the winner's counters. That losing caller then falls through to `HALF_OPEN_ADMISSION_SCRIPT` and is admitted as an ordinary probe against the generation the winner just established, exactly like any other half-open request — it is never silently dropped, and it never gets to clobber what the winner wrote.
- **`HALF_OPEN_ADMISSION_SCRIPT`** (called from `isAvailable()`'s half-open branch, including by a losing transition-caller per the paragraph above): atomically increments `half_open_probe_count`, self-correcting back down (with zero other mutation) if the result exceeds N=3, and returns the generation the probe slot was actually consumed against — never a stale pre-read snapshot.
- **`HALF_OPEN_OUTCOME_SCRIPT`** (called from `recordSuccess()`/`recordFailure()` when a non-null generation is passed): the check-then-mutate operation described above — re-verifies `state`/`half_open_generation` match what the probe was admitted under, discards with zero mutation on mismatch, otherwise increments the outcome counter and performs the close/reopen transition atomically when the 2-of-3 threshold is met.

Because each of these three operations is a single, whole-script Redis execution, and Redis serializes script execution against a given key end-to-end, there is no interleaving window between any two of them — the only race that existed was the gap this third script closes.

### `failure_count` reset on entry to half-open

`transitionToHalfOpen()` must reset `failure_count` to `0`. This fixed a pre-decision bug (see "Context" above): `transitionToHalfOpen()` did not reset `failure_count`, so a leftover count from the prior `open` episode could contribute to a later closed-state reopen decision that has nothing to do with the new half-open episode. Half-open's own close/reopen decision is governed entirely by `half_open_successes`/`half_open_failures`, not by `failure_count` — `failure_count` only matters again once the breaker is back in `closed` state accumulating ordinary failures.

### Redis state (single hash, no new keys)

The existing hash `tsms:circuit-breaker:{serviceKey}` gains these fields (building on what `data-model.md` and earlier drafts of this ADR already anticipated — no duplication, only what was missing is added):

| Field | Type | Meaning |
|---|---|---|
| `state` | string | `closed` \| `open` \| `half-open` (existing). |
| `failure_count` | int | Closed-state consecutive-failure counter (existing); reset to `0` on entry to half-open (new rule, see above). |
| `opened_at` | int (timestamp) | Existing. |
| `half_open_generation` | int | **New.** Monotonically incremented each time `transitionToHalfOpen()` runs. |
| `half_open_started_at` | int (timestamp) | **New.** Set when `transitionToHalfOpen()` runs for the current generation; used to detect episode expiry against `resetTimeoutSeconds`. |
| `half_open_probe_count` | int | Number of probes **admitted** so far in the current generation (monotonic within the generation, capped by admission logic at N=3 — it is not decremented when a probe completes or is abandoned; it exists purely to gate the Nth+1 admission attempt). |
| `half_open_successes` | int | **New.** Count of successful outcomes recorded against the current generation; triggers close at 2. |
| `half_open_failures` | int | **New.** Count of failed outcomes recorded against the current generation; triggers reopen at 2. |
| `last_success_at` / `last_failure_at` | int (timestamp) | Existing. |

No new Redis keys are introduced. The existing whole-hash `expire()`-on-every-write behavior (`stateTtlSeconds`) is unchanged — it remains a coarse safety net against an orphaned key, not the mechanism that bounds a half-open episode's lifetime. Generation comparison, not hash TTL, is what actually bounds how long an abandoned or superseded probe can matter.

### Interface changes

- `recordSuccess(?int $generation = null): void` and `recordFailure(?int $generation = null): void` — both gain an optional generation parameter. `null` (the default) preserves today's behavior exactly for closed-state call sites that have no generation to pass (no generation check is performed when `null`). When a caller passes a non-null generation (i.e. it came from an admitted half-open probe), the stale/mismatch discard rule above applies.
- `CircuitBreaker::currentHalfOpenGeneration(): ?int` — a new accessor exposing the generation an admission was made against, so callers (the middleware) can retrieve it immediately after a truthy `isAvailable()` result.
- `CircuitBreakerMiddleware::handle()` (`app/Http/Middleware/CircuitBreakerMiddleware.php`) propagates the admitted generation as a new request attribute, `circuit_breaker.half_open_generation`, analogous to how it already propagates `circuit_breaker.downstream_attempted` — so that after the protected handler runs, it can pass the stored generation into `recordSuccess()`/`recordFailure()`. `isAvailable()`/`currentHalfOpenGeneration()` is where the middleware reads the generation at admission time, before calling `$next($request)`.

### Retry-After status: T028b is complete

At the time this ADR was first written, `CircuitBreakerMiddleware::handle()`'s `503 Service unavailable` JSON response carried no `Retry-After` header, and closing that gap was deliberately deferred as a separate follow-up task, **T028b**, out of scope for T028a/T028 (unrelated to half-open probe correctness). **T028b has since been implemented and is marked complete in `tasks.md`.** Both `OPEN`-state rejections and over-cap `HALF_OPEN` rejections (admission denied because N=3 concurrent probes are already in flight) now include a `Retry-After` header, computed once via `CircuitBreaker::retryAfterSeconds()` and applied identically to both the JSON body's `retry_after_seconds` field and the `Retry-After` HTTP header — the same single-computation pattern already established for the backpressure service (T035). See `CircuitBreakerMiddleware.php` and `CircuitBreaker::retryAfterSeconds()`/`computeRetryAfterSeconds()` for the implementation.

## Alternatives Considered and Rejected

### Alternative 1: Single global half-open probe

Only one in-flight request is treated as "the probe" while `state === 'half-open'`; all other concurrent requests during that window are rejected/short-circuited (treated as if the breaker were still open) until the probe resolves.

- **Concurrency behavior**: Exactly one request is admitted at a time during half-open. Requires an atomic claim (e.g. Redis `SETNX`/`SET ... NX` on a `probe_in_flight` key, or a Lua script that reads state and atomically claims the probe) so two workers can't both believe they hold the probe.
- **Recovery behavior**: Slowest to fully recover (one success at a time, serialized), but safest — the dependency never sees more than one extra request beyond its current (already-open, zero) load during evaluation.
- **Why rejected**: Under 100 tenants, serializing recovery through a single probe unnecessarily delays every other tenant's traffic behind one request's outcome, and gives no majority-vote resilience against one atypical (e.g. unusually slow) probe skewing the decision.

### Alternative 3: Time-window-based probe allowance

Allow requests through for a fixed window (e.g. 5-10 seconds) after entering half-open, then evaluate the window's aggregate success/failure ratio to decide the next transition, rather than gating by request count.

- **Concurrency behavior**: Unbounded concurrency within the window — closer to today's actual behavior, but the *decision* is deferred to the end of the window instead of reacting to the first success or first threshold-crossing failure.
- **Recovery behavior**: Most forgiving of transient blips, but reintroduces a bounded version of the thundering-herd risk since all traffic is admitted during the window, just as today.
- **Why rejected**: Does not bound concurrency during the window at all, reproducing the thundering-herd risk this ADR exists to fix; also requires a time-based evaluation step (lazy check or scheduled sweep) with more implementation surface than a counter-based approach for no correctness benefit at 100-tenant scale.

## Comparison Summary (retained for historical record)

| Dimension | Alt 1: Single probe | **Alt 2: Bounded concurrent probes (chosen)** | Alt 3: Time-window |
|---|---|---|---|
| Protects recovering dependency from thundering herd | Strongest | Moderate (bounded by N=3) | Weakest |
| Speed of full recovery signal | Slowest | Moderate | Fast (but noisy) |
| Redis complexity | Low | Moderate | Moderate-High |
| Fixes "single failure reopens" bug | Yes | Yes (2-of-3 reopen) | Yes |
| Fixes "single success closes" bug | Yes (by construction) | Yes (2-of-3 close) | Yes (via ratio) |
| `CircuitBreakerMiddleware.php` compatibility | No changes needed | Requires propagating generation as a request attribute | No changes needed |

## Consequences (as implemented)

- `app/Services/CircuitBreaker.php`: `transitionToHalfOpen()` resets `failure_count` and atomically sets `half_open_generation`/`half_open_started_at`/the probe/outcome counters via `HALF_OPEN_TRANSITION_SCRIPT`; `isAvailable()` gates admission at N=3 per generation via `HALF_OPEN_ADMISSION_SCRIPT` and surfaces the admitted generation through `currentHalfOpenGeneration()`; `recordSuccess()`/`recordFailure()` accept the optional `?int $generation` parameter and apply the stale/mismatch discard rule via `HALF_OPEN_OUTCOME_SCRIPT`; expired-episode detection drives a fresh open → half-open cycle rather than an in-place reset. This work is complete — see `tests/Unit/RedisCircuitBreakerTest.php` (T028) and `tests/Unit/Services/CircuitBreakerTest.php`.
- `app/Http/Middleware/CircuitBreakerMiddleware.php`: reads the admitted generation via `currentHalfOpenGeneration()`, stashes it as the `circuit_breaker.half_open_generation` request attribute, and passes it into `recordSuccess()`/`recordFailure()` after the handler runs. Also attaches the T028b `Retry-After` header (see above) to rejection responses.
- `data-model.md`'s Circuit Breaker State entity reflects the final field set and semantics (`half_open_generation`, `half_open_started_at`, `half_open_probe_count`, `half_open_successes`, `half_open_failures`), citing this ADR.
- `tests/Unit/RedisCircuitBreakerTest.php` (T028) asserts this decision's specific behavior, including the three-script atomicity described above (admission-cap self-correction, outcome discard on stale generation, and the winning/losing-caller transition race).
- The `Retry-After` header gap tracked as T028b is complete (see above) — it is no longer a separate open item, only a separate *task ID* for traceability.

## Unresolved items

None. All design questions this ADR was responsible for (alternative selection, N and threshold values, generation lifecycle, late-result/abandoned-probe/expiry handling, `failure_count` reset, Redis field set, interface signatures, and — added after a later review round — the open → half-open transition's own atomicity) are resolved and implemented. T028 (tests), T028b (`Retry-After`), and the transition-atomicity fix are all complete per `tasks.md`.
