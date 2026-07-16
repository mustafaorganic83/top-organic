# Sales & POS API v1

Base path: `/api/v1`. All routes require `Authorization: Bearer <access-token>` (API JWT) and a trusted **tenant + branch** context resolved by the `identity.context` middleware from the token. Routes that mutate state or read device-sensitive data additionally require a registered `pos.device` or `pos.device:edge` context. Scope fields (`tenant_id`, `branch_id`, `device_id`, `user_id`, `actor_id`, `approved_by`) are **prohibited** in every request body.

---

## Envelopes and errors

Success (single): `{ "data": { ... } }`.
Success (list): `{ "data": [...], "meta": { "current_page", "per_page", "total", "last_page" } }`.
Error: `{ "error": { "code": "STABLE_CODE", "message": "...", "details": {} } }`.
Validation: `{ "error": { "code": "...", "message": "...", "fields": { "<field>": ["..."] } }`.
Clients branch on `code`, never on `message`.

Pagination defaults: `per_page` 1–100, default 25 (`sales.pagination.default`).

---

## Primitive types

| Type | Wire format | Rules |
|------|------------|-------|
| **Money** | `integer` | Minor units (IQD pence, fils, cents). Non-negative unless noted. Max `9223372036854775807`. |
| **Rate** | `integer` | Basis points: 100 = 1 %. Range 1–10000 unless noted. |
| **Quantity** | `string` | Canonical decimal: `/\A(?:[1-9][0-9]*|0\.[0-9]{0,5}[1-9]|[1-9][0-9]*\.[0-9]{1,6})\z/`. No floats accepted. |
| **ULID** | `string` | 26-char Crockford Base32. All entity IDs are ULIDs. |
| **Timestamp** | `string` | ISO 8601 UTC (`2026-07-15T12:00:00Z`). |
| **Currency** | `string` | ISO 4217, 3-char uppercase (e.g. `IQD`, `USD`). |

---

## Optimistic locking and idempotency

Every mutable aggregate carries `lock_version: integer`. Mutating calls supply `expected_version`; a mismatch returns `409 SALES_STALE_VERSION`. Completed orders are terminal (`SALES_TERMINAL_ORDER`). Most mutating routes accept `client_operation_id` (1–128 chars, pattern `[A-Za-z0-9._:-]+`); an already-applied operation returns the stored outcome without re-applying.

---

## Device profiles

| Profile | Middleware | Allowed device types |
|---------|-----------|----------------------|
| `pos.device` | POS device required | `pos` |
| `pos.device:edge` | Edge device required | `pos`, `desktop` |
| *(none)* | Auth + context only | any / none |

---

## Error codes (Sales module)

| Code | HTTP | Meaning |
|------|------|---------|
| `SALES_INVALID_INPUT` | 422 | Validation or business rule violation |
| `SALES_INVALID_MONEY` | 422 | Amount out of range or currency mismatch |
| `SALES_INVALID_QUANTITY` | 422 | Quantity format invalid |
| `SALES_CURRENCY_MISMATCH` | 422 | Currency does not match order/card |
| `SALES_SCOPE_VIOLATION` | 403 | Missing tenant/branch/device context |
| `SALES_NOT_FOUND` | 404 | Resource absent in this branch scope |
| `SALES_CATALOG_UNAVAILABLE` | 409 | Item removed from branch catalog |
| `SALES_INVALID_STATE` | 409 | Aggregate in wrong state for operation |
| `SALES_STALE_VERSION` | 409 | `expected_version` does not match server |
| `SALES_TERMINAL_ORDER` | 409 | Order is completed or cancelled |
| `SALES_IDEMPOTENCY_CONFLICT` | 409 | `client_operation_id` replayed with different payload |
| `SALES_LIMIT_EXCEEDED` | 422 | Item/modifier/charge limit exceeded |
| `SALES_INSUFFICIENT_BALANCE` | 422 | Gift card balance too low |
| `SALES_PAYMENT_EXCEEDS_DUE` | 409 | Payment exceeds order due amount |
| `SALES_RESYNC_REQUIRED` | 409 | Cursor outside retained sync window |

---

## Route matrix

### Catalog

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `GET` | `/sales/catalog` | `sales.catalog.view` | — | Paginated branch catalog; active, available items |
| `POST` | `/sales/catalog/barcode/scan` | `sales.catalog.view` | — | Look up item by barcode |

**`GET /sales/catalog`** query params: `page`, `per_page`, `channel` (`pos|online|delivery|takeaway|dine_in`, default `pos`).

**`POST /sales/catalog/barcode/scan`** body:

```json
{ "barcode": "TOP-0001", "channel": "pos" }
```

Response item shape:

```json
{
  "product_id": "01J...",
  "variant_id": "01J...",
  "name": "Grilled Chicken",
  "variant_name": "Large",
  "sku": "CHK-LG",
  "barcode": "TOP-0001",
  "unit_price_amount": 15000,
  "currency": "IQD",
  "tax": { "code": "VAT", "rate_bps": 1500, "inclusive": true },
  "catalog_revision": 7,
  "price_revision": 3
}
```

---

### Shifts & drawers (POS)

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `POST` | `/pos/shifts` | `pos.shifts.manage` | `pos.device` | Open shift |
| `GET` | `/pos/shifts/{shift}` | `pos.shifts.view` | — | Show shift |
| `POST` | `/pos/shifts/{shift}/close` | `pos.shifts.manage` | `pos.device` | Close shift |
| `POST` | `/pos/drawers/sessions` | `pos.cash.manage` | `pos.device` | Open drawer session |
| `POST` | `/pos/drawers/sessions/{session}/close` | `pos.cash.manage` | `pos.device` | Close drawer session |
| `POST` | `/pos/drawers/sessions/{session}/movements` | `pos.cash.manage` | `pos.device` | Record cash movement |
| `POST` | `/pos/cash-movements/{movement}/reverse` | `pos.cash.reverse` | `pos.device` | Reverse cash movement |

`POST /pos/shifts` — no body required. Response:

```json
{
  "data": {
    "id": "01J...", "business_date": "2026-07-15", "sequence": 42,
    "state": "open", "opened_at": "2026-07-15T09:00:00Z",
    "closed_at": null, "lock_version": 0
  }
}
```

`POST /pos/shifts/{shift}/close` body: `{ "expected_version": 0 }`.

`POST /pos/drawers/sessions` body:

```json
{ "shift_id": "01J...", "drawer_id": "01J...", "currency": "IQD", "opening_amount": 500000 }
```

Response adds `expected_amount`, `counted_amount`, `variance_amount`, `state`, `lock_version`.

`POST /pos/drawers/sessions/{session}/close` body: `{ "counted_amount": 520000, "expected_version": 0 }`.

`POST /pos/drawers/sessions/{session}/movements` body:

```json
{ "type": "cash_in", "amount": 50000, "currency": "IQD", "client_operation_id": "device-01:seq-12", "reason": "Opening float top-up" }
```

Movement types: `cash_in | cash_out | sale | refund | adjustment`. Amount is non-zero signed integer.

`POST /pos/cash-movements/{movement}/reverse` body: `{ "reason": "...", "client_operation_id": "..." }`.

---

### Floors & tables

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `GET` | `/pos/floors` | `pos.tables.view` | — | Active floors with table layout and open sessions |
| `GET` | `/pos/tables` | `pos.tables.view` | — | Active tables ordered by `sort_order` |
| `POST` | `/pos/table-sessions` | `pos.tables.manage` | `pos.device` | Open table session |
| `POST` | `/pos/table-sessions/{session}/close` | `pos.tables.manage` | `pos.device` | Close table session |

`POST /pos/table-sessions` body: `{ "table_id": "01J...", "guest_count": 4 }`.

Table session response: `{ "id", "table_id", "guest_count", "state", "opened_at", "closed_at", "lock_version" }`.

`POST /pos/table-sessions/{session}/close` body: `{ "expected_version": 0 }`.

---

### Orders

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `GET` | `/sales/orders` | `sales.orders.view` | — | Paginated order list; filter `state`, `type` |
| `POST` | `/sales/orders` | `sales.orders.create` | `pos.device` | Create order (draft) |
| `GET` | `/sales/orders/{order}` | `sales.orders.view` | — | Show order |
| `GET` | `/sales/orders/{order}/tracking` | `sales.orders.view` | — | Order event timeline |
| `POST` | `/sales/orders/{order}/items` | `sales.orders.update` | `pos.device` | Add item |
| `PATCH` | `/sales/orders/{order}/items/{item}` | `sales.orders.update` | `pos.device` | Update item |
| `DELETE` | `/sales/orders/{order}/items/{item}` | `sales.orders.update` | `pos.device` | Remove item |
| `PUT` | `/sales/orders/{order}/customer` | `sales.orders.update` | `pos.device` | Assign customer |
| `PUT` | `/sales/orders/{order}/delivery` | `sales.orders.update` | `pos.device` | Set delivery details |
| `POST` | `/sales/orders/{order}/place` | `sales.orders.place` | `pos.device` | Place order (draft → placed) |
| `POST` | `/sales/orders/{order}/state` | `sales.orders.state` | `pos.device` | Transition order state |
| `POST` | `/sales/orders/{order}/discounts/manual` | `sales.orders.discount` | `pos.device` | Apply manual discount |
| `POST` | `/sales/orders/{order}/discounts/membership` | `sales.orders.discount` | `pos.device` | Apply membership discount |
| `POST` | `/sales/orders/{order}/discounts/coupon` | `sales.orders.discount` | `pos.device` | Redeem coupon discount |
| `PUT` | `/sales/orders/{order}/charges` | `sales.orders.charges` | `pos.device` | Replace order charges |
| `PUT` | `/sales/orders/{order}/service-charge` | `sales.orders.charges` | `pos.device` | Replace service charge (alias) |
| `POST` | `/sales/orders/{order}/tips` | `sales.orders.update` | `pos.device` | Add tip |
| `POST` | `/sales/orders/{order}/split` | `sales.orders.transfer` | `pos.device` | Split items into new order |
| `POST` | `/sales/orders/{order}/merge` | `sales.orders.transfer` | `pos.device` | Merge source order into this order |
| `POST` | `/sales/orders/{order}/transfer/order` | `sales.orders.transfer` | `pos.device` | Transfer (merge alias) |
| `POST` | `/sales/orders/{order}/transfer/table` | `sales.orders.transfer` | `pos.device` | Transfer order to another table |
| `POST` | `/sales/orders/{order}/transfer/customer` | `sales.orders.transfer` | `pos.device` | Reassign order customer |
| `GET` | `/sales/orders/{order}/quote` | `sales.orders.view` | — | Current order totals (read-only) |
| `POST` | `/sales/orders/{order}/recalculate` | `sales.orders.update` | `pos.device` | Force recalculate totals under lock |

#### Order lifecycle

```
draft ──place──► placed ──confirm──► confirmed ──prepare──► preparing ──ready──► ready ──complete──► completed
  │                │                    │                      │                    │
  └── cancel ──────┴────────── cancel ──┴────────── cancel ────┴────────── cancel ──┘       │
                                                                                        (terminal)
cancelled (terminal)
```

- `POST /sales/orders/{order}/place` transitions **draft → placed**. Required: `expected_version`, `client_operation_id`.
- `POST /sales/orders/{order}/state` transitions between named states. Body: `{ "expected_version": 1, "state": "confirmed", "client_operation_id": "..." }`. Valid targets: `confirmed | preparing | ready | completed | cancelled`.
- Completed and cancelled orders are **terminal**: `SALES_TERMINAL_ORDER` (409) on any further mutation.

#### Create order

```json
{
  "type": "dine_in",
  "currency": "IQD",
  "source": "pos",
  "client_operation_id": "POS-01:batch-001:op-001",
  "table_session_id": "01J...",
  "pos_shift_id": "01J..."
}
```

Order types: `dine_in | takeaway | delivery | online`. Sources: `pos | online | delivery | kiosk | mobile`. Returns `201`.

#### Order response (abbreviated)

```json
{
  "data": {
    "id": "01J...", "number": 1042, "type": "dine_in", "source": "pos",
    "state": "draft", "currency": "IQD",
    "subtotal_amount": 30000, "discount_amount": 0, "charge_amount": 0,
    "tax_amount": 4500, "tip_amount": 0, "rounding_amount": 0,
    "total_amount": 34500, "paid_amount": 0, "due_amount": 34500,
    "customer_id": null, "table_session_id": "01J...", "pos_shift_id": "01J...",
    "business_date": "2026-07-15", "placed_at": null, "settled_at": null,
    "lock_version": 0,
    "items": [
      {
        "id": "01J...", "line_number": 1, "variant_id": "01J...",
        "name": "Grilled Chicken", "variant_name": "Large", "sku": "CHK-LG",
        "quantity": "1", "unit_price_amount": 15000, "gross_amount": 15000,
        "discount_amount": 0, "tax_amount": 2250, "net_amount": 15000,
        "currency": "IQD", "state": "active",
        "course_number": null, "seat_number": null, "notes": null,
        "modifiers": []
      }
    ],
    "delivery": null
  }
}
```

#### Add item

Body: `{ "expected_version": 0, "variant_id": "01J...", "quantity": "2", "modifiers": [{ "option_id": "01J...", "quantity": "1" }], "channel": "pos", "client_operation_id": "..." }`. Returns `201`.

#### Manual discount

Body: `{ "expected_version": 1, "discount_type": "fixed", "amount": 5000, "reason": "Manager comp", "client_operation_id": "..." }` or `{ "discount_type": "percent", "rate_bps": 1000, "maximum_amount": 10000, "reason": "...", "client_operation_id": "..." }`.

#### Replace charges

```json
{
  "expected_version": 2,
  "charges": [
    { "code": "SERVICE", "name": "Service Charge", "type": "service", "calculation": "percent", "rate_bps": 1000 }
  ],
  "client_operation_id": "..."
}
```

`calculation`: `fixed | percent`. `fixed_amount` required when `calculation=fixed`; `rate_bps` required when `calculation=percent`.

#### Split

Body: `{ "expected_version": 2, "selections": [{ "item_id": "01J...", "quantity": "1" }], "client_operation_id": "..." }`. Returns `201` with the new (child) order.

#### Merge / transfer order

Body: `{ "expected_version": 2, "source_order_id": "01J...", "source_version": 3, "client_operation_id": "..." }`.

---

### Customers

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `GET` | `/sales/customers` | `sales.customers.view` | — | Paginated customer list; search via `query` |
| `GET` | `/sales/customers/search` | `sales.customers.view` | — | Alias of list with `query` param |
| `POST` | `/sales/customers` | `sales.customers.manage` | `pos.device` | Create customer |
| `GET` | `/sales/customers/{customer}` | `sales.customers.view` | — | Show customer |
| `PATCH` | `/sales/customers/{customer}` | `sales.customers.manage` | `pos.device` | Update customer (optimistic lock) |
| `GET` | `/sales/customers/{customer}/history` | `sales.customers.history` | — | Order history |
| `POST` | `/sales/customers/{customer}/memberships` | `sales.customers.membership` | `pos.device` | Assign membership tier |

`query` searches name, customer number, phone hash, and email hash. Phone and email are stored as HMAC-SHA-256 hashes; the API matches without exposing raw values.

Customer response: `{ id, customer_number, name, phone, email, locale, status, last_order_at, lock_version, memberships: [{ id, membership_number, tier: {id, code, name, discount_rate_bps}, status, started_at, expires_at }] }`.

Membership body: `{ "membership_tier_id": "01J...", "membership_number": "M-0042", "expires_at": "2027-07-15T00:00:00Z" }`.

---

### Gift cards

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `POST` | `/sales/gift-cards/issue` | `sales.gift_cards.issue` | `pos.device` | Issue new gift card |
| `POST` | `/sales/gift-cards/load` | `sales.gift_cards.load` | `pos.device` | Load (top-up) gift card |
| `POST` | `/sales/gift-cards/balance` | `sales.gift_cards.view` | — | Check balance by token |
| `POST` | `/sales/gift-cards/redeem` | `sales.gift_cards.redeem` | `pos.device` | Redeem against order |
| `POST` | `/sales/gift-cards/reverse` | `sales.gift_cards.reverse` | `pos.device` | Reverse a transaction |

**Safety rules:**
- Gift card `token` is never logged or returned after issue. The issue response returns `token` exactly once.
- `token` is hashed (HMAC-SHA-256) for storage and balance lookup.
- Gift card redemption through offline sync (`payment.capture`) is **rejected**; only cash and methods with `supports_offline=true` can capture offline.
- `token_last4` is the only token fragment ever returned in read responses.

Issue body: `{ "currency": "IQD", "initial_amount": 100000, "client_operation_id": "...", "customer_id": null, "expires_at": null }`. Returns `201`.

Issue response: `{ id, customer_id, token, token_last4, currency, balance_amount, status, issued_at, expires_at }`. `token` is only present on `POST /issue`.

Balance body: `{ "token": "<raw-token>" }`. Response: same shape without `token`.

Redeem body: `{ "token": "...", "currency": "IQD", "amount": 20000, "order_id": "01J...", "client_operation_id": "..." }`.

Transaction response: `{ id, gift_card_id, order_id, original_transaction_id, type, amount, balance_after, currency, occurred_at }`.

Reverse body: `{ "transaction_id": "01J...", "client_operation_id": "..." }`.

---

### Billing

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `GET` | `/sales/billing/payment-methods` | `sales.billing.view` | — | Active branch payment methods |
| `POST` | `/sales/billing/payments` | `sales.billing.capture` | `pos.device` | Capture payment |
| `GET` | `/sales/billing/payments` | `sales.billing.view` | — | Paginated payment list |
| `POST` | `/sales/billing/payments/{payment}/reverse` | `sales.billing.reverse` | `pos.device` | Reverse a payment |
| `GET` | `/sales/billing/invoices/{invoice}` | `sales.billing.view` | — | Get invoice |
| `GET` | `/sales/billing/receipts/{invoice}` | `sales.billing.view` | — | Get receipt (invoice + `document_type: receipt`) |
| `GET` | `/sales/billing/orders/{order}/settlement` | `sales.billing.view` | — | Settlement summary for order |

**Payment safety:**
- `provider_snapshot.pan`, `provider_snapshot.cvv`, `provider_snapshot.cvc`, and `provider_snapshot.card_number` are **prohibited** in capture requests (422).
- `provider_snapshot` may carry at most 30 keys of non-sensitive metadata.
- Idempotency: supply unique `idempotency_key` (≤128 chars) per payment attempt; duplicate key returns the original payment.

Capture body:

```json
{
  "order_id": "01J...",
  "expected_version": 3,
  "payment_method_id": "01J...",
  "amount": 34500,
  "idempotency_key": "shift-42-order-1042-pay-1",
  "client_operation_id": "...",
  "provider_reference": null,
  "gift_card_token": null
}
```

Payment response: `{ id, order_id, method: {id, code, name, kind}, status, tender_amount, tender_currency, base_amount, base_currency, captured_at }`.

Payment method response: `{ id, code, name, kind, minimum_amount, maximum_amount }`.

Reverse body: `{ "amount": 34500, "reason": "Customer refund", "client_operation_id": "..." }`.

Reverse response: `{ id, original_payment_id, reversal_payment_id, amount, currency, reason, occurred_at }`.

Invoice/receipt response: `{ id, order_id, document_type, number, business_date, currency, subtotal_amount, discount_amount, charge_amount, tax_amount, tip_amount, rounding_amount, total_amount, status, issued_at, lines: [...], payments: [...] }`.

Settlement response: `{ order_id, state, currency, total_amount, paid_amount, due_amount, settled_at, lock_version }`.

---

### KDS (Kitchen Display System)

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `GET` | `/sales/kds/tickets` | `sales.kds.view` | — | Active tickets (queued, in_progress, ready); filter `state`, `station_id` |
| `GET` | `/sales/kds/tickets/{ticket}` | `sales.kds.view` | — | Show ticket |
| `POST` | `/sales/kds/dispatch` | `sales.kds.dispatch` | `pos.device:edge` | Dispatch order items to kitchen stations |
| `POST` | `/sales/kds/tickets/{ticket}/start` | `sales.kds.manage` | `pos.device:edge` | queued → in_progress |
| `POST` | `/sales/kds/tickets/{ticket}/ready` | `sales.kds.manage` | `pos.device:edge` | in_progress → ready |
| `POST` | `/sales/kds/tickets/{ticket}/bump` | `sales.kds.manage` | `pos.device:edge` | ready → bumped |
| `POST` | `/sales/kds/tickets/{ticket}/recall` | `sales.kds.manage` | `pos.device:edge` | bumped → ready |

#### KDS state machine

```
queued ──start──► in_progress ──ready──► ready ──bump──► bumped
                                           ▲                 │
                                           └─────recall───────┘
```

Transitions are strict: wrong source state returns `409 SALES_INVALID_STATE`.

`POST /sales/kds/dispatch` body: `{ "order_id": "01J...", "client_operation_id": "..." }`. Returns `201` with array of created/updated tickets. Items are routed to stations by catalog configuration; unmapped items go to the DEFAULT station.

Transition body (start/ready/bump/recall): `{ "expected_version": 1, "client_operation_id": "...", "reason": null }`.

Ticket response: `{ id, order_id, number, station: {id, code, name}, state, priority, lock_version, started_at, ready_at, items: [{id, order_item_id, quantity, preparation: {name, variant_name, modifiers, course_number, notes}, state}], events: [{id, sequence, type, reason, occurred_at}] }`.

---

### Printing (edge print)

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `POST` | `/sales/printing/jobs` | `sales.printing.create` | `pos.device` | Enqueue print job |
| `GET` | `/sales/printing/jobs/{job}` | `sales.printing.view` | — | Show print job |
| `POST` | `/sales/printing/edge/jobs/claim` | `sales.printing.edge` | `pos.device:edge` | Claim next pending job for this device |
| `POST` | `/sales/printing/edge/jobs/{job}/complete` | `sales.printing.edge` | `pos.device:edge` | Mark claimed job printed |
| `POST` | `/sales/printing/edge/jobs/{job}/fail` | `sales.printing.edge` | `pos.device:edge` | Mark claimed job failed |
| `POST` | `/sales/printing/edge/jobs/{job}/retry` | `sales.printing.edge` | `pos.device:edge` | Re-queue failed job |

#### Print payload types

| `payload_type` | `document_id` | Printed document |
|---------------|--------------|-----------------|
| `kitchen_ticket` | KDS ticket ULID | Kitchen prep ticket |
| `invoice` | Invoice ULID | Customer invoice |
| `receipt` | Invoice ULID | Receipt copy |
| `barcode_label` | Product variant ULID | SKU/barcode label |
| `qr_verification` | Invoice or Order ULID | Signed QR verification slip |

#### Edge print JSON protocol

The `payload` field on every `PrintJob` follows this envelope (never render payloads from memory — re-fetch the job):

```json
{
  "protocol": "top-organic.edge-print",
  "version": 1,
  "payload_type": "receipt",
  "created_at": "2026-07-15T12:05:00Z",
  "document": { ... }
}
```

For `barcode_label`, `document` contains: `{ id, sku, name, variant_name, barcode }`.

For `qr_verification`, `document` contains:
```json
{
  "claims": { "type": "invoice", "id": "01J...", "number": "INV-0042", "total_amount": 34500, "currency": "IQD", "issued_at": "2026-07-15T12:04:00Z" },
  "signature": "<HMAC-SHA256-hex>",
  "algorithm": "HMAC-SHA256"
}
```
QR payload `claims` and `signature` must be verified server-side; do not accept client-provided signatures. Never embed the signing key in a client app.

#### Print job lifecycle

```
pending ──claim──► processing ──complete──► printed
              │                  └──fail──► failed ──retry──► pending (up to max_attempts=5)
              └── (max_attempts reached on claim) ──► failed
```

`claim` returns `{ "data": null }` when no pending job is available. A job auto-fails on claim if `attempt_count >= 5`.

Enqueue body: `{ "payload_type": "receipt", "document_id": "01J...", "idempotency_key": "inv-01-copy-1", "printer_id": null }`. Omitting `printer_id` uses automatic route resolution.

`complete` body: `{ "expected_version": 1 }`.

`fail` body: `{ "expected_version": 1, "error_code": "PRINTER_OFFLINE", "error_message": "USB disconnected" }`. `error_code` pattern: `[A-Z0-9_]+`.

`retry` body: `{ "expected_version": 2 }`. Only `state=failed` with `attempt_count < 5` can be retried. Queues with `available_at = now + 30s`.

Print job response: `{ id, printer_id, payload_type, document_type, document_id, payload, state, priority, attempt_count, available_at, printed_at, lock_version }`.

---

### Offline sync

Sync routes are documented in full in [offline-sync-v1.md](offline-sync-v1.md).

| Method | Path | Permission | Device | Purpose |
|--------|------|-----------|--------|---------|
| `POST` | `/sales/sync/push` | `sales.sync.push` | `pos.device` | Push offline command batch |
| `GET` | `/sales/sync/pull` | `sales.sync.pull` | `pos.device` | Pull change feed |
| `POST` | `/sales/sync/cursor` | `sales.sync.pull` | `pos.device` | Acknowledge pull high-water mark |
| `GET` | `/sales/sync/conflicts` | `sales.sync.conflicts.view` | — | List open conflicts |
| `POST` | `/sales/sync/conflicts/{conflict}/resolve` | `sales.sync.conflicts.resolve` | — | Resolve conflict |
