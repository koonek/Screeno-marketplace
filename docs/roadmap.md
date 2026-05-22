# NKZ Marketplace – Roadmap & Status

Živý dokument. Aktualizuje se s každým chunkem. Verze pluginu = stav core; verze Stripe adapteru se vede zvlášť.

## Aktuální verze

| Package | Verze | Stav |
|---|---|---|
| `nkz-marketplace` (core) | **0.7.0-dev** | dev — Fáze 0 in progress |
| `nkz-mp-stripe` (slug `nkz-woo-stripe-vendor-split`) | **0.6.7** | produkční pro Screeno |

## Fáze 0 — Refactor + rename + observability

Cíl: Screeno produkce funguje identicky, core paralelně zapisuje ledger/audit, reconciliation běží denně. PR #16.

| # | Kus | Stav | Verze | Pozn. |
|---|---|---|---|---|
| 1 | Tag `v0.6.5-screeno-stable` | ✅ | — | Lokální tag, záchranný bod pre-refaktor |
| 2 | Smoke testy current behavior | 🟡 | — | Probíhá manuálně na Screeno staging |
| 3 | Monorepo struktura `packages/` | ✅ | 0.4.0-dev | Core + adapter |
| 4 | Extrakce core (`nkz-marketplace`) + Stripe adapter (`nkz-mp-stripe`) | ✅ | 0.4.0-dev | Slug adapteru zůstal `nkz-woo-stripe-vendor-split` |
| 5a | Append-only Ledger + idempotency | ✅ | 0.4.0-dev | `wp_nkzmp_ledger`, schema v1 |
| 5b | Payout state machine + repository | ✅ | 0.4.0-dev | `wp_nkzmp_payouts`, 6 stavů |
| 5c | Shadow observer pro legacy Stripe | ✅ | 0.4.0-dev | Pasivní zápis do ledgeru, refund + reversal |
| 5d | Audit log + listener | ✅ | 0.5.0-dev | `wp_nkzmp_audit`, hooky payout/ledger/vendor/role |
| 5e | Allocation Service — bridge (mapper + hook) | ✅ | 0.7.0-dev | `from_legacy_calc()` + `nkzmp/v1/allocation/calculated`. Pure-math TBD |
| 5f | Reconciliation Service + cron | ✅ | 0.6.0-dev | Driver Stripe, drift do auditu, cron daily 03:00 |
| 6 | REST `nkzmp/v1/*` | ✅ | 0.5.0-dev | vendors/orders/ledger, OwnershipGuard |
| 6b | WP-CLI `wp nkzmp ...` | ✅ | 0.5.0-dev + 0.6.0-dev | status, backfill, ledger, reconcile, +allocation |
| 7 | Capabilities + role `nkzmp_vendor` | ✅ | 0.4.0-dev | Opt-in CPT přes `NKZMP_ENABLE_CORE_CPT` |
| 8 | Migrace meta klíčů `_nkv_*` → `_nkzmp_*` | ⏳ | — | Read-shim hotov, write migration WP-CLI ještě ne |
| 9 | Hook reference | 🟡 | — | Manuální `docs/hook-reference.md`, generátor z PHPDoc TBD |
| 10 | GDPR exporter + eraser | ✅ | 0.5.0-dev | Vendor profile + ledger + payouts + audit |
| 11 | Screeno staging upgrade → produkce | ⏳ | — | Po dokončení 5e + 8 |

**Kritérium dokončení Fáze 0:**
- Screeno produkce po upgradu funguje identicky (žádná změna chování pro uživatele)
- `wp nkzmp reconcile --since=24h` hlásí `drift_count: 0` na produkci stabilně 7 dní
- Ledger entries jsou pro každý Stripe transfer + reversal
- Migrace meta klíčů provedena, legacy fallback může jít pryč v 0.8.0

## Fáze 1 — MVP pro AOZ (Art of Život)

Nezačne dokud Fáze 0 není na Screeno produkci.

| Kus | Add-on package | Stav |
|---|---|---|
| Vendor registrace (frontend + 2-stage approval) | `nkz-mp-vendor-registration` | TODO |
| Vendor billing subscription (Stripe Billing, CZK, konfigurovatelná částka) | `nkz-mp-vendor-billing` | TODO |
| Per-vendor paušální shipping | `nkz-mp-shipping` | TODO |
| Vendor storefront `/vendor/<slug>` | `nkz-mp-storefront` | TODO |
| Vendor backoffice lite (wp-admin scoped) | core | TODO |
| Vendor StatusService + admin akce (approve/suspend/terminate) | core | TODO |
| CZ překlad (.po) | core + add-ony | TODO |

**Provozní rozhodnutí:** viz [`docs/staging-install.md`](staging-install.md) sekce "AOZ provozní".

## Fáze 2+ — Po MVP

- Topování produktů (`nkz-mp-promoted-listings`)
- Frontend vendor dashboard (React / Elementor)
- Vendor reviews
- Messaging / disputes
- Pokročilý shipping (zóny, hmotnost, třídy)
- CZ/SK tax pack (DPH, OSS, faktury, VIES)

## Aktivní rizika

| # | Riziko | Mitigace | Stav |
|---|---|---|---|
| R1 | Migrace meta klíčů na produkci může selhat | read-shim min 2 verze + dry-run CLI | částečně (read-shim ✅, dry-run TBD) |
| R2 | Hook breaking pro Screeno theme/Elementor | starý hook names jako aliasy + `_deprecated_hook()` | částečně (Elementor `screeno_vendors` alias ✅, ostatní TBD) |
| R3 | Stripe webhook URL změna | starý endpoint zachovat min 6 měsíců | nezačato (webhook controller zůstává v adapteru) |
| R4 | Ledger reconciliation drift | denní cron + audit záznam + admin notice | ✅ implementováno |
| R5 | AOZ subscription před hotovým core | pořadí fází striktní | dodržováno |
