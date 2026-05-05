# Hoxton Wallet & Ledger Platform

An event-driven, double-entry-ledger-backed wallet platform with asynchronous transfer processing. Built as a senior-engineer take-home exercise.

- **Stack:** Laravel 12, PHP 8.4, Postgres 16, Pest 3, Docker Compose
- **Scope:** backend HTTP API + background worker. No UI, no users table — stateless API protected by a static API key
- **Design philosophy:** correctness first, no over-engineering. Single currency (USD), all amounts in minor units (cents)

The full design rationale is in [`DESIGN.md`](./DESIGN.md). AI usage disclosed in [`AI_USAGE.md`](./AI_USAGE.md).

---

## Quick start

```bash
git clone <repo>
cd hoxton-assessment
cp .env.example .env

# Bring up app, web (nginx), worker, and Postgres.
docker compose up -d --build

# Run migrations + seed the singleton system account.
docker compose exec app php artisan migrate --seed
```

You should now be able to:

- Hit the API at `http://localhost:8080/api/v1/`
- Browse the OpenAPI / Swagger UI at `http://localhost:8080/api/documentation`
- Connect to Postgres at `localhost:5432` (user `hoxton`, db `hoxton`, password `hoxton`)

Default API keys (from `.env.example`): `dev-key-1`, `dev-key-2`. Configure via the `API_KEYS` env (comma-separated).

---

## Running the test suite

The tests run against a separate Postgres database to keep dev data clean. Create it once:

```bash
docker compose exec db psql -U hoxton -d postgres -c "CREATE DATABASE hoxton_test OWNER hoxton;"
```

Then:

```bash
docker compose exec app php artisan test
```

89 tests, ~10 seconds. See `tests/Feature/**/*Test.php` for what each covers.

---

## API reference

All endpoints are under `/api/v1/` and require the `X-Api-Key` header. Every response carries an `X-Correlation-Id` (request-scoped UUIDv7, generated if not supplied). Try the same flows interactively at `http://localhost:8080/api/documentation`.

### End-to-end walkthrough (copy-paste)

```bash
API=http://localhost:8080/api/v1
KEY="X-Api-Key: dev-key-1"
JSON="Content-Type: application/json"

# 1. Create two accounts
ALICE=$(curl -s -H "$KEY" -H "$JSON" -d '{"name":"Alice"}' $API/accounts | jq -r .id)
BOB=$(curl -s -H "$KEY" -H "$JSON" -d '{"name":"Bob"}' $API/accounts | jq -r .id)

# 2. Deposit funds (synchronous; system account is the counterparty)
curl -s -H "$KEY" -H "$JSON" -d "{\"amount\":10000,\"idempotency_key\":\"d-1\"}" \
  $API/accounts/$ALICE/deposits

# 3. Initiate an async transfer (202 Accepted; status PENDING)
T=$(curl -s -H "$KEY" -H "$JSON" \
  -d "{\"from_account_id\":\"$ALICE\",\"to_account_id\":\"$BOB\",\"amount\":3000,\"idempotency_key\":\"t-1\"}" \
  $API/transfers | jq -r .transfer_id)

# 4. Poll status until COMPLETED (worker settles in <1s)
sleep 2
curl -s -H "$KEY" $API/transfers/$T | jq

# 5. Inspect balances and ledger
curl -s -H "$KEY" $API/accounts/$ALICE | jq      # balance: 7000
curl -s -H "$KEY" $API/accounts/$BOB | jq        # balance: 3000
curl -s -H "$KEY" "$API/accounts/$ALICE/ledger?limit=10" | jq
```

### Endpoints

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/v1/accounts` | Create a new account. Body: `{"name": string}` |
| `GET`  | `/api/v1/accounts/{id}` | Get account with current balance (computed from ledger) |
| `GET`  | `/api/v1/accounts/{id}/ledger` | Cursor-paginated ledger history (newest first). Query: `limit` (1–100, default 50), `cursor` |
| `POST` | `/api/v1/accounts/{id}/deposits` | Synchronous deposit. Body: `{"amount": int, "idempotency_key": string}`. Replaying the same key returns the original transfer |
| `POST` | `/api/v1/transfers` | Initiate an async user-to-user transfer. Body: `{"from_account_id", "to_account_id", "amount", "idempotency_key"}`. 202 on first request, 200 on idempotent replay. Per-API-key throttle (default 60/min) |
| `GET`  | `/api/v1/transfers/{id}` | Get transfer status. Works for both async transfers and synchronous deposits |
| `GET`  | `/api/v1/ping` | Liveness probe. Useful for verifying API key works |

### HTTP semantics

- **401** missing/invalid `X-Api-Key`
- **404** account or transfer not found, or operation targets the system account
- **422** validation error (missing fields, non-positive amount, same `from`/`to`)
- **429** per-API-key rate limit exceeded; retry after the duration in `Retry-After`

---

## Operational commands

### Reconcile the ledger

Verifies three invariants and exits non-zero on drift:
1. Per-transfer double-entry — every COMPLETED transfer has exactly two zero-sum ledger rows.
2. Global zero-sum — signed sum of all ledger rows is exactly 0.
3. No user (non-system) account has a negative balance.

```bash
docker compose exec app php artisan ledger:reconcile
```

### Failed-job (DLQ) management

```bash
# List ProcessTransferJob entries that exhausted their retries
docker compose exec app php artisan transfers:retry-failed

# Replay a specific failed transfer
docker compose exec app php artisan transfers:retry-failed <transfer_id>
```

### Standard Laravel queue tooling

```bash
# Force-restart the worker (e.g. after deploying job code changes)
docker compose restart worker

# Drain the entire queue (one-shot processing)
docker compose exec app php artisan queue:work --once
```

---

## Important operational notes

- **The queue worker holds the framework in memory.** After modifying a Job class (e.g. `ProcessTransferJob`), run `docker compose restart worker` so the new code is loaded. Standard Laravel behaviour.
- **The Postgres `cache_locks` and `jobs` tables come from Laravel's default migrations** (kept because the queue uses the database driver). The `users` and `password_reset_tokens` migrations were removed since the API is stateless.
- **The system account** is a singleton row with `is_system = true`, enforced by a partial unique index. It serves as the counterparty for deposits (and any future withdrawal flow). It has no API surface; depositing/transferring to or from it through the API returns 404.

---

## Configuration reference

All values live in `.env` (see `.env.example`):

| Key | Default | Purpose |
|---|---|---|
| `API_KEYS` | `dev-key-1,dev-key-2` | Comma-separated list of accepted `X-Api-Key` values |
| `TRANSFER_RATE_LIMIT` | `60` | Requests per minute per API key on `POST /transfers` |
| `QUEUE_CONNECTION` | `database` | Laravel queue driver. Don't change without porting the worker config |
| `LOG_CHANNEL` | `json` | One JSON line per event in `storage/logs/laravel.json.log`, with `correlation_id`, `transfer_id`, `account_id` promoted to top level |
| `L5_SWAGGER_GENERATE_ALWAYS` | `true` | Auto-regenerate the OpenAPI spec on each Swagger UI load. Disable in production |

---

## Assumptions

- **Single currency.** All amounts are integer minor units (cents). The schema does not carry a currency column; multi-currency would require schema changes plus FX policy decisions.
- **No user model.** Accounts are nameable but not owned by anyone. Authentication is a shared API key. Real production would map keys → tenants and gate operations by ownership.
- **Stateless API.** Sessions are `array` driver (in-memory, ephemeral). No CSRF; this is a pure HTTP API for backend-to-backend traffic.
- **Synchronous deposits, asynchronous user-to-user transfers.** Deposits cannot fail (system account never overdraws), so the round-trip stays in the request handler. Transfers can fail (insufficient balance, contention) so they go through a queue.
- **Idempotency keys are global.** Reusing a key returns the original transfer regardless of body. Production would scope idempotency per (caller, key).

## Trade-offs

- **Database queue (not Redis).** The spec allows simulation; one less piece of infra in the README. Trade-off: lower throughput at very high scale; we'd swap for Redis (or SQS / Pub-Sub) for production.
- **Compute balance from ledger every read (not a cached `accounts.balance` column).** Always correct, no cache invalidation. Trade-off: O(N) per read for an account with N entries. Production would maintain a balance snapshot table updated atomically with each ledger write, with the live ledger as ground truth for reconciliation.
- **Two-phase processor (PROCESSING commits before settlement).** Makes the lifecycle observable even on crashes (a stuck `PROCESSING` transfer with no terminal event is unambiguously "we crashed mid-handle"). Trade-off: a sweeper/reconciliation job is needed to recover transfers stuck in `PROCESSING` after retry exhaustion. We have the reconciliation command but not the sweeper — see DESIGN.md §4.
- **VARCHAR + CHECK constraint instead of Postgres native ENUM.** Migrations stay portable and easy to evolve. Trade-off: ~tens of bytes per row over a 1-byte enum lookup; negligible at our target scale.
- **UUIDv7 instead of UUIDv4 for primary keys.** Time-ordered → much better B-tree index locality for an append-heavy ledger workload. Trade-off: leaks creation time. Acceptable for this domain.
- **Sequential test for concurrent-overdraft prevention.** A truly parallel test (forking processes) was punted in favour of a sequential test that proves the recompute-under-lock logic. The `lockForUpdate()` directive itself is in place but exercised only by Postgres at runtime. The reconciliation command catches any drift the lock somehow misses.

---

## Project layout

```
app/
  Concerns/HasUuidv7s.php             — model trait that auto-generates UUIDv7 PKs
  Console/Commands/                   — artisan: ledger:reconcile, transfers:retry-failed
  Domain/Exceptions/                  — typed business-rule exceptions
  Enums/                              — PHP 8.1 backed enums (matched by DB CHECK constraints)
  Http/
    Controllers/Api/V1/               — controllers, all with OpenAPI annotations
    Middleware/                       — ApiKeyAuth, CorrelationId, ForceJson
    Requests/Api/V1/                  — FormRequest validation
  Jobs/ProcessTransferJob.php         — the worker entry-point
  Logging/                            — JSON formatter + tap class
  Models/                             — Account, Transfer, LedgerEntry, AuditLog
  Services/                           — AccountService, LedgerService, TransferService, TransferProcessor
config/                               — Laravel config (logging, l5-swagger, app)
database/
  migrations/                         — accounts, transfers, ledger_entries, audit_logs, plus Laravel jobs/cache
  seeders/SystemAccountSeeder.php
docker/                               — nginx config
docker-compose.yml                    — app, web, worker, db
PLAN.md                               — task-by-task implementation plan (kept for transparency)
DESIGN.md                             — architecture, schema, event flow, failure handling, scaling, security
AI_USAGE.md                           — AI assistance disclosure
```
