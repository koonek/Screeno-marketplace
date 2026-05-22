# NKZ Marketplace – Hook Reference (v1)

Veřejný API kontrakt. Všechny hooky core jsou prefixované `nkzmp/v1/*`. Add-ony se proti nim programují. Změny v `v1` namespace mají **6měsíční deprecation periodu** přes `_deprecated_hook()` před odstraněním.

> **Stav:** Fáze 0 – seznam je živý, hooky se přidávají postupně. Položky označené `(planned)` ještě nejsou implementované.

## Vendor lifecycle

| Hook | Typ | Args | Kdy |
|---|---|---|---|
| `nkzmp/v1/vendor/registered` | action | `int $vendor_id` | Po vytvoření vendora ve stavu `pending`. *(planned)* |
| `nkzmp/v1/vendor/approved` | action | `int $vendor_id, int $approver_user_id` | Admin schválil vendora → `approved_awaiting_kyc`. *(planned)* |
| `nkzmp/v1/vendor/rejected` | action | `int $vendor_id, string $reason` | Admin zamítl vendora. *(planned)* |
| `nkzmp/v1/vendor/activated` | action | `int $vendor_id` | KYC dokončeno, vendor je `active`. *(planned)* |
| `nkzmp/v1/vendor/suspended` | action | `int $vendor_id, string $reason` | Vendor přešel do `suspended`. *(planned)* |
| `nkzmp/v1/vendor/reactivated` | action | `int $vendor_id` | Návrat z `suspended` do `active`. *(planned)* |
| `nkzmp/v1/vendor/terminated` | action | `int $vendor_id` | Trvalá terminace. *(planned)* |
| `nkzmp/v1/vendor/status` | filter | `Status $status, int $vendor_id` | Pro override resolved status (např. add-on chce dočasně suspendovat). *(planned)* |

## Allocation & ledger

| Hook | Typ | Args | Kdy |
|---|---|---|---|
| `nkzmp/v1/allocation/calculate` | filter | `Allocation[] $allocations, WC_Order $order` | Po výpočtu, před zápisem do ledgeru. Adapter může upravit. *(planned)* |
| `nkzmp/v1/allocation/calculated` | action | `Allocation[] $allocations, WC_Order $order` | Po definitivní alokaci. *(planned)* |
| `nkzmp/v1/ledger/entry_recorded` | action | `LedgerEntry $entry` | Po zápisu do `wp_nkzmp_ledger`. *(planned)* |
| `nkzmp/v1/ledger/reconciliation` | action | `array $drift` | Cron reconciliation report. *(planned)* |

## Shipping

| Hook | Typ | Args | Kdy |
|---|---|---|---|
| `nkzmp/v1/shipping/allocation` | filter | `array $map, WC_Order $order` | Mapa `vendor_id => amount_minor`. Default = vše platformě. `nkz-mp-shipping` add-on tu vrací per-vendor split. *(planned)* |
| `nkzmp/v1/shipping/requires` | filter | `bool $requires, WC_Product $product` | Zda produkt vyžaduje shipping (digital flag). *(planned)* |

## Payout state machine

| Hook | Typ | Args | Kdy |
|---|---|---|---|
| `nkzmp/v1/payout/transition` | action | `int $payout_id, State $from, State $to, array $context` | Při každé změně stavu. *(planned)* |
| `nkzmp/v1/payout/adapter` | filter | `?PayoutAdapter $adapter, int $vendor_id` | Výběr payout adaptéru per vendor. *(planned)* |

## Subscription (vendor billing)

| Hook | Typ | Args | Kdy |
|---|---|---|---|
| `nkzmp/v1/subscription/status_changed` | action | `int $vendor_id, string $old, string $new` | Reportuje subscription adapter. *(planned)* |

## Backward-compat aliasy (Fáze 0 deprekace)

Staré hooky ze Stripe adapter pluginu `nkv_svs_*` zůstávají funkční přes `_deprecated_hook()` shim. Tabulka mapování:

| Starý hook | Nový hook | Plán odstranění |
|---|---|---|
| `nkv_svs_after_calculate_split` | `nkzmp/v1/allocation/calculated` | min. 2 minor verze |
| `nkv_svs_before_create_transfer` | `nkzmp/v1/payout/transition` (přechod do `payable`) | min. 2 minor verze |
| `nkv_svs_after_create_transfer` | `nkzmp/v1/payout/transition` (přechod do `paid`) | min. 2 minor verze |
| `nkv_svs_transfer_failed` | `nkzmp/v1/payout/transition` (přechod do `failed`) | min. 2 minor verze |
| `nkv_svs_filter_platform_fee_percent` | `nkzmp/v1/allocation/calculate` | min. 2 minor verze |
| `nkv_svs_filter_transfer_amount_minor` | `nkzmp/v1/allocation/calculate` | min. 2 minor verze |
| `nkv_svs_filter_calculation` | `nkzmp/v1/allocation/calculate` | min. 2 minor verze |
