# NKZ Marketplace – Zásilkovna

Výběr výdejního místa Zásilkovny v checkoutu (Packeta widget v6). **Scope A** – výběr výdejny, cena = per-vendor paušál. Štítky se zatím generují ručně.

## Setup

1. Založ účet u Zásilkovny (https://www.zasilkovna.cz) → Klientská sekce
2. **Nastavení → API** → zkopíruj API klíč
3. WP: **NKZ Marketplace → Zásilkovna** → vlož API klíč → Save
4. **WooCommerce → Settings → Shipping → zóna → Add method → „Zásilkovna – výdejní místo"**

Bez API klíče se widget nenačte (metoda půjde vybrat, ale výběr výdejny ne).

## Jak to funguje

- Zákazník v checkoutu zvolí „Zásilkovna – výdejní místo" → objeví se tlačítko **Vybrat výdejní místo** → Packeta widget → vybere výdejnu
- Výdejna se uloží k objednávce (`_nkzmp_packeta_point_id`, `_nkzmp_packeta_point_name`)
- Zobrazí se: admin order detail, thank-you, e-maily, (vendor uvidí v order detailu)
- Cena = součet per-vendor paušálů (stejně jako naše doprava)

## Fulfillment (zatím ručně)

Každý vendor vidí u objednávky cílovou výdejnu. Štítek vygeneruje ve svém **Packeta klientovi** (nebo admin centrálně). Auto-generování štítků per vendor přes API = další fáze (Scope B).

## Mimo 0.1.0 (Scope B+)

- ❌ Auto štítky přes Packeta API (createPacket per vendor)
- ❌ Tracking sync do objednávky
- ❌ Cenník Zásilkovny dle váhy (teď per-vendor paušál)
- ❌ Adresní doručení Zásilkovnou (teď jen výdejní místa)
- ❌ Block checkout (Gutenberg) – zatím classic checkout
