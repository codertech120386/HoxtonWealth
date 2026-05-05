# AI Usage Disclosure

This project was built collaboratively with Claude (Anthropic) via Claude Code in a single multi-day session. The exercise spec explicitly permits AI assistance and requires disclosure; this document is that disclosure.

---

## How AI was used

Every layer of the project was AI-assisted to some degree:

- **Plan generation** — the initial PLAN.md (14 tasks across 5 phases, ~10.5 hr estimate) was drafted by Claude after a Q&A round on plan-shaping decisions (database, queue strategy, auth, bonus features, money representation, funding flow, containerization, test framework).
- **Schema design** — I (the developer) iterated on the schema with Claude and pushed back on early proposals. The final shape (UUIDv7 PKs, the unified `transfers` table that swallows deposits, explicit FKs on `audit_logs` instead of polymorphic columns, the `is_system` partial unique index) emerged from those conversations.
- **Implementation** — task-by-task code generation. For each task I reviewed Claude's output before accepting and ran the test suite to verify behaviour.
- **Test design** — Claude proposed test cases per task; I added or modified a few to cover edge cases I cared about (e.g. the crash-mid-Phase-2 test, the sequential-overdraft prevention test, the cross-process correlation propagation test).
- **Documentation** — README, DESIGN, and this file were drafted by Claude based on the actual code in the repo, then reviewed. After the first full draft I asked Claude to re-read DESIGN.md end-to-end and flag anything mentioned but not explained; that audit pass added §2.6 (mixed PK strategy: UUIDv7 for entities vs `BIGSERIAL` for log tables), §4.5 (the `markFailed()` + `$this->fail()` fail-vs-retry mechanism), the system-account bootstrap note in §2.5, and the correlation-ID provenance clarification in §6.8. None of these changed the code — they closed gaps a reviewer would have asked about.

---

## Notable prompts that shaped the design

A handful of conversations meaningfully changed what got built. The most significant:

1. **"Use this document `Senior Software Engineer - Exercise.docx` and create plan using php-laravel and do not over-engineer. Ask questions till you are at least 95% sure of the requirements"** — set the tone for the whole project. The "do not over-engineer" instruction is responsible for several non-decisions: no Redis, no separate `transactions` parent table, no cached balance column, no multi-currency, no users table.
2. **"I still don't understand `is_system` on accounts, elaborate it more"** — drove Claude to explain double-entry's zero-sum invariant and the "world account" pattern from real banking. Confirmed the design choice rather than changing it.
3. **"Okay so basically I assume this is the bank's own account so why is `ledger_entries.transfer_id` column nullable?"** — this was the highest-leverage prompt. It exposed an inconsistency in the original plan (deposits had no parent table, so deposit-side ledger rows had no FK target). The fix was to broaden the definition of "transfer" to include deposits (`type=DEPOSIT`, `from_account = system`), making `ledger_entries.transfer_id` NOT NULL. This simplified the schema, the reconciliation invariant, the ledger-history endpoint, and removed a dedicated `idempotency_keys` table that was originally planned for deposits.
4. **"can we add swagger so that hitting api endpoints becomes easy"** — added Task 3.5 for `darkaonline/l5-swagger`. Each endpoint task subsequently included `OA\Get` / `OA\Post` annotations.
5. **Mid-Task-8 design pivot** — my first cut of `TransferProcessor` put the entire settlement in one transaction. A failing test (`insufficient_balance` expected `[Processing, Failed]` audit chain but got `[Failed]` only) prompted Claude to lay out the trade-off between one-tx (crash-safe but Processing audit invisible on failure) and two-phase (Processing audit always observable but a transfer can be left stuck in PROCESSING after retry exhaustion). I picked two-phase because it makes the lifecycle observable in failure scenarios; documented the stuck-PROCESSING gap in DESIGN.md §4.2 instead of papering over it.
6. **"deposit is also like a transfer correct? so if thats the case then why deposit is synchronous while transfers are asynchronous?"** — surfaced a real gap in the design doc: §2.3 explained the structural symmetry (deposits modelled as transfers from the system account) but the operational asymmetry wasn't justified anywhere. Drove a new DESIGN.md §2.4 unpacking four reasons in priority order — trivial failure surface for deposits, bounded lock contention, caller UX (deposit returns the new balance inline, transfer returns `202` and a status URL), and real-world modelling of an external rail vs internal authorisation. The conclusion: we could make deposits async with the same `Transfer` row, but none of what async buys (failure handling, deadlock avoidance, queue smoothing, retry) applies. Didn't change the implementation — only the doc.

---

## What I (the developer) verified or modified

**Verified by reading & testing:**

- All schema constraints (CHECK on enum-style columns, `amount > 0`, partial unique index on `is_system`).
- Concurrent-overdraft prevention via `SELECT ... FOR UPDATE` ordered by UUIDv7. Read the lock acquisition order in `TransferProcessor::process()`; sanity-checked the test that exercises it.
- The two-phase processor's behaviour under crash. Stepped through what happens when Phase 2 throws — confirmed Phase 1 stays committed, ledger writes roll back, retry produces a clean audit trail.
- Idempotency on both deposits and transfers. Verified the unique-violation race handler returns the existing row rather than erroring.
- That `LOG_CHANNEL=json` actually writes to `laravel.json.log` after the `JsonFormatterTap` type-hint was fixed (Claude initially typed it against `Monolog\Logger`; Laravel actually passes `Illuminate\Log\Logger`).

**Modified or steered:**

- Pushed back on a polymorphic `audit_logs.aggregate_type / aggregate_id` pair in favour of explicit nullable FKs (`account_id`, `transfer_id`). The polymorphic shape's flexibility wasn't earning its keep for two aggregate types.
- Pushed back on a separate `transactions` parent table (Option 2 in the deposits-as-transfers conversation) in favour of just broadening `transfers`. One table, fewer joins.
- Bumped PHP from 8.3 to 8.4 after Pest 3.8's transitive dependencies required it. Captured in PLAN.md's "Locked Decisions".
- Added a `ForceJson` middleware after the first live curl (no `Accept: application/json` header) returned an HTML redirect on validation failure rather than JSON. Now every `/api/v1/*` response is JSON regardless of `Accept`.
- Added a per-API-key partial unique index on `accounts.is_system` so the database itself rejects accidentally seeding a second system account.

---

## What was NOT verified by me

Honest gaps so reviewers know where the seams are:

- **Forking-process parallelism.** I trust the `lockForUpdate` directive but didn't write a test that actually causes lock contention. The sequential test demonstrates the algorithm; reconciliation catches drift if the lock somehow doesn't bite. A real test (e.g. `pcntl_fork` + two simultaneous `php artisan` invocations) was punted for time.
- **High-volume performance.** No load testing. The architecture should hold under thousands of transfers/sec for unrelated accounts; hot accounts will serialize.
- **Long-running production behaviour.** This is a fresh codebase with ~92 tests. It hasn't been observed under prolonged real traffic, so anything that surfaces only under sustained load (memory leaks, connection pool exhaustion, disk-fill from logs) is unproven.

---

## On using AI for a take-home

The output here would have been impossible to produce manually in the spec's 8–12 hour budget — including the design rationale, OpenAPI annotations on every endpoint, structured JSON logging with cross-process correlation IDs, reconciliation, DLQ replay, rate limiting, and ~92 tests. AI assistance is what made the breadth feasible.

What it did *not* do is make the engineering judgment for me. Decisions about schema (single `transfers` table vs separate, polymorphic vs explicit FKs, `is_system` vs magic UUID), the two-phase vs one-tx processor, what to test vs trust, and what to defer to "production hardening" all came from explicit conversations where Claude laid out trade-offs and I picked. The design rationale in DESIGN.md is the result of those conversations, recorded faithfully.
