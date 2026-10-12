# Screenuj – termín odeslání předobjednávek

U produktů prodejců s balíčkem se zobrazuje **„Odešleme do 12. 11."**: na detailu produktu, v košíku, v objednávce i v potvrzovacím e-mailu. Termín se k objednávce uloží v okamžiku nákupu, takže pozdější změna nastavení ho už nezmění.

## Růst a Naplno – týdenní dávka

Uzávěrka **v pondělí ve 12:00**, odeslání **v pátek** téhož týdne.

| Objednávka | Odešleme do |
|---|---|
| pondělí 11:59 | pátek téhož týdne |
| pondělí 12:01 až neděle | pátek následujícího týdne |

Den a čas uzávěrky i den odeslání se mění v **WooCommerce → Nastavení → Stripe Vendor Split → Termín odeslání předobjednávek**. Zatím platí stejně pro Růst i Naplno.

## Rozjezd – tisk podle dohody

1. V kartě prodejce vyplň **Datum tisku (jen Rozjezd)**, na kterém jste se domluvili.
2. Do dne před tiskem běží předobjednávky. U produktu je odpočet: „Předobjednávky končí 12. 11. · zbývá 5 dní".
3. V den tisku je **stopka**: produkty prodejce nejdou koupit a u produktu je „Předobjednávky jsou uzavřené, termín dalšího tisku brzy oznámíme". Prodej se znovu otevře, až zadáš nové datum tisku.
4. Odeslání = datum tisku + **10 pracovních dnů** (počet se dá změnit v nastavení).

Bez vyplněného data tisku se u produktů Rozjezdu termín neukazuje a prodej se neblokuje.

## Pauzy (svátky, Vánoce)

V nastavení do pole **Pauzy** napiš jedno období na řádek, například:

```
28.10.2026
17.12.2026 - 4.1.2027
```

Platí pro všechny balíčky najednou. V pauze se neodesílá (týdenní dávka se posune na první pátek po pauze) a pracovní dny se do Rozjezdu nepočítají.

## Elementor šablona produktu

Termín se zobrazí sám nad tlačítkem **Přidat do košíku** – v klasické šabloně WooCommerce, v blokovém motivu i v Elementor widgetu *Add to Cart*. Na stránce je vždy jen jednou. Kdybys ho chtěla jinde (nebo šablona nemá tlačítko košíku), vlož widget **Shortcode** s `[screeno_odeslani]`.
