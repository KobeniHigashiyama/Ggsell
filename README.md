# GgSell Digital Goods Backend

Backend core for a digital-goods marketplace. It provides a catalog, order
creation, payment webhooks, supplier integrations, automatic code delivery,
reconciliation, and recovery workflows.

The design centers on three guarantees that must be enforced by data and
transactions rather than application conventions:

- exactly-once delivery under concurrent requests;
- safe retries after an ambiguous supplier timeout;
- a balanced financial ledger tied to order state.

Stack: PHP 8.5, Laravel 13, PostgreSQL 17, nginx, PHP-FPM, and a PostgreSQL-backed
queue.

## Quick Start

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The API is available at `http://localhost:8000`. PostgreSQL is exposed on
`localhost:55432`.

```bash
curl -s 'http://localhost:8000/api/v1/products?limit=3'
```

Containers:

- `web`: nginx;
- `app`: PHP-FPM;
- `worker`: delivery queue;
- `scheduler`: recovery and reconciliation;
- `postgres`: application and test databases.

## API

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/products` | Storefront with stock and keyset pagination (`type`, `in_stock`, `cursor`, `limit`) |
| `POST` | `/api/v1/orders` | Create an order by SKU; accepts `Idempotency-Key` |
| `GET` | `/api/v1/orders/{order_id}` | Read an order; the code is returned only after delivery |
| `POST` | `/api/v1/webhooks/payment` | Receive a payment webhook |
| `GET` | `/api/v1/ops/reconciliation` | Return `200` when consistent or `409` on discrepancies |
| `POST` | `/api/v1/ops/orders/{order_id}/redeliver` | Trigger manual order recovery |
| `POST` | `/api/v1/ops/orphaned-codes/{id}/resolve` | Resolve an orphaned supplier code |
| `POST` | `/api/suppliers/{a\|b}/issue` | Request a code from a supplier stub |
| `GET` | `/api/suppliers/{a\|b}/stock` | Read supplier stock |

## End-to-End Example

```bash
# 1. Create an order.
ORDER=$(curl -s -X POST localhost:8000/api/v1/orders \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"sku":"KEY-CS2-PRIME"}' | jq -r .data.order_id)

# 2. Send a payment webhook.
curl -s -X POST localhost:8000/api/v1/webhooks/payment \
  -H 'Content-Type: application/json' \
  -d "{\"event_id\":\"evt_$RANDOM\",\"order_id\":\"$ORDER\",\"status\":\"paid\",\
       \"amount\":1290,\"currency\":\"RUB\",\"created_at\":\"$(date -Iseconds)\"}"

# 3. Read the result.
sleep 3
curl -s localhost:8000/api/v1/orders/$ORDER | jq
```

## Reliability Scenarios

### Concurrent Payment Webhooks

```bash
docker compose exec app php artisan chaos:race --n=50 --mode=distinct
docker compose exec app php artisan chaos:race --n=50 --mode=same
```

`distinct` sends different event IDs for one order, exercising order row
serialization. `same` sends repeated copies of one event, exercising event
deduplication. The command checks persisted facts rather than trusting HTTP
responses:

- every event was recorded;
- exactly one delivery exists;
- exactly one supplier key was consumed;
- exactly one payment event was applied;
- ledger transactions balance;
- customer liability reaches zero after delivery;
- the order ends in `delivered`.

Any invariant violation returns a non-zero exit code.

The command pins both supplier stubs to `ok`, clears their circuit breakers, and
tops the key pools back up before firing, restoring the previous stub modes
afterwards. The property under test is exactly-once under concurrency, not
supplier availability: with the stubs left random a run can legitimately end in
`delivery_failed` because a timeout stayed unresolved, which is correct behavior
and not a broken invariant. Pass `--chaos` to keep whatever modes are currently
set.

### Supplier Failure and Fallback

```bash
docker compose exec app php artisan chaos:supplier a error
docker compose exec app php artisan chaos:supplier b ok
```

Create and pay for an order after setting these modes. Supplier A returns a
definitive failure, supplier B delivers, and only one delivery is committed.

### Timeout Trap

```bash
docker compose exec app php artisan chaos:supplier a timeout
```

In timeout mode, the stub consumes and records a key before delaying the
response. The caller therefore cannot treat a timeout as rejection. Retries use
the same `request_id`, causing the supplier to return the same code rather than
issuing another one.

Restore random behavior with:

```bash
docker compose exec app php artisan chaos:supplier a random
```

### Empty Stock

```bash
docker compose exec app php artisan chaos:supplier a out_of_stock
docker compose exec app php artisan chaos:supplier b out_of_stock
```

The order enters recoverable `out_of_stock` instead of failing permanently.
After inventory is replenished, background recovery can reach `delivered`
without duplicate delivery.

### Reconciliation

```bash
docker compose exec app php artisan orders:reconcile --grace=30
curl -s 'localhost:8000/api/v1/ops/reconciliation?grace_seconds=30' | jq
```

The report compares payment events, order state, deliveries, supplier attempts,
orphaned codes, and ledger balances using a consistent database snapshot.

## Tests

```bash
docker compose exec app php artisan test
docker compose exec app php artisan test --testsuite=Unit
docker compose exec app php artisan test --testsuite=Feature
docker compose exec app php artisan test --testsuite=Integration
```

Tests use PostgreSQL rather than in-memory SQLite because row locks,
`SKIP LOCKED`, partial indexes, and PostgreSQL constraint behavior are part of
the implementation.

The `Integration` suite requires the running Docker stack. It targets the real
HTTP endpoint through nginx and therefore uses the stack's application database.
It creates real orders and consumes supplier-stub keys. Restore a clean state
with:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Acceptance coverage:

| Requirement | Coverage |
|---|---|
| Fifty concurrent paid webhooks produce one delivery | `chaos:race`, `ParallelWebhookRaceTest` |
| Repeated `event_id` changes nothing | `PaymentWebhookTest` |
| Out-of-order or pre-order webhook is handled | `PaymentWebhookTest` |
| Timeout after supplier issuance does not duplicate delivery | `TimeoutTrapTest`, `SupplierStubContractTest` |
| Supplier A failure falls back to B exactly once | `SupplierFallbackTest` |
| Empty stock creates a recoverable state | `SupplierFallbackTest` |

## Catalog Load Test

```bash
docker compose exec app php artisan catalog:seed-load --skus=50000 --keys-per-sku=4
```

This creates 50,000 SKUs and 400,000 supplier keys. The storefront uses a narrow
stock projection, keyset pagination, and partial covering indexes. Recorded
plans and design details are documented in [NOTES.md](NOTES.md).

## Operational Commands

```bash
php artisan orders:reconcile [--json] [--grace=60]
php artisan orders:resolve-stuck
php artisan payments:replay
php artisan stock:refresh
php artisan chaos:race --n=50 --mode=distinct [--chaos]
php artisan chaos:supplier a timeout
php artisan catalog:seed-load --skus=50000
php artisan ops:resolve-orphan --list
```

Structured logs share a `correlation_id` across webhook receipt, payment
application, queue processing, and supplier requests:

```bash
docker compose exec app tail -f storage/logs/payments-$(date +%F).log
docker compose exec app tail -f storage/logs/delivery-$(date +%F).log
```

## Time Spent

Roughly 6 hours end to end.

## Further Reading

[NOTES.md](NOTES.md) explains the data invariants, concurrency model, timeout
handling, reconciliation checks, performance decisions, and the scaling plan.
