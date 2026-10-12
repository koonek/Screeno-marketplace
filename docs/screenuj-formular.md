# Screenuj – poptávkový formulář (Elementor Pro)

Formulář na stránce Screenuj. Tlačítka „Poptat cenu…“ u balíčků a „Chci na Marketplace / vlastní e-shop“ u modelů ho předvyplní a odscrollují k němu.

## 1. Formulář

Widget **Form** (Elementor Pro), sekce s CSS ID `kontakt`.

| Pole | Typ | ID pole (Advanced → ID) | Povinné |
|---|---|---|---|
| Jméno | Text | `jmeno` | ano |
| Příjmení | Text | `prijmeni` | ano |
| E-mail | Email | `email` | ano |
| Telefon | Tel | `telefon` | – |
| Název projektu | Text | `projekt` | – |
| Web | URL | `web` | – |
| Který balíček tě zajímá? | Select | `balicek` | – |
| Marketplace, nebo vlastní e-shop? | Select | `model` | – |
| Zpráva | Textarea | `zprava` | – |
| Souhlasím s GDPR | Acceptance | `gdpr` | ano |

Možnosti selectů (jedna na řádek, přesně takto – skript je páruje podle textu):

- `balicek`: `Rozjezd`, `Růst`, `Naplno`, `Ještě nevím, poraďte mi`
- `model`: `Marketplace – prodej na screeno.cz`, `Vlastní e-shop (POD)`, `Ještě nevím, poraďte mi`

U obou selectů nastav jako první prázdnou možnost s textem otázky (`|` na začátku řádku = prázdná hodnota), např. `Který balíček tě zajímá?|`.

**Actions After Submit:** `Email` (To: `tiskneme@screeno.cz`, Reply-To: pole `email`, Subject: `Screenuj poptávka – [field id="balicek"] / [field id="model"]`) + `Collect Submissions`, aby poptávky zůstaly i v *Elementor → Submissions*.

## 2. Tlačítka

U každého tlačítka: Link `#kontakt`, a v **Advanced → Attributes** (každý atribut na vlastní řádek, formát `klíč|hodnota`):

| Tlačítko | Attributes |
|---|---|
| Rozjezd – Poptat cenu pro Marketplace | `data-balicek\|Rozjezd` a `data-model\|Marketplace – prodej na screeno.cz` |
| Růst – pro Marketplace | `data-balicek\|Růst` a `data-model\|Marketplace – prodej na screeno.cz` |
| Růst – pro e-shop | `data-balicek\|Růst` a `data-model\|Vlastní e-shop (POD)` |
| Naplno – pro Marketplace | `data-balicek\|Naplno` a `data-model\|Marketplace – prodej na screeno.cz` |
| Naplno – pro e-shop | `data-balicek\|Naplno` a `data-model\|Vlastní e-shop (POD)` |
| Chci na Marketplace (model A) | `data-model\|Marketplace – prodej na screeno.cz` |
| Chci vlastní e-shop (model B) | `data-model\|Vlastní e-shop (POD)` |

Atribut chybí = pole se nemění (tlačítko modelu tak nepřepíše už vybraný balíček).

## 3. Skript

*Elementor → Custom Code* → nový kód, umístění **Body – End**, podmínka jen pro stránku Screenuj:

```html
<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-balicek], [data-model]');
  if (!btn) return;
  [['balicek', btn.getAttribute('data-balicek')], ['model', btn.getAttribute('data-model')]].forEach(function (pair) {
    if (!pair[1]) return;
    var sel = document.querySelector('select[name="form_fields[' + pair[0] + ']"]');
    if (!sel) return;
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === pair[1] || sel.options[i].text.trim() === pair[1]) {
        sel.selectedIndex = i;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
    }
  });
});
</script>
```

Kontrola: klik na „Růst – pro e-shop“ → stránka sjede k formuláři a oba selecty jsou předvyplněné. Odeslaný formulář přijde na tiskneme@screeno.cz a je vidět v *Elementor → Submissions*.
