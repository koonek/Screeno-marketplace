# NKZ Woo Stripe Vendor Split

Tenký WordPress plugin nad WooCommerce a oficiální WooCommerce Stripe Gateway. Po úspěšné platbě objednávky vytvoří **Stripe Connect Transfer** na connected accounts vendorů podle vlastnictví produktů a provize platformy.

**Plugin NENÍ marketplace.** Neřeší vendor dashboard, registraci vendorů, ani správu produktů vendorem.

## Funkce

- Custom post type `nkv_vendor` pro evidenci vendorů (Stripe `acct_…`, default fee %, status).
- Pole na produktu: vendor, override provize, povolit/zakázat split.
- Deterministická kalkulace splitu v minor units (integer math).
- Stripe Connect "separate charges and transfers" model — vhodné pro multi-vendor objednávky.
- Idempotency key per vendor/order + lock proti race condition.
- `source_transaction = charge_id` (pokud je dostupné) — eliminuje negative-balance okno.
- Dry-run / log-only režim.
- Order meta box s akcemi: Recalculate, Create transfers, Retry, Mark resolved.
- Order notes pro každou akci.
- WC_Logger source: `nkz-stripe-vendor-split`.
- Refund hook: varování + volitelný auto-reversal při plném refundu.
- HPOS-safe.

## Instalace

1. Zkopírujte plugin do `wp-content/plugins/nkz-woo-stripe-vendor-split/`.
2. (Volitelně) `composer install` — pouze pokud chcete bundled `stripe/stripe-php` pro budoucí webhook ověřování. MVP používá `wp_remote_*` přímo.
3. Aktivujte plugin.
4. WooCommerce → Settings → **Stripe Vendor Split** — vyplňte secret keys, default fee, režim.
5. Vytvořte vendory v menu **Vendors (Stripe Split)**.
6. Přiřaďte produkty vendorům v Product Data panelu.

## Použité hooky

- `woocommerce_payment_complete` (primární spouštěč).
- `woocommerce_order_status_processing`, `…_completed` (safety net).
- `woocommerce_order_refunded` (varování / volitelný auto reversal).

## Stavy splitu

`none` · `calculated` · `processing` · `completed` · `partial` · `failed` · `manual`

## Pravidla výpočtu (defaults)

- **Base vendora** = post-discount net items + (DPH, pokud je `split_includes_tax`).
- **Provize platformy** = `floor(base × fee%)` + `fee_fixed`. Zbytek po zaokrouhlení **drží platforma** (vendor nikdy nedostane víc, než má).
- **Doprava** zůstává platformě (lze přepnout).
- **Kupóny** se promítnou poměrně do položek vendorů (lze vypnout).
- **Stripe fee** výchozí nese platforma.
- **Měna**: minor units přes `nkvsvs_to_minor()`. Plugin požaduje shodu měn vendor accountu a objednávky (lze vypnout).

## Bezpečnost

- Secret keys se v UI nikdy nezobrazují celé (maskováno na 4+4 znaky).
- Capability `manage_woocommerce` + WP nonces na všech admin akcích.
- API klíče se nelogují (sanitizace v `Logger::format`).
- Per-request API key — žádný globální `Stripe::setApiKey()`.

## Idempotence

- Idempotency key tvaru `wc_order_{id}_vendor_{vendor_id}_transfer_v1`.
- Pre-write status `processing` před API callem.
- Per-order lock (transient + meta fallback, TTL 300s).

## ⚠️ Účetní a právní upozornění

Tento plugin řeší **technický** split plateb. **Neřeší daňové, účetní a právní nastavení vztahu mezi platformou a vendorem.** Před nasazením do produkce je nutné s účetním a právníkem vyřešit:

- Zda platforma prodává **vlastním jménem** (vendor je dodavatel), nebo jen **zprostředkovává**.
- Kdo vystavuje **fakturu zákazníkovi** (platforma vs. vendor).
- Kdo nese **refundy, chargebacky a Stripe fees**.
- Smluvní vztah s vendorem (DPA, podmínky vyplácení).
- DPH model — zda vendor je plátce, kdo DPH odvádí, jak se počítá ze splitu.
- Měna a FX riziko — plugin v defaultu odmítá cross-currency transfery.

Plugin **automaticky neprovádí** chargeback reversaly. Refund reversaly jsou ve výchozím režimu **manuální** (auto pouze pro plný refund, pokud zapnete).

## Co plugin NEDĚLÁ

- vendor dashboard
- frontend registrace vendorů
- vlastní checkout / platební bránu
- správa produktů vendorem
- automatické fakturace
- komplexní marketplace
- chargeback reversals
- hackování WC Stripe Gateway internals

## Vývoj — známá omezení MVP

- Stripe webhook endpoint ještě není součástí MVP (architektura ho předpokládá v Fázi 9).
- Stripe fee deduction (`deduct_stripe_fee_from_vendor`) je nastavitelné, ale alokace skutečného fee z `balance_transaction` zatím není automatizována — výpočet zůstává konzervativní (fee_share=0).
- Doprava není rozdělována mezi více vendorů — vždy zůstává platformě v MVP.
