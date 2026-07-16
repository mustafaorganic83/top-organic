# ER Diagrams

Diagrams are intentionally bounded; the schema catalog remains authoritative for columns,
scope, indexes, and delete behavior. `TENANT` represents a restaurant company.

## Platform, Identity & Catalog

```mermaid
erDiagram
  TENANT ||--o{ BRANCH : owns
  TENANT ||--o{ USER : employs
  USER ||--o{ USER_BRANCH_ROLE : receives
  BRANCH ||--o{ USER_BRANCH_ROLE : scopes
  ROLE ||--o{ USER_BRANCH_ROLE : grants
  ROLE ||--o{ ROLE_PERMISSION : contains
  PERMISSION ||--o{ ROLE_PERMISSION : assigned
  BRANCH ||--o{ DEVICE : operates
  USER ||--o{ DEVICE_APPROVAL : approves
  DEVICE ||--o{ DEVICE_APPROVAL : reviewed_through
  TENANT ||--o{ CATEGORY : catalogs
  CATEGORY ||--o{ CATEGORY : parent_of
  CATEGORY ||--o{ PRODUCT : classifies
  PRODUCT ||--o{ PRODUCT_VARIANT : varies
  PRODUCT ||--o{ PRODUCT_MODIFIER_GROUP : offers
  MODIFIER_GROUP ||--o{ PRODUCT_MODIFIER_GROUP : attached
  MODIFIER_GROUP ||--o{ MODIFIER_OPTION : contains
  TENANT ||--o{ PRICE_LIST : defines
  PRICE_LIST ||--o{ PRICE_LIST_ITEM : prices
  PRODUCT_VARIANT ||--o{ PRICE_LIST_ITEM : priced
  BRANCH ||--o{ BRANCH_CATALOG_ITEM : publishes
  PRODUCT_VARIANT ||--o{ BRANCH_CATALOG_ITEM : available
  PRODUCT ||--o{ RECIPE : produced_by
  RECIPE ||--o{ RECIPE_COMPONENT : consumes
  INVENTORY_ITEM ||--o{ RECIPE_COMPONENT : ingredient
```

## POS, Kitchen & Billing

```mermaid
erDiagram
  BRANCH ||--o{ FLOOR : has
  FLOOR ||--o{ DINING_TABLE : contains
  DINING_TABLE ||--o{ TABLE_SESSION : opens
  BRANCH ||--o{ POS_SHIFT : runs
  POS_SHIFT ||--o{ CASH_DRAWER_SESSION : controls
  TABLE_SESSION o|--o{ ORDER : groups
  CUSTOMER o|--o{ ORDER : places
  DEVICE ||--o{ ORDER : captures
  ORDER ||--|{ ORDER_ITEM : contains
  ORDER_ITEM ||--o{ ORDER_ITEM_MODIFIER : selects
  ORDER ||--o{ ORDER_DISCOUNT : discounts
  ORDER ||--o{ ORDER_EVENT : changes
  KDS_STATION ||--o{ KDS_TICKET : receives
  ORDER_ITEM ||--o{ KDS_TICKET_ITEM : routed
  KDS_TICKET ||--o{ KDS_TICKET_ITEM : contains
  KDS_TICKET ||--o{ KDS_TICKET_EVENT : changes
  ORDER ||--o{ PAYMENT : settles
  PAYMENT ||--o{ PAYMENT_ALLOCATION : allocates
  ORDER ||--o{ PAYMENT_ALLOCATION : receives
  PAYMENT o|--o{ PAYMENT_REVERSAL : reversed_by
  ORDER ||--o{ INVOICE : documented
  INVOICE ||--|{ INVOICE_LINE : snapshots
  INVOICE_LINE ||--o{ INVOICE_TAX_LINE : taxed
  INVOICE o|--o{ CREDIT_NOTE : corrected_by
```

## Inventory, Procurement, CRM & Delivery

```mermaid
erDiagram
  TENANT ||--o{ INVENTORY_ITEM : owns
  BRANCH ||--o{ WAREHOUSE : operates
  WAREHOUSE ||--o{ STORAGE_LOCATION : contains
  INVENTORY_ITEM ||--o{ STOCK_BALANCE : balances
  STORAGE_LOCATION ||--o{ STOCK_BALANCE : stores
  INVENTORY_ITEM ||--o{ STOCK_MOVEMENT : moves
  STOCK_TRANSFER ||--|{ STOCK_TRANSFER_ITEM : contains
  BRANCH ||--o{ STOCK_TRANSFER : sends
  BRANCH ||--o{ STOCK_TRANSFER : receives
  SUPPLIER ||--o{ PURCHASE_ORDER : supplies
  PURCHASE_ORDER ||--|{ PURCHASE_ORDER_ITEM : contains
  PURCHASE_ORDER ||--o{ GOODS_RECEIPT : fulfilled_by
  GOODS_RECEIPT ||--|{ GOODS_RECEIPT_ITEM : contains
  CUSTOMER ||--o{ CUSTOMER_ADDRESS : has
  CUSTOMER ||--o{ LOYALTY_ACCOUNT : owns
  LOYALTY_ACCOUNT ||--o{ LOYALTY_TRANSACTION : posts
  BRANCH ||--o{ DELIVERY_ZONE : covers
  ORDER ||--o| DELIVERY : fulfilled_as
  DELIVERY ||--o{ DELIVERY_EVENT : changes
  AGGREGATOR_ACCOUNT ||--o{ EXTERNAL_ORDER : imports
  EXTERNAL_ORDER ||--|{ EXTERNAL_ORDER_MAPPING : maps
  ORDER ||--o| EXTERNAL_ORDER_MAPPING : linked
```

## Synchronization, Versioning & Audit

```mermaid
erDiagram
  DEVICE ||--|| DEVICE_SEQUENCE : owns
  DEVICE ||--o{ SYNC_OUTBOX_OPERATION : emits
  SYNC_OUTBOX_OPERATION ||--o| SYNC_INBOX_RECEIPT : acknowledged
  SYNC_OUTBOX_OPERATION ||--o{ SYNC_ATTEMPT : attempted
  SYNC_OUTBOX_OPERATION ||--o| SYNC_CONFLICT : quarantined
  SYNC_CONFLICT ||--o{ SYNC_CONFLICT_ACTION : resolved
  DEVICE ||--o{ SYNC_PULL_CURSOR : tracks
  SYNC_CHANGE_LOG_ENTRY ||--o{ SYNC_TOMBSTONE : may_delete
  CONFIG_CHANGE_SET ||--|{ ENTITY_REVISION : groups
  CONFIG_CHANGE_SET ||--o| PUBLICATION_MANIFEST : publishes
  PUBLICATION_MANIFEST ||--|{ PUBLICATION_ITEM : contains
  DEVICE ||--o{ PUBLICATION_ACKNOWLEDGEMENT : acknowledges
  USER o|--o{ AUDIT_EVENT : acts
  DEVICE o|--o{ AUDIT_EVENT : sources
  ACTIVITY_EVENT ||--o{ ACTIVITY_RECIPIENT : delivered
  USER ||--o{ ACTIVITY_RECIPIENT : reads
```

## Workforce, Notifications & Reporting

```mermaid
erDiagram
  USER o|--o| EMPLOYEE : represents
  EMPLOYEE ||--o{ EMPLOYMENT_ASSIGNMENT : assigned
  BRANCH ||--o{ EMPLOYMENT_ASSIGNMENT : hosts
  EMPLOYEE ||--o{ WORK_SCHEDULE : scheduled
  EMPLOYEE ||--o{ ATTENDANCE_EVENT : records
  ATTENDANCE_EVENT ||--o| ATTENDANCE_PERIOD : bounds
  POS_SHIFT o|--o{ TIP_POOL : funds
  TIP_POOL ||--|{ TIP_ALLOCATION : distributes
  EMPLOYEE ||--o{ TIP_ALLOCATION : receives
  NOTIFICATION_TEMPLATE ||--|{ NOTIFICATION_TEMPLATE_VERSION : versions
  NOTIFICATION_TEMPLATE_VERSION ||--o{ NOTIFICATION : renders
  NOTIFICATION ||--o{ NOTIFICATION_ATTEMPT : attempts
  NOTIFICATION_ATTEMPT ||--o{ NOTIFICATION_RECEIPT : receives
  REPORT_DEFINITION ||--o{ REPORT_SCHEDULE : schedules
  REPORT_DEFINITION ||--o{ REPORT_RUN : executes
  REPORT_SCHEDULE o|--o{ REPORT_RUN : triggers
  REPORT_RUN ||--o{ GENERATED_DOCUMENT : produces
```

## Cardinality Rules

- A company owns many branches; a branch belongs to exactly one company.
- Company-level masters are shared only through branch override/publishing tables.
- Users are company-owned and gain branch access only through active role grants.
- Orders, devices, tables, shifts, KDS, warehouses, deliveries, and ledgers are branch-owned.
- Transfers are company-owned and reference two branches of the same company.
- Historical, financial, stock, sync receipt, and audit records are append-only.
- Generic audit/translation targets are logical references; every other relationship uses an
  enforced scope-aware foreign key unless explicitly identified as an external-system ID.