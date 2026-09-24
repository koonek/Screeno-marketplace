# NKZ Marketplace – Shipping

Per-vendor paušální doprava.

## Jak funguje

- Každý prodejce má **jeden paušál** (Kč). Nastaví ho admin v edit screen vendora, nebo vendor sám v dashboardu (`/muj-ucet/vendor-shipping`).
- Produkt má flag `_nkzmp_requires_shipping` (default ano). Digital / virtual / downloadable produkty dopravu nevyžadují.
- V košíku: pro **každého prodejce**, který má aspoň 1 produkt vyžadující dopravu, se přičte jeho paušál.
- Pokud košík obsahuje jen digital produkty → doprava 0.

## Setup

1. Aktivuj plugin (v bundlu je automaticky)
2. WooCommerce → Settings → Shipping → tvoje zóna (např. Česká republika) → Add shipping method → **„NKZ Marketplace – per-vendor doprava"**
3. NKZ Marketplace → Doprava → nastav výchozí paušál (default 79 Kč)
4. Per-vendor paušál: edit vendora → meta box „Doprava – paušál"

## Příklad

Košík:
- Produkt A (vendor Jana, paušál 80 Kč)
- Produkt B (vendor Jana, paušál 80 Kč)
- Produkt C (vendor Petr, paušál 120 Kč)
- Produkt D (digital, vendor Jana)

Doprava = 80 (Jana) + 120 (Petr) = **200 Kč**. Jana se počítá jen jednou, digital se nepočítá.

## Mimo 0.1.0

- ❌ Zóny / hmotnost / třídy (jen paušál)
- ❌ Free shipping threshold per vendor
- ❌ Více shipping options per vendor (vendor picker)
