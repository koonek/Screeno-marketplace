# NKZ Marketplace – stav & handoff

> Živý stav projektu. Aktualizuj při větších milnících. Vše commitnuté na
> branchi `claude/trusting-fermat-YBzZT`, PR #19 (Phase 1).

## Aktuální verze (bundle 0.17.0)

| Modul | Verze |
|---|---|
| nkz-marketplace (core) | 0.10.7-dev |
| nkz-mp-stripe (adapter, slug nkz-woo-stripe-vendor-split) | 0.6.7 |
| nkz-mp-storefront | 0.4.0 |
| nkz-mp-vendor-registration | 0.4.0 |
| nkz-mp-vendor-dashboard | 0.7.0 |
| nkz-mp-shipping | 0.1.0 |
| nkz-mp-vendor-billing | 0.5.0 |
| nkz-mp-packeta | 0.1.0 |
| **nkz-mp-aoz-bundle** | **0.18.0** |

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
- **Packeta:** výběr výdejny (widget v6), cena = per-vendor paušál, zobrazení u objednávky/e-mailů. Štítky ručně. **Čeká na Packeta API klíč.**
- **Vendor order e-mail** při processing/completed.

## Zbývá – polish (neblokuje launch)

### Admin / ops batch (oranžová)
- [ ] **Vendor detail** – 1 admin obrazovka per vendor (status, billing, produkty, tržby, ledger)
- [ ] **Bulk approve** vendorů/produktů
- [x] **Reconcile drift → e-mail adminovi** ✅ (0.18.0, DriftNotifier, dedupe 12h)
- [ ] **Setup wizard / first-run checklist** pro admina
- [ ] **Unified Settings** – jedna stránka s taby místo 6 podstránek (čistě organizační, ~půl dne)

### Correctness / robustnost (žlutá)
- [ ] **Refund → reverzace provize** – ověřit proporční vrácení platform commission
- [ ] **Terminated vendor** – profil viditelný 30 dní pak archiv (plán); teď neimplementováno
- [ ] **Vendor orders pagination** – OrdersView skenuje posledních 80 objednávek
- [ ] **HPOS** ověření u nových modulů (používají WC API, pravděpodobně OK)

### Fáze 2 (po launchi)
Zásilkovna auto-štítky (Scope B), reviews, messaging, topování produktů, pokročilý shipping (zóny/váha), CZ/SK tax pack (DPH/OSS/faktury/VIES), i18n .pot/.po, Stripe adapter → first-class PaymentAdapter, pure-math Allocation\Calculator.

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
- Packeta štítky ručně (Scope A)
- Billing webhook funguje i bez secretu pro prvotní aktivaci (sync na návratu); secret nutný pro renewaly
- CZ texty natvrdo (žádné .pot)
- Dva webhook endpointy/secrety (adapter Connect + billing) – Stripe standard, nelze sdílet
