# TSMS Training Plan

## Audience
- POS integration engineers
- Operations / support staff
- Onboarding / customer success

## Objectives
Learners will:
1. Authenticate and obtain tokens
2. Submit single, batch, and official transactions
3. Use idempotency safely
4. Interpret status + errors
5. Execute void operations
6. Monitor health & queue basics
7. Validate tokens via introspection

## Duration
Half-day (approx. 4 hours) blended session.

## Agenda
| Time | Topic | Outcome |
|------|-------|---------|
| 00:00–00:20 | Overview & architecture | Shared mental model |
| 00:20–00:50 | Authentication & tokens | Working token |
| 00:50–01:20 | Single + batch submission | Valid queued response |
| 01:20–01:30 | Break | — |
| 01:30–02:00 | Official format & checksum | Correct hash generation |
| 02:00–02:30 | Idempotency & retries | No duplicates |
| 02:30–02:50 | Voids & lifecycle | Safe void flow |
| 02:50–03:10 | Callbacks vs polling | Right choice made |
| 03:10–03:30 | Observability & logs | Correlation usage |
| 03:30–03:50 | Troubleshooting lab | Resolve sample errors |
| 03:50–04:00 | Q&A + quiz | Retention check |

## Materials
- Quick Start Guide
- User Manual
- Sample payloads
- Error code cheat sheet
- (Optional) Postman collection

## Exercises
| # | Task | Success |
|---|------|---------|
| 1 | Authenticate | Introspection active=true |
| 2 | Submit single | status=queued |
| 3 | Retry same idempotency | Same response, no duplicate |
| 4 | Deliberate bad checksum | 422 checksum_failed |
| 5 | Void transaction | voided_at present |
| 6 | Batch submit with one invalid | Mixed result counts |

## Assessment
- Short quiz (terminology + error codes)
- Practical: Provide valid official format payload & checksum

## Post-Session Support
- FAQ & Glossary
- Email / chat escalation
- Monthly office hours

## Success Criteria
- 90% quiz average
- All exercises completed
- Zero unresolved blocking questions
