# Daně (DPH) a doprava – otevřené otázky k rozhodnutí

Stav: **čeká na potvrzení účetní / daňového poradce AOZ.** Tento dokument
shrnuje, jak to systém dělá dnes a co je potřeba rozhodnout před spuštěním
naostro (reálné peníze, případně SK/EU zásilky).

> ⚠️ Není daňové poradenství. Body níže potvrdit s účetní.

---

## 1. DPH – model marketplace

**Zvolený model: zprostředkovatel.** AOZ (platforma) zprostředkuje prodej,
smlouva o koupi je **prodejce ↔ kupující**. Prodávající je prodejce.

- **Platforma = neplátce DPH** → neúčtuje DPH na nic (provize, servisní
  poplatek). Hlídat vlastní obrat (registrace plátce ~2 mil Kč / 12 měs).
- **Prodejce = plátce DPH** → k ceně účtuje DPH, sám vystaví daňový doklad
  kupujícímu (své DIČ + rozpis DPH), DPH odvádí.
- **Prodejce = neplátce** → prodává bez DPH, běžná účtenka.

**Stripe Connect / escrow / transfery nemají na DPH vliv** – DPH se řídí
právním plněním (prodejce→kupující), ne tokem peněz.

### Co je hotové v systému
- Store-level daň vypnutá (platforma neplátce). Cena = finální.
- Servisní poplatek `taxable=false` (neplátce). Filtr pro zapnutí kdyby se
  AOZ stalo plátcem: `nkzmp/v1/platform_fee/taxable`.

### Co je potřeba rozhodnout / dořešit
- [ ] **Daňový doklad plátce** – největší mezera. Defaultní WC faktura je od
      platformy, ale plátce-prodejce musí vystavit **vlastní** doklad se svým
      DIČ. Cesty:
  - (a) prodejce vystavuje sám ze svého účetnictví → do dashboardu přidat
        **export objednávek** s potřebnými údaji. *(menší práce)*
  - (b) generovat **per-vendor doklady** v systému (DIČ prodejce, rozpis
        DPH). *(větší kus – invoicing modul)*
- [ ] Zakotvit zprostředkovatelský model + odpovědnost prodejce za DPH/doklady
      do **Podmínek pro prodejce**.
- [ ] Označení „prodejce je plátce DPH" v profilu prodejce (+ DIČ) – užitečné
      tak jako tak. *(připraveno udělat, nezávisí na rozhodnutí o dokladech)*

---

## 2. Doprava a prodej do SK / EU

### Fyzická doprava
- Oba shipping moduly (paušál i Packeta) podporují **WC shipping zones** →
  SK/EU se řeší **konfigurací zón**, ne kódem.
- Packeta (Zásilkovna) doručuje do **SK i dalších zemí EU** (výdejní místa
  i adresa) – widget výběru místa umí víc zemí.

### Co je potřeba rozhodnout / nastavit
- [ ] **Do kterých zemí prodávat/doručovat?** (jen CZ / CZ+SK / EU)
      → WooCommerce → Nastavení → Obecné → „Prodávat do" + „Doručovat do".
- [ ] **Sazby dopravy pro SK/EU** – dnes je per-vendor paušál. Pro SK/EU je
      doprava dražší → nastavit **shipping zóny** (CZ / SK / EU) s vlastními
      sazbami, nebo per-vendor SK příplatek. *(config, případně drobný kód)*
- [ ] Ověřit v Packeta účtu, že jsou **povolené SK/EU výdejní body** a že
      widget je nabízí.

### DPH při přeshraničním prodeji (EU / SK) – per prodejce
- Prodej B2C do jiné země EU řeší pravidla **OSS (One Stop Shop)**:
  - Plátce nad souhrnným prahem **10 000 € / rok** (přeshraniční B2C do EU)
    účtuje **DPH země určení** a registruje se do OSS.
  - Pod prahem účtuje domácí (CZ) DPH.
  - Neplátce – bez DPH.
- V zprostředkovatelském modelu je to **odpovědnost každého prodejce** (je
  prodávající). Platforma to neřeší.
- [ ] Zmínit v Podmínkách pro prodejce (prodejce odpovídá za OSS/DPH při EU
      prodeji).
- [ ] Pokud budou plátci-prodejci reálně prodávat přeshraničně nad práh →
      zvážit podporu **DPH sazeb dle země** v systému. *(zatím pravděpodobně
      nepotřeba – malí tvůrci pod prahem.)*

---

## Shrnutí – co udělat teď vs. počkat na účetní

**Můžeme udělat kdykoli (nezávisí na účetní):**
- Označení plátce DPH + DIČ v profilu prodejce
- Export objednávek pro účetnictví prodejce
- Shipping zóny CZ / SK / EU se sazbami (až padne rozhodnutí o zemích)

**Čeká na účetní / poradce:**
- Způsob vystavování daňových dokladů (a vs. b)
- Potvrzení zprostředkovatelského modelu a textace podmínek
- Potřeba per-country DPH sazeb (OSS) – zřejmě zatím ne
