# GgSell Digital Goods Backend

Backend core for a digital-goods marketplace: catalog, multi-item orders, payment
webhooks, supplier integrations, automatic code delivery, refunds, reconciliation
and recovery. Payment and suppliers are stubs, as the assignment specifies.

The design centres on guarantees enforced by data and transactions rather than by
convention:

- exactly-once delivery under concurrency, and safe retries after an ambiguous
  supplier timeout;
- a partly undeliverable order settling honestly — what was delivered stays, the
  rest is refunded, and the money always adds up;
- a supplier that duplicates, substitutes or hides codes harming no customer;
- a spike becoming a queue rather than a wave of failures.

Stack: PHP 8.5, Laravel 13, PostgreSQL 17, nginx, PHP-FPM, PostgreSQL-backed queue.

## Quick Start

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

curl -s 'http://localhost:8000/api/v1/products?limit=3'
```

The API is on `localhost:8000`, PostgreSQL on `localhost:55432`. Containers:
`web` (nginx), `app` (PHP-FPM), `worker` (delivery and settlement queues),
`scheduler` (recovery, settlement and audit sweeps), `postgres`. The examples
below use `jq` and `uuidgen`.

## API

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/products` | Storefront with stock and keyset pagination |
| `POST` | `/api/v1/orders` | Create an order of one or many products; accepts `Idempotency-Key` |
| `GET` | `/api/v1/orders/{id}` | Read an order and its lines; a code appears only once that line is delivered |
| `POST` | `/api/v1/webhooks/payment` | Payment webhook |
| `GET` | `/api/v1/ops/reconciliation` | `200` when consistent, `409` on discrepancies |
| `GET` | `/api/v1/ops/delivery/progress` | Backlog, queue depth, remaining supplier allowance |
| `GET` | `/api/v1/ops/orders/{id}/at?at=` | The order and its money at a past moment |
| `GET` | `/api/v1/ops/reports/period?from=&to=` | Period totals from the event log, cross-checked against the ledger |
| `POST` | `/api/v1/ops/orders/{id}/redeliver` | Manual recovery for the unsettled lines |
| `POST` | `/api/v1/ops/orphaned-codes/{id}/resolve` | Resolve a stranded code by hand |
| `POST` | `/api/suppliers/{a\|b}/issue` | Supplier stub: request a code |
| `GET` | `/api/suppliers/{a\|b}/requests/{request_id}` | Supplier stub: what it did with a request |
| `POST` | `/api/suppliers/{a\|b}/return` | Supplier stub: hand a code back |
| `GET` | `/api/suppliers/{a\|b}/stock` \| `/rate` | Supplier stub: stock and per-minute counters |
| `POST` | `/api/payments/refund` | Payment gateway stub |

Both order shapes are accepted. The stage-1 body still works unchanged, and
`quantity` expands into one deliverable line per unit because each unit needs its
own code:

```bash
curl -s -X POST localhost:8000/api/v1/orders -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" -d '{"sku":"KEY-CS2-PRIME"}'

curl -s -X POST localhost:8000/api/v1/orders -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"items":[{"sku":"KEY-CS2-PRIME"},{"sku":"KEY-EFT","quantity":2}]}'
```

End to end: create an order, pay it, read the result.

```bash
ORDER=$(curl -s -X POST localhost:8000/api/v1/orders -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" -d '{"sku":"KEY-CS2-PRIME"}' | jq -r .data.order_id)

curl -s -X POST localhost:8000/api/v1/webhooks/payment -H 'Content-Type: application/json' \
  -d "{\"event_id\":\"evt_$RANDOM\",\"order_id\":\"$ORDER\",\"status\":\"paid\",\
       \"amount\":1290,\"currency\":\"RUB\",\"created_at\":\"$(date -Iseconds)\"}"

sleep 3 && curl -s localhost:8000/api/v1/orders/$ORDER | jq
```

## Reproducing a Partial Order Failure

One command creates a two-product order, empties the supplier pools for one of
them so the outcome is deterministic, pays, and checks the result:

```bash
docker compose exec app php artisan chaos:partial
```

```
| 1 | KEY-CS2-PRIME | delivered | YPLV-QK2Z-IUS5 |
| 2 | KEY-EFT       | refunded  | -              |

| Order reached a terminal state      | partially_delivered      | OK |
| Paid equals delivered plus refunded | 478000 = 129000 + 349000 | OK |
| Nothing is still owed on this order | 0                        | OK |
| Money adds up system wide           | 0                        | OK |
```

Repeat any step and nothing changes: `orders:resolve-stuck` re-runs delivery,
`orders:settle` re-runs settlement, `orders:reconcile` stays clean. To see it
survive a crash mid-delivery, restart the worker while an order is being
fulfilled (`docker compose stop worker && docker compose start worker`); recovery
commits a code that was already issued rather than buying a second one.
`MultiItemRecoveryTest` covers that sequence deterministically.

## Reproducing a Dishonest Supplier

Supplier A is switched into a dishonest mode while B stays honest, so customers
are still served while A misbehaves:

```bash
docker compose exec app php artisan chaos:untrusted duplicate
docker compose exec app php artisan chaos:untrusted foreign_code
docker compose exec app php artisan chaos:untrusted error_but_issued
```

```
| Supplier misbehaved as instructed        | duplicate: 2 violations   | OK |
| One code never reached two customers     | 3/3 distinct              | OK |
| Every customer holds exactly one code    | 3/3 delivered, 3 distinct | OK |
| Discrepancies closed without an operator | 0                         | OK |
| Stranded codes returned to the supplier  | 0                         | OK |
| Money still adds up                      | 0                         | OK |
```

- `duplicate` — hands over a code it already gave to another order. The global
  `deliveries.code UNIQUE` makes a second delivery impossible; that customer is
  served from supplier B instead.
- `foreign_code` — hands over a code from another product's pool. The response
  names the SKU the code belongs to, and the mismatch is refused before delivery.
- `error_but_issued` — issues the code and then reports failure. Asking again
  under the same `request_id` returns that code rather than consuming a second
  one, and `ops:auto-resolve` audits reported failures against the supplier's own
  registry: a hidden code is delivered when its line still waits, otherwise it is
  quarantined and handed back — unless a customer already holds it.

`chaos:supplier a duplicate` drives the stubs by hand; `chaos:supplier a random`
restores them.

## Spikes and Mass Failure

Suppliers accept 30 requests per minute each by default. Sixty orders at once:

```bash
docker compose exec app php artisan chaos:burst --n=60
```

Nothing fails and nothing is dropped: over the limit, lines are released back
onto the queue and delivered as the next window opens. The core counts requests
the same way the supplier does, so the two cannot disagree under load, and the
suppliers' own counters report zero rejections.

The harder case is a large batch already in flight when a supplier stops
behaving:

```bash
docker compose exec app php artisan chaos:storm --n=40 --fail=timeout
docker compose exec app php artisan chaos:storm --n=40 --fail=error_but_issued
docker compose exec app php artisan chaos:storm --n=25 --fail=out_of_stock --fail-both
```

```
| Every order reached a terminal state | 0                               | OK |
| Every line settled                   | 40 delivered + 0 refunded of 40 | OK |
| No code delivered twice              | 40/40 distinct                  | OK |
| Every consumed key accounted for     | 0                               | OK |
| Supplier limits never exceeded       | 0                               | OK |
| Money adds up                        | 0                               | OK |
```

The inventory check is the strongest one: every key the supplier consumed must be
delivered, recorded as stranded, or attached to a line still in play. A key that
is none of those was paid for and lost, and nothing else would notice.
`--fail-both` takes the whole supply chain down, so the part of the batch that
was not delivered before the outage comes back as refunds. Orders reach a
terminal state before the incident is closed, so the run keeps going until
reconciliation is clean.

## Checking That the Money Adds Up

The invariant is **paid = delivered + refunded + still owed**, computed twice by
independent routes — once from order and line rows, once from the ledger — and
compared per currency:

```bash
curl -s 'localhost:8000/api/v1/ops/reconciliation?grace_seconds=30' \
  | jq '.checks.money_conservation'
```

`count: 0` means every delivery and every refund was recorded exactly once; a
non-zero delta names which of the five identities broke. Neighbouring checks
cover what the identity cannot see alone: `refunds_unfinished` reports money the
gateway never confirmed, `supplier_violations` reports open incidents. The
endpoint answers `200` when the whole report is healthy and `409` otherwise, so
monitoring need not parse the body. `php artisan orders:reconcile --grace=30`
prints the same thing.

Period totals come from the append-only log and are cross-checked against the
ledger, with revenue and cash kept as separate numbers because a refund reduces
the second and never the first:

```bash
curl -s "localhost:8000/api/v1/ops/reports/period?from=$(date -Iseconds -d '1 hour ago')&to=$(date -Iseconds)" | jq
curl -s "localhost:8000/api/v1/ops/orders/$ORDER/at?at=$(date -Iseconds -d '5 min ago')" | jq
```

History is append-only at the database level: triggers on `order_events` and
`ledger_entries` refuse `UPDATE`, `DELETE` and `TRUNCATE`.

## Tests

```bash
docker compose exec app php artisan test                         # 131 unit + feature
docker compose exec app php artisan test --testsuite=Integration # 11 scenarios
```

Tests run on PostgreSQL rather than SQLite because row locks, `SKIP LOCKED`,
partial indexes, triggers and constraint behaviour are part of the
implementation. The `Integration` suite drives the running stack by running each
scenario command above as a subprocess, so the suite and this README cannot
drift apart; it resets the application database between scenarios, so restore it
afterwards with `migrate:fresh --seed`.

| Requirement | Coverage |
|---|---|
| Several products in one order, delivered lines stay, the rest refunded | `PartialOrderSettlementTest`, `chaos:partial` |
| Paid equals delivered plus refunded, always | `money_conservation`, asserted in every settlement test |
| Any step repeatable without a second delivery or refund | `RefundSafetyTest`, `MultiItemRecoveryTest` |
| A terminal state after a crash mid-delivery | `MultiItemRecoveryTest`, `AuditHardeningTest` |
| One code never reaches two customers | `UntrustedSupplierTest`, `chaos:untrusted duplicate` |
| A retry after a failure that hid a code issues nothing new | `SupplierStubContractTest`, `UntrustedSupplierTest` |
| Discrepancies resolve without an operator | `UntrustedSupplierTest`, `ops:auto-resolve` |
| A spike queues instead of failing, and the limit holds | `SupplierRateLimitTest`, `chaos:burst` |
| Mass delivery while a supplier times out, lies or dies | `MassDeliveryUnderFailureTest`, `chaos:storm` |
| Order and money state at any past moment; history append-only | `OrderHistoryTest` |
| Fifty concurrent paid webhooks produce one delivery | `chaos:race`, `ParallelWebhookRaceTest` |

## Operational Commands

```bash
php artisan orders:reconcile [--json] [--grace=60]
php artisan orders:resolve-stuck        # requeue stuck delivery lines
php artisan orders:settle               # refund lines that can no longer be delivered
php artisan ops:auto-resolve            # audit suppliers, return stranded codes
php artisan ops:wait-idle               # block until the delivery queues drain
php artisan ops:resolve-orphan --list
php artisan payments:replay
php artisan stock:refresh
php artisan catalog:seed-load --skus=50000   # 50k SKUs, 400k keys; plans in NOTES.md
```

Structured logs share a `correlation_id` across webhook receipt, payment
application, queue processing, supplier requests and refunds:
`tail -f storage/logs/{payments,delivery}-$(date +%F).log`.

## Time Spent

Roughly 6 hours for stage 1 and 13 on top of it for stage 2, a meaningful share
of the latter spent auditing the result against its own claims. That found five
defects worth the time on their own, three of them covered by tests that were
passing for the wrong reason. [NOTES.md](NOTES.md) records what each was.

## A Small Change on a Call

Happy to. The places most likely to come up are small and isolated on purpose:
the status derivation is one pure function (`OrderStatus::deriveFrom`), the
settlement policy one query (`SettleUnfulfillableItems::eligible`), and the
supplier trust rules one action (`ValidateSupplierCode`).
