# NKZ Marketplace (monorepo)

Marketplace platforma pro WooCommerce, postavená jako **tenké jádro + add-ony**. Žádný monolit ve stylu Dokan – core vystavuje stabilní API (hooky, REST, WP-CLI) a každá další schopnost (PSP, subscription billing, registrace, storefront, shipping, …) je samostatný plugin.

## Balíčky

| Plugin | Stav | Co dělá |
|---|---|---|
| [`packages/nkz-marketplace`](packages/nkz-marketplace) | 0.1.0-dev (skeleton) | **Core** – vendor model, product ownership, allocation, ledger, payout state machine, REST, CLI, audit, GDPR |
| [`packages/nkz-mp-stripe`](packages/nkz-mp-stripe) | 0.15.x (AOZ) · 0.6.7.x (Screeno) | **Stripe adapter** – Connect onboarding, transfers, refunds, webhooks. Dnes ještě obsahuje vendor model – přesun do core probíhá ve Fázi 0. |
| `packages/nkz-mp-vendor-registration` | plánováno | Frontend registrace + 2-stage approval |
| `packages/nkz-mp-vendor-billing` | plánováno | Vendor subscription přes Stripe Billing |
| `packages/nkz-mp-shipping` | plánováno | Per-vendor paušální shipping |
| `packages/nkz-mp-storefront` | plánováno | `/vendor/<slug>` store page |
| `packages/nkz-mp-promoted-listings` | Fáze 2 | Topování produktů |

## Větve

| Větev | Pro koho | Co tam patří |
|---|---|---|
| `main` (+ defaultní `claude/woocommerce-stripe-split-payments-F6Sxs`) | **AOZ** / platforma | Veškerý nový vývoj. Bundle `nkz-mp-aoz-bundle`, Stripe adapter 0.15.x. |
| `screeno/production` | **Screeno** | Přesný stav produkce (Stripe adapter 0.6.7.4, bez core). Jen hotfixy pro Screeno; nic z `main` sem nemergovat. |

Hotfix pro Screeno: větev z `screeno/production` → PR do `screeno/production` → a pokud se týká i AOZ, mergnout `screeno/production` do `main`. Stripe adapter v `main` pozná AOZ storefront (`NKZMP_STOREFRONT_VERSION`) a Screeno stránky prodejců (`/prodejce/<slug>`, tagy `nkv-vendor`) pak nechává vypnuté, stránky prodejců řeší storefront (`/vendor/<slug>`).

## Klienti

- **Screeno** – produkce běží na `nkz-woo-stripe-vendor-split` 0.6.7.4 z větve `screeno/production` (core zatím ne). Fáze 0 upgrade musí být chování-neutrální.
- **Art of Život (AOZ)** – instaluje vše z MVP scope (Fáze 1). Staging: https://artofzivot.nkz.studio/

## Vývoj

Viz `docs/` a plán refaktoru. Aktuální fáze: **Fáze 0 – extrakce core ze Stripe adapteru, rename `NKVSVS` → `NKZMP`, ledger + payout state machine + reconciliation cron.**

Stav Screeno produkce: větev `screeno/production` (0.6.7.4). Starší záchranný bod `v0.6.5-screeno-stable` je jen lokální tag.

### Staging

Pro nahrání na Screeno staging viz [`docs/staging-install.md`](docs/staging-install.md). Core je v této fázi **pasivní pozorovatel** – instaluje vlastní tabulky a oprávnění, ale neovlivňuje chování existujícího Stripe adapteru.
