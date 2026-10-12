# Screeno – balík pluginů

Jeden plugin (`screeno.zip`) se dvěma moduly:

| Modul | Zdroj | Co dělá |
|---|---|---|
| `nkz-woo-stripe-vendor-split` | `packages/nkz-mp-stripe` (větev `screeno/production`) | Prodejci, rozdělení plateb přes Stripe, Screenuj (balíčky, platby kartou, termíny odeslání) |
| `nkz-studio-booking` | repo Screeno-rezervace | Rezervace studia, workshopy, poukazy |

Moduly se dál vyvíjejí ve svých repozitářích, do balíku se jen kopírují.

## Sestavení

```
packages/screeno-bundle/build.sh /cesta/ke/Screeno-rezervace
```

Výsledek: `dist/screeno-<verze>.zip`. Při každé nové verzi modulu zvyš verzi v `screeno.php`.

## Přechod ze samostatných pluginů (jednorázově)

1. **Pluginy → Přidat nový → Nahrát plugin** → `screeno-<verze>.zip` → **Aktivovat**. Staré pluginy zatím nech aktivní – moduly v balíku se díky pojistce nenačtou a ukáže se upozornění.
2. **Deaktivuj** samostatné pluginy *NKZ Woo Stripe Vendor Split* a *NKZ Studio Booking*. Od té chvíle běží moduly z balíku (stejná data, nastavení, webhook URL).
3. Otevři libovolnou stránku administrace (balík dokončí instalaci modulů) a teprve potom staré pluginy **smaž**.

Data se nemažou: Stripe split při odinstalaci nic nemaže a u bookingu balík vypne volbu „smazat data při odinstalaci", takže smazání starého pluginu tabulky rezervací nechá být.

## Další aktualizace

Pluginy → Přidat nový → Nahrát plugin → nový `screeno-<verze>.zip` → **Nahradit současný nahraným**.
