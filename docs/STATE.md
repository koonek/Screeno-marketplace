# NKZ Marketplace – stav & handoff

> Živý stav projektu. Aktualizuj při větších milnících. Vše commitnuté na
> branchi `claude/trusting-fermat-YBzZT`, PR #19 (Phase 1).

## Aktuální verze (bundle 0.23.10)

| Modul | Verze |
|---|---|
| nkz-marketplace (core) | 0.10.15-dev |
| nkz-mp-stripe (adapter, slug nkz-woo-stripe-vendor-split) | 0.6.10 |
| nkz-mp-storefront | 0.5.0 |
| nkz-mp-vendor-registration | 0.5.9 |
| nkz-mp-vendor-dashboard | 0.10.7 |
| nkz-mp-shipping | 0.1.0 |
| nkz-mp-vendor-billing | 0.5.2 |
| nkz-mp-packeta | 0.2.2 |
| **nkz-mp-aoz-bundle** | **0.23.10** |

Build: `./scripts/build-bundles.sh` → `dist/nkz-marketplace-aoz-<ver>.zip`.

## Architektura (rychlý kontext)

- **Monorepo** `packages/`, každý modul = samostatný WP plugin.
- **Bundle** (`nkz-mp-aoz-bundle`) je tenký wrapper – `require_once` všech modulů + jednotná aktivace. Distribuuje se jako 1 ZIP pro AOZ.
- **Screeno** používá jen core + adapter (separátně). **AOZ** používá bundle (vše).
- Moduly mají cross-module reference vždy guardované `class_exists` / `defined`.
- AOZ design: bílá/černá + accent `#0060FF`, font Fabio XM (theme @font-face), kurátorský minimalismus.

## Hotovo (Fáze 0 + Fáze 1)

- **Core:** vendor model, ledger (append-only), payouts state machine, audit, reconciliation cron + Stripe driver, REST `nkzmp/v1/*`, WP-CLI, top-level admin menu + Dashboard (pending vendoři/produkty inline approve, billing přehled, config health filter `nkzmp/v1/admin/health_checks`), Tools (migrace/reconcile/backfill/cleanup rolí), GDPR, OwnershipGuard (email fallback), StatusService, MetaMigrator.
- **Stripe adapter:** Connect split plateb, webhook (signature ověřený), reconciliation driver, observer → ledger.
- **Storefront:** `/vendors`, `/vendor/<slug>`, hybrid render (Elementor TB / theme / fallback), Elementor Dynamic Tags + Loop Grid query, product→vendor link, SEO.
- **Registration:** frontend form + ARES, 2-stage approval, AOZ HTML e-maily (editovatelné), status page (magic link), auto-create WP user + password e-mail, MetaWatcher (mirror status + emit hook).
- **Dashboard (WC My Account):** přehled + onboarding checklist + provize, produkty (card grid, frontend editor create/edit, edit publ. zůstává live, stáhnout/smazat), objednávky, výplaty, profil (self-service), redirect vendor z wp-admin, AOZ branding.
- **Shipping:** per-vendor paušál, product requires_shipping flag, admin meta box + vendor self-service sazba.
- **Billing:** Stripe Billing subscription (CZK konfig.), Checkout + portal, webhook (signature), aktivace i na návratu, enforcement (bez předplatného nelze prodávat), grace cron fallback, admin přehled (MRR), health checks.
- **Packeta:** výběr výdejny (widget v6), cena = per-vendor paušál, zobrazení u objednávky/e-mailů. **Auto-štítky (0.2.0):** `createPacket` per vendor + PDF štítek z dashboardu prodejce i admin detailu objednávky (idempotentní, multi-vendor split, váha z produktu/fallback, dobírka u `cod`). Odesílatel = per-vendor `eshop` label (fallback globální); adresa odesílatele v profilu prodejce (`_nkzmp_sender_*`). **Zrušení zásilky (0.2.1):** `cancelPacket` tlačítko v dashboardu i admin objednávce (užitečné pro testování bez přístupu do Packeta klienta). **Čeká na Packeta API klíč (widget) + API heslo (štítky).**
- **Vendor order e-mail** při processing/completed.

## Zbývá – polish (neblokuje launch)

### Admin / ops batch (oranžová)
- [x] **Vendor detail** ✅ (0.22.0, VendorDetailPage – read-only konsolidace: identita+status, Stripe Connect, adresa pro odeslání, finance z ledgeru + poslední pohyby, produkty; panel hook `nkzmp/v1/admin/vendor_detail/panels`; řádková akce „NKZ detail")
- [x] **Bulk approve** vendorů ✅ (0.19.0, AdminBulk – bulk akce „Schválit (NKZ) → čeká na KYC" ve vendor list table; produkty řeší WC nativně)
- [x] **Reconcile drift → e-mail adminovi** ✅ (0.18.0, DriftNotifier, dedupe 12h)
- [ ] **Setup wizard / first-run checklist** pro admina
- [x] **Unified Settings** ✅ (0.22.0, SettingsHub „Nastavení" s taby přes filter `nkzmp/v1/admin/settings/tabs`; Packeta + Billing migrované jako taby s fallbackem na vlastní submenu když hub chybí. Tools/Status zůstávají vlastní (nejsou config), Stripe je pod WC.)

### Correctness / robustnost (žlutá)
- [x] **Refund → reverzace provize** ✅ (0.10.9-dev, LegacyStripeObserver::record_reversals – při reverzaci transferu zapíše i proporční REVERSAL platform provize, vendor_id=0; dřív zůstávala provize započtená)
- [ ] **Terminated vendor** – profil viditelný 30 dní pak archiv (plán); teď neimplementováno
- [x] **Vendor orders pagination** ✅ (0.9.0, OrderVendorIndex meta `_nkzmp_order_vendor` + stránkovaná query přes wc_get_orders; fallback sken + lazy backfill indexu)
- [x] **HPOS** ✅ (0.22.0, declare_compatibility `custom_order_tables` doplněno do packeta + dashboard; core + adapter měly už dřív)

### Fáze 2 (po launchi)
Packeta: tracking sync + ceník dle váhy + adresní doručení (auto-štítky hotové v 0.2.0), Packeta výběr výdejny v blokovém checkoutu (Blocks integrace + Store API), reviews, messaging, topování produktů, pokročilý shipping (zóny/váha), CZ/SK tax pack (DPH/OSS/faktury/VIES), i18n .pot/.po, Stripe adapter → first-class PaymentAdapter, pure-math Allocation\Calculator.

## Před produkcí (go-live checklist)

- [ ] E2E test ve Stripe test módu dle `docs/test-scenarios-aoz.md` (sekce 1-8)
- [ ] Billing webhook signing secret vyplněný (NKZ Marketplace → Billing)
- [ ] Stripe adapter webhook signing secret vyplněný
- [ ] Packeta účet + API klíč (pokud výdejní místa od startu)
- [ ] Reálné AOZ Stripe live klíče (teď test)
- [ ] Fabio XM @font-face v theme
- [ ] Permalinks → Save po každé instalaci/upgradu

## Známé „by design" (ne bugy)

- Shipping = 1 řádek se součtem (ne řádek per vendor) – WC standard
- Packeta odesílatel = `eshop` label v účtu (createPacket neumí volnou adresu odesílatele); per-vendor odesílatele nutno jednorázově založit v Packeta klientovi a namapovat label do profilu prodejce
- **Packeta výběr výdejny vyžaduje klasický checkout** (`[woocommerce_checkout]` shortcode **nebo Elementor Pro widget Pokladna** – oba renderují classic review-order tabulku). CheckoutWidget se věší na `woocommerce_review_order_after_shipping`. **Blokový checkout (Gutenberg Checkout block) NEPODPOROVÁN** (nevolá classic hooky → picker se nevykreslí). Blocks integrace = Scope B.
- Billing webhook funguje i bez secretu pro prvotní aktivaci (sync na návratu); secret nutný pro renewaly
- CZ texty natvrdo (žádné .pot)
- Dva webhook endpointy/secrety (adapter Connect + billing) – Stripe standard, nelze sdílet
