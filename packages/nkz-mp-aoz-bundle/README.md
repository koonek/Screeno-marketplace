# NKZ Marketplace AOZ Bundle

Tenký wrapper kolem samostatných modulů. Distribuovaný jako jeden ZIP — uživatel nahraje jeden plugin, dostane vše najednou.

## Co bundle obsahuje (verze 0.1.0)

- `modules/nkz-marketplace` — core
- `modules/nkz-woo-stripe-vendor-split` — Stripe Connect vendor split
- `modules/nkz-mp-storefront` — vendor pages + Elementor Dynamic Tags

## Co bundle **nebude** obsahovat (Phase 1 dotahování)

- `modules/nkz-mp-vendor-registration` — frontend reg + 2-stage approval *(TODO)*
- `modules/nkz-mp-vendor-billing` — Stripe Billing CZK subscription *(TODO)*
- `modules/nkz-mp-shipping` — per-vendor paušál *(TODO)*

S přidáním modulu bumpne bundle minor verzi.

## Build

V `scripts/build-bundles.sh` (root repa) je shell skript, který:

1. Zkopíruje `packages/nkz-marketplace/`, `packages/nkz-mp-stripe/`, `packages/nkz-mp-storefront/` do build-dir jako `modules/`
2. Přidá `nkz-mp-aoz-bundle.php` z tohoto packagu
3. Zipuje `dist/nkz-marketplace-aoz-<version>.zip`

```bash
./scripts/build-bundles.sh
```

Spustitelné lokálně, ne na serveru.

## Migrace pro stávající AOZ staging

Pokud máš na AOZ staging už 3 samostatné pluginy (core, adapter, storefront):

1. **Deaktivuj** všechny 3 (NESMAZÁVEJ — uninstall.php by smazal DB tabulky a roli)
2. **Install** bundle ZIP přes Plugins → Add New → Upload
3. **Aktivuj** bundle
4. Bundle při aktivaci zjistí že DB schéma už existuje (idempotentní via `Schema::needs_install()`) → nic neudělá, data zůstanou
5. Po ověření že vše funguje, smaž staré plugin složky **přes FTP** (ne WP delete, který by spustil uninstall.php)

## Settings & UI

Bundle nemá vlastní UI. Settings stránek je víc, podle modulů:

- **WooCommerce → NKZ Marketplace** (core status + ledger / payouts)
- **WooCommerce → NKZ Marketplace Tools** (migrace, reconcile, status changes)
- **WooCommerce → Stripe Vendor Split** (adapter config)
- **WooCommerce → NKZ Storefront** (storefront slugy)
