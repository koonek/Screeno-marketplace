# NKZ Marketplace – Zásilkovna

Výběr výdejního místa Zásilkovny v checkoutu (Packeta widget v6) + zakládání zásilek a tisk štítků per prodejce. Cena = per-vendor paušál.

## Setup

1. Založ účet u Zásilkovny (https://www.zasilkovna.cz) → Klientská sekce
2. **Nastavení → API** → zkopíruj **API klíč** (veřejný, pro widget) a **API heslo** (tajné, pro zásilky/štítky)
3. WP: **NKZ Marketplace → Zásilkovna** → vlož API klíč + API heslo + výchozí odesílatel (eshop label) + výchozí váhu → Save
4. **WooCommerce → Settings → Shipping → zóna → Add method → „Zásilkovna – výdejní místo"**

- Bez **API klíče** se nenačte widget (metoda půjde vybrat, ale výběr výdejny ne).
- Bez **API hesla** nejdou auto-štítky (výběr výdejny funguje dál; štítky pak ručně).

## Jak to funguje

- Zákazník v checkoutu zvolí „Zásilkovna – výdejní místo" → objeví se tlačítko **Vybrat výdejní místo** → Packeta widget → vybere výdejnu
- Výdejna se uloží k objednávce (`_nkzmp_packeta_point_id`, `_nkzmp_packeta_point_name`)
- Zobrazí se: admin order detail, thank-you, e-maily, (vendor uvidí v order detailu)
- Cena = součet per-vendor paušálů (stejně jako naše doprava)

## Fulfillment (auto-štítky)

S vyplněným **API heslem**:

- Objednávka se zbožím od víc prodejců = **jedna zásilka na prodejce** (položky se seskupí podle `_nkzmp_vendor_id`).
- **Prodejce** si v dashboardu (Moje objednávky) klikne „Vytvořit štítek (PDF)" → založí se zásilka (`createPacket`) a stáhne se PDF štítek. **Admin** má totéž v detailu objednávky jako zálohu.
- Zásilka se ukládá idempotentně na objednávku (`_nkzmp_packeta_packets` = `[ vendor_id => [id, barcode, created] ]`), opakovaný klik štítek jen znovu stáhne.
- **Hodnota** = součet položek prodejce (vč. daně). **Váha** = z váhy produktů × množství, fallback = výchozí váha z nastavení. **Dobírka** se nastaví jen u platby `cod`.

### Odesílatel (sender)

Packeta `createPacket` neumí libovolnou adresu odesílatele – odesílatel se určuje hodnotou `eshop` (label nakonfigurovaný v Packeta účtu). Per-vendor odesílatel:

1. Prodejce vyplní **adresu pro odeslání** v profilu (kvůli virtuálním sídlům – ukládá se na vendor meta `_nkzmp_sender_*`).
2. Admin podle ní v Packeta klientovi založí prodejce jako **odesílatele** a zjistí jeho `eshop` label.
3. Label se zapíše do profilu prodejce (`_nkzmp_packeta_sender_label`). Když chybí, použije se výchozí odesílatel z nastavení.

## Mimo 0.2.0 (Scope B+)

- ✅ ~~Auto štítky přes Packeta API (createPacket per vendor)~~ – hotovo v 0.2.0
- ❌ Tracking sync do objednávky
- ❌ Cenník Zásilkovny dle váhy (teď per-vendor paušál)
- ❌ Adresní doručení Zásilkovnou (teď jen výdejní místa)
- ❌ Block checkout (Gutenberg) – zatím classic checkout
