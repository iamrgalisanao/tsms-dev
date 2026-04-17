# TSMS Transaction Intake Flow Assessment

Given the current flow, the most likely root cause is **not the queue dispatch itself**. The higher-probability issue is in the **synchronous intake path before the job is pushed**.

## Most Likely Root Cause

### 1. The intake endpoint is still doing too much synchronously under load

Even though the “actual processing” is async, the request still does all of this before returning:

- checksum validation
- payload parsing and merge
- DB transaction
- deadlock retry wrapper
- `insertOrIgnore`
- fetch inserted row again
- adjustments insert
- taxes insert
- job dispatch

For a normal app, that may be acceptable. For TSMS, where multiple POS terminals can push transactions in near real time, this can still overwhelm Apache if request concurrency rises.

The biggest red flags in the current flow are:

- **DB transaction inside request cycle**
- **deadlock retry inside request cycle**
- **extra fetch after insert**
- **child inserts for adjustments/taxes inside request cycle**

That combination can keep Apache workers occupied longer than expected.

---

## Strongest Technical Suspects in the Current Design

### A. Deadlock retry in the HTTP request path

This is the top suspect.

If multiple terminals are posting at once, and the transaction table or related inserts contend on indexes or unique constraints, then `DeadlockRetryService` can cause one incoming request to stay alive longer, then retry, then keep the Apache worker occupied.

That creates a cascading effect:

- one request slows
- more requests arrive
- more Apache workers get consumed
- timeouts begin

For a real-time ingestion endpoint, retry logic in the request layer can become dangerous under burst load.

### B. `insertOrIgnore` + fetch-after-insert pattern

This can be deceptively expensive.

The flow appears to:

1. attempt `insertOrIgnore`
2. if inserted, fetch the transaction again
3. then insert adjustments/taxes

That means the request is doing more round trips than necessary. Under concurrency, this pattern can amplify lock contention and query volume.

Possible pain points:

- unique index contention
- repeated lookups on a large transaction table
- race behavior when duplicates arrive closely together

### C. Adjustments and taxes inserts are still synchronous

If adjustments and taxes arrays are non-trivial, this increases request time.

Even if processing is queued later, the request is still waiting for:

- parent insert
- child inserts
- commit

That means the endpoint is not truly “lightweight ingest.” It is still a transactional write pipeline.

### D. Duplicate requests from POS terminals

TSMS naturally deals with retries, unstable connections, and vendor-side resend behavior. If POS devices resend aggressively after a delayed response, then the endpoint may get duplicate storms.

That makes the current synchronous pattern worse:

- original request is slow
- POS retries
- duplicate hits pile up
- `insertOrIgnore` still costs DB work
- Apache workers stack up

This fits the production symptom very well.

---

## Why This Fits TSMS Specifically

TSMS is a **real-time transaction ingestion system**, not just an admin portal.

That means the `/official` endpoint is exposed to:

- bursts during business hours
- repeated submissions from multiple terminals
- possible retry storms from vendors
- duplicate transaction pressure
- logging and validation on every request

So even “small” synchronous work becomes dangerous if the ingestion endpoint is hit at scale.

---

## Ranked Assessment for the Current Flow

### Most likely

1. **Deadlock retry + DB transaction in request path**
2. **Duplicate/retry storm from terminals**
3. **Insert + re-fetch + child inserts causing lock/query overhead**

### Moderately likely

4. **Indexes/constraints on transaction table causing contention**
5. **Large adjustments/taxes payloads increasing synchronous request time**
6. **Queue dispatch shard selection or queue backend latency adding a bit more delay**

### Less likely from the flow alone

7. **The async job itself**

Because that is already offloaded.

---

## Architectural Issue in One Sentence

The endpoint is **asynchronous in the processing stage, but still too transactional and DB-heavy in the intake stage** for a high-concurrency TSMS workload.

---

## What Should Be Inspected First

### 1. Request duration around `TransactionIngestService@ingest`

Measure:

- time before service call
- time inside deadlock retry
- time for parent insert
- time for fetch-after-insert
- time for adjustments insert
- time for taxes insert
- time before response return

If Apache is timing out, one of these is likely taking much longer than expected under concurrency.

### 2. Deadlock frequency

Check whether deadlocks or lock waits are happening at all.

If yes, that is a major clue.

### 3. Duplicate submission rate

Check whether the same transaction or checksum is hitting repeatedly in a short time window.

### 4. Table/index design

Look at:

- unique constraints
- indexes on transaction identifiers
- foreign key constraints on adjustments/taxes
- whether inserts are causing secondary index pressure

---

## Most Probable Root Cause Statement

If one best answer must be given:

**The root cause is likely DB contention in the synchronous transaction intake path, made worse by retry/duplicate behavior from POS submissions, causing Apache workers to remain busy until the server becomes saturated.**

---

## Best Design Direction

For TSMS, the safer target design is:

- do minimal auth + minimal validation
- write a lean intake record as fast as possible
- acknowledge quickly
- move enrichment, child inserts, and downstream logic to queue workers

In the current design, the endpoint still behaves more like a mini-processing pipeline than a thin intake gateway.

---

## Practical Next Step

The next thing to review should be the actual `TransactionIngestService@ingest` code and the transaction table indexes. That will likely reveal whether the bottleneck is:

- lock contention
- duplicate contention
- re-query overhead
- child insert overhead