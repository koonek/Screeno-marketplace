# AOZ Marketplace – End-to-end test scénář (Stripe test mód)

Projdi v pořadí na AOZ staging (https://artofzivot.nkz.studio). Vše ve **Stripe test módu** (testovací klíče). Testovací karta: `4242 4242 4242 4242`, libovolné budoucí datum, libovolné CVC.

> Před začátkem: bundle ≥ 0.14.0 aktivní, Settings → Permalinks → Save Changes.

---

## 0. Předpoklady / konfigurace

- [ ] Bundle `nkz-marketplace-aoz` aktivní, verze ≥ 0.14.0
- [ ] WooCommerce → Stripe Vendor Split: **test** secret + publishable key vyplněné, mode = test
- [ ] WooCommerce → Stripe Vendor Split: webhook secret vyplněný (Connect events)
- [ ] NKZ Marketplace → Billing: zapnuto, částka nastavená, webhook secret vyplněný
- [ ] WooCommerce → Settings → Shipping → zóna → přidaná metoda „NKZ Marketplace – per-vendor doprava"
- [ ] Stripe → Webhooks: 2 endpointy (adapter Connect + billing) s 200 OK
- [ ] NKZ Marketplace → Status: vše zelené (ledger / payouts / audit / role / Stripe adapter)

---

## 1. Registrace vendora

- [ ] Otevři stránku s `[nkzmp_vendor_registration]` v anonym okně
- [ ] Vyplň IČO (8 čísel) → jméno se autofillne z ARES
- [ ] Odešli formulář → flash „Tvoji přihlášku jsme dostali"
- [ ] **E-mail vendorovi** dorazil (potvrzení) — zkontroluj diakritiku
- [ ] **E-mail adminovi** dorazil (nová přihláška)
- [ ] WP admin → Vendoři: nový vendor, status `pending`

## 2. Schválení adminem

- [ ] NKZ Marketplace → Dashboard → sekce „Čekají na schválení" → vendor je tam
- [ ] Klikni **Schválit** → flash „Vendor #X schválen, čeká na KYC"
- [ ] Vendor status = `approved_awaiting_kyc`
- [ ] **E-mail vendorovi**: „Jsi v Art of život. Zbývá jeden krok." + Stripe Connect link + password setup link
- [ ] WP user vytvořen (Uživatelé) s rolí Vendor (NKZ), stejný e-mail
- [ ] Audit (Status page) má `vendor.status_changed`

## 3. Stripe Connect KYC

- [ ] Vendor klikne Stripe Connect link z e-mailu
- [ ] Vyplní Express onboarding (test mód: použij testovací údaje, „Skip" kde Stripe nabízí)
- [ ] Po dokončení → návrat na web
- [ ] Vendor status → `active` (přes webhook `account.updated`; pokud ne hned, počkej pár s / zkontroluj webhook log)
- [ ] **E-mail vendorovi**: „Vítej v Art of život. Můžeš prodávat."

## 4. Přihlášení vendora + předplatné

- [ ] Vendor nastaví heslo (z password e-mailu) → přihlásí se
- [ ] Po loginu → redirect na `/muj-ucet/vendor` (ne wp-admin)
- [ ] Dashboard ukazuje status „Aktivní", statistiky
- [ ] `/muj-ucet/vendor-billing` → stav „Neaktivní" + tlačítko Aktivovat
- [ ] Klikni **Aktivovat předplatné** → Stripe Checkout
- [ ] Zaplať testovací kartou `4242…`
- [ ] Návrat → stav **„Aktivní"** (ověřeno přímo ze session, i bez webhooku)
- [ ] Billing webhook (pokud nastavený) → Stripe Webhooks log 200 OK

## 5. Vytvoření produktu

- [ ] `/muj-ucet/vendor-products` → **Nový produkt**
- [ ] Vyplň název, cenu, nahraj hlavní fotku, vyber kategorii, nech „vyžaduje dopravu" zaškrtnuté
- [ ] **Poslat na schválení** (tlačítko modré, bílý text) → flash „Produkt jsme dostali"
- [ ] Produkt v listu se stavem „Čeká schválení"
- [ ] **E-mail vendorovi + adminovi** o novém produktu
- [ ] Admin → Dashboard → „Čekající produkty" → produkt je tam
- [ ] Klikni **Publikovat** → status publish
- [ ] Produkt se zobrazí na `/vendor/<slug>` a v katalogu

## 6. Shipping kalkulace

- [ ] Vlož vendorův fyzický produkt do košíku → doprava = jeho paušál
- [ ] Přidej produkt 2. vendora → doprava = paušál A + paušál B
- [ ] Přidej digital produkt (vendor odškrtl „vyžaduje dopravu") → doprava se nezmění
- [ ] Cart UI ukazuje správný součet

## 7. Objednávka + split + ledger

- [ ] Dokonči checkout testovací kartou
- [ ] Objednávka `processing`/`completed`
- [ ] WooCommerce → NKZ Marketplace → Status → Ledger: nové řádky (order_credit, payout, platform_commission)
- [ ] Vendor `/muj-ucet/vendor-orders`: objednávka s jeho položkami + mezisoučet
- [ ] Vendor `/muj-ucet/vendor-payouts`: transfer řádek, balance
- [ ] Admin order detail: Stripe transfer ID v meta boxu
- [ ] Stripe Dashboard → Connect → transfers: reálný transfer na vendor účet

## 8. Reconciliation

- [ ] NKZ Marketplace → Tools → Reconciliation → Spustit (okno 24h/7d)
- [ ] **Drift: 0**, Matched ≥ 1
- [ ] Pokud drift > 0 → Tools → Backfill → znovu reconcile

## 9. Billing enforcement (neplatící vendor)

- [ ] V Stripe test: zruš subscription vendora (Customers → subscription → Cancel) NEBO simuluj `invoice.payment_failed`
- [ ] Webhook → vendor `suspended` (po grace) / billing `canceled`
- [ ] Vendorův produkt v katalogu: badge „dočasně nedostupné", add-to-cart disabled
- [ ] Admin Dashboard → billing přehled: vendor v „Po splatnosti" / „Zrušeno"
- [ ] Vendor znovu aktivuje předplatné → produkty zase koupitelné

## 10. Refund (volitelné)

- [ ] Admin refundne objednávku (WooCommerce → Orders → Refund)
- [ ] Ledger: REVERSAL řádek s reálným `trr_*` ID
- [ ] Reconcile pořád drift 0

---

## Výsledek

Pokud všech 10 sekcí prošlo → MVP je funkčně ověřené. Před přepnutím na **live** Stripe klíče:

1. Přepni adapter + billing na live klíče
2. Vytvoř live webhooky (Connect + billing) + vlož live signing secrets
3. Smaž / archivuj testovací vendory a produkty
4. Otestuj 1 reálnou malou objednávku (např. 1 Kč produkt) než pustíš vendory

## Kde to nejčastěji drhne

| Symptom | Příčina | Fix |
|---|---|---|
| Vendor po loginu nevidí dashboard | WP user není propojen s vendor CPT | Stejný e-mail u WP usera i vendora (auto-link), nebo admin nastaví `_nkzmp_wp_user_id` |
| Status zůstává „awaiting KYC" | Connect webhook nedorazil | Stripe Webhooks log; zkontroluj URL + secret |
| Předplatné „Neaktivní" po platbě | Billing webhook chybí | Funguje i tak (sync na návratu); pro renewaly nastav webhook |
| Reconcile drift | Transfery před instalací observeru | Tools → Backfill, nebo install baseline (ignoruje starší) |
| Form submit nic | (vyřešeno v 0.9.4) redirect blokoval admin-post | bundle ≥ 0.9.4 |
