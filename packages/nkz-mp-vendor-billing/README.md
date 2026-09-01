# NKZ Marketplace – Vendor Billing

Měsíční předplatné prodejců přes Stripe Billing.

## Jak to funguje

1. Admin zapne billing + nastaví částku (NKZ Marketplace → Billing). Default 250 Kč/měsíc, konfigurovatelné, per-vendor override přes meta `_nkzmp_billing_amount_override`.
2. Prodejce v dashboardu (`/muj-ucet/vendor-billing`) klikne **Aktivovat předplatné** → Stripe Checkout (subscription mode) → zadá kartu → předplatné běží.
3. Stripe webhooky řídí stav:
   - `invoice.paid` → billing `active`, vendor reactivován (pokud byl suspended)
   - `invoice.payment_failed` → `past_due`; po grace period → vendor `suspended`
   - `customer.subscription.deleted` → `canceled` → suspend
4. **Suspended prodejce**: produkty zůstanou v katalogu viditelné, ale „add to cart" je disabled + badge „dočasně nedostupné".

## Setup

1. Stripe klíče se berou ze Stripe adapteru (WooCommerce → Stripe Vendor Split). Není potřeba zadávat zvlášť.
2. NKZ Marketplace → Billing → zapni + nastav částku, měnu, grace period.
3. Stripe Dashboard → Developers → Webhooks → Add endpoint:
   - URL: `https://<web>/wp-json/nkzmp/v1/billing/webhook`
   - Eventy: `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.deleted`

## Stav předplatného (meta na vendor CPT)

- `_nkzmp_billing_customer_id` — Stripe customer
- `_nkzmp_billing_subscription_id` — Stripe subscription
- `_nkzmp_billing_status` — active | past_due | canceled | none
- `_nkzmp_billing_amount_override` — per-vendor částka (volitelné)

## Mimo 0.1.0

- ❌ Webhook signature verification (zatím se ověřuje jen tvar payloadu). Doporučeno doplnit přes Stripe signing secret před produkcí s reálnými penězi.
- ❌ Proforma / faktury v PDF (řeší Stripe hosted invoices)
- ❌ Anniversary billing reminders e-mailem
- ❌ Cron fallback pro grace (zatím se suspend řeší při dalším payment_failed retry od Stripe)
