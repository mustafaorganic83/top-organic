# Schema Catalog — POS, KDS & Billing

All tables here are branch-owned unless stated otherwise. Financial amounts are minor-unit
`BIGINT`; quantities are `DECIMAL(18,6)`. Settled/issued records are immutable.

## Floors, Tables, Reservations & Shifts

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `floors` | tenant/branch, code, localized name, layout revision, status | UQ active branch+code; SD/V |
| `dining_tables` | tenant/branch, floor, code, capacity, status, sort position | UQ active branch+code; SD; current occupancy is derived |
| `table_sessions` | tenant/branch, table, opened/closed times and actors, guest count, state | one active session per table; close is AO terminal transition |
| `reservations` | tenant/branch, table nullable, customer/contact snapshot, starts/ends, party size, status | IX branch+start+status; SD only before fulfillment |
| `reservation_tables` | tenant/branch, reservation, table | UQ reservation+table; prevents overlapping assignment through service transaction/lock |
| `pos_shifts` | tenant/branch, business date, daily sequence, opened/closed actors/times, state | sequence resets per branch/business date; UQ branch+date+sequence; AO after close |
| `cash_drawers` | tenant/branch, device, code, status | UQ active branch+code; SD |
| `cash_drawer_sessions` | tenant/branch, drawer, shift, opener/closer, opening/expected/count totals | one active per drawer; AO after close |
| `cash_movements` | tenant/branch, drawer session, type, amount/currency, reason, actor, occurred_at | AO; IX session+occurred_at; correction references original |
| `shift_summaries` | tenant/branch, shift, tender/tax/sales totals JSON, checksum, generated_at | derived immutable snapshot; UQ shift+revision |

## Orders

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `orders` | scope, device, shift, table session/customer nullable, number, type/source, state, currency, totals, policy/version snapshots, timestamps | UQ branch+number and creation idempotency; IX branch+state+created; mutable until settlement |
| `order_items` | scope, order, parent line nullable, product/variant snapshot IDs+names+SKU, quantity, unit/discount/tax/net totals, state, course/seat | UQ scoped candidate key; IX order+state; never cascade from settled order |
| `order_item_modifiers` | scope, order item, modifier/option snapshot IDs+names, quantity, surcharge | owned line snapshot; UQ item+line number |
| `order_discounts` | scope, order/item nullable, policy/code snapshot, type/value, applied amount, reason, actor/approver | AO application; reversal/void event compensates |
| `order_price_overrides` | scope, order item, original/new unit price, currency, reason, actor/approver, occurred_at | AO permissioned override; explicit `PriceOverridden` event and audit |
| `order_charges` | scope, order, charge code/name, basis, rate/fixed amount, tax class, amount | AO calculation snapshot |
| `order_tax_lines` | scope, order/item nullable, policy/rule revision, taxable basis, rate, amount, order | AO calculation snapshot; no JSON-only tax ledger |
| `order_events` | scope, order, sequence, event type/version, safe payload, actor/device, logical clock, occurred_at | AO; UQ order+sequence and operation id; authoritative transition history |
| `order_links` | scope, source order, target order, relation `split/merge/reopen/correction`, actor/time | AO; prevents loss of lineage |
| `order_status_projections` | scope, order, last sequence, current state/totals, refreshed_at | rebuildable projection; no independent business writes |

### Order State Rules

- Draft/placed orders use optimistic `lock_version`; events serialize by `(order_id,sequence)`.
- `placed → preparing → ready → served → settled → closed`; void/reopen require explicit events.
- Settlement freezes all product, customer, region-policy, exchange-rate, discount, charge, and
  tax snapshots. Later corrections use linked adjustment documents and events.

## Kitchen Display

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `kds_stations` | scope, code, localized name, device nullable, SLA, status | UQ active branch+code; SD |
| `kds_routing_rules` | scope, station, category/product/channel target, priority, effective interval | V; UQ station+target+effective_from |
| `kds_tickets` | scope, order, station, number, state, priority, created/started/ready/cleared times, last sequence | UQ branch+number; IX station+state+created; mutable projection |
| `kds_ticket_items` | scope, ticket, order item, quantity, preparation snapshot, state | UQ ticket+order-item; IX ticket+state |
| `kds_ticket_events` | scope, ticket/item nullable, sequence, event, actor/device, occurred_at, reason | AO; UQ ticket+sequence; bump/recall never overwrites history |

## Tender, Payments & Settlement

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `payment_methods` | tenant, code, kind, enabled, provider nullable, config reference | UQ active tenant+code; SD/V; no secrets in row |
| `branch_payment_methods` | scope, payment method, enabled, limits, settlement account code | UQ branch+method; V |
| `payments` | scope, order nullable, method, status, tender/base amounts+currencies, FX rate, provider reference, captured actor/device/time | AO after capture; UQ provider reference and idempotency; IX branch+captured_at |
| `payment_allocations` | scope, payment, order, amount/currency | AO; UQ payment+order; allocated sum must equal captured accounting amount |
| `payment_events` | scope, payment, sequence, event, provider status/reference, safe payload, occurred_at | AO; UQ payment+sequence |
| `payment_reversals` | scope, original payment, replacement/refund payment, reason, amount, approvers, occurred_at | AO; UQ reversal payment; original never updated destructively |
| `customer_account_entries` | tenant, customer, recording branch nullable, order/payment nullable, debit/credit minor units, currency, occurred_at | company-owned AO sub-ledger; balance is company-wide; branch is provenance only |

## Invoices, Receipts & Tax Snapshots

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `invoices` | scope, order, document type, number, business date, customer snapshot, currency, totals, policy revision, issue actor/time, status | AO after issue; UQ branch+number; IX branch+business_date |
| `invoice_lines` | scope, invoice, line number, product/order-line reference nullable, full description/SKU snapshot, quantity, unit/gross/discount/net totals | AO; UQ invoice+line number |
| `invoice_tax_lines` | scope, invoice line nullable, tax rule code/revision, basis, rate, amount, inclusive flag, order | AO; IX invoice+rule |
| `invoice_payments` | scope, invoice, payment allocation, amount | AO; UQ invoice+allocation |
| `credit_notes` | scope, original invoice, number, reason, totals, issued actor/time | AO compensating document; UQ branch+number |
| `credit_note_lines` | scope, credit note, original invoice line nullable, quantity/amount/tax reversal | AO; UQ credit-note+line number |
| `document_print_events` | scope, invoice/credit note, actor/device, format, occurred_at, copy number | AO; records reprints without mutating issued document |

## Financial Foreign-Key Policy

- `payments`, `invoices`, tax lines, credit notes, cash movements, and closed shifts use
  `ON DELETE RESTRICT`; they are never soft-deleted.
- Optional actor/customer references use `SET NULL` only after denormalized identity snapshots
  exist. Tenant/branch identity itself is never nullable.
- Provider payloads are redacted and encrypted where necessary; card PAN/CVV is never stored.