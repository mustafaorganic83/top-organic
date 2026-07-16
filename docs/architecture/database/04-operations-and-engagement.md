# Schema Catalog — Operations & Engagement

## Inventory & Warehousing

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `inventory_items` | tenant, code/SKU, localized name, base unit, costing method, tracking flags, status | UQ active tenant+code; SD/V |
| `warehouses` | tenant/branch, code, localized name, type, status | UQ active branch+code; central warehouse is a branch-owned warehouse |
| `storage_locations` | scope, warehouse, code, parent nullable, type, status | self FK; UQ active warehouse+code; SD |
| `stock_balances` | scope, location, item, on-hand/reserved quantities, moving-average cost, last movement sequence | UQ location+item; derived under locked posting transaction |
| `stock_movements` | scope, item/location, signed quantity, unit, cost, type, source type/id, operation ID, occurred/business dates | AO ledger; UQ operation; IX scope+item+occurred |
| `stock_reservations` | scope, item/location, order/item, quantity, state, expires_at | active reservation UQ; release/consume transitions audited |
| `stock_alert_rules` | scope, required item, optional location, reorder/minimum quantity, recipients/template, enabled | UQ active scope+item+normalized-location; generated location+active markers handle null; V/SD |
| `stock_counts` | scope, warehouse, number, status, frozen_at, approved/posted actors/times | UQ branch+number; V until posted, then AO |
| `stock_count_lines` | scope, count, item/location, expected/counted/variance quantities, reason | UQ count+item+location; posted variance creates movement |
| `wastage_records` | scope, item/location, quantity, reason, cost snapshot, actor/approver, occurred_at | AO; creates stock movement; correction references original |
| `stock_transfers` | tenant, from/to branches+warehouses, number, state, dispatch/receipt actors/times | two tenant-scoped branch FKs; UQ tenant+number; AO after receipt |
| `stock_transfer_items` | tenant, transfer, item, requested/dispatched/received quantities, unit, cost | UQ transfer+line; receipt posts paired movements |

## Suppliers & Procurement

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `suppliers` | tenant, code, names, tax/contact/payment data, default currency, status | UQ active tenant+code; SD/anonymizable |
| `supplier_items` | tenant, supplier, inventory item, supplier SKU, lead time, last price/currency | UQ supplier+item; V |
| `purchase_orders` | tenant, destination branch/warehouse, supplier, number, status, dates, currency, totals, actors | UQ tenant+number; V until issued, AO after final close |
| `purchase_order_items` | tenant, PO, line, item, quantity/unit, unit cost, tax, totals | UQ PO+line; snapshot |
| `goods_receipts` | tenant/branch, warehouse, PO nullable, number, supplier document, actor/time, state | UQ branch+number; AO when posted |
| `goods_receipt_items` | scope, receipt, PO line nullable, item/location, received/rejected qty, lot/expiry, cost | UQ receipt+line; posting creates movements |
| `supplier_returns` | tenant/branch, supplier, warehouse, number, reason, status, actor/times | UQ branch+number; AO after dispatch |
| `supplier_return_items` | scope, return, receipt item/item, quantity/unit/cost | UQ return+line; creates outbound movement |

## Customers, CRM & Loyalty

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `customers` | tenant, canonical phone/email hashes, localized name, locale, status, merged_into nullable | active UQ tenant+phone/email; SD/anonymizable |
| `customer_addresses` | tenant, customer, label, localized address fields, coordinates, zone nullable, default flag | one active default per customer; SD |
| `customer_consents` | tenant, customer, channel/purpose, status, source, captured/withdrawn times, policy revision | AO consent history; current state is latest valid event |
| `customer_preferences` | tenant, customer, locale, quiet hours, default branch/address | UQ customer; mutable non-compliance preferences |
| `loyalty_accounts` | tenant, customer, program code, state, cached balance | UQ tenant+customer+program; balance derived from ledger |
| `loyalty_transactions` | tenant, account, order nullable, signed points, type, expires, original/reversal IDs | AO ledger; UQ operation/idempotency; IX account+occurred |
| `customer_feedback` | tenant/branch, customer/order nullable, rating, category, comment, channel, state | SD/anonymizable; IX branch+created+state |

## Delivery & External Aggregators

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `delivery_zones` | scope, code, localized name, geometry, fee/minimum/SLA, status | UQ active branch+code; spatial IX; V/SD |
| `drivers` | tenant, user/employee nullable, provider, code, status | UQ active tenant+code; SD |
| `deliveries` | scope, order, customer/address snapshot, zone, driver nullable, fee, state, promised times | UQ order; IX branch+state+created; state history separate |
| `delivery_events` | scope, delivery, sequence, state/event, actor/source, location nullable, occurred_at | AO; UQ delivery+sequence |
| `aggregator_accounts` | tenant/branch, provider, external merchant ID, status, secret reference | UQ provider+external merchant; V; credentials outside DB |
| `external_orders` | scope, aggregator account, external ID/version, raw safe snapshot, state, received_at | UQ account+external ID; AO revisions |
| `external_order_mappings` | scope, external order, internal order, mapping state | UQ external-order+internal-order and UQ internal-order; one external order may split into several internal orders, but an internal order has at most one external origin |
| `aggregator_events` | scope, account/external order, external event ID, type/version, payload hash, received_at | AO; UQ provider event; webhook dedup ledger |

## Workforce & HR Inputs

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `employees` | tenant, user nullable, employee number, names, phone hash, employment status, hired/ended dates | UQ tenant+number; SD/anonymizable |
| `employment_assignments` | tenant, employee, branch, role/job title, effective interval, pay-input profile | AO effective-dated history; no overlapping equivalent assignment |
| `work_schedules` | scope, employee, starts/ends, role, status, published revision | UQ employee+start; V |
| `attendance_events` | scope, employee, device, type, occurred_at, source, correction_of nullable | AO; IX employee+occurred; corrections append |
| `attendance_periods` | scope, employee, business date, clock-in/out event IDs, worked/break minutes, state | derived/approved projection; UQ employee+date+sequence, UQ each non-null boundary event, and boundaries must differ |
| `leave_requests` | tenant, employee, date range, type, state, requester/approver | V through approval; AO once finalized |
| `payroll_inputs` | tenant/branch, employee, period, input type, quantity/amount/currency, source, approval | AO after approval; not a full payroll engine |
| `tip_pools` | scope, shift/period, amount/currency, policy revision, state | AO after distribution |
| `tip_allocations` | scope, tip pool, employee, basis, amount | UQ pool+employee; AO |

## Notifications, Documents & Reporting

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `notification_templates` | tenant, code, channel, locale, current revision, status | UQ tenant+code+channel+locale; SD/V |
| `notification_template_versions` | tenant, template, revision, subject/body, variables schema, checksum, approved_at | UQ template+revision; AO after publish |
| `notifications` | tenant/branch nullable, recipient type/id, template revision, event, scheduled/status times | IX tenant+status+scheduled; mutable delivery job |
| `notification_attempts` | tenant, notification, provider, attempt, status, safe response code, cost, times | AO; UQ notification+attempt |
| `notification_receipts` | tenant, notification/attempt, provider receipt ID, state, occurred_at | AO; UQ provider+receipt ID |
| `report_definitions` | tenant, code, parameters schema, output formats, status | UQ tenant+code; V/SD |
| `report_schedules` | tenant/branch nullable, definition, cron/timezone, recipients, parameters, status | UQ scope+definition+schedule code; SD |
| `report_runs` | tenant/branch nullable, definition/schedule, period, state, started/finished, error code | AO run history; IX state+created |
| `generated_documents` | tenant/branch nullable, report/invoice owner, file asset, format/locale, checksum, retention | AO metadata; file deletion follows retention/legal hold |
| `branch_daily_facts` | scope, business date, metric dimensions, amounts/counts, source watermark | rebuildable read model; UQ branch+date+dimensions |

Operational logs belong in centralized observability storage. MySQL retains only durable audit,
activity, notification/report run metadata, and references needed for business traceability.