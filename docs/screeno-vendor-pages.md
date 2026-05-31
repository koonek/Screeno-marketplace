# Screeno – veřejné profilové stránky prodejců

Hotfix `0.6.5.1` zapíná veřejné profilové stránky prodejců s hezkou URL a
přidává Elementor dynamické tagy pro veřejná pole prodejce.

## Co se mění

| Před | Po |
|---|---|
| URL `…/?nkv_vendor=jelen` | hezká URL `…/prodejce/jelen/` |
| Profil prodejce vracel 404 | profil **aktivního** prodejce se vykreslí |
| Pole prodejce se v Elementoru nedala vybrat (chráněné `_` meta) | nové dynamické tagy **Prodejce: Bio** a **Prodejce: Web** |

Citlivá pole (email, IČO, provize, Stripe ID, interní poznámka) zůstávají
**neveřejná** – nejsou vystavená jako dynamický tag ani na profilu.
Profil zobrazují **jen aktivní** prodejci (`Stav prodejce = aktivní`); ostatní
stavy a koncepty dál vrací 404.

## Nasazení (živý web)

1. Záloha DB (standard před úpravou pluginu).
2. Nahraď soubory pluginu `nkz-woo-stripe-vendor-split` verzí `0.6.5.1`
   (upload zipu přes Pluginy → Přidat nový → Nahrát, nebo SFTP do
   `wp-content/plugins/nkz-woo-stripe-vendor-split/`).
3. Hezké URL se aktivují samy – plugin po updatu jednou pročistí rewrite
   pravidla (flush). Pokud by profil přesto vracel 404, otevři **Nastavení →
   Trvalé odkazy** a klikni *Uložit změny* (ruční flush).
4. Ověř: otevři `…/prodejce/<slug-prodejce>/` u aktivního prodejce → načte se
   (zatím přes výchozí šablonu motivu, viz níže Elementor).

> Základ je `/prodejce/`. Jde změnit filtrem `nkv_svs_vendor_rewrite_slug`
> (např. na `vendor`). Po změně jednou ulož Trvalé odkazy.

## Sestavení profilu v Elementoru (Theme Builder, Elementor Pro)

1. **Šablony → Theme Builder → Single → Add New → Single**.
2. Podmínka zobrazení: **Prodejci** (CPT `nkv_vendor`).
3. Poskládej obsah dynamickými widgety:
   - **Nadpis / Post Title** → jméno prodejce.
   - **Obrázek / Featured Image** → logo (náhledový obrázek prodejce).
   - **Editor textu / Nadpis** → ikona dynamického obsahu → **Prodejce: Bio**.
   - **Tlačítko** → odkaz → ikona dynamického obsahu → **Prodejce: Web**.
4. Publikovat. Tlačítka **Prodejce: Bio/Web** najdeš ve výběru dynamického
   obsahu ve skupině **Prodejce**.

Výpis prodejců (Loop Grid) funguje beze změny přes Query ID `screeno_vendors`;
odkaz na položku teď míří na hezkou URL profilu.
