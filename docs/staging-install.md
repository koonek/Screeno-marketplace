# NKZ Marketplace – Staging instalace

Tento dokument popisuje, jak nahrát **core (`nkz-marketplace`)** vedle existujícího Stripe adapteru (`nkz-woo-stripe-vendor-split`) na staging Screeno bez rizika pro běžící data a chování.

## Co se v této verzi NEMĚNÍ

- Stripe Connect transfery jedou dál přes `nkz-woo-stripe-vendor-split` přesně jako před instalací core.
- Žádné existující CPT, meta klíče, hooks, REST endpointy ani options nejsou dotčené.
- Core se chová jako **pasivní pozorovatel** – přidá vlastní tabulky a oprávnění, ale nic z toho zatím není čteno produkčním kódem.

## Co se nainstaluje

| Komponenta | Akce |
|---|---|
| DB tabulka `wp_nkzmp_ledger` | vytvořena (prázdná) |
| DB tabulka `wp_nkzmp_payouts` | vytvořena (prázdná) |
| Role `nkzmp_vendor` | přidána (žádný user ji zatím nemá) |
| Capabilities `nkzmp_manage_vendors`, `nkzmp_approve_vendor`, `nkzmp_view_audit_log`, `nkzmp_manage_payouts` | přiřazeny administratorovi (a shop_manager kde dává smysl) |
| Submenu **WooCommerce → NKZ Marketplace** | status diagnostika (read-only) |
| WP-CLI `wp nkzmp status` | příkaz dostupný |

## Postup nahrání

1. **Záloha DB.** Standardní praxe před aktivací jakéhokoli pluginu.
2. **Zip core balíčku.** Z monorepa zazipuj obsah `packages/nkz-marketplace/`:
   ```bash
   cd packages
   zip -r nkz-marketplace.zip nkz-marketplace -x '*/.git/*'
   ```
3. **Upload** přes WP admin → Pluginy → Přidat nový → Nahrát plugin. Nebo nakopíruj do `wp-content/plugins/nkz-marketplace/` přes SFTP.
4. **Aktivace.** Aktivace pustí instalační hook (DB tabulky, role, caps).
5. **Ověř status:**
   - admin: WooCommerce → NKZ Marketplace → vše zelené
   - CLI: `wp nkzmp status` → `Success: Core install OK.`
6. **Ověř, že Stripe adapter dál funguje** – zkušební objednávka s vendorem stále vytvoří Stripe transfer.

## Coexistence s legacy

| Aspekt | Legacy `nkz-woo-stripe-vendor-split` | Nový `nkz-marketplace` |
|---|---|---|
| CPT | `nkv_vendor` (aktivní) | `nkzmp_vendor` (inactive, opt-in přes `NKZMP_ENABLE_CORE_CPT`) |
| Hooks | `nkv_svs_*` (aktivní) | `nkzmp/v1/*` (zatím nikdo nevolá) |
| Meta | `_nkv_*` (aktivní) | `_nkzmp_*` (zatím prázdné) |
| Options | `nkv_svs_*` | `nkzmp_*` |
| REST | `/wp-json/nkv-svs/v1/*` | `/wp-json/nkzmp/v1/*` (zatím neregistruje endpointy) |
| Tabulky | nemá | `wp_nkzmp_ledger`, `wp_nkzmp_payouts` |
| Role | nemá | `nkzmp_vendor` |

Žádné jméno se nepřekrývá → souběh je bezpečný.

## Rollback

Pokud cokoli vadí:
1. Deaktivuj `nkz-marketplace`. Žádná data ze Stripe adapteru se nedotkla, takže Screeno běží dál.
2. Smaž plugin přes WP admin – `uninstall.php` smaže DB tabulky, role a caps. Vrátí instalaci do předchozího stavu.

## Co testovat na stagingu

- [ ] Aktivace nehlásí chybu.
- [ ] WooCommerce → NKZ Marketplace zobrazí status – vše zelené.
- [ ] `wp nkzmp status --format=json` vrátí validní JSON s `"ledger_exists": true`, `"payouts_exists": true`, `"vendor_role": true`.
- [ ] Existující Stripe vendor objednávka (test order) dál vytvoří transfer (chování legacy se nezměnilo).
- [ ] Deaktivace nehlásí chybu.
- [ ] Smazání pluginu odstraní obě DB tabulky a obě role.
- [ ] V `wp-content/debug.log` po hodině provozu nepřibyly chyby z namespace `NKZMP\` nebo source `nkzmp`.

## Backfill historických dat (volitelné)

Po instalaci je ledger prázdný. Aby ses dostal k historickým datům všech existujících Stripe transferů, spusť backfill:

```bash
# Dry run – jen napočítá, nezapisuje
wp nkzmp backfill --dry-run

# Reálný import všech historických transferů
wp nkzmp backfill

# Jen ordery od 1.1.2024
wp nkzmp backfill --since=2024-01-01
```

Backfill je **idempotentní** – opakované spuštění nevytváří duplikáty díky deterministickým idempotency keys. Bezpečné spustit kdykoliv.

Po backfillu uvidíš v `wp nkzmp ledger list` nebo na admin status page reálnou historii všech Screeno Stripe transferů.

## CLI příkazy

```bash
wp nkzmp status                              # Health check
wp nkzmp status --format=json                # Strojový output
wp nkzmp ledger list                         # 20 posledních záznamů
wp nkzmp ledger list --vendor=42             # Filtr na vendora
wp nkzmp ledger list --order=1234            # Filtr na order
wp nkzmp ledger balance 42 --currency=CZK    # Balance vendora
wp nkzmp backfill --dry-run                  # Spočítat historické transfery
wp nkzmp backfill                            # Importovat historické transfery
```

## Co tato verze **neumí**

- Žádný frontend / vendor registrace / subscription billing / per-vendor shipping. Tyhle add-ony přijdou ve Fázi 1.
- Stripe adapter zatím nečte z nového ledgeru a nezapisuje do něj – wireup proběhne v dalším PR.
- Migrace dat vendorů (`wp nkzmp migrate-vendors`) zatím neexistuje. Bude přidána před první ostrou produkční aktivací CPT.
