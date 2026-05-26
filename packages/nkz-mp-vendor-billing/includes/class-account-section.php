<?php
/**
 * AccountSection – billing sekce v My Account (/muj-ucet/vendor-billing).
 *
 * Vlastní endpoint registrovaný nezávisle na vendor-dashboard modulu.
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class AccountSection {

	public const SLUG = 'vendor-billing';

	private static ?AccountSection $instance = null;

	public static function instance(): AccountSection {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'init', [ $this, 'add_endpoint' ] );
		add_filter( 'woocommerce_get_query_vars', [ $this, 'query_var' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ], 6 );
		add_action( 'woocommerce_account_' . self::SLUG . '_endpoint', [ $this, 'render' ] );
		add_filter( 'woocommerce_endpoint_' . self::SLUG . '_title', fn() => __( 'Předplatné', 'nkz-mp-vendor-billing' ) );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::SLUG, EP_PAGES );
	}

	public function query_var( array $vars ): array {
		$vars[ self::SLUG ] = self::SLUG;
		return $vars;
	}

	public function menu_item( array $items ): array {
		if ( ! Settings::is_enabled() ) {
			return $items;
		}
		if ( ! class_exists( \NKZMP\Dashboard\VendorContext::class ) || ! \NKZMP\Dashboard\VendorContext::user_is_vendor() ) {
			return $items;
		}
		$label = __( 'Předplatné', 'nkz-mp-vendor-billing' );

		// Vlož do vendor skupiny – hned za Profil (poslední vendor položka).
		$new = [];
		foreach ( $items as $k => $v ) {
			$new[ $k ] = $v;
			if ( $k === 'vendor-profile' ) {
				$new[ self::SLUG ] = $label;
			}
		}
		// Fallback: před logout, jinak na konec.
		if ( ! isset( $new[ self::SLUG ] ) ) {
			$new = [];
			foreach ( $items as $k => $v ) {
				if ( $k === 'customer-logout' ) {
					$new[ self::SLUG ] = $label;
				}
				$new[ $k ] = $v;
			}
			if ( ! isset( $new[ self::SLUG ] ) ) {
				$new[ self::SLUG ] = $label;
			}
		}
		return $new;
	}

	public function render(): void {
		if ( ! class_exists( \NKZMP\Dashboard\VendorContext::class ) || ! \NKZMP\Dashboard\VendorContext::user_is_vendor() ) {
			echo '<p>' . esc_html__( 'Tato sekce je pro prodejce.', 'nkz-mp-vendor-billing' ) . '</p>';
			return;
		}
		$vendor_id = \NKZMP\Dashboard\VendorContext::current_vendor_id();

		$flash = isset( $_GET['nkzmp_billing'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_billing'] ) ) : '';
		$err   = isset( $_GET['nkzmp_billing_err'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_billing_err'] ) ) : '';

		// Po návratu z Checkoutu ověř session přímo u Stripe (fallback když
		// webhook ještě nedorazil / není nastavený).
		if ( $flash === 'success' && ! empty( $_GET['session_id'] ) ) {
			$this->sync_from_session( $vendor_id, sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) );
		}

		$status    = (string) get_post_meta( $vendor_id, NKZMP_BILLING_STATUS_META, true ) ?: 'none';
		$amount    = Settings::amount_for_vendor( $vendor_id );
		$currency  = (string) Settings::get()['currency'];

		echo '<div class="nkzmp-vd nkzmp-billing-account">';
		echo '<header class="nkzmp-vd-section-head"><h1>' . esc_html__( 'Předplatné', 'nkz-mp-vendor-billing' ) . '</h1>';
		echo '<p class="nkzmp-vd-meta">' . esc_html( sprintf( __( 'Členství prodejce: %d %s / měsíc.', 'nkz-mp-vendor-billing' ), $amount, $currency ) ) . '</p></header>';

		if ( $flash === 'success' ) {
			echo '<div class="nkzmp-vd-flash nkzmp-vd-flash--success"><div class="icon">✓</div><div><strong>' . esc_html__( 'Předplatné aktivní. Děkujeme!', 'nkz-mp-vendor-billing' ) . '</strong></div></div>';
		} elseif ( $err ) {
			echo '<div class="nkzmp-vd-form-error"><strong>' . esc_html__( 'Chyba.', 'nkz-mp-vendor-billing' ) . '</strong> ' . esc_html( $err ) . '</div>';
		}

		// Status karta.
		$labels = [
			'active'   => [ __( 'Aktivní', 'nkz-mp-vendor-billing' ), 'success' ],
			'past_due' => [ __( 'Po splatnosti', 'nkz-mp-vendor-billing' ), 'error' ],
			'canceled' => [ __( 'Zrušeno', 'nkz-mp-vendor-billing' ), 'muted' ],
			'none'     => [ __( 'Neaktivní', 'nkz-mp-vendor-billing' ), 'neutral' ],
		];
		[ $label, $tone ] = $labels[ $status ] ?? $labels['none'];

		echo '<div class="nkzmp-vd-stats"><div class="nkzmp-vd-stat">';
		echo '<div class="nkzmp-vd-stat-label">' . esc_html__( 'Stav předplatného', 'nkz-mp-vendor-billing' ) . '</div>';
		echo '<div class="nkzmp-vd-stat-value" style="font-size:24px;">' . esc_html( $label ) . '</div>';
		echo '</div></div>';

		echo '<div style="margin-top:24px;display:flex;gap:16px;align-items:center;">';
		if ( in_array( $status, [ 'none', 'canceled' ], true ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . Checkout::ACTION_START ), Checkout::ACTION_START );
			echo '<a class="nkzmp-vd-cta-new" href="' . esc_url( $url ) . '" style="background:#0060FF;color:#fff;padding:14px 28px;text-decoration:none;font-weight:500;">' . esc_html__( 'Aktivovat předplatné', 'nkz-mp-vendor-billing' ) . '</a>';
		} else {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . Checkout::ACTION_PORTAL ), Checkout::ACTION_PORTAL );
			echo '<a class="nkzmp-vd-cta-new" href="' . esc_url( $url ) . '" style="background:#000;color:#fff;padding:14px 28px;text-decoration:none;font-weight:500;">' . esc_html__( 'Spravovat předplatné', 'nkz-mp-vendor-billing' ) . '</a>';
		}
		echo '</div>';

		if ( $status === 'past_due' ) {
			echo '<p style="margin-top:16px;color:#b00020;">' . esc_html__( 'Poslední platba neprošla. Aktualizuj platební metodu, jinak ti dočasně skryjeme produkty.', 'nkz-mp-vendor-billing' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Ověří Checkout session přímo u Stripe a nastaví stav. Funguje i bez
	 * webhooku (důležité na stagingu kde webhook nemusí být nastavený).
	 */
	private function sync_from_session( int $vendor_id, string $session_id ): void {
		if ( $vendor_id <= 0 || $session_id === '' || ! class_exists( StripeApi::class ) ) {
			return;
		}
		$api = new StripeApi();
		if ( ! $api->is_ready() ) {
			return;
		}
		$session = $api->get_checkout_session( $session_id );
		if ( ! $session ) {
			return;
		}
		// payment_status 'paid' nebo subscription přítomná → aktivní.
		$paid         = ( $session['payment_status'] ?? '' ) === 'paid';
		$subscription = (string) ( $session['subscription'] ?? '' );
		if ( ! $paid && $subscription === '' ) {
			return;
		}

		if ( $subscription !== '' ) {
			update_post_meta( $vendor_id, NKZMP_BILLING_SUBSCRIPTION_META, $subscription );
		}
		if ( ! empty( $session['customer'] ) ) {
			update_post_meta( $vendor_id, NKZMP_BILLING_CUSTOMER_META, (string) $session['customer'] );
		}
		update_post_meta( $vendor_id, NKZMP_BILLING_STATUS_META, 'active' );
		delete_post_meta( $vendor_id, '_nkzmp_billing_failed_at' );

		// Reaktivovat vendora pokud byl suspended kvůli platbě.
		$status = ( new \NKZMP\Vendor\Repository() )->status( $vendor_id );
		if ( $status === \NKZMP\Vendor\Status::SUSPENDED ) {
			try {
				( new \NKZMP\Vendor\StatusService() )->transition( $vendor_id, \NKZMP\Vendor\Status::ACTIVE, [ 'source' => 'billing_checkout_return' ] );
			} catch ( \Throwable $e ) {
				update_post_meta( $vendor_id, '_nkzmp_vendor_status', 'active' );
			}
		}

		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      'billing.activated_on_return',
				entity_type: 'vendor',
				entity_id:   $vendor_id,
				summary:     'Subscription confirmed on checkout return',
				actor_label: 'billing_return',
			);
		}
	}
}
