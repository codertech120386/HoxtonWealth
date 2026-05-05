# Implementation Plan: Event-Driven Wallet & Ledger Platform (Laravel)

## Overview
A backend HTTP API + background worker for asynchronous, double-entry-ledger-backed transfers between accounts. Built in Laravel 12 on PHP 8.4, Postgres, with a database-backed queue and audit log. API protected by a static API key. Targeted at the spec's 8–12 hour budget; scope is deliberately narrow with no UI, no users table, no Redis.

## Locked Decisions
- **Stack:** Laravel 12, PHP 8.4 (required by Pest 3.8 transitive deps), Postgres 16, Pest 3, Docker Compose.
- **IDs:** UUIDv7 for `accounts.id` and `transfers.id` — generated via `Str::uuid7()` through a `HasUuidv7s` trait on the relevant models. Time-ordered → much better B-tree locality than UUIDv4 for an append-heavy ledger workload. `ledger_entries.id` and `audit_logs.id` stay `BIGSERIAL` (high-volume append-only, no need for a UUID surface).
- **Enums:** every constrained-vocabulary column uses `VARCHAR + CHECK(...)` at the DB layer, and a PHP 8.1 backed enum cast at the model layer (`TransferType`, `TransferStatus`, `LedgerDirection`, `AuditEventType`). Gives us DB integrity, friendly migrations, and PHP-side type safety without Postgres native ENUMs.
- **Money:** single currency (USD), `BIGINT` minor units. No floats anywhere.
- **Auth:** static API key (`X-Api-Key`) checked by middleware. `API_KEYS` env (comma-separated) supports multiple keys for rate limiting.
- **Async:** Laravel `database` queue driver + a single `php artisan queue:work` worker. No Redis.
- **Events:** in-process Laravel events on the write path; persisted to an `audit_logs` table inside the same DB transaction as the state change. `audit_logs` is the durable event log. Append-only by convention (no UPDATE / DELETE paths in code).
- **Concurrency:** within `ProcessTransferJob` we wrap the debit/credit in a transaction and lock both involved `accounts` rows with `SELECT ... FOR UPDATE`, ordered by account UUIDv7 ASC to prevent deadlocks. Balance is read by aggregating `ledger_entries`.
- **Idempotency:** `transfers.idempotency_key` UNIQUE column. Duplicate requests collide on insert and return the existing transfer.
- **Unified "Transfer" concept:** a transfer is any movement of value between two accounts, one of which may be the system account. Deposits are transfers with `type=DEPOSIT` and `from_account_id = system_account.id`; user-to-user transfers are `type=TRANSFER`. This lets `ledger_entries.transfer_id` be NOT NULL — every ledger row has a parent operation. Deposits are processed synchronously (system account cannot overdraft), user-to-user transfers go through the queue.
- **Funding:** `POST /api/v1/accounts/{id}/deposits` — synchronous, writes a `transfers` row with `type=DEPOSIT`, `status=COMPLETED`, plus two ledger entries (credit user, debit `system`) in a single DB transaction.
- **DLQ:** Laravel's built-in `failed_jobs` table + an artisan command to inspect/replay.
- **Bonuses included:** reconciliation artisan command, DLQ replay command, structured JSON logs w/ correlation IDs, per-API-key rate limiting.
- **Spec's `transaction_id` mapping:** maps to `transfers.id`; surfaced on ledger-history rows via `transfer_id`.

## Architecture Snapshot

```
HTTP API (api/v1/*)
  ├─ ApiKeyAuth middleware  ─┐
  ├─ RateLimit middleware    ├─ inject correlation_id
  └─ CorrelationId middleware┘
       │
       ▼
  Controllers ──▶ Application services (AccountService, TransferService, LedgerService)
                       │
                       ├─ Synchronous writes (account create, deposit) ─▶ DB tx ─▶ transfers + ledger_entries + audit_logs
                       │
                       └─ Transfer enqueue ─▶ insert transfers(type=TRANSFER, status=PENDING) + audit_logs(TransferRequested) ─▶ dispatch ProcessTransferJob
                                                                                                                  │
                                                                                                                  ▼
                                                                                                  queue:work worker
                                                                                                  ProcessTransferJob:
                                                                                                    BEGIN
                                                                                                      lock accounts FOR UPDATE
                                                                                                      verify balance from ledger
                                                                                                      insert 2 ledger_entries
                                                                                                      transfers.status = COMPLETED
                                                                                                      audit_logs(TransferCompleted)
                                                                                                    COMMIT
                                                                                                  on exception → status=FAILED + audit_logs(TransferFailed)
                                                                                                  retries handled by Laravel; final failure → failed_jobs (DLQ)
```

## Schema (4 tables + Laravel's failed_jobs)

- **accounts**
  - `id` UUIDv7 PK
  - `name` VARCHAR
  - `is_system` BOOL NOT NULL DEFAULT false — flags the singleton internal counterparty account used for deposits (and future withdrawals/fees)
  - timestamps
  - `CREATE UNIQUE INDEX accounts_one_system ON accounts (is_system) WHERE is_system = true;` — DB-enforced "exactly one system account" invariant

- **transfers**
  - `id` UUIDv7 PK
  - `type` VARCHAR + CHECK(`TRANSFER`, `DEPOSIT`) — synchronous-vs-async processing path
  - `idempotency_key` VARCHAR UNIQUE
  - `from_account_id` UUID FK accounts(id) — `= system_account.id` when `type=DEPOSIT`
  - `to_account_id` UUID FK accounts(id)
  - `amount` BIGINT, CHECK(amount > 0)
  - `status` VARCHAR + CHECK(`PENDING`, `PROCESSING`, `COMPLETED`, `FAILED`) — deposits go straight to `COMPLETED`; transfers traverse the full state machine
  - `error_reason` TEXT NULL
  - `attempts` INT NOT NULL DEFAULT 0
  - timestamps
  - Indexes: (status), (from_account_id), (to_account_id), (type, created_at DESC)

- **ledger_entries** — append-only, never updated
  - `id` BIGSERIAL PK
  - `account_id` UUID FK accounts(id)
  - `transfer_id` UUID FK transfers(id) **NOT NULL** — every ledger row has a parent operation
  - `direction` VARCHAR + CHECK(`DEBIT`, `CREDIT`)
  - `amount` BIGINT, CHECK(amount > 0) — magnitude only; sign is implied by `direction`
  - `created_at`
  - Index: (account_id, created_at)

- **audit_logs** — append-only, never updated
  - `id` BIGSERIAL PK
  - `event_type` VARCHAR + CHECK(see vocabulary below)
  - `account_id` UUID FK accounts(id) NULL — set when the event is *about* an account
  - `transfer_id` UUID FK transfers(id) NULL — set when the event is *about* a transfer
  - `correlation_id` UUID NOT NULL — same ID across HTTP request and any worker jobs it spawns; lets you trace one logical flow end-to-end
  - `payload` JSONB — event-specific snapshot (amounts, prior status, error message, etc.)
  - `created_at`
  - Indexes: (transfer_id, created_at), (account_id, created_at), (correlation_id)

- **failed_jobs** — Laravel default; serves as the DLQ.

### Audit event vocabulary

The CHECK on `audit_logs.event_type` permits exactly:

| `event_type`           | Emitted when                                         | Aggregate(s) populated     |
|------------------------|------------------------------------------------------|----------------------------|
| `AccountCreated`       | `POST /accounts` succeeds                            | account_id                 |
| `DepositMade`          | `POST /accounts/{id}/deposits` succeeds              | account_id, transfer_id    |
| `TransferRequested`    | `POST /transfers` accepts a new request              | transfer_id                |
| `TransferProcessing`   | `ProcessTransferJob` starts handling                 | transfer_id                |
| `TransferCompleted`    | Job commits both ledger entries                      | transfer_id                |
| `TransferFailed`       | Job sets status=FAILED (insufficient balance, etc.)  | transfer_id                |

This is exactly the event set the spec calls out under "Event-Driven Design". Mirror this list as a PHP `AuditEventType` enum.

## Task List

### Phase 1 — Foundation

#### Task 1: Scaffold Laravel + Docker Compose
**Description:** Create a fresh Laravel 12 app in the working directory; add `docker-compose.yml` with `app` (php-fpm), `web` (nginx), `worker` (php-cli running queue:work), and `db` (postgres 16). Configure `.env.example` with API_KEYS, DB, and `QUEUE_CONNECTION=database`. Install Pest 3.
**Acceptance criteria:**
- `docker compose up` boots app, web, worker, db without errors.
- `curl http://localhost:8080/up` returns Laravel's health response.
- `docker compose exec app php artisan test` runs zero failures (no tests yet).

**Verification:** Run all three commands above.
**Dependencies:** None.
**Files:** `composer.json`, `docker-compose.yml`, `Dockerfile`, `docker/nginx/default.conf`, `.env.example`, `.dockerignore`.
**Scope:** M.

#### Task 2: API key middleware + correlation-ID middleware + base routing
**Description:** Add `ApiKeyAuth` middleware reading `X-Api-Key`, hashing it, and matching against an `API_KEYS` env list. Add `CorrelationId` middleware that takes `X-Correlation-Id` from the request or generates a UUIDv7, stores it in the request, and echoes it in the response. Wire both onto the `api/v1` route group in `bootstrap/app.php`.
**Acceptance criteria:**
- Request with no `X-Api-Key` to any `/api/v1/*` route returns `401`.
- Request with valid key succeeds and includes `X-Correlation-Id` in response headers.
- Correlation ID survives into log context.

**Verification:** Pest test `ApiKeyAuthTest`, `CorrelationIdTest`. Manual `curl -H "X-Api-Key: test"`.
**Dependencies:** Task 1.
**Files:** `app/Http/Middleware/ApiKeyAuth.php`, `app/Http/Middleware/CorrelationId.php`, `bootstrap/app.php`, `routes/api.php`, `tests/Feature/Auth/ApiKeyAuthTest.php`.
**Scope:** S.

#### Task 3: Schema migrations + UUIDv7 trait + enums + seed system account
**Description:** Write migrations for `accounts`, `transfers`, `ledger_entries`, `audit_logs` exactly as specified in the Schema section above (UUIDv7 PKs, CHECK constraints, partial unique index for `is_system`). Add `app/Concerns/HasUuidv7s.php` trait that overrides `newUniqueId()` to call `Str::uuid7()`. Add PHP 8.1 backed enums: `TransferType`, `TransferStatus`, `LedgerDirection`, `AuditEventType`. Wire them via `$casts` on the Eloquent models. Seeder creates the singleton `system` account (`is_system=true`).
**Acceptance criteria:**
- `migrate:fresh --seed` runs cleanly on Postgres.
- All FK + UNIQUE + CHECK constraints in place; partial unique index on `is_system` works.
- `Account::getBalance()` returns 0 for a fresh account; sums `ledger_entries` correctly.
- Eloquent reads return enum instances on `transfers.status`, `transfers.type`, `ledger_entries.direction`, `audit_logs.event_type`.
- Inserting a second `is_system=true` row fails with a unique-violation.

**Verification:** Pest tests:
- `SchemaTest::system_account_exists_and_is_unique`
- `SchemaTest::balance_is_computed_from_ledger`
- `SchemaTest::status_casts_to_enum`

**Dependencies:** Task 1.
**Files:** `database/migrations/*` (4 files), `app/Concerns/HasUuidv7s.php`, `app/Enums/{TransferType,TransferStatus,LedgerDirection,AuditEventType}.php`, `app/Models/{Account,Transfer,LedgerEntry,AuditLog}.php`, `database/seeders/SystemAccountSeeder.php`.
**Scope:** M.

### Checkpoint A — Foundation
- [ ] `docker compose up` boots cleanly.
- [ ] Schema migrates and seeds; system account is unique.
- [ ] Auth + correlation-ID middleware enforced.
- [ ] Enum casts work end-to-end.
- [ ] All Pest tests pass.

---

### Phase 2 — Synchronous read & funding paths

#### Task 4: Create-account endpoint
**Description:** `POST /api/v1/accounts` creates a regular account, returns `{id, name, balance: 0}`. Validation via FormRequest (`name` required string).
**Acceptance criteria:**
- Returns 201 + body `{id, name, balance}` for valid input.
- Returns 422 on missing/invalid name.
- Writes `audit_logs(event_type=AccountCreated, account_id, correlation_id)` row.

**Verification:** Pest `CreateAccountTest` (happy + validation).
**Dependencies:** Task 3.
**Files:** `app/Http/Controllers/Api/V1/AccountController.php`, `app/Http/Requests/Api/V1/CreateAccountRequest.php`, `app/Services/AccountService.php`, `tests/Feature/Accounts/CreateAccountTest.php`.
**Scope:** S.

#### Task 5: Deposit endpoint (synchronous, writes a Transfer)
**Description:** `POST /api/v1/accounts/{id}/deposits` body `{amount, idempotency_key}`. In a single `DB::transaction`:
1. Insert `transfers` row: `type=DEPOSIT`, `status=COMPLETED`, `from_account_id=system_account.id`, `to_account_id={id}`, `amount`, `idempotency_key`.
2. Insert two `ledger_entries`: `(account=user, direction=CREDIT, transfer_id, amount)` and `(account=system, direction=DEBIT, transfer_id, amount)`.
3. Insert `audit_logs(event_type=DepositMade, account_id=user, transfer_id, correlation_id, payload={amount,new_balance})`.

Idempotency: if `idempotency_key` already exists in `transfers`, return the existing row (HTTP 200) with no new writes.
**Acceptance criteria:**
- Returns 201 with `{transfer_id, status: "COMPLETED", balance}`.
- Exactly one `transfers` row, two `ledger_entries`, and one `audit_logs` row are created.
- System account's signed balance decreases by exactly `amount`; user account increases by exactly `amount`.
- Reposting with same `idempotency_key` returns 200 with the same `transfer_id` and creates no new rows.
- 422 on amount ≤ 0; 404 on missing account.

**Verification:** Pest `DepositTest` (happy, idempotent replay, validation, system account counterparty).
**Dependencies:** Task 4.
**Files:** `app/Http/Controllers/Api/V1/DepositController.php`, `app/Http/Requests/Api/V1/DepositRequest.php`, `app/Services/LedgerService.php`, `app/Services/TransferService.php`, `tests/Feature/Deposits/DepositTest.php`.
**Scope:** M.

#### Task 6: Balance + ledger-history endpoints
**Description:** `GET /api/v1/accounts/{id}` returns `{id, name, balance}` (computed from ledger). `GET /api/v1/accounts/{id}/ledger?cursor=...&limit=...` returns paginated ledger entries (cursor pagination on `created_at,id`). Each row exposes `transfer_id` (the spec's `transaction_id`) and the parent transfer's `type`.
**Acceptance criteria:**
- Balance reflects all deposits and completed transfers in real time.
- Ledger endpoint returns correct rows with direction, amount, transfer_id, transfer_type, timestamp.
- Pagination yields stable, non-overlapping pages.

**Verification:** Pest `BalanceTest`, `LedgerHistoryTest`.
**Dependencies:** Task 5.
**Files:** `app/Http/Controllers/Api/V1/AccountController.php` (extend), `app/Http/Controllers/Api/V1/LedgerController.php`, `tests/Feature/Accounts/BalanceTest.php`, `tests/Feature/Accounts/LedgerHistoryTest.php`.
**Scope:** S.

### Checkpoint B — Synchronous flows
- [ ] Account → deposit → balance round-trip works end-to-end.
- [ ] Idempotent deposit replay verified.
- [ ] Ledger history shows deposits with `type=DEPOSIT`.
- [ ] All Pest tests green.

---

### Phase 3 — Asynchronous transfer flow (the core)

#### Task 7: Initiate-transfer endpoint (idempotent enqueue)
**Description:** `POST /api/v1/transfers` body `{from_account_id, to_account_id, amount, idempotency_key}`. Validates input, inserts a `transfers` row with `type=TRANSFER` and `status=PENDING`, writes `audit_logs(TransferRequested)` in same tx, dispatches `ProcessTransferJob` (passing the request's `correlation_id` into the job). On unique-key collision, looks up the existing transfer and returns it (HTTP 200) instead of erroring. Returns immediately with `{transfer_id, status}`.
**Acceptance criteria:**
- Returns 202 with `transfer_id` for first request; 200 with the same `transfer_id` for duplicate `idempotency_key`.
- 422 on same `from`/`to`, on amount ≤ 0, or on missing fields.
- 404 if either account does not exist.
- Rejects requests where `from_account_id = system_account.id` (system account is not user-controllable).
- Job is enqueued (assert via `Queue::fake`).
- `audit_logs(TransferRequested)` row exists and carries the request's `correlation_id`.

**Verification:** Pest `InitiateTransferTest` covering happy path, idempotent replay, validation, missing accounts, system-account rejection.
**Dependencies:** Task 3.
**Files:** `app/Http/Controllers/Api/V1/TransferController.php`, `app/Http/Requests/Api/V1/InitiateTransferRequest.php`, `app/Services/TransferService.php` (extend), `app/Jobs/ProcessTransferJob.php` (stub), `tests/Feature/Transfers/InitiateTransferTest.php`.
**Scope:** M.

#### Task 8: ProcessTransferJob — debit/credit with locking + double-entry
**Description:** Implement `ProcessTransferJob::handle()`:

1. Push the constructor-supplied `correlation_id` onto the Log context.
2. Mark transfer `PROCESSING`; emit `TransferProcessing` audit log.
3. Inside `DB::transaction`: lock involved `accounts` rows ordered by UUIDv7 ASC (`lockForUpdate`); recompute sender balance from ledger; if insufficient, throw `InsufficientBalanceException`; otherwise insert two `ledger_entries` (debit sender, credit receiver), set `transfers.status = COMPLETED`, emit `TransferCompleted` audit log.
4. On business-rule failure (insufficient balance, account missing), set `status=FAILED`, write `error_reason`, emit `TransferFailed`, and `$this->fail()` so the job is not retried.
5. On unexpected exception, allow Laravel's retry policy (`tries=3`, exponential backoff).

**Acceptance criteria:**
- Successful transfer leaves sender balance reduced by exactly `amount`, receiver balance increased by exactly `amount`, and creates exactly two ledger entries linked by `transfer_id`.
- Insufficient balance leaves balances unchanged, transfer `FAILED`, audit `TransferFailed` with reason.
- Job is safe under concurrent execution: simulated parallel transfers from the same account never produce a negative balance.
- Worker crash mid-handle (simulated by throwing inside the transaction after partial work) leaves no partial ledger writes; on retry, completes idempotently.

**Verification:** Pest tests:
- `ProcessTransferJobTest::happy_path`
- `ProcessTransferJobTest::insufficient_balance`
- `ProcessTransferJobTest::concurrent_transfers_never_overdraw` (spawn 2 jobs in DB transactions; verify final state)
- `ProcessTransferJobTest::crash_mid_processing_does_not_corrupt_state`

**Dependencies:** Task 7.
**Files:** `app/Jobs/ProcessTransferJob.php`, `app/Domain/Exceptions/InsufficientBalanceException.php`, `app/Services/TransferProcessor.php`, `tests/Feature/Transfers/ProcessTransferJobTest.php`.
**Scope:** M.

#### Task 9: Get-transfer-status endpoint (covers transfers and deposits)
**Description:** `GET /api/v1/transfers/{id}` returns `{id, type, status, from, to, amount, error_reason, created_at, updated_at}` for any row in the `transfers` table — both async transfers and synchronous deposits.
**Acceptance criteria:**
- Status reflects actual job state for transfers: PENDING → PROCESSING → COMPLETED/FAILED.
- Returns deposits too, with `type=DEPOSIT` and `status=COMPLETED` immediately.
- 404 if transfer not found.

**Verification:** Pest `TransferStatusTest` covering both `type=TRANSFER` and `type=DEPOSIT` rows.
**Dependencies:** Task 8.
**Files:** `app/Http/Controllers/Api/V1/TransferController.php` (extend), `tests/Feature/Transfers/TransferStatusTest.php`.
**Scope:** S.

### Checkpoint C — Core async flow
- [ ] End-to-end: deposit → transfer → poll status → balances reconcile.
- [ ] Idempotent replay verified.
- [ ] Concurrent-transfer test passes.
- [ ] Crash-mid-handler test passes.
- [ ] All Pest tests green.

---

### Phase 4 — Resilience + bonuses

#### Task 10: DLQ replay artisan command
**Description:** `php artisan transfers:retry-failed {transferId?}` re-dispatches a job from `failed_jobs`. Without an ID, lists failed jobs. Document in README.
**Acceptance criteria:**
- A job that throws past max attempts ends up in `failed_jobs`.
- The command can list it and re-queue it; on success the transfer reaches `COMPLETED`.

**Verification:** Pest `DlqReplayTest`.
**Dependencies:** Task 8.
**Files:** `app/Console/Commands/RetryFailedTransferCommand.php`, `tests/Feature/Operations/DlqReplayTest.php`.
**Scope:** S.

#### Task 11: Reconciliation artisan command
**Description:** `php artisan ledger:reconcile` enforces three invariants uniformly across all `transfers` (deposits and user-to-user alike):
1. **Per-transfer double-entry:** every `transfers.id` (with status `COMPLETED`) has exactly two `ledger_entries`, one DEBIT and one CREDIT, of the same `amount`.
2. **Global zero-sum:** signed sum of `ledger_entries.amount` (CREDIT positive, DEBIT negative) across all accounts is exactly 0.
3. **Per-account balance sanity:** each account's computed balance equals the signed sum of its ledger rows (trivially true given current code, but makes the assertion explicit).

Exits non-zero if any invariant is violated and prints the offending rows.
**Acceptance criteria:**
- Command prints an OK report on a healthy DB.
- If a ledger row is manually deleted to simulate drift, the command flags it and exits 1.
- Per-transfer invariant catches a transfer with only one ledger row.

**Verification:** Pest `ReconciliationCommandTest` covering OK, missing-row, and zero-sum-violation cases.
**Dependencies:** Task 8.
**Files:** `app/Console/Commands/ReconcileLedgerCommand.php`, `tests/Feature/Operations/ReconciliationCommandTest.php`.
**Scope:** S.

#### Task 12: Structured JSON logging + correlation propagation
**Description:** Add a `json` log channel; default `LOG_CHANNEL=json` in `.env.example`. Push `correlation_id`, `transfer_id`, and `account_id` into Log context where relevant. The job receives the originating request's `correlation_id` via its constructor and pushes it onto Log context in `handle()`.
**Acceptance criteria:**
- Logs are valid JSON, one event per line.
- All log lines emitted within a request share the same `correlation_id`.
- Lines emitted by `ProcessTransferJob` include the originating request's `correlation_id`.

**Verification:** Pest `LoggingTest` capturing the log channel.
**Dependencies:** Task 2, Task 8.
**Files:** `config/logging.php`, `app/Logging/JsonFormatter.php`, `app/Http/Middleware/CorrelationId.php` (extend), `app/Jobs/ProcessTransferJob.php` (extend), `tests/Feature/Logging/LoggingTest.php`.
**Scope:** S.

#### Task 13: Per-API-key rate limiting on transfer initiation
**Description:** Define a named RateLimiter (e.g. 60 requests/minute keyed on hashed API key) and apply it to `POST /api/v1/transfers`. Return 429 with a `Retry-After` header when exceeded.
**Acceptance criteria:**
- Burst of N+1 requests in under a minute returns one 429.
- Different API keys do not share buckets.

**Verification:** Pest `RateLimitTest`.
**Dependencies:** Task 7.
**Files:** `app/Providers/AppServiceProvider.php` (configure limiter), `routes/api.php` (apply throttle), `tests/Feature/RateLimit/RateLimitTest.php`.
**Scope:** S.

### Checkpoint D — Resilience + bonuses
- [ ] Failed job path lands in DLQ; replay works.
- [ ] Reconciliation passes on a healthy DB and detects drift across all transfer types.
- [ ] Logs are structured JSON with correlation IDs end-to-end.
- [ ] Rate limit returns 429.
- [ ] All Pest tests green.

---

### Phase 5 — Documentation deliverables

#### Task 14: README + Design Doc + AI_USAGE
**Description:** Write three Markdown files at the repo root:

- **`README.md`** — Setup (`docker compose up`, `php artisan migrate --seed`, `php artisan queue:work`), how to set `API_KEYS`, full API reference (curl examples for every endpoint), how to trigger reconciliation/DLQ replay, assumptions (single currency, no users, deposits modelled as transfers from system account), trade-offs (chose Laravel DB queue not Redis; chose ledger-sum balance not cached balance; chose VARCHAR+CHECK over native ENUM; chose UUIDv7 over UUIDv4).
- **`DESIGN.md`** — Architecture diagram, schema rationale (including the unified-transfer model and the `is_system` invariant), event-flow lifecycle (state machine for both `type=TRANSFER` and `type=DEPOSIT`), failure scenarios + recovery (worker crash, duplicate request, insufficient balance, retry exhaustion), scaling strategy (partition by account_id, move to Redis/Kafka, snapshot balances), security & compliance section.
- **`AI_USAGE.md`** — Prompts used during planning + implementation, where AI helped, what was verified or modified.

**Acceptance criteria:**
- All three files present and complete per the spec's deliverables section.
- README includes a copy-pasteable curl walkthrough that completes a transfer end-to-end against a fresh `docker compose up`.
- DESIGN.md covers all six required subsections from the spec.

**Verification:** Manual review against the spec's "Deliverables" checklist; run the README walkthrough top to bottom against a fresh repo.
**Dependencies:** Tasks 1–13.
**Files:** `README.md`, `DESIGN.md`, `AI_USAGE.md`.
**Scope:** M.

### Checkpoint E — Submission ready
- [ ] All Pest tests pass.
- [ ] README walkthrough executes cleanly on a fresh checkout.
- [ ] DESIGN.md addresses every section the spec calls out.
- [ ] AI_USAGE.md is honest and specific.
- [ ] `docker compose down -v && docker compose up` from scratch yields a working system.

---

## Time Estimate (vs. spec's 8–12 hr budget)

| Phase | Tasks | Est. |
|---|---|---|
| 1. Foundation | 1–3 | 1.5 h |
| 2. Sync paths | 4–6 | 2 h |
| 3. Async transfer flow | 7–9 | 3.5 h |
| 4. Resilience + bonuses | 10–13 | 2 h |
| 5. Docs | 14 | 1.5 h |
| **Total** | **14 tasks** | **~10.5 h** |

Within budget with ~1 hour of slack for debugging and the inevitable Postgres-vs-Laravel quirk.

## Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Concurrent transfers cause negative balance | High | `SELECT ... FOR UPDATE` on accounts ordered by UUIDv7; balance recomputed inside the lock; explicit concurrent-test in Task 8. |
| Worker crash leaves transfer stuck in `PROCESSING` | Med | Status transition is inside the same DB tx as ledger writes — crash before commit ⇒ tx rolls back, transfer remains `PENDING` for retry. Status set to `PROCESSING` is itself written inside the same tx so it's only visible on commit. |
| Duplicate transfer requests | Med | UNIQUE constraint on `transfers.idempotency_key`; collision returns the existing transfer. Same key reused for deposits via the same column. |
| Job retried after partial work | Med | Eliminated by single-tx design; the job has no externally visible side effects until commit. |
| Second `is_system` row accidentally seeded | Low | Partial unique index `accounts_one_system` rejects it at the DB layer. |
| Time overrun (12 h soft cap) | Med | Bonuses in Phase 4 are independent — drop rate limiting or DLQ replay if Phase 3 runs long. Docs are non-negotiable. |
| Postgres ENUM migration friction | Low | Avoided entirely — using VARCHAR + CHECK + PHP backed enums. |
