# NKZ Marketplace – Vendor Dashboard

Vendor frontend dashboard rozšířující WooCommerce My Account.

## Funkce v 0.1.0

- `/muj-ucet/vendor` — Přehled (status, statistiky, quick actions, KYC callout)
- `/muj-ucet/vendor-products` — List vlastních produktů s Edit linkem
- `/muj-ucet/vendor-payouts` — Historie transferů + balance
- Vendor role redirect z wp-admin na `/muj-ucet/vendor`
- Admin bar skrytý pro vendory

## Závislosti

- `nkz-marketplace` core ≥ 0.10.0-dev
- WooCommerce ≥ 8.0
- Vendor musí mít `_nkzmp_wp_user_id` (nebo legacy `_nkv_wp_user_id`) meta s ID WP usera

## Mapování WP user ↔ vendor

Bez tohoto vendor neuvidí dashboard:

```sql
-- Příklad ručního propojení (po vytvoření WP usera pro vendora)
UPDATE wp_postmeta
SET meta_value = '<wp_user_id>'
WHERE post_id = <vendor_post_id> AND meta_key = '_nkzmp_wp_user_id';
```

Plánuje se auto-link při schválení vendora (vytvoří WP user s vendor rolí + propojí), zatím manuální.

## Mimo 0.1.0

- ❌ Frontend product create/edit (zatím odkaz na wp-admin pro `edit_post`)
- ❌ Vendor profile edit (bio, web, image) — zatím v admin
- ❌ Messaging
- ❌ Order detail view
