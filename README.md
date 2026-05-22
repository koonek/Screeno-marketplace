# NKZ Marketplace (monorepo)

Marketplace platforma pro WooCommerce, postavená jako **tenké jádro + add-ony**. Žádný monolit ve stylu Dokan – core vystavuje stabilní API (hooky, REST, WP-CLI) a každá další schopnost (PSP, subscription billing, registrace, storefront, shipping, …) je samostatný plugin.

## Balíčky

| Plugin | Stav | Co dělá |
|---|---|---|
| [`packages/nkz-marketplace`](packages/nkz-marketplace) | 0.1.0-dev (skeleton) | **Core** – vendor model, product ownership, allocation, ledger, payout state machine, REST, CLI, audit, GDPR |
| [`packages/nkz-mp-stripe`](packages/nkz-mp-stripe) | 0.6.5 (legacy) | **Stripe adapter** – Connect onboarding, transfers, refunds, webhooks. Dnes ještě obsahuje vendor model – přesun do core probíhá ve Fázi 0. |
| `packages/nkz-mp-vendor-registration` | plánováno | Frontend registrace + 2-stage approval |
| `packages/nkz-mp-vendor-billing` | plánováno | Vendor subscription přes Stripe Billing |
| `packages/nkz-mp-shipping` | plánováno | Per-vendor paušální shipping |
| `packages/nkz-mp-storefront` | plánováno | `/vendor/<slug>` store page |
| `packages/nkz-mp-promoted-listings` | Fáze 2 | Topování produktů |

## Klienti

- **Screeno** – instaluje `nkz-marketplace` + `nkz-mp-stripe`. Fáze 0 upgrade musí být chování-neutrální.
- **Art of Život (AOZ)** – instaluje vše z MVP scope (Fáze 1). Staging: https://artofzivot.nkz.studio/

## Vývoj

Viz `docs/` a plán refaktoru. Aktuální fáze: **Fáze 0 – extrakce core ze Stripe adapteru, rename `NKVSVS` → `NKZMP`, ledger + payout state machine + reconciliation cron.**

Stable Screeno produkce tag: `v0.6.5-screeno-stable`.

### Staging

Pro nahrání na Screeno staging viz [`docs/staging-install.md`](docs/staging-install.md). Core je v této fázi **pasivní pozorovatel** – instaluje vlastní tabulky a oprávnění, ale neovlivňuje chování existujícího Stripe adapteru.
