# NKZ Marketplace – Capabilities Matrix

Canonical zdroj: `NKZMP\Support\Capabilities` (`packages/nkz-marketplace/includes/support/class-capabilities.php`).

## Role

- **administrator** – plný přístup, dostává všechny capabilities při aktivaci.
- **shop_manager** – WC default + vybrané NKZMP capabilities pro provoz marketplace.
- **nkzmp_vendor** – nová role, registrovaná coreem. Vidí jen své entity.

## Capabilities

| Capability | admin | shop_manager | nkzmp_vendor |
|---|:-:|:-:|:-:|
| `nkzmp_manage_vendors` | ✓ | ✓ | – |
| `nkzmp_approve_vendor` | ✓ | ✓ | – |
| `nkzmp_view_audit_log` | ✓ | – | – |
| `nkzmp_manage_payouts` | ✓ | ✓ | – |
| `nkzmp_view_own_dashboard` | ✓ | ✓ | ✓ |
| `nkzmp_edit_own_products` | ✓ | ✓ | ✓ |
| `nkzmp_view_own_orders` | ✓ | ✓ | ✓ |
| `nkzmp_view_own_payouts` | ✓ | ✓ | ✓ |

## Guard pro „own" entity

Capability sama nestačí – přístup k cizím entitám blokuje **OwnershipGuard** (REST `permission_callback`, admin `current_user_can` filter, capability map filter). Vendor s `nkzmp_edit_own_products` může editovat **jen produkty s `_nkzmp_vendor_id === jeho vendor_id`**.

## Mapování vendor → WP user

Vendor (CPT `nkzmp_vendor`) má meta `_nkzmp_wp_user_id`. WP user má taxonomy/usermeta odkaz zpět. Vendor lze přiřadit max 1 WP uživateli v MVP (multi-user team až ve Fázi 2+).
