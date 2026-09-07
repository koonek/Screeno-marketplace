# NKZ Marketplace – Vendor Registration

Frontend registrační formulář + 2-stage approval workflow s AOZ tone-of-voice e-maily.

## Použití

Vlož na stránku shortcode:

```
[nkzmp_vendor_registration]
```

Nebo přes Gutenberg jako Shortcode block.

Settings v WooCommerce → NKZ Registrace:
- Admin notification e-mail
- Success message po odeslání
- URL podmínek platformy (povinný checkbox)
- Optional redirect URL po odeslání

## Workflow

```
visitor → form (/pro-tvurce)
        ↓ POST
        vendor CPT created, status=pending
        ↓ emails sent
        ├─ applicant: "Tvoji přihlášku jsme dostali"
        └─ admin: "Nová přihláška: <name>"

admin → WooCommerce → NKZ Marketplace Tools → Změna statusu vendora
      → vyber vendor + status=approved_awaiting_kyc → Provést
        ↓ Listener catch nkzmp/v1/vendor/status_changed
        applicant: "Jsi v Art of život. Zbývá jeden krok." + Stripe Connect link

vendor → klikne na Stripe Connect link → vyplní KYC v Stripe
        ↓ Stripe webhook account.updated → legacy adapter nastaví _nkv_vendor_status=active
        ↓ MetaWatcher chytne meta změnu → emit nkzmp/v1/vendor/status_changed
        applicant: "Vítej v Art of život. Můžeš prodávat."
```

## Tone-of-voice

E-maily psané v duchu Komunikačního manuálu Art of život:
- Suchý inteligentní humor, přirozená autorita
- 1. osoba množného čísla pro mluvčí, 3. osoba pro značku
- "Art of život" vždy velké A, neskloňuje se
- Žádné automatické formality typu "Vážený zákazníku"

## Závislosti

- `nkz-marketplace` core ≥ 0.8.4-dev
- `nkz-woo-stripe-vendor-split` adapter (pro Stripe Connect link)
- WooCommerce ≥ 8.0
