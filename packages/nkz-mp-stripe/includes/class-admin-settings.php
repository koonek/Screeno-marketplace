<?php
/**
 * WC Settings tab.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Admin_Settings {

	private const TAB = 'nkv_stripe_split';

	private static ?Admin_Settings $instance = null;
	public static function instance(): Admin_Settings { return self::$instance ??= new self(); }

	public function init(): void {
		// Pokud je dostupný NKZ Marketplace SettingsHub, registrujeme se jako jeho
		// tab (sjednocení). Jinak fallback na vlastní WooCommerce settings tab
		// (např. Screeno standalone bez core hubu).
		if ( class_exists( \NKZMP\Admin\SettingsHub::class ) && \NKZMP\Admin\SettingsHub::available() ) {
			add_filter( 'nkzmp/v1/admin/settings/tabs', [ $this, 'register_hub_tab' ] );
			add_action( 'admin_init', [ $this, 'maybe_save_hub' ] );
		} else {
			add_filter( 'woocommerce_settings_tabs_array', [ $this, 'register_tab' ], 50 );
			add_action( 'woocommerce_settings_tabs_' . self::TAB, [ $this, 'render' ] );
			add_action( 'woocommerce_update_options_' . self::TAB, [ $this, 'save' ] );
		}
	}

	/** Registrace jako tab v NKZ Marketplace → Nastavení. */
	public function register_hub_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'payments',
			'label'    => __( 'Platby (Stripe)', 'nkz-woo-stripe-vendor-split' ),
			'priority' => 15,
			'render'   => [ $this, 'render_hub' ],
		];
		return $tabs;
	}

	/** Render uvnitř SettingsHub (vlastní form, ukládá maybe_save_hub). */
	public function render_hub(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( isset( $_GET['nkv_svs_saved'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Nastavení uloženo.', 'nkz-woo-stripe-vendor-split' ) . '</p></div>';
		}
		echo '<form method="post" action="">';
		wp_nonce_field( 'nkv_svs_hub_save', 'nkv_svs_hub_nonce' );
		$this->render(); // woocommerce_admin_fields($fields) – tabulka polí
		submit_button();
		echo '</form>';
	}

	/** Uloží nastavení odeslaná z hub formuláře (mimo WC update hook). */
	public function maybe_save_hub(): void {
		if ( ! isset( $_POST['nkv_svs_hub_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nkv_svs_hub_nonce'] ) ), 'nkv_svs_hub_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$this->save();
		if ( class_exists( \NKZMP\Admin\SettingsHub::class ) ) {
			wp_safe_redirect( add_query_arg( 'nkv_svs_saved', '1', \NKZMP\Admin\SettingsHub::url( 'payments' ) ) );
			exit;
		}
	}

	public function register_tab( array $tabs ): array {
		$tabs[ self::TAB ] = __( 'Stripe Vendor Split', 'nkz-woo-stripe-vendor-split' );
		return $tabs;
	}

	private function fields(): array {
		return [
			[ 'type' => 'title', 'title' => __( 'Stripe — rozdělení plateb', 'nkz-woo-stripe-vendor-split' ), 'id' => 'nkv_svs_section' ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__enabled', 'title' => __( 'Aktivovat plugin', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'select', 'id' => 'nkv_svs__mode', 'title' => __( 'Režim', 'nkz-woo-stripe-vendor-split' ), 'options' => [ 'test' => __( 'Testovací', 'nkz-woo-stripe-vendor-split' ), 'live' => __( 'Ostrý', 'nkz-woo-stripe-vendor-split' ) ] ],
			[ 'type' => 'password', 'id' => 'nkv_svs_secret_test', 'title' => __( 'Stripe secret key (test)', 'nkz-woo-stripe-vendor-split' ), 'autoload' => false ],
			[ 'type' => 'password', 'id' => 'nkv_svs_secret_live', 'title' => __( 'Stripe secret key (ostrý)', 'nkz-woo-stripe-vendor-split' ), 'autoload' => false ],
			[ 'type' => 'password', 'id' => 'nkv_svs_webhook_secret', 'title' => __( 'Stripe webhook secret (whsec_…)', 'nkz-woo-stripe-vendor-split' ), 'autoload' => false, 'desc_tip' => __( 'Zkopíruj ze Stripe Dashboard po vytvoření webhooku na URL níže.', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'number', 'id' => 'nkv_svs__default_fee_percent', 'title' => __( 'Výchozí provize platformy (%)', 'nkz-woo-stripe-vendor-split' ), 'custom_attributes' => [ 'step' => '0.01', 'min' => '0', 'max' => '100' ] ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__split_includes_tax', 'title' => __( 'Zahrnout DPH do základu prodejce', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__split_includes_shipping', 'title' => __( 'Zahrnout dopravu do rozdělení', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__deduct_coupons_proportionally', 'title' => __( 'Odečíst slevy poměrově', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'select', 'id' => 'nkv_svs__stripe_fee_vendor_share_percent', 'title' => __( 'Stripe poplatek — kolik nese prodejce', 'nkz-woo-stripe-vendor-split' ), 'options' => [ '0' => __( '0 % — celé platí platforma', 'nkz-woo-stripe-vendor-split' ), '50' => __( '50 % — půl na půl', 'nkz-woo-stripe-vendor-split' ), '100' => __( '100 % — celé platí prodejce', 'nkz-woo-stripe-vendor-split' ) ] ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__automatic_transfers', 'title' => __( 'Automatické transfery', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__log_only_mode', 'title' => __( 'Pouze logovat (dry-run režim)', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'number', 'id' => 'nkv_svs__minimum_transfer_amount', 'title' => __( 'Minimální částka transferu', 'nkz-woo-stripe-vendor-split' ), 'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ] ],
			[ 'type' => 'select', 'id' => 'nkv_svs__transfer_hook', 'title' => __( 'Spouštěcí hook', 'nkz-woo-stripe-vendor-split' ), 'options' => [ 'payment_complete' => __( 'po dokončení platby (doporučeno)', 'nkz-woo-stripe-vendor-split' ), 'processing' => __( 'stav objednávky: zpracovává se', 'nkz-woo-stripe-vendor-split' ), 'completed' => __( 'stav objednávky: dokončeno', 'nkz-woo-stripe-vendor-split' ) ] ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__require_currency_match', 'title' => __( 'Vyžadovat shodu měny prodejce a objednávky', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__auto_reversal_on_full_refund', 'title' => __( 'Automatický reversal při plném refundu', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__debug_logging', 'title' => __( 'Debug logování', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'sectionend', 'id' => 'nkv_svs_section' ],
		];
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Prefill from current settings.
		$settings = Plugin::settings();
		$fields   = $this->fields();
		foreach ( $fields as &$f ) {
			if ( isset( $f['id'] ) && str_starts_with( $f['id'], 'nkv_svs__' ) ) {
				$key = substr( $f['id'], strlen( 'nkv_svs__' ) );
				$f['value'] = $settings[ $key ] ?? '';
			} elseif ( in_array( $f['id'] ?? '', [ 'nkv_svs_secret_test', 'nkv_svs_secret_live', 'nkv_svs_webhook_secret' ], true ) ) {
				// Masked: show placeholder, never reveal full key.
				$existing = (string) get_option( $f['id'], '' );
				$f['value'] = '';
				$desc = $existing ? sprintf( __( 'Aktuálně nastaveno: %s', 'nkz-woo-stripe-vendor-split' ), self::mask( $existing ) ) : __( 'Nenastaveno.', 'nkz-woo-stripe-vendor-split' );
				if ( 'nkv_svs_webhook_secret' === $f['id'] ) {
					$desc .= '<br><strong>' . esc_html__( 'Endpoint URL pro Stripe:', 'nkz-woo-stripe-vendor-split' ) . '</strong> <code>' . esc_html( Webhook_Controller::endpoint_url() ) . '</code>';
				}
				$f['desc'] = $desc;
			}
		}
		woocommerce_admin_fields( $fields );
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = Plugin::settings();
		foreach ( $this->fields() as $f ) {
			if ( ! isset( $f['id'] ) || ! str_starts_with( $f['id'], 'nkv_svs__' ) ) {
				continue;
			}
			$key = substr( $f['id'], strlen( 'nkv_svs__' ) );
			$raw = $_POST[ $f['id'] ] ?? null;
			switch ( $f['type'] ) {
				case 'checkbox':
					$settings[ $key ] = ( '1' === $raw || 'yes' === $raw ) ? 'yes' : 'no';
					break;
				case 'number':
					$settings[ $key ] = (float) $raw;
					break;
				case 'select':
					$settings[ $key ] = sanitize_text_field( (string) $raw );
					break;
				default:
					$settings[ $key ] = sanitize_text_field( (string) $raw );
			}
		}
		update_option( 'nkv_svs_settings', $settings );

		// Secret keys: only overwrite when non-empty submitted value (preserve existing).
		foreach ( [ 'nkv_svs_secret_test', 'nkv_svs_secret_live', 'nkv_svs_webhook_secret' ] as $opt ) {
			$val = isset( $_POST[ $opt ] ) ? trim( (string) wp_unslash( $_POST[ $opt ] ) ) : '';
			if ( '' !== $val ) {
				update_option( $opt, $val, false );
			}
		}
	}

	private static function mask( string $key ): string {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', max( 4, $len - 8 ) ) . substr( $key, -4 );
	}
}
