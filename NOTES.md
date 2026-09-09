# Design Notes

Why the code looks the way it does. README covers running it; this covers the
decisions I would expect to be asked about.

## The idea everything follows from

Guarantees belong in the schema, not in application conventions. Code dies
mid-transaction, workers duplicate, a webhook arrives fifty times — a unique
index does not care. The application layer only arranges work around constraints
the database will not let it break.

| Guarantee | What enforces it |
|---|---|
| One `event_id` applied once | `payment_events.event_id UNIQUE` |
| An order paid once | `SELECT … FOR UPDATE` on the order row |
| One delivery per line | `deliveries.order_item_id UNIQUE` |
| One customer per code | `deliveries.code UNIQUE`, global |
| One refund per line | `refunds.order_item_id UNIQUE` |
| A retry after a timeout issues no second key | `delivery_attempts.request_id UNIQUE`, same on the supplier side |
| A retry after a refund timeout moves no second payment | `refunds.refund_request_id UNIQUE`, same on the gateway side |
| The ledger balances | checked before insert, `UNIQUE(ref_type, ref_id, account)` |
| History is never rewritten | triggers refusing `UPDATE`, `DELETE`, `TRUNCATE` |

Stage 2 changed one row of that table and it changed everything else: the unit is
the order line, not the order.

The smaller decision that does most of the work: a delivery attempt is its own
row with its own state, not a variable inside an HTTP call. That is what makes an
`unknown` outcome representable at all.

## An order is a set of lines

One `order_items` row is one deliverable unit and therefore one code; `quantity:
3` becomes three rows, because a line that could hold two is a line that needs a
counter, and counters are what the rest of this document avoids.

Order status stopped being a state machine and became a projection.
`OrderStatus::deriveFrom()` folds line states into one value and
`RecalculateOrderStatus` is its only writer, always under the order lock the line
change already holds. No actor can transition an order that is simultaneously
delivered, refunded and retrying. For a single-line order the derivation
reproduces stage-1 behaviour exactly, which is why the stage-1 tests still assert
what they always asserted.

`orders.sku` and `orders.quantity` were dropped rather than kept in step: an
order spanning two products has no single SKU, and a denormalised copy of one
line's is a second source of truth that diverges the first time somebody trusts
it. The API still returns a top-level `sku` and `code` for single-line orders,
computed from the line, so stage-1 clients see no change.

## The timeout trap

A rejection is information: nothing was issued, move on. A timeout is the absence
of information: the supplier may hold a key whose response never arrived.
Treating the second as the first is how you buy two keys with one payment.

Classification is therefore lopsided. Only a verdict the supplier actually
rendered counts as a rejection, and the marker is an HTTP status. Everything else
is `unknown`, and an unknown attempt may only re-ask the same supplier with the
same `request_id`. Being wrong toward `unknown` costs one harmless re-ask; being
wrong the other way costs a key.

The same asymmetry applies with opposite results to the two questions we ask a
supplier. Asking for a code, a refused connection proves the request never
arrived, so fallback is safe. Asking what it already did, the same refusal proves
only that we could not ask — and reading it as "no record" lets an audit close an
attempt on an answer it never received.

## Refunds, and delivery and refund as opposites

A line that cannot be delivered has to give the money back, or the order never
ends. `SettleUnfulfillableItems` does that on a schedule, and a refund gets the
same machinery as a supplier request: a row written before the call, a
deterministic `refund_request_id` the gateway deduplicates on, and an outcome
vocabulary where only a definitive rejection means no money moved.

The part worth reading is what keeps a line from being both delivered and
refunded, since that is the one error no ledger entry can undo:

- `RefundOrderItem` refuses a line with an unresolved attempt, or one holding a
  code that was never committed. A supplier may still owe us a code.
- `CommitDelivery` refuses a line whose refund exists and was not definitively
  rejected. The money may already be on its way back.

The second check lives at the write and not in its callers, and that is a
correction an audit forced: it began as a guard in the fulfilment path, which was
true of every path that existed then, until the audit sweep gained the ability to
deliver a code it found and went straight past it.

The run budget needed the same correction. It exists to stop a hopeless line from
making new supplier requests; a line already holding a code is not making one, it
is finishing work already paid for. Gating that produced a closed loop — the code
could not be committed because the budget was spent, and the refund was blocked
because the code existed.

## A supplier you cannot trust

Stage 1 assumed an honest supplier having a bad day. Stage 2 assumes one that
hands the same code to two customers, hands over a code for another product, or
reports failure after issuing.

Uniqueness needs no cleverness: `deliveries.code UNIQUE` is global, so a
duplicated code physically cannot reach a second customer. What the core adds:

- **Provenance.** The issue response names the SKU the code belongs to and it is
  compared with what was ordered. A supplier that will not say is refused too.
- **Quarantine.** A refused code marks the attempt `quarantined_at` rather than
  rewriting its status. "The supplier answered ok with code X" stays true; the
  quarantine is our separate verdict on it, so history never needs editing.
- **Audit.** `ops:auto-resolve` asks the supplier's registry about requests it
  will not resolve itself — ones it rejected, and ones whose outcome was never
  learned, which otherwise block their line from being either delivered or
  refunded. A code found behind a reported failure is delivered when its line
  still waits, and handed back otherwise.
- **Never at a customer's expense.** A stranded code is handed back and revoked,
  unless it is the code somebody is already holding — decided from `deliveries`,
  not from the reason the incident was recorded under. That decision and the
  write that would deliver the code are serialised on the code itself, because
  row locks cannot order two decisions taken in different tables.
- **Trust.** A violation feeds the circuit breaker: a supplier answering 200 with
  unusable codes is failing, and nothing else would notice.

## Pacing a spike

The core counts requests exactly the way the supplier does — a fixed window on
the wall-clock minute — and the stub answers 429 when its own window is spent, so
restraint is checkable against the supplier's books rather than self-reported.

That shape is a correction too. A token bucket is nicer in the abstract, but one
that starts full and refills continuously allows its capacity *plus* a window of
refill inside one of the supplier's windows: against a limit of thirty per
minute, a burst of thirty followed by paced traffic reached forty-five. A limiter
has to model the accounting of the party it protects.

Over the limit a line is deferred, not failed: the check happens before the line
is claimed, so waiting costs no retry budget, and the job releases itself back
onto the queue. It has to be a release rather than a fresh dispatch — the running
job holds the unique lock for that line.

Audits and code returns spend the same allowance, because a question is still a
request, and they yield part of every window to deliveries. The reserve is capped
one below the limit so it can slow that work but never stop it.

## Reading the past

`order_events` is append-only, enforced by triggers refusing `UPDATE`, `DELETE`
and `TRUNCATE` — the last one because a row trigger does not fire on it, so the
first version of this guarantee could be erased in one statement.
`ledger_entries` carries the same protection: it is the independent side of every
money check, and a check is worth nothing if one side can be edited.

Events are written inside the transactions that make them true, so an event
exists if and only if the change committed. Recorded are every movement of money
and every change of line state including the non-terminal ones, without which a
partly failed order reads as a row of identical unsettled lines.

Two clocks are kept apart. `occurred_at` is when something happened — a webhook
delayed five minutes still records the moment of payment — and `created_at` is
when it was recorded. Period totals use business time; the cross-check against
the ledger uses recording time, so both sides cover the same writes. Business
time comes from a public unsigned webhook, so it is bounded at the boundary.

## Money and reconciliation

Double-entry, signed amounts, balance is `SUM() = 0`. Payment is Dr `cash` / Cr
`customer_liability`; delivery is Dr `customer_liability` / Cr `revenue`; a
refund is Dr `customer_liability` / Cr `cash`. Revenue lands at delivery because
until then we owe goods, and a refund never touches revenue because nothing was
earned — refunds only ever happen for lines that were not delivered.

The stage-2 identity is **paid = delivered + refunded + still owed**, and
`money_conservation` computes both sides independently: the domain side from
order and line rows, the ledger side from entries by account and reference type.
One check catches a missing refund, a double refund, a delivery that never became
revenue, and an order total that drifted from its lines.

## What auditing this turned up

Five defects, found by re-reading the result against its own claims rather than
by a failing test, and all one mistake: deciding from a proxy instead of from the
data that answers the question.

- The rate limiter modelled the supplier's accounting differently from the
  supplier.
- A third path to a delivery went around the guard keeping delivery and refund
  exclusive.
- Clean-up would hand back a code a customer was holding, judging by the reason
  the code was stranded instead of by whether it was delivered.
- A retry budget blocked recovery of a code the supplier had already been paid
  for, judging by the attempt's status instead of by whether a code was held.
- A classifier read a supplier's refusal to answer as an answer.

Three of them were covered by tests that passed for the wrong reason, which was
the more useful lesson: a green check that measures the wrong thing is worse than
no check.

## Catalog performance

50,000 SKUs, 400,000 keys, PostgreSQL 17. Stock is a counter in a narrow side
table, not a column on `products`: it changes on every issuance and would wreck
HOT updates on the row the storefront index covers. That index is partial and
covering with column order matching `ORDER BY`, so there is no sort step and
`Heap Fetches: 0`. Pagination is keyset via tuple comparison.

Storefront query: **0.685 ms, 105 buffers**. `COUNT(*)` over the key pool with
`OFFSET` is **164 ms and 4,471 buffers** on the same data; keyset holds ~0.9 ms
at any depth while `OFFSET` reaches 150 ms by page 1,000. "In stock only" filters
a boolean flag on `products`, not the counter: the counter churns and cannot live
in the index, the flag only flips when stock crosses zero. On a catalog 95% sold
out, 0.38 ms versus 3.21 ms.

## Scaling

The queue is on Postgres today, one component instead of two. Once it competes
with business load: Redis and Horizon, a queue per supplier so a slow one does
not eat the other's workers, and the rate-limit window moves with it. Orders grow
with turnover but the working set is only unsettled ones, which partial indexes
already isolate, so monthly partitioning would not touch a query. Breaker state
moves to Redis with a real half-open window; read replicas for storefront and
reconciliation, with the money path staying on the primary because it reads what
it just wrote.

## Known limits

- **A consistently lying supplier is not detectable from inside.** Provenance is
  checked against the SKU the supplier declares, so one handing over another
  customer's code while naming the right product passes. Catching that needs an
  independent record of what was sold. What holds regardless: one code never
  reaches two customers, because that is an index over our own data.
- **The money identity is internal.** Both sides are ours; a double charge by the
  acquirer needs reconciliation against its statement, which is out of scope.
- **A sustained spike delays background work.** Deliveries win both the queue and
  the allowance, so clean-up waits. The invariants hold; the time to a terminal
  state does not.

## Left out on purpose

- **Stock reservation at order creation.** The pool is the supplier's, so an
  honest reservation is a distributed lock on someone else's resource with
  timeout release — too much machinery for unpaid orders, which are most of them.
- **Reversing a delivered order.** Refunds cover lines that were never delivered.
  Taking a code back after the customer has it is a commercial decision about a
  product that may already be spent.
- **Webhook signatures**, excluded by the task. `ops` endpoints are open so
  reconciliation can be called with one curl; in production they would not be.

## Deviations

**`payment_failed` is not quite final.** The task lists it as final. Here it
keeps one exit, to `paid`, because the money is real: if a provider reports a
failure and then a success — a retried card, a delayed capture, two webhooks that
crossed — refusing the success means the customer is charged and receives
nothing. It runs through the same out-of-order guard as every other transition,
so only a strictly newer event can take it, and it is logged as
`payment.succeeded_after_failure` because it must never look routine.

**The stubs share a process with the core** instead of being separate
deployments. What matters for correctness is still separated: their own Postgres
schema, no shared models, no foreign keys into core tables, communication only
over HTTP through nginx with real timeouts.
