# Operační příručka — Stripe Connect pro Screeno

Interní dokumentace pro administraci marketplace v WP-adminu.

---

## 1. Přidání nového prodejce

1. **WP Admin → Prodejci → Přidat prodejce**
2. Vyplň:
   - **Název** — typicky jméno prodejce nebo název firmy (zobrazí se v UI)
   - **Email prodejce** — pošle se na něj onboarding odkaz
   - **IČO / DIČ** — pro interní evidenci (Stripe si IČO sebere sám)
   - **Provize platformy (%)** — pokud chceš pro tohoto prodejce default jiný než globální (15 %)
   - **Fixní poplatek (haléře)** — pokud chceš ke každé objednávce fixní příplatek
3. **Stav prodejce → aktivní**
4. **Uložit**

V pravém sloupci pod **Stripe Connect** vidíš onboarding panel:

- Onboarding odkaz s tlačítkem **Kopírovat**
- **Odeslat odkaz emailem** — pošle WP mailem ze Screena
- **Otevřít v mém emailu** — vyplní `mailto:` se zprávou
- **Otevřít onboarding (test)** — pro vlastní ověření, jak to prodejce vidí

## 2. Po dokončení onboardingu

Když prodejce odeslal formulář, status se nastaví automaticky přes webhook `account.updated`. Možné stavy:

| Stav | Co znamená | Co dělat |
|---|---|---|
| **Aktivní** | Stripe schválil, prodejce může přijímat platby | nic — vše OK |
| **Probíhá ověření** | Stripe interně kontroluje (až 24 h) | počkat, případně nudge prodejce |
| **Omezený** | Stripe vyžaduje další doklady nebo zamítl | kontaktovat prodejce, ať otevře onboarding odkaz znovu |
| **Neznámý** | Něco se pokazilo při sync | klikni **Obnovit stav ze Stripe**, pokud chyba → zkontroluj klíče |

### Když je status zaseklý nebo chybný

1. **Klikni Obnovit stav ze Stripe** — re-sync z API
2. Pokud chyba (např. _"key does not have access"_) — buď máš špatné klíče v nastavení, nebo byl Stripe účet smazán → klikni **Odpojit Stripe účet** a onboarduj znovu
3. Cron job sám synchronizuje všechny vendory **1× za hodinu** jako pojistku pro webhook výpadky

## 3. Vytvoření produktu

1. **WP Admin → Produkty → Nový**
2. Záložka **General** — vidíš sekci pluginu:
   - **Prodejce (Stripe split)** — vyber z dropdownu
   - **Aktivovat rozdělení** — zaškrtnout (default)
   - **Provize platformy — procento (%)** — override globálního defaultu, prázdné = default
   - **Provize platformy — fixní částka (Kč)** — pokud chceš fixní fee, např. dle materiálu
3. Pokud je fixní vyplněná, **procento se ignoruje** (× počet kusů × fixní)

## 4. Zpracování objednávky

Po platbě se automaticky:
1. Spočítá split (podle nastavení plugin + per-produkt overrides)
2. Vytvoří se Stripe transfer pro každého vendora
3. Stripe fee se rozdělí proporčně 50/50 mezi prodejce a Screeno

V detailu objednávky → meta box **Rozdělení plateb Stripe** vidíš:

- **Stav:** `completed` / `partial` / `failed` / `none`
- **Tabulka** per prodejce: základ, provize, jeho podíl Stripe fee, kolik mu šlo, transfer ID

### Když transfer selže

1. Klikni na transfer ID v meta boxu → otevře tě to do Stripe dashboardu pro debug
2. Nejčastější příčiny:
   - **Vendor account smazán** → odpoj a onboarduj znovu
   - **Mismatch klíčů** mezi Payment Plugins for Stripe a naším pluginem → sjednoť
   - **Currency mismatch** → vendor má jinou měnu než objednávka
3. Po opravě klikni **Opakovat neúspěšné** v meta boxu
4. Pokud nelze zachránit → **Označit jako vyřešené** a vyřiď refund / wire transfer ručně

## 5. Refundy

### Plný refund
1. V objednávce klikni **Refund** → zadej plnou částku → **Refund via Stripe**
2. Plugin **automaticky vyreversuje** transfery z vendorů (pokud je v nastavení zapnuto **Auto reversal on full refund**)
3. Vendor v meta boxu uvidíš nový reversal záznam

### Částečný refund
1. Stejný postup, ale částečnou částku
2. **Plugin nereversuje automaticky** — musíš to udělat ručně
3. V meta boxu objednávky → sekce **Ruční reversal**
   - Suggested hodnota se předvyplní podle proporce posledního refundu
   - Můžeš ji upravit
   - Klikni **Vrátit částku**
4. Stripe reversal proběhne, peníze se vrátí z vendor balance na platformu

## 6. Konfigurace platformy

**WP Admin → WooCommerce → Settings → Platby → záložka NKZ Stripe Vendor Split**

| Volba | Doporučení |
|---|---|
| **Aktivovat plugin** | yes |
| **Režim** | live (v produkci) |
| **Test secret key** | sk_test_… (pro debug, jinak prázdné) |
| **Ostrý secret key** | sk_live_… |
| **Webhook secret** | whsec_… (z Stripe Dashboard) |
| **Výchozí provize platformy (%)** | 15 (nebo dle smluv s vendory) |
| **Zahrnout DPH do základu prodejce** | yes (DPH se počítá z plné ceny) |
| **Zahrnout dopravu do rozdělení** | **no** (doprava jde celá Screenu) |
| **Odečíst slevy poměrově** | yes |
| **Stripe poplatek — kolik nese prodejce** | 50 % — půl na půl |
| **Automatické transfery** | yes |
| **Pouze logovat (dry-run)** | no |
| **Minimální částka transferu** | 1 |
| **Spouštěcí hook** | payment_complete (doporučeno) |
| **Vyžadovat shodu měny prodejce a objednávky** | yes |
| **Automatický reversal při plném refundu** | yes |
| **Debug logování** | yes (zapnuto pro první týdny v produkci) |

## 7. Monitoring

**Co kontrolovat denně (první 2 týdny po go-live):**

1. **WC → Status → Logs** → najdi log `nkv-svs` → projet, jestli nejsou errory
2. **Stripe Dashboard → Developers → Webhooks** → endpoint `nkv-svs/v1/webhook` → "Delivery attempts" musí být 200 OK
3. **Stripe Dashboard → Payments** → cross-check že každá WC objednávka má odpovídající charge
4. **WC → Orders → filtruj podle stavu** → žádné `partial` / `failed` statusy v meta boxu

**Cron job statistika:** `Cron vendor sync complete` log řádek — udržuj v očích `failed` počet, mělo by být 0.

## 8. Hláška uživateli, když prodejce není payable

Buyer přidá do košíku produkt vendora, který má status `Omezený` / `Neaktivní`. Validace v `Checkout_Guard` vrátí:

> Produkt „X" aktuálně nelze objednat — prodejce Y čeká na dokončení ověření Stripe účtu. Zkus to prosím později.

To zabrání nákupu. Vendor musí dokončit onboarding nebo opravit Stripe issue, pak produkt jde zase prodat. Není potřeba nic dělat v adminu — guard řeší automaticky.

## 9. Plánované rozšíření

Není v plánu, ale připravený interface filtry:

- `nkv_svs_filter_platform_fee_percent` — můžeš override fee % per-vendor dynamicky
- `nkv_svs_filter_transfer_amount_minor` — můžeš upravit transfer amount per-vendor před odesláním do Stripe
- `nkv_svs_filter_calculation` — můžeš upravit celou kalkulaci splitu
- `nkv_svs_before_create_transfer`, `nkv_svs_after_create_transfer`, `nkv_svs_transfer_failed` — eventy pro audit/notifikace

Použít je možné z child theme `functions.php`.
