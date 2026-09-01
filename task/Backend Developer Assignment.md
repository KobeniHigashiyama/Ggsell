# Backend Developer Assignment

## Project

Build the backend core of a digital-goods marketplace for gamers. The system
must cover payments, supplier integrations, catalog access, and automatic code
delivery.

The assignment focuses on exactly-once delivery, ambiguous timeouts, and safe
recovery. Briefly explain the key design decisions in the submission.

## Stack

- Backend: preferably Node.js or PHP; Python, Go, and other languages are accepted.
- Database: PostgreSQL or another relational database.
- REST API, webhooks, background jobs, and queue technology are your choice.

There is no real payment processor. Emulate payment by sending webhooks using
the same tooling used for concurrency tests.

## Stages

### Stage 1: API Core - Required

Implement order creation by SKU, order retrieval by ID, and a payment webhook.
Automatically deliver a product through a supplier stub. Provide correct status
transitions and a reasonable data model.

### Stage 2: Exactly Once Under Concurrency - Required

Duplicate and concurrent webhooks for one order must produce exactly one
delivery without lost events. Include a reproducible concurrency test.

### Stage 3: Resilient Integrations and the Timeout Trap - Strongly Preferred

Implement two supplier stubs that can fail randomly or time out. Add timeouts,
backoff retries, and fallback from supplier A to B.

A timeout is not a rejection. The supplier may have issued a code before the
response was lost. Retrying after a timeout must not create a second delivery.

### Stage 4: Reconciliation, Observability, and Recovery - Bonus

Provide structured payment and delivery logs. Add an endpoint or script that
finds paid-but-undelivered and delivered-but-unpaid orders. Add a background job
that safely recovers stuck orders. A balanced financial ledger is an additional
advantage.

### Stage 5: Catalog Under Load - Bonus

Assume thousands of SKUs and a hot storefront stock query. Design the schema and
indexes so the query remains fast, and briefly explain the execution plan.

No frontend is required. Real payment processing and real supplier integrations
are out of scope. Docker and CI are welcome but not mandatory.

## Acceptance Criteria

The solution is considered reliable when all scenarios pass:

1. Fifty concurrent paid webhooks for one order produce exactly one delivery,
   with no lost or duplicate events.
2. Repeating a webhook with the same `event_id` changes nothing.
3. An out-of-order webhook or a webhook received before its order is handled
   correctly.
4. A supplier times out after actually issuing a code; retrying with the same
   `request_id` does not create a second delivery.
5. Supplier A is unavailable; fallback to B delivers exactly once.
6. Empty stock creates a recoverable state without crashing.

## Evaluation

- Reliability of financial operations, transactions, idempotency, and consistency.
- Integration resilience, especially correct timeout handling.
- Data model, reconciliation, logs, and critical-path tests.
- Ability to explain engineering decisions.

## Submission

Provide:

1. Source code through GitHub or an archive with startup and test instructions.
2. Commands to reproduce concurrency and supplier fallback scenarios.
3. A short explanation of key decisions and a scaling plan.
4. Actual time spent.

## Product Catalog

Base prices are in RUB. Currency switching in the mockup is display-only and
does not require conversion.

| SKU | Name | Type | Price | Currency | Image |
|---|---|---|---:|---|---|
| `STEAM-TOPUP-500` | Steam Wallet Top-Up 500 RUB | `topup` | 500 | RUB | `assets/steam.png` |
| `STEAM-TOPUP-1000` | Steam Wallet Top-Up 1000 RUB | `topup` | 1000 | RUB | `assets/steam.png` |
| `STEAM-TOPUP-2500` | Steam Wallet Top-Up 2500 RUB | `topup` | 2500 | RUB | `assets/steam.png` |
| `KEY-CS2-PRIME` | CS2 Prime Status Key | `key` | 1290 | RUB | `assets/cs2.png` |
| `KEY-GTA5` | GTA V Activation Key | `key` | 1990 | RUB | `assets/gta5.png` |
| `KEY-EFT` | Escape from Tarkov Key | `key` | 3490 | RUB | `assets/eft.png` |
| `SUB-DISCORD-1M` | Discord Nitro 1 Month | `subscription` | 399 | RUB | `assets/discord.png` |
| `SUB-YT-3M` | YouTube Premium 3 Months | `subscription` | 1490 | RUB | `assets/youtube.png` |
| `SUB-SPOTIFY-1M` | Spotify Premium 1 Month | `subscription` | 299 | RUB | `assets/spotify.png` |
| `GIFT-PSN-1000` | PlayStation Store Gift Card 1000 RUB | `giftcard` | 1000 | RUB | `assets/psn.png` |
| `GIFT-XBOX-1500` | Xbox Gift Card 1500 RUB | `giftcard` | 1500 | RUB | `assets/xbox.png` |
| `GIFT-ROBLOX-800` | Roblox 800 Robux | `giftcard` | 890 | RUB | `assets/roblox.png` |

## Test Key Pool

One key cannot be assigned to multiple orders.

```text
LFXC-TNCS-BPCD
P3EI-W8UO-9B4K
FEL3-GUXN-TCCH
YPLV-QK2Z-IUS5
0K9E-P1FR-BY1U
5LZV-UQ48-RXCZ
X93K-NYAQ-GEC1
EIO5-CQT5-35KO
M58F-GIIR-VJAP
NU8Y-SWYB-6252
OODW-CCHF-MBAF
DNA5-WFJM-NE49
QRDD-MJ3F-A8TF
TAT9-5ZJN-G1T2
LI39-4330-ISMB
BKJY-8Q79-8NHI
HHW6-4RX2-DX62
1RG2-L28O-O80G
EF63-F39X-MTEA
8XS7-P53H-JKIV
JPE6-MQV6-P7ST
SAPG-A2GR-0ULS
T2DU-IJ1S-U16P
WSSY-QTR7-Z57J
U74E-EPCI-CY26
FZXF-58H8-OR93
FPSM-HLZA-TPAL
WSC9-28DJ-B2JE
P63J-F7UZ-DCYP
C7W2-D4C5-QMT7
JESI-DFBH-LK1K
SGMA-JA0T-GR7D
3PR4-OSY9-M3ZW
OMBE-C0JF-D45Y
KIKQ-FQJ8-9TI8
LMAN-RSHS-AJDO
BAKI-VT1X-Z5OL
9F0X-B46W-03FS
S423-V6YY-IBEM
D4UW-WYRA-20ST
XC0J-CJ0H-09RN
RY1W-XCFJ-0KUA
CJYY-YKSQ-QE6H
97AQ-38QJ-H8HU
FS8E-3S5Z-I6RA
ARQK-FML4-A14E
7Z6K-NO9V-MPJB
D4K7-IJSG-N853
W67T-ZB0Q-1XKB
7EQM-K09J-XKUO
```

## Payment Webhook Contract

Endpoint example: `POST /webhook/payment`

```json
{
  "event_id": "evt_a1b2c3",
  "order_id": "ord_00123",
  "status": "paid",
  "amount": 500,
  "currency": "RUB",
  "created_at": "2025-01-01T12:00:00Z"
}
```

- `status` is `paid` or `failed`.
- `event_id` identifies an event; a retry uses the same ID.
- Delivery is at least once, so processing must be idempotent.
- Webhooks may arrive out of order.
- Return `200 OK` quickly when accepted. The payment system retries `5xx`.
- Signature verification and real money movement are out of scope.

## Supplier Contract

Implement two supplier stubs that issue codes and can fail or delay responses.

Request: `POST /issue`

```json
{
  "request_id": "req_00123-1",
  "sku": "STEAM-TOPUP-500",
  "order_id": "ord_00123"
}
```

Successful response:

```json
{
  "status": "ok",
  "request_id": "req_00123-1",
  "code": "LFXC-TNCS-BPCD"
}
```

Error response:

```json
{
  "status": "error",
  "reason": "out_of_stock"
}
```

A timeout means no response within the configured duration.

The supplier must return the same code when the same `request_id` is repeated.
A timeout does not prove rejection because the supplier may have issued the code
before its response was lost.

Supplier A should support `5xx` and timeout behavior. Supplier B is the fallback
and may also fail. Failure and timeout rates must be configurable for reproducible
tests.

## Order Lifecycle

- `created`: waiting for payment.
- `paid`: payment confirmed; delivery can start.
- `delivering`: requesting a code from a supplier.
- `delivered`: code issued and attached to the order; final.
- `payment_failed`: payment rejected; final unless a newer success arrives.
- `out_of_stock`: paid but inventory unavailable; recoverable.
- `delivery_failed`: suppliers could not deliver; recoverable.

Main path:

```text
created -> paid -> delivering -> delivered
```

Failure and recovery paths:

```text
created -> payment_failed
paid -> delivering -> out_of_stock -> delivering -> delivered
paid -> delivering -> delivery_failed -> delivering -> delivered
```

Repeated payment or delivery must not change an already final order. Recovery
from `out_of_stock` and `delivery_failed` must remain safe and exactly once.
