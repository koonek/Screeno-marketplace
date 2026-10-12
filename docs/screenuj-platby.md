# Screenuj – platby prodejců (onboarding + měsíční fee)

Od verze 0.6.8 platí prodejce kartou přes Stripe:

| Balíček | Při onboardingu | Pak každý měsíc |
|---|---|---|
| Rozjezd | 2 400 Kč | – |
| Růst | 2 400 Kč | 490 Kč |
| Naplno | 2 400 Kč | 790 Kč |

Částky jsou konečné (Screeno není plátce DPH) a mění se v **WooCommerce → Nastavení → Stripe Vendor Split → Platby prodejců (Screenuj)**. Změna platí pro nové platby; už běžící předplatné se upravuje ve Stripe.

## Jak to probíhá

1. V kartě prodejce vyber **Balíček** a ulož.
2. Pošli prodejci onboarding odkaz jako dosud. Po dokončení Stripe onboardingu uvidí tlačítko **Zaplatit kartou**.
3. Na platební stránce Stripe zaplatí onboarding a u Růstu/Naplno se mu zároveň založí měsíční předplatné. Další měsíce se strhávají samy.
4. Stav je vidět v kartě prodejce (box **Platby Screenuj**) a ve sloupci **Platby** v seznamu prodejců. Je tam i odkaz k platbě, který můžeš prodejci poslat ručně, a odkaz pro prodejce na správu karty.

**Stávající prodejci**, kteří onboarding zaplatili jinak (fakturou), dostanou po přiřazení balíčku k platbě jen měsíční fee: v boxu Platby Screenuj stačí zaškrtnout *Onboarding už zaplatil(a) jinak* a uložit.

Když platba neprojde, Stripe ji několikrát zkusí znovu a prodejci napíše sám. Stav se změní na **neplaceno** a na e-mail z nastavení přijde upozornění. Když se to nepovede ani tak, předplatné se zruší (stav **zrušeno**, další e-mail). Produkty prodejce se automaticky neskrývají.

## Nastavení ve Stripe (jednorázově)

1. **Webhook:** Developers → Webhooks → stávající endpoint (`…/wp-json/nkv-svs/v1/webhook`) → přidat události
   `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_failed`
   (vedle stávající `account.updated`).
2. **Zákaznický portál:** Settings → Billing → Customer portal → zapnout (povolit změnu platební metody a zobrazení faktur). Bez toho nefunguje odkaz „Správa karty".
3. **E-maily zákazníkům:** Settings → Customer emails → zapnout potvrzení o úspěšných platbách a upozornění na neúspěšnou platbu.
4. **Opakované pokusy:** Settings → Billing → Subscriptions and emails → Smart Retries a po posledním neúspěšném pokusu **zrušit předplatné**.

Nejdřív vše vyzkoušej v testovacím režimu (testovací karta `4242 4242 4242 4242`).

## Co se nemění

- Změna balíčku u už platícího prodejce (např. Růst → Naplno) se dělá ve Stripe u předplatného zákazníka; pole Balíček v kartě prodejce přepiš ručně.
- Další motiv (1 200 Kč) a model Vlastní e-shop se dál fakturují mimo plugin.
- Výpočet převodů z objednávek (cena za kus = fixní poplatek platformy u produktu) zůstává beze změny.
