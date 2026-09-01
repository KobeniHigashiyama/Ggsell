# Design Notes

## The governing principle

**Guarantees live in the database schema, not in application conventions.**
Code crashes mid-transaction, workers get duplicated, webhooks arrive fifty
times — a unique index never gets it wrong. The application layer only
organizes work around constraints that are physically impossible to violate.

A second decision follows from the first: **a delivery attempt is a first-class
entity with its own state**, not an implementation detail of an HTTP call. That
is why it has an `unknown` status, and that status is what solves the timeout
trap.

## Where each invariant physically lives

| Invariant | Mechanism | Location |
|---|---|---|
| An `event_id` is processed once | `payment_events.event_id UNIQUE` | `RecordPaymentEvent` |
| An order moves `created → paid` once | `SELECT … FOR UPDATE` + state machine | `ApplyPaymentToOrder` |
| An event outcome is never overwritten | row lock on the event itself | `ApplyPaymentToOrder` |
| An order has at most one delivery | `deliveries.order_id UNIQUE` | `CommitDelivery` |
| A code never reaches two orders | `deliveries.code UNIQUE` | `CommitDelivery` |
| A retry after timeout issues no second code | `delivery_attempts.request_id UNIQUE` + `stub.supplier_requests.request_id PK` | `FulfilOrder`, stub |
| A transport failure cannot close an open attempt | verdict accepted only with an HTTP status | `DeliveryAttempt::resolveStatus` |
| An issued code survives a crash before commit | committed from storage before any new call | `FulfilOrder::commitAlreadyIssuedCode` |
| Two runs cannot open two attempts | attempt creation under the order row lock | `FulfilOrder::openAttempt` |
| One key goes to one order | `FOR UPDATE SKIP LOCKED` + `supplier_requests.key_id UNIQUE` | `SupplierIssueController` |
| The ledger always balances | balance checked before write + `UNIQUE(ref_type, ref_id, account)` | `PostTransaction` |
| Final states are immutable | explicit transition table | `OrderStatus` |

Almost everything sits in the database. `ShouldBeUnique` on the job exists too,
but as an optimization: a cache lock can expire, a unique index cannot.

---

## Stage 2 — exactly-once under concurrency

The acceptance criterion describes fifty webhooks **for one order**, and they
may carry **different** `event_id` values — fifty events, not fifty copies of
one. Deduplication by `event_id` does not fire there at all. The row lock on
the order does the work.

Five layers, in the order they engage:

1. **`payment_events.event_id UNIQUE`.** Duplicates are caught on insert, not by
   a preceding check. Check-then-insert is itself a race: under fifty parallel
   requests, all fifty see an empty `SELECT`.
2. **`SELECT … FOR UPDATE` on the order.** Exactly one request performs
   `created → paid`; the other forty-nine wait for the lock, observe a paid
   order, and exit with `no_op`.
3. **`ShouldBeUnique` on the job.** Not a guarantee — saved work.
4. **`deliveries.order_id UNIQUE`.** The last line: even if two workers obtain a
   code, only one row exists.
5. **`FOR UPDATE SKIP LOCKED` in the supplier pool.** Parallel issuances take
   different keys instead of queueing behind the first one.

The loser at layer four is not silent: its code lands in `orphaned_codes` and
appears in the reconciliation report. It is paid-for goods and must not be
discarded.

**A defect found on the first `chaos:race --mode=same` run.** Delivery
invariants held, but the recorded outcome was wrong: concurrent repeats of one
`event_id` overwrote `outcome` from `applied` to `no_op`. The first handler had
not yet committed its result, the repeat was not filtered as a duplicate
(`processed_at` was still null), and it wrote its own outcome on top. Fixed by
locking the event row and re-checking `processed_at` under that lock. Lock
acquisition order is uniform across the system — event first, then order — so
deadlock is impossible.

### Webhook before the order

The event is accepted and stored with `processed_at = null`, answered `200`.
Replying with an error is wrong: the payment provider would retry something
that is not its fault. Replay happens on two paths — immediately after order
creation, and independently from the scheduler. The duplication is deliberate:
there is a window between "no order found" and order creation that only a
periodic sweep closes.

### Out-of-order webhooks

`orders.last_payment_event_at` holds the `occurred_at` of the last applied
event. An older event is recorded as `stale` and changes nothing.

A separate decision: **a payment that succeeds after a failure is accepted.**
`payment_failed` is nominally final, but ignoring money that has arrived would
mean taking payment and delivering nothing — the worst possible outcome. The
`payment_failed → paid` transition is permitted only for a strictly newer event.

---

## Stage 3 — the timeout trap

The entire difference between a reliable system and a double issuance fits into
the difference between two outcomes:

- **Rejection** is information. The supplier definitively issued no code. Move on.
- **Timeout** is the absence of information. The supplier may have issued a code
  whose response never arrived. Moving on is forbidden.

Everything else follows:

**Classification is asymmetric.** Only what we know for certain becomes
`Rejected`. Everything else is `Unknown`. An error toward `Unknown` is harmless:
we re-ask with the same `request_id` and get the same answer. An error toward
`Rejected` costs a second key for the same money. So `Unknown` also covers a
successful response with no code in the body and a `5xx` without a contract-shaped
error body — a `502`/`504` from a proxy may well have reached the supplier.

Connection failures are split further: if the TCP connection never opened
(`cURL error 6/7`), the request did not arrive — that is a definite rejection
and fallback is safe. But that verdict is synthetic, and it may close an attempt
only on the **first** send inside a live request cycle. On the reconciliation
path it never closes an attempt, regardless of current status: `unknown` and
`pending` mean the same thing, "the request may have arrived". Whether the
process died before or after sending cannot be recovered from the data.

**`request_id` is deterministic**: `req_{order}_{supplier}_{attempt_no}`.
`attempt_no` increments **only on a definite rejection**. Incrementing on
timeout is exactly the bug this task probes for.

**The attempt row is written before the HTTP call.** If the process dies between
sending the request and receiving the answer, the database still holds the
intent with a concrete `request_id`, and reconciliation can determine the
outcome. Without that row we would not know we ever contacted the supplier.

**Step order in `FulfilOrder`** is the substance of the class:

1. Claim the order under a lock; confirm delivery is needed.
2. Commit a code already obtained but not yet recorded.
3. **Reconcile every open attempt** — before any new request.
4. Only then walk the supplier chain.

Steps 2 and 3 before step 4 are the solution to the trap. Starting with a new
request would mean asking for a second key against the same payment.

Reconciliation deliberately ignores the circuit breaker: it creates no new
obligation, it closes an existing one.

**On the stub side** the guarantee rests on the primary key of
`stub.supplier_requests.request_id`. A repeat with the same identifier
physically cannot issue a second key. The `timeout` mode claims the key and
registers the request **before** the delay — otherwise it would simulate a
rejection rather than a trap.

---

## Stage 4 — ledger, reconciliation, recovery

**Double-entry with signed amounts.** Debits positive, credits negative, so
balance is a plain `SUM() = 0`. An unbalanced set is rejected **before** the
database is touched: there is nowhere to write a transaction that does not
balance.

| Event | Entries |
|---|---|
| Payment | Dr `cash` / Cr `customer_liability` |
| Delivery | Dr `customer_liability` / Cr `revenue` |

Revenue is recognized at delivery, not at payment: until delivery we owe goods.

Idempotency comes from `UNIQUE(ref_type, ref_id, account)`. Recovery and
background retries travel the same paths as the main flow and must be free to
re-post without duplicating money.

**The most substantive check** is not entry balance (that is structural) but the
`customer_liability` balance against the sum of orders that are paid and not yet
delivered. Those two numbers come from different tables by different routes and
must match to the kopeck. Any exactly-once error — under-delivery or double
delivery — breaks the equality immediately. It is computed per currency:
summing rubles with yen into one number would produce a check that balances by
accident.

**The precise scope of the guarantee.** The ledger balances by construction, and
its liability balance is reconciled against order state. But ledger balance is
internal consistency, not a guarantee about money: payments the system declined
to apply, and payments reversed after delivery, fall outside it. That is exactly
why the report carries separate `payments_not_applied` and `payments_reversed`
checks — without them the ledger would balance cheerfully during a direct loss.

**The whole report reads one snapshot** in `REPEATABLE READ`. Otherwise two
checks would see the database at different moments, and an ordinary payment
committing between them would produce a false discrepancy — and a false alert
quickly teaches people to ignore the real one. Counts are computed without
`LIMIT`: returning the size of a truncated sample as `count` yields a metric
that saturates and lies to monitoring precisely when things are worst.

**Findings can be closed.** `orphaned_codes` is cleared through
`ops:resolve-orphan` or `POST /ops/orphaned-codes/{id}/resolve`, with a
mandatory description of what was done with the code. The system decides nothing
automatically here and should not: returning a key to the supplier pool is
impossible (the pool is not ours), and writing it off is a financial decision.
But without a way to close what has been handled, the report would stay red
forever, and every subsequent discrepancy would go unnoticed with it.

`duplicate_payments` is **informational** and does not clear the green status.
Telling a genuine double charge from a re-send under a new identifier is
impossible from webhook data alone, and the acceptance scenario "fifty
concurrent webhooks for one order" is literally fifty distinct `event_id`s with
status `paid`.

**Recovery has no logic of its own.** `orders:resolve-stuck` enqueues the same
job the payment webhook does. A repair path that differs from the main path will
inevitably drift from it and become a source of duplication exactly when it is
used. The limiter is `fulfilment_runs`; manual redelivery resets it, so a human
is never blocked by an exhausted budget.

**Logging** uses dedicated `payments` and `delivery` channels, JSON format, and a
`correlation_id` carried into the job payload. An order's history reassembles
with a single grep even though it crosses three processes.

---

## Stage 5 — catalog under load

Measured on 50,000 SKUs and 400,000 supplier keys, PostgreSQL 17.

### Design

**Stock is a denormalized counter in a separate narrow table.** Not a column on
`products`: the counter changes on every issuance, and keeping it inside a row
covered by the storefront index would defeat HOT updates and inflate the index
on the hot write path.

**Partial covering index:**

```sql
CREATE INDEX products_showcase_idx ON products (type, sort_rank, sku)
    INCLUDE (name, price_minor, currency, image) WHERE is_active;
```

Column order matches `ORDER BY`, so there is no separate `Sort` step. `INCLUDE`
covers the whole storefront row list, so `Heap Fetches: 0`. `WHERE is_active`
keeps the index from growing with the archive.

**Keyset pagination** via tuple comparison `(sort_rank, sku) > (?, ?)` — a single
range scan on the index.

**Availability is a flag on `products`, not a counter.** The "in stock only" view
is the primary storefront view: sold-out items are not shown. Filtering by the
counter on the joined table applies the predicate as a `Filter` after the join,
and `LIMIT` stops being served by an index range: to collect 25 rows PostgreSQL
walks the product index until it has enough. On a largely sold-out catalog — the
normal state of a key marketplace — this degenerates into a full index scan, and
the constant per-page cost that keyset was chosen for disappears.

The flag may live in the index while the counter may not, because of write
frequency. `available_count` changes on every issuance; `in_stock` flips only
when crossing zero, orders of magnitude less often. The update statement writes
a row only when the flag actually changes, so the vast majority of deliveries
never touch `products` at all.

### Measurements

Hot storefront query:

```
Limit (actual time=0.128..0.471 rows=25)
  Buffers: shared hit=105
  ->  Nested Loop
        ->  Index Only Scan using products_showcase_idx on products
              Index Cond: ((type = 'key') AND (ROW(sort_rank, sku) > ROW(30000, 'LOAD-030000')))
              Heap Fetches: 0
              Buffers: shared hit=5
        ->  Index Scan using product_stock_pkey on product_stock (loops=25)
Execution Time: 0.685 ms
```

`Heap Fetches: 0` requires a populated visibility map, so the load seeder ends
with `VACUUM ANALYZE`. Without the vacuum, an Index Only Scan still visits the
heap for every row and the number does not reproduce.

The naive alternative — `COUNT(*)` over the key pool plus `OFFSET`:

```
Limit (actual time=163.070..164.721 rows=25)
  Buffers: shared hit=4471
  ->  Sort (rows=7525)                       ← sorted 7525, returned 25
        ->  Finalize HashAggregate (rows=12500)
              ->  Parallel Seq Scan on supplier_keys (rows=133333, loops=3)
Execution Time: 164.721 ms
```

**170× slower, 43× more buffers.** Two independent sources of degradation:
aggregation over 400,000 rows on every storefront render, and `OFFSET` forcing
PostgreSQL to read and discard everything it skips.

Per-page cost by depth:

| Depth | Keyset | OFFSET |
|---|---|---|
| ~100 | 0.95 ms | 1.8 ms |
| ~25,000 | 1.00 ms | 150 ms |
| ~49,000 | 0.88 ms | 150 ms |

Keyset costs the same on any page — the property it was chosen for.

"In stock only" storefront, catalog 95% sold out (2,812 available of 50,012):

| | Counter on joined table | Flag on `products` |
|---|---|---|
| Time | 3.21 ms | **0.38 ms** |
| Buffers | 1,661 | **104** |
| Index rows read for 25 results | 409 | **25** |

The first form's cost grows as the catalog sells out: at 5% available it reads
409 rows for 25; at 1% it would read roughly two thousand. The second always
reads exactly 25. In the degenerate case — a page past the end of available
items — the gap reaches 361 ms / 192,077 buffers versus 0.08 ms / 4 buffers.

---

## How this would scale

**Queue.** Currently `database` on PostgreSQL (`FOR UPDATE SKIP LOCKED`) — one
component instead of two, with honest semantics. At volumes where the queue
starts competing with business load for the same cluster: Redis plus Horizon,
with separate queues per supplier so a slow supplier does not occupy the fast
one's workers.

**Storefront.** The first step is not a cache but a materialized view or a
purpose-built denormalized table for the specific screen, refreshed
incrementally. A cache in front of a 1 ms query buys less than the invalidation
complexity costs. Past a few million SKUs: partition `products` by type and move
search into a dedicated store.

**Orders and attempts.** `orders` and `delivery_attempts` grow linearly with
turnover, but the working set is only unsettled orders, already separated by
partial indexes. Next step: monthly partitioning with cold partitions archived;
the partial indexes let this happen without changing any query.

**Suppliers.** The circuit breaker currently uses the shared cache. Under
horizontal growth: move its state to Redis with a proper window and half-open
state, plus a per-supplier concurrency budget (bulkhead) so one slow supplier
cannot consume the shared worker pool.

**Database.** Read replicas for the storefront and for reconciliation — both
tolerate lag. The money path stays on the primary: it reads what it just wrote.

**Observability.** Logs are already structured and joined by `correlation_id`.
Next: metrics (share of `unknown` per supplier, age of the oldest unresolved
order, `customer_liability` balance) and an alert on a non-empty reconciliation
result. `GET /ops/reconciliation` returns `409` on a discrepancy precisely so it
can be wired to monitoring without parsing the body.

---

## Adversarial review

The finished solution was put through three independent review rounds. This
section records what they found, because the findings are more informative than
a clean report would have been. Every fix is covered by a regression test; for
the key ones it was verified that the test turns red when the bug is restored.

### Round one — implementation

**Two paths to a second issuance.**

1. *A transport failure closed a suspended attempt.* "Connection refused" on the
   first send honestly means the request never arrived. On a retry of a request
   that already timed out it means only "we could not re-ask" — the supplier may
   by then hold an issued key. A verdict is now accepted only when the supplier
   itself rendered it; the marker is the presence of an HTTP status.
2. *A code obtained but not recorded.* Saving the successful response and
   writing the delivery are two transactions. A crash between them left the
   attempt `succeeded` — not suspended, therefore invisible to reconciliation —
   and the next run opened a new `request_id`, taking a second key.

**Three ways to lose a payment.** An invalid webhook was rejected with 422 and
no trace (the provider retries only on `5xx`, so the event was gone forever);
amounts serialized as `"500.00"` — routine for payment gateways — were rejected
outright; the idempotency key was released on `5xx` even though the order was
already committed, so a client retry created a second order.

**Blind spots in reconciliation.** The `mismatch` and `pending_order` outcomes
appeared in no check at all, though they are literally "money arrived, no goods".
A code comment claimed otherwise.

### Round two — reviewing the fixes

Fresh code is more dangerous than old code; it has not settled.

**A fix that broke what it fixed.** To stop reconciliation-only runs from
consuming the retry budget, the `fulfilment_runs` increment was moved out of
order claim. Consequence: a run that reduced to a single reconciliation stopped
consuming budget at all, so an order whose attempt cannot leave `unknown` was
retried by the scheduler forever, and the `run_budget_exhausted` signal became
unreachable for exactly the class of orders it was written for. Reverted; the
original complaint is addressed from the other side — manual redelivery resets
the counter.

**Reconciliation red on healthy data.** The `payments_reversed` check selected
events by the mere presence of a failure with a non-null `paid_at`. That caught
both a stale failure the domain had deliberately discarded and the legitimate
"card declined, then paid with another card" flow. Row granularity was per event
while the amount came from the order, so two failures doubled the reported loss.
On the running stack the report claimed a 6,980 ₽ loss on a delivered 3,490 ₽
order and never cleared.

**A rule that protected half the cases.** The ban on transport failures closing
an attempt guarded only `unknown`, but `pending` means the same thing.

**A swallowed result.** `commitAlreadyIssuedCode` ignored a `false` return from
the commit, leaving the order in `delivering` with no failure status while the
scheduler added a new `orphaned_codes` row every minute.

**A permanently locked idempotency key.** Refusing to release the key on `5xx`
(correct in itself) created a state with no exit. An in-flight window now
releases a stuck key after five minutes.

### Round three — acceptance against the task

A separate review played the customer: it ran all six acceptance criteria by
hand and verified results with independent SQL rather than trusting
`chaos:race`. All six passed, including a scenario absent from the criteria that
the reviewer added: `SIGKILL` of the worker exactly between writing the intent
and the supplier's response, with the key already claimed. The system reconciled
with the same `request_id` and issued the code once.

It also found the most serious defect of all three rounds: **a recorded delivery
did not move the order to `delivered`.** The result of `tryTransitionTo` was
unchecked, so if the order had meanwhile moved to `out_of_stock` (a parallel run
declaring a shortage after this one obtained a code), the delivery was written,
`delivered_at` was set and revenue recognized — while the status stayed put. The
customer saw an order with no code but a delivery date; recovery did not repair
it and reconciliation did not see it. The transition is now mandatory, and a
`delivered_not_settled` check stands guard over that requirement.

Also found: two parallel runs claimed two keys (`attempt_no` computed outside a
lock); `in_stock=true` returned 422 because Laravel's `boolean` rule rejects the
string form that query strings actually produce; a second successful payment
under a different `event_id` was invisible.

**On the environment.** During verification the Docker bind mount was found to
serve the container a stale copy of a file after `sed -i` and `cp` — both replace
the inode, and the container kept the old contents until restart. One control
run produced a false green because of it. After discovery the source tree was
compared by checksum between host and container and the whole suite re-run.

---

## Deliberately not done

- **Stock reservation at order creation.** The pool belongs to the supplier; an
  honest reservation would be a distributed lock on someone else's resource with
  timeout-based release. Large complexity for unpaid orders, which are the
  majority. The task explicitly describes "paid but no code" as a normal
  recoverable outcome, and the system follows that path.
- **Refunds.** A failure on an order whose money was received is logged and
  surfaced in reconciliation (`payments_reversed`), but nothing is reversed
  automatically: a refund is a separate financial flow, not a status rollback.
- **Webhook signature verification** — excluded by the task.
- **Authentication.** The `ops` endpoints would be closed in a real system; here
  they are open so a reviewer can call reconciliation with a single curl. Product
  codes are masked out of the report regardless.

## Departure from the letter of the task

The supplier stubs share a process with the core rather than running as a
separate deployment. The boundary that matters for correctness is intact: their
own PostgreSQL schema, no shared models, no foreign keys pointing at core
tables, and communication only over HTTP through nginx with real timeouts. A
separate deployment would add two containers without changing a single property
under test.
