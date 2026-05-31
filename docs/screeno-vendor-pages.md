# Screeno – veřejné profilové stránky prodejců

<<<<<<< HEAD
Hotfix `0.6.5.3` zapíná veřejné profilové stránky prodejců s hezkou URL a
=======
Hotfix `0.6.5.4` zapíná veřejné profilové stránky prodejců s hezkou URL a
>>>>>>> e4cd752 (0.6.5.4 fix: robustní registrace Elementor tagů (skupina Prodejce se nezobrazovala))
přidává Elementor dynamické tagy pro veřejná pole prodejce.

## Co se mění

| Před | Po |
|---|---|
| URL `…/?nkv_vendor=jelen` | hezká URL `…/prodejce/jelen/` |
| Profil prodejce vracel 404 | profil **aktivního** prodejce se vykreslí |
| Pole prodejce se v Elementoru nedala vybrat (chráněné `_` meta) | nové dynamické tagy **Prodejce: Údaj** (Jméno, Bio, IČO/DIČ, Měna) a **Prodejce: Web** |

Citlivá pole (email, provize, Stripe ID, interní poznámka) zůstávají
**neveřejná** – nejsou vystavená jako dynamický tag ani na profilu. IČO/DIČ je
veřejné (běžně se na profilu prodejce uvádí).
Profil zobrazují **jen aktivní** prodejci (`Stav prodejce = aktivní`); ostatní
stavy a koncepty dál vrací 404.

## Nasazení (živý web)

1. Záloha DB (standard před úpravou pluginu).
<<<<<<< HEAD
2. Nahraď soubory pluginu `nkz-woo-stripe-vendor-split` verzí `0.6.5.3`
=======
2. Nahraď soubory pluginu `nkz-woo-stripe-vendor-split` verzí `0.6.5.4`
>>>>>>> e4cd752 (0.6.5.4 fix: robustní registrace Elementor tagů (skupina Prodejce se nezobrazovala))
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
3. Poskládej obsah dynamickými widgety. U textových widgetů (Nadpis, Editor
   textu) klikni na **ikonu dynamického obsahu** a ve skupině **Prodejce**
   vyber:
   - **Prodejce: Údaj** → v jeho nastavení přepni **Pole prodejce** na
     *Jméno / Bio / IČO/DIČ / Měna* (jeden tag, libovolné veřejné pole).
   - **Prodejce: Web** → pro odkaz (u widgetu **Tlačítko** ho dej do pole odkaz).
   - **Prodejce: Logo** → u widgetu **Obrázek** jako dynamický obsah.
   - **Prodejce: Odkaz na profil** → odkaz na `/prodejce/<slug>/`.
4. Publikovat. Všechny tagy najdeš ve výběru dynamického obsahu ve skupině
   **Prodejce** (ne v generickém *Custom Field* — chráněná `_` meta tam
   WordPress nenabízí, proto tyto dedikované tagy).

## Zobrazení prodejce na stránce produktu

Tytéž tagy fungují i v **šabloně produktu** (Theme Builder → Single Product
nebo WooCommerce widgety). Tag si z produktu sám dohledá jeho prodejce přes
vazbu `_nkv_vendor_id` (pole *Prodejce* v produktu → záložka NKZ).

- **Prodejce: Údaj** (Jméno/IČO/Bio/Měna), **Prodejce: Logo**, **Prodejce: Web**
  a **Prodejce: Odkaz na profil** vykreslí data prodejce navázaného na produkt.
- Když produkt nemá prodejce (nebo je neaktivní), tagy nevypíší nic.

Typické použití: na produkt přidat „Prodejce: Logo" + „Prodejce: Údaj = Jméno"
a tlačítko s odkazem „Prodejce: Odkaz na profil".

Výpis prodejců (Loop Grid) funguje beze změny přes Query ID `screeno_vendors`;
odkaz na položku teď míří na hezkou URL profilu.
