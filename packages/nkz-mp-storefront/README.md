# NKZ Marketplace – Storefront

Veřejné vendor stránky pro NKZ Marketplace.

## Funkce v 0.1.0

- `/vendors` — archive všech `active` vendorů (paginace, řazení)
- `/vendor/<slug>` — single vendor page (profil + product grid)
- Product stránka → odkaz na vendora
- Settings: enable/disable jednotlivých funkcí, base slug
- SEO: schema.org Organization markup, canonical URL
- Templates s WC hierarchy override (theme může přepsat)

## Závislosti

- `nkz-marketplace` core ≥ 0.8.3
- WooCommerce ≥ 8.0
- PHP ≥ 8.1

## URL routing

| URL | Co zobrazí |
|---|---|
| `/vendors` | List všech `active` vendorů |
| `/vendor/<slug>` | Profil jednoho vendora + jeho produkty |

Base slugy lze změnit v Settings (`vendors` / `vendor` defaults).

## Override šablon v theme

Kopíruj soubory z `packages/nkz-mp-storefront/templates/` do tvého theme jako:

```
<theme>/woocommerce/nkz-mp-storefront/archive-vendor.php
<theme>/woocommerce/nkz-mp-storefront/single-vendor.php
<theme>/woocommerce/nkz-mp-storefront/parts/vendor-header.php
```

WC `wc_locate_template()` automaticky najde override před fallback templatem pluginu.
