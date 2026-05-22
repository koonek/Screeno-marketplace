# NKZ Marketplace (core)

Marketplace jádro pro WooCommerce. **Nedělá platby** – platby řeší adaptéry (např. [`nkz-mp-stripe`](../nkz-mp-stripe)).

## Co je v jádře

- **Vendor model** – CPT `nkzmp_vendor`, role `nkzmp_vendor`, capabilities matrix, statusy (`pending` → `approved_awaiting_kyc` → `active` → `suspended` → `terminated`).
- **Product ownership** – meta `_nkzmp_vendor_id` na produktech/variacích, capability guard.
- **Allocation Service** – `WC_Order` → `Allocation[]` (gross, commission, shipping_share, tax_share, fee_share, net). PSP-agnostic.
- **Append-only Ledger** – `wp_nkzmp_ledger`. Korekce = nový řádek s `reverses_id`.
- **Payout state machine** – `pending → payable → on_hold → paid → failed → reversed`.
- **Shipping ownership kontrakt** – flag + filter, kdo dostane shipping částku (implementace v `nkz-mp-shipping` add-onu).
- **Adapter interfaces** – `PaymentAdapterInterface`, `PayoutAdapterInterface`, `SubscriptionAdapterInterface`.
- **REST API** – `nkzmp/v1/*`.
- **WP-CLI** – `wp nkzmp vendor|order|ledger ...`.
- **Audit log** + **GDPR hooks** + **i18n**.

## Co v jádře není

- Konkrétní PSP (Stripe → `nkz-mp-stripe`).
- Vendor subscription billing → `nkz-mp-vendor-billing`.
- Frontend registrace vendora → `nkz-mp-vendor-registration`.
- Storefront / vendor page → `nkz-mp-storefront`.
- Per-vendor shipping kalkulace → `nkz-mp-shipping`.
- Topování / promoted listings → `nkz-mp-promoted-listings`.

## Stav

`0.1.0-dev` – skeleton. Fáze 0 refactoring probíhá: extrakce z `nkz-mp-stripe`, rename `NKVSVS` → `NKZMP`.
