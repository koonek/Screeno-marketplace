# AOZ Marketplace – End-to-end test scénář (Stripe test mód)

Projdi v pořadí. Vše ve **Stripe test módu** (testovací klíče). Testovací karta: `4242 4242 4242 4242`, libovolné budoucí datum, libovolné CVC.

> Před začátkem: nejnovější bundle aktivní, Settings → Permalinks → Save Changes.
> Doména už je produkční **artofzivot.cz** — testuj tam, ale **na testovacích Stripe klíčích**, ať nejedou reálné platby.

---

## 0. Předpoklady / konfigurace

- [ ] Bundle `nkz-marketplace-aoz` aktivní (nejnovější verze)
- [ ] WooCommerce → Stripe Vendor Split: **test** secret + publishable key, mode = test
- [ ] Stripe Vendor Split: webhook secret vyplněný (Connect events) — endpoint `…/nkv-svs/v1/webhook`
- [ ] NKZ Marketplace → Billing: zapnuto, částka nastavená, webhook secret vyplněný
- [ ] WooCommerce → Settings → Shipping → zóna → metoda „NKZ Marketplace – per-vendor doprava"
- [ ] Stripe → Webhooks: endpointy (Connect `account.updated` + billing) vrací 200 OK
- [ ] NKZ Marketplace → Status: vše zelené (ledger / payouts / audit / role / Stripe adapter)
- [ ] Platform fee zapnuté (1 %, min 5 Kč) — pokud chceme testovat i poplatek

---

## 1. Registrace vendora

- [ ] Otevři registrační stránku v anonym okně
- [ ] Vyplň IČO (8 čísel) → jméno se autofillne z ARES
- [ ] Odešli formulář → flash „Tvoji přihlášku jsme dostali"
- [ ] **E-mail vendorovi** dorazil (potvrzení) — **zkontroluj diakritiku** (č š ž ř ě)
- [ ] **E-mail adminovi** dorazil (nová přihláška)
- [ ] WP admin → Vendoři: nový vendor, status `pending`
- [ ] (Antibot) Rychlé odeslání / prázdný honeypot / dvojí submit se zablokuje

## 2. Schválení adminem — POUZE heslo, žádný Stripe

> **Nový flow:** při schválení jde vendorovi **jen e-mail s nastavením hesla**. Žádný Stripe link. Stripe + předplatné si dokončí až po přihlášení.

- [ ] Dashboard → „Čekají na schválení" → vendor je tam (počet sedí s listem)
- [ ] Klikni **Schválit** → vendor status = `approved_awaiting_kyc`
- [ ] **E-mail vendorovi = jen „Nastav si heslo"** (žádný Stripe e-mail!)
- [ ] WP user vytvořen s rolí Vendor (NKZ), stejný e-mail
- [ ] Audit má `vendor.status_changed`

### 2b. Nastavení hesla (ověřeno naostro ✅)

- [ ] Klikni „nastav si heslo" v e-mailu → **pustí to na formulář „Zapomenuté heslo"** (nové heslo + potvrzení) na `/muj-ucet`, NE na login
- [ ] Zadej heslo → Uložit → přihlášeno
- [ ] Odkaz platí 7 dní (ne 24 h)
- [ ] **Re-test expirace:** smaž vendorovi heslo / re-schval → nový password e-mail dorazí i na existující WP účet (příznak `_nkzmp_needs_pw_setup`)

## 3. Přihlášení + dashboard PŘED dokončením (zámek)

> **Klíčové:** dokud není Stripe hotový A předplatné zaplacené, **přidávání produktů je zamčené**.

- [ ] Po loginu → redirect na `/muj-ucet/vendor` (ne wp-admin)
- [ ] Onboarding přehled ukazuje 2 kroky: **Ověření Stripe** a **Předplatné** — oba jako NEsplněné
- [ ] **KYC checkmark NENÍ zelený** (u nového účtu) — dřív falešně ukazoval hotovo (fix 0.45.2)
- [ ] `/muj-ucet/vendor-products` → „Nový produkt" je **🔒 zamčený** (nejde kliknout)
- [ ] Přímý pokus o přidání produktu (URL `?new=1`) → redirect s chybou

## 4. Stripe Connect KYC (z dashboardu)

- [ ] V přehledu klikni na krok **Ověření Stripe** → Express onboarding
- [ ] Vyplň test údaje (Stripe test mód, „Skip" kde nabízí)
- [ ] Návrat na web
- [ ] Webhook `account.updated` → `_nkv_stripe_account_status` = `enabled`, charges/payouts enabled
- [ ] **KYC checkmark teď zelený** ✅ (pokud ne hned → zkontroluj webhook log)

## 5. Předplatné (členství)

- [ ] Krok **Předplatné** → Stripe Checkout
- [ ] Zaplať testovací kartou `4242…`
- [ ] Návrat → stav **„Aktivní"** (ověřeno ze session i bez webhooku)
- [ ] Custom fee: pokud má vendor nastavené vlastní měsíční členské → Checkout ukazuje TU částku
- [ ] Billing webhook → Stripe log 200 OK

## 6. Odemčení produktů + první produkt

- [ ] **Teprve teď** (Stripe enabled + předplatné active) → „Nový produkt" **odemčený** ✅
- [ ] Vyplň název, cenu, fotku, kategorii, „vyžaduje dopravu" zaškrtnuté
- [ ] **Poslat na schválení** → flash „Produkt jsme dostali", stav „Čeká schválení"
- [ ] E-mail vendorovi + adminovi
- [ ] Admin → „Čekající produkty" → **Publikovat** → produkt na `/vendor/<slug>` i v katalogu

## 7. Variabilní produkt (varianty) — NOVÉ, neověřeno naostro

- [ ] Nový produkt → zapni **„Produkt má varianty"**
- [ ] Přidej atribut (např. Velikost) + varianty (S / M / L) s vlastní **cenou** a **skladem**
- [ ] Přidat / odebrat řádek varianty funguje (JS)
- [ ] Odešli → publikuj
- [ ] Na produktové stránce: **dropdown variant**, cena/sklad se mění dle výběru — zkontroluj diakritiku v názvech variant
- [ ] Vlož konkrétní variantu do košíku → správná cena
- [ ] Edituj variabilní produkt → varianty se načtou zpět správně

## 8. Shipping kalkulace

- [ ] Vendorův fyzický produkt do košíku → doprava = jeho paušál
- [ ] Přidej produkt 2. vendora → doprava = paušál A + paušál B
- [ ] Digital produkt (bez „vyžaduje dopravu") → doprava se nezmění
- [ ] Cart UI ukazuje správný součet (tabulka i mobilní zobrazení)

## 9. Platform fee (poplatek platformy)

- [ ] V košíku i pokladně je řádek **poplatek platformy** (1 %, min 5 Kč)
- [ ] **Tooltip** funguje v košíku i pokladně (ne jen jednom)
- [ ] Poplatek se počítá z mezisoučtu produktů (ne z dopravy)
- [ ] Poplatek zůstává platformě (není v žádném vendor transferu)

## 10. Objednávka + split + ESCROW hold — NEJCITLIVĚJŠÍ, neověřeno naostro

> **Escrow:** zákazník zaplatí → platforma **drží** → vendor podá balík (Packeta) → uvolnění po ochranné lhůtě (3 dny).

- [ ] Dokonči checkout testovací kartou (klidně 2 vendoři v košíku)
- [ ] Objednávka `processing`
- [ ] **Peníze se hned NErozdělí** — žádný okamžitý transfer na vendory (transfer_hook = escrow)
- [ ] Ledger: order_credit / platform_commission zapsané, ale payout/transfer **čeká**
- [ ] Vendor podá balík / vytvoří Packeta štítek → naplánuje se uvolnění (+3 dny)
- [ ] **Uvolnění:** buď počkej na cron, nebo admin akce „uvolnit teď" → transfer per vendor proběhne
- [ ] Stripe → Connect → transfers: reálné transfery až PO uvolnění
- [ ] Fallback fronta (bez WP cronu): `process_due` na admin_init to dožene
- [ ] Multi-vendor: každý vendor dostane transfer jen za SVÉ položky, doprava zvlášť

## 11. Reconciliation

- [ ] NKZ Marketplace → Tools → Reconciliation → Spustit (24h/7d okno)
- [ ] **Drift: 0**, Matched ≥ 1
- [ ] Drift > 0 → Tools → Backfill → znovu reconcile

## 12. Billing enforcement (neplatící vendor)

- [ ] Ve Stripe test zruš subscription NEBO simuluj `invoice.payment_failed`
- [ ] Webhook → vendor `suspended` (po grace) / billing `canceled`
- [ ] Vendorův produkt: badge „dočasně nedostupné", add-to-cart disabled
- [ ] Znovu aktivuje předplatné → produkty zase koupitelné, add-to-cart odemčený

## 13. Refund (volitelné)

- [ ] Admin refundne objednávku
- [ ] Ledger: REVERSAL řádek s reálným `trr_*` ID (pozor: pokud escrow ještě nedržel → není co reverzovat)
- [ ] Reconcile pořád drift 0

---

## Výsledek

Prošlo vše → MVP funkčně ověřené. Před přepnutím na **live** Stripe klíče:

1. Přepni adapter + billing na live klíče
2. Vytvoř live webhooky (Connect `account.updated` + billing) + vlož live signing secrets
3. Zkontroluj, že webhook URL sedí na produkční doménu **artofzivot.cz** (ne staré staging)
4. Smaž / archivuj testovací vendory a produkty
5. Otestuj 1 reálnou malou objednávku (např. 1 Kč) než pustíš vendory

## Ještě otevřené (nekódové) — čeká na rozhodnutí

- [ ] **DPH model** — potvrdit s účetní (viz `dane-doprava-otevrene-otazky.md`)
- [ ] **Doprava CZ/SK/EU** — do kterých zemí prodáváme/posíláme
- [ ] **Právní stránky** — „Podmínky používání" + „Podmínky pro prodejce" (čeká na právníka)
- [ ] **Viditelnost produktů** neaktivního vendora — skrýt v katalogu / nechat blokované / nepublikovat (rozhodnutí A/B/C)

## Kde to nejčastěji drhne

| Symptom | Příčina | Fix |
|---|---|---|
| Vendor po loginu nevidí dashboard | WP user není propojen s vendor CPT | Stejný e-mail u WP usera i vendora (auto-link), nebo `_nkzmp_wp_user_id` |
| Nový účet ukazuje „ověřeno" bez Stripe | KYC se bral z celkového statusu | Vyřešeno 0.45.2 — čte reálný Stripe stav |
| Status zůstává „awaiting KYC" | Connect `account.updated` webhook nedorazil | Stripe Webhooks log; zkontroluj URL + secret |
| „nastav heslo" hodí na login | Odkaz mířil na wp-login (skrytý /prihlaseni) | Vyřešeno — míří na WC reset formulář na /muj-ucet |
| „odkaz vypršel" u registrace | Cachovaný nonce | Nonce non-blocking, spoléhá na antibot |
| Rozbitá diakritika (č→ċ, ž→ż) | Variabilní font Fabio má vadné caron glyfy | Statický font + Elementor „Fabio XM" → statické soubory |
| Search/stránkování nejde v Safari | admin-ajax cross-origin po migraci | Same-origin URL rewrite + native fallback |
| Předplatné „Neaktivní" po platbě | Billing webhook chybí | Funguje i tak (sync na návratu); pro renewaly nastav webhook |
| Reconcile drift | Transfery před instalací observeru | Tools → Backfill / install baseline |
| Escrow neuvolní | Bez WP cronu | Fallback fronta na admin_init, nebo admin „uvolnit teď" |
