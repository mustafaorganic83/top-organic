# Schema Catalog — Platform, Identity & Catalog

Notation: `PK` primary key, `FK` foreign key, `UQ` unique key, `IX` non-unique index,
`SD` soft-deleted, `AO` append-only, `V` versioned. Unless marked global, every table has a
ULID `id`; every owned table has its scope-aware candidate key described in document 05.

## Platform & Company Structure

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `tenants` | `slug`, legal/display names, `status`, default locale/currency/timezone | UQ `slug`; SD; one row per restaurant company |
| `branches` | `tenant_id`, `code`, names, timezone, region-policy version, status, address, phone | FK tenant; UQ `(tenant_id,code)`; SD |
| `branch_settings` | tenant/branch, `setting_key`, typed value JSON, revision | UQ scope+key; V; branch override only |
| `region_policies` | tenant nullable, `code`, country, locale, currencies, timezone, status | global template or tenant-owned; UQ tenant+code; SD |
| `region_policy_versions` | policy, `version`, effective interval, calculation/format JSON, checksum | UQ policy+version; AO; approved policy snapshot |
| `tenant_settings` | tenant, key, typed value JSON, revision | UQ tenant+key; V; no branch data |
| `feature_flags` | tenant, key, default value, effective interval | UQ tenant+key+effective_from; V |
| `branch_feature_overrides` | tenant/branch, flag, value, effective interval | scoped FK flag and branch; V |
| `translations` | tenant, owner type/id, locale, field, text, revision | UQ tenant+owner+locale+field; V; intentional logical FK exception |
| `business_sequences` | tenant/branch, document type, fiscal period, next value, lock version | UQ scope+type+period; mutable under row lock; numbers are gap-tolerant |
| `file_assets` | tenant, branch nullable, owner type/id, object key, MIME, bytes, checksum, status | UQ tenant+object key; SD after retention; private object-store metadata |
| `currencies` | ISO code, exponent, enabled | global; PK ISO `CHAR(3)`; IQD exponent 0, USD exponent 2 |
| `exchange_rates` | tenant, branch nullable, from/to currencies, `DECIMAL(18,8)` rate, effective interval, source, setter/approver | UQ scope+pair+effective_from; AO after approval; IX scope+pair+effective interval |
| `locales` | BCP-47 code, direction, fallback code | global; PK locale code; Arabic/English metadata |

## Identity, RBAC & Devices

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `users` | tenant, phone/email canonical hashes, Arabic/English names, password hash, locale, status | UQ active tenant+phone/email; SD/anonymizable |
| `user_credentials` | tenant, user, type, secret hash, failed count, locked/expires timestamps | FK user; one active credential per type; secrets never audited |
| `roles` | tenant, code, localized names, system flag, status | UQ active tenant+code; SD |
| `permissions` | code, module, resource, action, risk level | platform-global seeded catalog; UQ code |
| `role_permissions` | tenant, role, permission, granted_by/at | UQ role+permission; controlled cascade from draft/custom role only |
| `user_branch_roles` | tenant, user, branch, role, effective/revoked timestamps, grant actors | append grant/revoke history; only one active equivalent grant |
| `user_tenant_roles` | tenant, user, role, effective/revoked timestamps | company-wide owner/chain roles; active-grant UQ |
| `devices` | tenant/branch, code, type, hardware public fingerprint, app/OS versions, status, last_seen | UQ tenant+code and hardware fingerprint; SD only after revocation |
| `device_approvals` | tenant/branch, device, requested/decided actors/times, decision, reason | AO decisions; IX pending branch requests |
| `device_sessions` | tenant/branch, device, user nullable, opened/expires/revoked timestamps | AO session lifecycle; IX active device sessions |
| `access_tokens` | tenant, device, user nullable, token hash, abilities JSON, expires/last_used/revoked | UQ token hash; hard purge after retention; never store clear token |
| `authentication_events` | tenant, branch nullable, user/device, event, result, safe network metadata | AO security ledger; no credential/PII payload |

## Catalog, Menu & Pricing

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `categories` | tenant, parent nullable, code, sort order, status | self scoped FK; UQ active tenant+parent+code; SD/V |
| `products` | tenant, category, SKU, type, localized content via translations, status, sellable flag | scoped FKs; UQ active tenant+SKU; SD/V |
| `product_variants` | tenant, product, code, barcode, sort order, status | UQ active tenant+product+code and barcode; SD/V |
| `modifier_groups` | tenant, code, min/max selections, required flag, status | UQ active tenant+code; SD/V |
| `modifier_options` | tenant, group, code, default surcharge, sort order, status | UQ active group+code; SD/V |
| `product_modifier_groups` | tenant, product/variant nullable, group, sort order, min/max override | scoped FKs; UQ target+group |
| `allergens` | tenant nullable, code, severity metadata | global template or tenant extension; UQ tenant+code |
| `product_allergens` | tenant, product, allergen, containment type | UQ product+allergen |
| `combos` | tenant, product, version, status | product represents sellable combo; UQ product+version; V |
| `combo_components` | tenant, combo, slot code, product/variant nullable, quantity, surcharge | UQ combo+slot+target; component rows owned by combo version |
| `price_lists` | tenant, code, currency, channel, effective interval, status, revision | UQ tenant+code+revision; V; approved publication required |
| `price_list_items` | tenant, price list, product variant, amount minor, tax class | UQ price-list+variant; owned by price-list revision |
| `branch_price_lists` | tenant/branch, price list, priority, effective interval | UQ branch+price-list+effective_from; branch publication |
| `branch_catalog_items` | tenant/branch, product variant, available flag, station nullable, schedule nullable | UQ branch+variant; replicated cloud→edge |
| `menu_schedules` | tenant/branch nullable, code, day/time windows, effective interval | UQ scope+code+effective_from; V |
| `menu_schedule_items` | tenant, schedule, product/category target | UQ schedule+target |

## Recipes & Units

| Table | Essential columns | Relations, keys, lifecycle |
|---|---|---|
| `units_of_measure` | tenant nullable, code, dimension, scale | global/tenant; UQ tenant+code; never delete once used |
| `unit_conversions` | tenant nullable, from/to unit, factor `DECIMAL(18,8)` | UQ tenant+from+to; V; same dimension check |
| `recipes` | tenant, finished product/variant, code, current revision, status | UQ active tenant+code; SD/V |
| `recipe_versions` | tenant, recipe, revision, yield quantity/unit, effective interval, checksum, status | UQ recipe+revision; AO after publish |
| `recipe_components` | tenant, recipe version, inventory item, quantity, unit, wastage factor | UQ version+item+line; component snapshot |

## Catalog Write Authority

- Company masters and drafts: cloud authoritative.
- Branch availability, station routing, and emergency stock-out flags: branch-authorized,
  synchronized with explicit revisions.
- Published price, recipe, policy, and translation revisions are immutable.
- Orders always snapshot product names, SKU, variant, price, tax class, and recipe revision
  needed for historical accuracy; they never depend on later catalog edits.