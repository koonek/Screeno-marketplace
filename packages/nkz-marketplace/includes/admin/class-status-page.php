<?php
/**
 * Admin status page – diagnostika instalace.
 *
 * Zobrazuje stav DB tabulek, capabilities, počty entit a koexistenci s
 * legacy Stripe adapter pluginem. **Read-only**, žádné write akce.
 *
 * Umístění: WooCommerce → NKZ Marketplace.
 *
 * @package NKZMP
 */

namespace NKZMP\Admin;

use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Payout\Schema as PayoutSchema;
use NKZMP\Support\Capabilities;
use NKZMP\Vendor\Registry;

defined( 'ABSPATH' ) || exit;

final class StatusPage {

	private static ?StatusPage $instance = null;

	public static function instance(): StatusPage {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'NKZ Marketplace', 'nkz-marketplace' ),
			__( 'NKZ Marketplace', 'nkz-marketplace' ),
			Capabilities::MANAGE_VENDORS,
			'nkz-marketplace-status',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}

		global $wpdb;

		$report = $this->build_report();

		echo '<div class="wrap">';
		echo '<h1>NKZ Marketplace – Status</h1>';
		echo '<p>' . esc_html__( 'Diagnostika instalace. Vše zelené = připraveno na testování na stagingu.', 'nkz-marketplace' ) . '</p>';

		echo '<table class="widefat striped" style="max-width: 900px;"><tbody>';
		foreach ( $report as $row ) {
			$state_icon = '✅';
			$state_color = '#46b450';
			if ( $row['state'] === 'warn' ) {
				$state_icon  = '⚠️';
				$state_color = '#ffb900';
			} elseif ( $row['state'] === 'fail' ) {
				$state_icon  = '❌';
				$state_color = '#dc3232';
			}
			echo '<tr>';
			echo '<td style="width: 40px; font-size: 18px; text-align: center;">' . esc_html( $state_icon ) . '</td>';
			echo '<td style="width: 280px;"><strong>' . esc_html( $row['label'] ) . '</strong></td>';
			echo '<td style="color: ' . esc_attr( $state_color ) . ';">' . wp_kses_post( $row['detail'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Pro staging', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Core NKZ Marketplace je v této verzi pasivní pozorovatel – existující Stripe adapter (nkz-woo-stripe-vendor-split) jede beze změny. Core jen registruje tabulky, role a oprávnění. Reálné chování objednávek se aktivuje až v dalších verzích po migraci dat.', 'nkz-marketplace' ) . '</p>';
		echo '</div>';
	}

	/**
	 * @return list<array{label:string,state:string,detail:string}>
	 */
	private function build_report(): array {
		global $wpdb;

		$rows = [];

		// Plugin version.
		$rows[] = [
			'label'  => __( 'Verze pluginu', 'nkz-marketplace' ),
			'state'  => 'ok',
			'detail' => defined( 'NKZMP_VERSION' ) ? esc_html( NKZMP_VERSION ) : 'unknown',
		];

		// PHP version.
		$rows[] = [
			'label'  => __( 'PHP', 'nkz-marketplace' ),
			'state'  => version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'fail',
			'detail' => PHP_VERSION,
		];

		// WooCommerce.
		if ( class_exists( 'WooCommerce' ) ) {
			$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown';
			$rows[]     = [
				'label'  => __( 'WooCommerce', 'nkz-marketplace' ),
				'state'  => version_compare( $wc_version, '8.0', '>=' ) ? 'ok' : 'fail',
				'detail' => esc_html( $wc_version ),
			];
		} else {
			$rows[] = [
				'label'  => __( 'WooCommerce', 'nkz-marketplace' ),
				'state'  => 'fail',
				'detail' => 'not active',
			];
		}

		// Ledger table.
		$ledger_table = LedgerSchema::table_name();
		$ledger_ok    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ledger_table ) ) === $ledger_table;
		$ledger_count = $ledger_ok ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ledger_table}" ) : 0; // phpcs:ignore
		$rows[]       = [
			'label'  => __( 'Tabulka ledger', 'nkz-marketplace' ),
			'state'  => $ledger_ok ? 'ok' : 'fail',
			'detail' => sprintf( '<code>%s</code> – %d záznamů', esc_html( $ledger_table ), $ledger_count ),
		];

		// Payouts table.
		$payouts_table = PayoutSchema::table_name();
		$payouts_ok    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payouts_table ) ) === $payouts_table;
		$payouts_count = $payouts_ok ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$payouts_table}" ) : 0; // phpcs:ignore
		$rows[]        = [
			'label'  => __( 'Tabulka payouts', 'nkz-marketplace' ),
			'state'  => $payouts_ok ? 'ok' : 'fail',
			'detail' => sprintf( '<code>%s</code> – %d záznamů', esc_html( $payouts_table ), $payouts_count ),
		];

		// Vendor role.
		$role = get_role( Capabilities::ROLE_VENDOR );
		$rows[] = [
			'label'  => __( 'Vendor role', 'nkz-marketplace' ),
			'state'  => $role ? 'ok' : 'fail',
			'detail' => $role
				? sprintf( '<code>%s</code> – %d capabilities', esc_html( Capabilities::ROLE_VENDOR ), count( $role->capabilities ) )
				: __( 'nenainstalováno', 'nkz-marketplace' ),
		];

		// Admin caps.
		$admin = get_role( 'administrator' );
		$has_admin_caps = $admin && $admin->has_cap( Capabilities::MANAGE_VENDORS ) && $admin->has_cap( Capabilities::APPROVE_VENDOR );
		$rows[] = [
			'label'  => __( 'Admin capabilities', 'nkz-marketplace' ),
			'state'  => $has_admin_caps ? 'ok' : 'fail',
			'detail' => $has_admin_caps
				? esc_html__( 'nkzmp_manage_vendors + nkzmp_approve_vendor přiřazeny administratorovi', 'nkz-marketplace' )
				: esc_html__( 'chybí – aktivuj plugin znovu', 'nkz-marketplace' ),
		];

		// Core CPT opt-in.
		$cpt_on = defined( 'NKZMP_ENABLE_CORE_CPT' ) && NKZMP_ENABLE_CORE_CPT;
		$rows[] = [
			'label'  => __( 'Core CPT nkzmp_vendor', 'nkz-marketplace' ),
			'state'  => 'ok',
			'detail' => $cpt_on
				? esc_html__( 'AKTIVNÍ (NKZMP_ENABLE_CORE_CPT=true) – nová CPT registrovaná', 'nkz-marketplace' )
				: esc_html__( 'pasivní (default) – během Fáze 0 záměrně. CPT nkzmp_vendor se registruje až po nastavení konstanty NKZMP_ENABLE_CORE_CPT=true.', 'nkz-marketplace' ),
		];

		// Legacy adapter coexistence.
		$legacy_active = is_plugin_active( 'nkz-woo-stripe-vendor-split/nkz-woo-stripe-vendor-split.php' );
		$legacy_count  = 0;
		if ( post_type_exists( 'nkv_vendor' ) ) {
			$legacy_count = (int) wp_count_posts( 'nkv_vendor' )->publish;
		}
		$rows[] = [
			'label'  => __( 'Legacy Stripe adapter', 'nkz-marketplace' ),
			'state'  => $legacy_active ? 'ok' : 'warn',
			'detail' => $legacy_active
				? sprintf( '%s – %d vendorů v <code>nkv_vendor</code> CPT', esc_html__( 'aktivní (chování beze změny)', 'nkz-marketplace' ), $legacy_count )
				: esc_html__( 'neaktivní – pokud je tohle Screeno produkce, někdo plugin deaktivoval!', 'nkz-marketplace' ),
		];

		// Autoloader probe.
		$probe_classes = [
			'NKZMP\\Allocation\\Allocation',
			'NKZMP\\Ledger\\Repository',
			'NKZMP\\Payout\\Repository',
			'NKZMP\\Vendor\\Status',
			'NKZMP\\Support\\Money',
		];
		$probe_ok = true;
		foreach ( $probe_classes as $c ) {
			if ( ! class_exists( $c ) && ! interface_exists( $c ) && ! enum_exists( $c ) ) {
				$probe_ok = false;
				break;
			}
		}
		$rows[] = [
			'label'  => __( 'Autoloader', 'nkz-marketplace' ),
			'state'  => $probe_ok ? 'ok' : 'fail',
			'detail' => $probe_ok
				? sprintf( '%d/%d core tříd loaduje', count( $probe_classes ), count( $probe_classes ) )
				: esc_html__( 'některé třídy nelze načíst', 'nkz-marketplace' ),
		];

		return $rows;
	}
}
