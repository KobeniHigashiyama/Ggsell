# Design Notes

Why the code looks the way it does. README covers running it; this covers the
decisions I'd expect to be asked about.

## The idea everything follows from

Guarantees belong in the schema, not in application conventions. Code dies
mid-transaction, workers duplicate, a webhook arrives fifty times — a unique
index doesn't care. The application layer only arranges work around constraints
the database won't let it break.

Smaller decision that does most of the work: a delivery attempt is its own row
with its own state, not a variable inside an HTTP call. That's what makes an
`unknown` outcome representable at all.

| Guarantee | What enforces it |
|---|---|
| One `event_id` applied once | `payment_events.event_id UNIQUE` |
| An order paid once | `SELECT … FOR UPDATE` on the order row |
| One delivery per order | `deliveries.order_id UNIQUE` |
| One order per code | `deliveries.code UNIQUE` |
| A retry after timeout issues no second key | `delivery_attempts.request_id UNIQUE`, same on the supplier side |
| The ledger balances | checked before insert, `UNIQUE(ref_type, ref_id, account)` |

## Exactly-once

The acceptance case is fifty webhooks for one order carrying fifty *different*
`event_id`s, so deduplication by `event_id` does nothing there. The row lock is
what stops them: one request performs `created → paid`, the other forty-nine
wait, see a paid order, return `no_op`. Under that sits `deliveries.order_id
UNIQUE` as a last resort, and the supplier pool uses `FOR UPDATE SKIP LOCKED` so
parallel issuances take different rows.

Duplicates are caught on insert, never by a preceding `SELECT` — check-then-insert
is itself a race, since fifty concurrent requests all see nothing.

The event row is locked too, not just the order, or concurrent repeats of one
`event_id` overwrite each other's recorded outcome and the journal ends up
claiming the event did nothing. Locks always go event first, then order, so
nothing deadlocks.

`POST /orders` takes an `Idempotency-Key`. A server error doesn't release it: the
order may already be committed and a retry would create a second one. That alone
would wedge the key forever, so a stuck key clears after five minutes.

## The timeout trap

The part worth reading.

A rejection is information: nothing was issued, move on. A timeout is the absence
of information: the supplier may hold a key whose response never arrived.
Treating the second as the first is how you buy two keys with one payment.

Classification is therefore lopsided. Only a verdict the supplier actually
rendered counts as rejection, and the marker is an HTTP status. Everything else
is `unknown`: read timeouts, a 200 with no code, a 502 with no contract-shaped
error. Being wrong toward `unknown` costs one harmless re-ask; being wrong the
other way costs a key.

Connection refused is the exception since nothing was sent, but that verdict is
synthetic, so it may only close an attempt on the first send. During
reconciliation it never closes one: `pending` and `unknown` mean the same thing,
and nothing in the data separates a process that died before sending from one
that died after.

`request_id` is `req_{order}_{supplier}_{attempt_no}`, and `attempt_no` moves
only on a real rejection. Incrementing it on a timeout is exactly the mistake
that produces the second issuance. The attempt row is written before the HTTP
call, so if the process dies mid-flight the intent survives with a concrete
`request_id` and reconciliation can still find out what happened.

`FulfilOrder` claims the order, commits any code already obtained but not
recorded, reconciles every open attempt, and only then talks to a new supplier.
That last step being last is the whole solution.

The stub plays fair: `stub.supplier_requests.request_id` is a primary key, so a
repeat cannot produce a second key. Its `timeout` mode claims the key *before*
sleeping, otherwise it would simulate a rejection rather than a trap.

## Money and reconciliation

Double-entry, signed amounts, balance is `SUM() = 0`. Payment is Dr `cash` / Cr
`customer_liability`; delivery is Dr `customer_liability` / Cr `revenue`. Revenue
lands at delivery, not payment, because until delivery we owe goods.

The check I care about isn't entry balance, which is structural. It's the
`customer_liability` balance against the sum of orders paid and not delivered:
two numbers from different tables by different routes, matching to the kopeck.
Any exactly-once bug breaks it immediately. A balanced ledger is still only
internal consistency — payments the system declined to apply, and payments
reversed after delivery, sit outside it, hence separate checks for both.

The report reads one `REPEATABLE READ` snapshot and counts without `LIMIT`, and
findings close through `ops:resolve-orphan`: a report that can't be cleared stays
red and stops being read. Recovery has no logic of its own — `orders:resolve-stuck`
enqueues the same job the webhook does, because a repair path that differs from
the main path drifts from it and starts duplicating deliveries exactly when
someone needs it.

## Catalog performance

50,000 SKUs, 400,000 keys, PostgreSQL 17.

Stock is a counter in a narrow side table, not a column on `products`: it changes
on every issuance and would wreck HOT updates on the row the storefront index
covers. That index is partial and covering with column order matching
`ORDER BY`, so there's no sort step and `Heap Fetches: 0`. Pagination is keyset
via tuple comparison.

Storefront query: **0.685 ms, 105 buffers**. The obvious alternative, `COUNT(*)`
over the key pool with `OFFSET`, is **164 ms and 4,471 buffers** on the same
data. Keyset holds ~0.9 ms at any depth; `OFFSET` goes from 1.8 ms to 150 ms by
page 1,000.

"In stock only" filters on a boolean flag on `products`, not on the counter.
Filtering the joined table applies the predicate after the join, so `LIMIT` stops
being served by an index range and the query walks the whole index on a sold-out
catalog. The counter can't live in the index because it churns; the flag can,
because it only flips when stock crosses zero. On a catalog 95% sold out:
0.38 ms versus 3.21 ms.

## Scaling

Queue is on Postgres today, one component instead of two. Once it competes with
business load: Redis and Horizon, a separate queue per supplier so a slow one
doesn't eat the other's workers. Orders and attempts grow with turnover, but the
working set is only unsettled orders and partial indexes already isolate it, so
monthly partitioning with cold partitions archived wouldn't touch a query.

Breaker state moves to Redis with a real half-open window, plus a per-supplier
concurrency budget so one slow supplier can't drain the pool. Read replicas for
storefront and reconciliation, both tolerate lag; the money path stays on the
primary because it reads what it just wrote.

## Left out on purpose

- **Stock reservation at order creation.** The pool is the supplier's, so an
  honest reservation is a distributed lock on someone else's resource with
  timeout release. Too much machinery for unpaid orders, which are most of them,
  and the task already treats "paid but no code" as recoverable.
- **Refunds.** A failure on an already-paid order is logged and shows up in
  reconciliation, but nothing reverses automatically: a refund is a financial
  flow, not a status rollback.
- **Webhook signatures**, excluded by the task. `ops` endpoints are open so
  reconciliation can be called with one curl; in production they wouldn't be.
  Product codes are masked out of the report either way.

## Deviations

Two, both deliberate.

**`payment_failed` is not quite final.** The task lists it as a final status.
Here it keeps exactly one exit, to `paid`, because the money is real: if a
provider reports a failure and then a success for the same order — a retried
card, a delayed capture, two webhooks that crossed — refusing the success means
the customer is charged and receives nothing. The transition runs through the
same out-of-order guard as every other one, so only a strictly newer event can
take it, and it is logged as `payment.succeeded_after_failure` because it must
never look routine. The status is final in every other direction: nothing leads
back to `created`, and a failure arriving after money was received rolls nothing
back — it is logged and surfaced in reconciliation instead.

**The supplier stubs share a process with the core** instead of being a separate
deployment. What matters for correctness is still separated: their own Postgres
schema, no shared models, no foreign keys into core tables, communication only
over HTTP through nginx with real timeouts. Splitting them adds two containers
and changes nothing under test.
