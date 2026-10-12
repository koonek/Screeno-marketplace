<?php
/**
 * Platby prodejců za službu Screenuj: jednorázový onboarding + měsíční fee podle balíčku.
 *
 * Prodejce zaplatí kartou přes Stripe Checkout na účtu platformy (stejný secret key jako split):
 *  - Rozjezd      → jen onboarding (mode=payment)
 *  - Růst, Naplno → onboarding + měsíční předplatné v jednom kroku (mode=subscription,
 *                   onboarding jde jako jednorázová položka na první fakturu)
 * Částky jsou v nastavení pluginu (konečné, bez DPH). Ve Stripe se nic nezakládá předem.
 * Stav se ukládá k prodejci z návratu z Checkoutu i z webhooků (idempotentně).
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Billing {

	/** Stav předplatného v meta `_nkv_billing_status`. */
	public const STATUS_ACTIVE   = 'active';
	public const STATUS_UNPAID   = 'unpaid';
	public const STATUS_CANCELED = 'canceled';

	private static ?Billing $instance = null;
	public static function instance(): Billing { return self::$instance ??= new self(); }

	public function init(): void {
		foreach ( [ 'nkv_billing_start' => 'handle_start', 'nkv_billing_return' => 'handle_return', 'nkv_billing_portal' => 'handle_portal' ] as $action => $method ) {
			add_action( 'admin_post_nopriv_' . $action, [ $this, $method ] );
			add_action( 'admin_post_' . $action, [ $this, $method ] );
		}
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . Vendors::POST_TYPE, [ $this, 'save_meta_box' ], 20 );
		add_filter( 'manage_' . Vendors::POST_TYPE . '_posts_columns', [ $this, 'admin_columns' ], 20 );
		add_action( 'manage_' . Vendors::POST_TYPE . '_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Částky + stav.
	 * ------------------------------------------------------------------- */

	public static function onboarding_fee(): float {
		return max( 0.0, (float) Plugin::settings()['billing_onboarding_fee'] );
	}

	/** Měsíční fee balíčku v Kč, 0 = bez předplatného. */
	public static function monthly_fee( string $package ): float {
		$s = Plugin::settings();
		return max( 0.0, (float) ( $s[ 'billing_monthly_' . $package ] ?? 0 ) );
	}

	public static function status( int $vendor_id ): string {
		return (string) get_post_meta( $vendor_id, '_nkv_billing_status', true );
	}

	public static function onboarding_paid( int $vendor_id ): bool {
		return 'yes' === get_post_meta( $vendor_id, '_nkv_billing_onboarding_paid', true );
	}

	/** Co prodejce ještě má zaplatit: ['onboarding' => float, 'monthly' => float]. Nuly = nic. */
	public static function due( int $vendor_id ): array {
		$package = Vendors::package_of( $vendor_id );
		if ( '' === $package ) {
			return [ 'onboarding' => 0.0, 'monthly' => 0.0 ];
		}
		$monthly = self::monthly_fee( $package );
		return [
			'onboarding' => self::onboarding_paid( $vendor_id ) ? 0.0 : self::onboarding_fee(),
			'monthly'    => ( $monthly > 0 && self::STATUS_ACTIVE !== self::status( $vendor_id ) && self::STATUS_UNPAID !== self::status( $vendor_id ) ) ? $monthly : 0.0,
		];
	}

	public static function needs_payment( int $vendor_id ): bool {
		$due = self::due( $vendor_id );
		return $due['onboarding'] > 0 || $due['monthly'] > 0;
	}

	public static function start_url( int $vendor_id ): string {
		return self::public_url( 'nkv_billing_start', $vendor_id );
	}

	private static function public_url( string $action, int $vendor_id, array $extra = [] ): string {
		return add_query_arg(
			array_merge( [ 'action' => $action, 'v' => $vendor_id, 't' => Onboarding_Controller::vendor_token( $vendor_id ) ], $extra ),
			admin_url( 'admin-post.php' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Veřejné stránky prodejce.
	 * ------------------------------------------------------------------- */

	public function handle_start(): void {
		[ $vendor_id, $vendor ] = $this->authorize_public();

		$due = self::due( $vendor_id );
		if ( $due['onboarding'] <= 0 && $due['monthly'] <= 0 ) {
			$this->render_page( __( 'Vše je zaplaceno', 'nkz-woo-stripe-vendor-split' ), __( 'Nemáš nic k zaplacení. Díky!', 'nkz-woo-stripe-vendor-split' ), $vendor_id );
		}

		$client = new Stripe_Client();
		if ( ! $client->is_ready() ) {
			$this->render_page( __( 'Něco se nepovedlo', 'nkz-woo-stripe-vendor-split' ), __( 'Platforma nemá nakonfigurovaný Stripe. Kontaktuj prosím provozovatele.', 'nkz-woo-stripe-vendor-split' ) );
		}

		$package = Vendors::package_of( $vendor_id );
		$label   = Vendors::package_labels()[ $package ];
		$items   = [];
		if ( $due['onboarding'] > 0 ) {
			$items[] = [
				'price_data' => [
					'currency'     => 'czk',
					'unit_amount'  => nkvsvs_to_minor( $due['onboarding'], 'CZK' ),
					'product_data' => [ 'name' => sprintf( __( 'Onboarding – balíček %s', 'nkz-woo-stripe-vendor-split' ), $label ) ],
				],
				'quantity'   => 1,
			];
		}
		if ( $due['monthly'] > 0 ) {
			$items[] = [
				'price_data' => [
					'currency'     => 'czk',
					'unit_amount'  => nkvsvs_to_minor( $due['monthly'], 'CZK' ),
					'recurring'    => [ 'interval' => 'month' ],
					'product_data' => [ 'name' => sprintf( __( 'Měsíční fee – balíček %s', 'nkz-woo-stripe-vendor-split' ), $label ) ],
				],
				'quantity'   => 1,
			];
		}

		$metadata = [
			'nkv_vendor_id'  => (string) $vendor_id,
			'nkv_package'    => $package,
			'nkv_onboarding' => $due['onboarding'] > 0 ? 'yes' : 'no',
			'site'           => home_url(),
		];
		$params = [
			'mode'                => $due['monthly'] > 0 ? 'subscription' : 'payment',
			'line_items'          => $items,
			'client_reference_id' => (string) $vendor_id,
			'metadata'            => $metadata,
			'locale'              => 'cs',
			'success_url'         => self::public_url( 'nkv_billing_return', $vendor_id ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => self::public_url( 'nkv_billing_return', $vendor_id, [ 'cancelled' => 1 ] ),
		];
		if ( 'subscription' === $params['mode'] ) {
			$params['subscription_data'] = [ 'metadata' => $metadata ];
		} else {
			$params['payment_intent_data'] = [ 'metadata' => $metadata ];
		}
		$customer_id = (string) get_post_meta( $vendor_id, '_nkv_billing_customer_id', true );
		if ( '' !== $customer_id ) {
			$params['customer'] = $customer_id;
		} else {
			if ( is_email( $vendor['email'] ) ) {
				$params['customer_email'] = $vendor['email'];
			}
			if ( 'payment' === $params['mode'] ) {
				$params['customer_creation'] = 'always';
			}
		}

		$session = $client->create_checkout_session( $params );
		if ( empty( $session['url'] ) ) {
			Logger::error( 'Billing checkout create failed', [ 'vendor' => $vendor_id, 'err' => $session['error']['message'] ?? '' ] );
			$this->render_page( __( 'Něco se nepovedlo', 'nkz-woo-stripe-vendor-split' ), __( 'Platbu se nepodařilo zahájit. Zkus to prosím za chvíli znovu, nebo se ozvi provozovateli.', 'nkz-woo-stripe-vendor-split' ) );
		}
		wp_redirect( (string) $session['url'] );
		exit;
	}

	public function handle_return(): void {
		[ $vendor_id ] = $this->authorize_public();

		if ( ! empty( $_GET['cancelled'] ) ) {
			$this->render_page( __( 'Platba nebyla dokončena', 'nkz-woo-stripe-vendor-split' ), __( 'Nic se nestrhlo. K platbě se můžeš kdykoli vrátit stejným odkazem.', 'nkz-woo-stripe-vendor-split' ), $vendor_id );
		}

		// Nečekáme na webhook – stav uložíme hned z dokončené session.
		// Přísný formát ID: hodnota jde do cesty volání Stripe API s tajným klíčem, nesmí
		// obsahovat lomítka, otazníky apod. Session musí patřit prodejci z podepsaného odkazu.
		$session_id = isset( $_GET['session_id'] ) ? (string) wp_unslash( $_GET['session_id'] ) : '';
		if ( preg_match( '/^cs_(test|live)_[A-Za-z0-9]{10,255}$/', $session_id ) ) {
			$session = ( new Stripe_Client() )->retrieve_checkout_session( $session_id );
			if ( is_array( $session ) && empty( $session['error'] ) && (int) ( $session['metadata']['nkv_vendor_id'] ?? 0 ) === $vendor_id ) {
				$this->apply_session( $session );
			}
		}
		$this->render_page( __( 'Zaplaceno, díky!', 'nkz-woo-stripe-vendor-split' ), __( 'Platba proběhla. Potvrzení ti pošle Stripe e-mailem. Měsíční fee se z karty strhává automaticky.', 'nkz-woo-stripe-vendor-split' ) );
	}

	public function handle_portal(): void {
		[ $vendor_id ] = $this->authorize_public();
		$customer_id = (string) get_post_meta( $vendor_id, '_nkv_billing_customer_id', true );
		if ( '' === $customer_id ) {
			$this->render_page( __( 'Zatím nic', 'nkz-woo-stripe-vendor-split' ), __( 'Ještě nemáš uloženou platbu. Nejdřív prosím zaplať přes odkaz od provozovatele.', 'nkz-woo-stripe-vendor-split' ), $vendor_id );
		}
		$portal = ( new Stripe_Client() )->create_portal_session( [ 'customer' => $customer_id, 'return_url' => home_url( '/' ) ] );
		if ( empty( $portal['url'] ) ) {
			Logger::error( 'Billing portal create failed', [ 'vendor' => $vendor_id, 'err' => $portal['error']['message'] ?? '' ] );
			$this->render_page( __( 'Něco se nepovedlo', 'nkz-woo-stripe-vendor-split' ), __( 'Správu platby se nepodařilo otevřít. Ozvi se prosím provozovateli.', 'nkz-woo-stripe-vendor-split' ) );
		}
		wp_redirect( (string) $portal['url'] );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Zápis stavu (návrat z Checkoutu + webhooky). Vše idempotentní.
	 * ------------------------------------------------------------------- */

	/** checkout.session.completed */
	public function apply_session( array $session ): void {
		// Jen session založené tímto pluginem na tomto webu – na stejném Stripe účtu můžou
		// Checkout zakládat i jiné služby (client_reference_id proto nestačí).
		if ( ! self::is_own_object( $session ) ) {
			return;
		}
		$vendor_id = (int) $session['metadata']['nkv_vendor_id'];
		if ( $vendor_id <= 0 || ! Vendor_Repository::get( $vendor_id ) ) {
			return;
		}
		if ( 'complete' !== ( $session['status'] ?? '' ) || ! in_array( $session['payment_status'] ?? '', [ 'paid', 'no_payment_required' ], true ) ) {
			return;
		}
		if ( ! empty( $session['customer'] ) && is_string( $session['customer'] ) ) {
			update_post_meta( $vendor_id, '_nkv_billing_customer_id', $session['customer'] );
		}
		if ( 'yes' === ( $session['metadata']['nkv_onboarding'] ?? '' ) ) {
			update_post_meta( $vendor_id, '_nkv_billing_onboarding_paid', 'yes' );
		}
		if ( ! empty( $session['subscription'] ) && is_string( $session['subscription'] ) ) {
			update_post_meta( $vendor_id, '_nkv_billing_subscription_id', $session['subscription'] );
			$this->set_status( $vendor_id, self::STATUS_ACTIVE );
		}
	}

	/** Objekt (session / předplatné) založil tento plugin na tomto webu – podle metadat. */
	private static function is_own_object( array $object ): bool {
		$meta = $object['metadata'] ?? [];
		return is_array( $meta )
			&& ! empty( $meta['nkv_vendor_id'] )
			&& untrailingslashit( (string) ( $meta['site'] ?? '' ) ) === untrailingslashit( home_url() );
	}

	/** customer.subscription.updated / customer.subscription.deleted */
	public function apply_subscription( array $subscription ): void {
		$vendor_id = $this->vendor_by_subscription( $subscription );
		if ( $vendor_id <= 0 ) {
			return;
		}
		$map = [
			'active'             => self::STATUS_ACTIVE,
			'trialing'           => self::STATUS_ACTIVE,
			'past_due'           => self::STATUS_UNPAID,
			'unpaid'             => self::STATUS_UNPAID,
			'canceled'           => self::STATUS_CANCELED,
			'incomplete_expired' => self::STATUS_CANCELED,
		];
		$status = $map[ $subscription['status'] ?? '' ] ?? '';
		if ( '' !== $status ) {
			$this->set_status( $vendor_id, $status );
		}
	}

	/** invoice.payment_failed (opakované pokusy řeší Stripe, my jen označíme a dáme vědět). */
	public function apply_failed_invoice( array $invoice ): void {
		// Starší API verze: invoice.subscription; novější (2025+): invoice.parent.subscription_details.
		$details = $invoice['parent']['subscription_details'] ?? $invoice['subscription_details'] ?? [];
		$sub_id  = $invoice['subscription'] ?? $details['subscription'] ?? '';
		if ( ! is_string( $sub_id ) || '' === $sub_id ) {
			return;
		}
		$vendor_id = $this->vendor_by_subscription( [ 'id' => $sub_id, 'metadata' => $details['metadata'] ?? [] ] );
		if ( $vendor_id > 0 ) {
			$this->set_status( $vendor_id, self::STATUS_UNPAID );
		}
	}

	private function vendor_by_subscription( array $subscription ): int {
		$vendor_id = self::is_own_object( $subscription ) ? (int) $subscription['metadata']['nkv_vendor_id'] : 0;
		if ( $vendor_id > 0 && Vendor_Repository::get( $vendor_id ) ) {
			return $vendor_id;
		}
		if ( empty( $subscription['id'] ) ) {
			return 0;
		}
		$posts = get_posts(
			[
				'post_type'      => Vendors::POST_TYPE,
				'meta_key'       => '_nkv_billing_subscription_id',
				'meta_value'     => (string) $subscription['id'],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			]
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/** Uloží stav a při přechodu do neplaceno/zrušeno jednou upozorní provozovatele. */
	private function set_status( int $vendor_id, string $status ): void {
		$previous = self::status( $vendor_id );
		if ( $previous === $status ) {
			return;
		}
		update_post_meta( $vendor_id, '_nkv_billing_status', $status );
		Logger::info( 'Billing status changed', [ 'vendor' => $vendor_id, 'from' => $previous, 'to' => $status ] );
		if ( in_array( $status, [ self::STATUS_UNPAID, self::STATUS_CANCELED ], true ) ) {
			$this->notify_admin( $vendor_id, $status );
		}
	}

	private function notify_admin( int $vendor_id, string $status ): void {
		$to = (string) Plugin::settings()['billing_notify_email'];
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		$name    = get_the_title( $vendor_id );
		$subject = self::STATUS_UNPAID === $status
			? sprintf( __( 'Prodejci %s neprošla platba měsíčního fee', 'nkz-woo-stripe-vendor-split' ), $name )
			: sprintf( __( 'Prodejci %s skončilo předplatné', 'nkz-woo-stripe-vendor-split' ), $name );
		$body = self::STATUS_UNPAID === $status
			? __( "Stripe nedokázal strhnout měsíční fee z karty. Platbu bude ještě několikrát zkoušet a prodejci napíše sám.\n\nKdyž se to nepovede ani tak, předplatné se zruší a přijde další e-mail.", 'nkz-woo-stripe-vendor-split' )
			: __( 'Předplatné prodejce bylo zrušeno (ve Stripe ručně, nebo po neúspěšných pokusech o platbu). Produkty prodejce zůstávají v prodeji, rozhodnutí je na tobě.', 'nkz-woo-stripe-vendor-split' );
		wp_mail( $to, $subject, $body . "\n\n" . get_edit_post_link( $vendor_id, 'url' ) );
	}

	/* ---------------------------------------------------------------------
	 * Administrace.
	 * ------------------------------------------------------------------- */

	public function add_meta_box(): void {
		add_meta_box( 'nkv_vendor_billing', __( 'Platby Screenuj', 'nkz-woo-stripe-vendor-split' ), [ $this, 'render_meta_box' ], Vendors::POST_TYPE, 'side', 'high' );
	}

	public function render_meta_box( \WP_Post $post ): void {
		$vendor_id = $post->ID;
		$package   = Vendors::package_of( $vendor_id );
		if ( '' === $package ) {
			echo '<p>' . esc_html__( 'Nejdřív prodejci vyber balíček a ulož.', 'nkz-woo-stripe-vendor-split' ) . '</p>';
			return;
		}
		$monthly = self::monthly_fee( $package );
		printf(
			'<p><strong>%s:</strong> %s<br><strong>%s:</strong> %s</p>',
			esc_html__( 'Onboarding', 'nkz-woo-stripe-vendor-split' ),
			self::onboarding_paid( $vendor_id ) ? esc_html__( 'zaplaceno ✓', 'nkz-woo-stripe-vendor-split' ) : esc_html( sprintf( __( 'nezaplaceno (%s Kč)', 'nkz-woo-stripe-vendor-split' ), number_format_i18n( self::onboarding_fee() ) ) ),
			esc_html__( 'Měsíční fee', 'nkz-woo-stripe-vendor-split' ),
			$monthly > 0 ? esc_html( self::status_label( self::status( $vendor_id ) ) . ' · ' . number_format_i18n( $monthly ) . ' Kč' ) : esc_html__( 'bez fee', 'nkz-woo-stripe-vendor-split' )
		);

		if ( ! self::onboarding_paid( $vendor_id ) ) {
			printf(
				'<p><label><input type="checkbox" name="nkv_billing_onboarding_paid_manual" value="yes"> %s</label></p>',
				esc_html__( 'Onboarding už zaplatil(a) jinak (např. fakturou)', 'nkz-woo-stripe-vendor-split' )
			);
		}

		if ( self::needs_payment( $vendor_id ) ) {
			echo '<p style="margin-bottom:4px;"><strong>' . esc_html__( 'Odkaz k platbě pro prodejce', 'nkz-woo-stripe-vendor-split' ) . '</strong></p>';
			printf( '<input type="text" readonly value="%s" style="width:100%%;font-size:11px;" onclick="this.select();">', esc_attr( self::start_url( $vendor_id ) ) );
			echo '<p style="font-size:12px;color:#50575e;">' . esc_html__( 'Ukáže se prodejci i po dokončení Stripe onboardingu.', 'nkz-woo-stripe-vendor-split' ) . '</p>';
		}
		$customer_id = (string) get_post_meta( $vendor_id, '_nkv_billing_customer_id', true );
		if ( '' !== $customer_id ) {
			$base = 'live' === Plugin::settings()['mode'] ? 'https://dashboard.stripe.com/' : 'https://dashboard.stripe.com/test/';
			printf( '<p><a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_url( $base . 'customers/' . rawurlencode( $customer_id ) ), esc_html__( 'Otevřít zákazníka ve Stripe ↗', 'nkz-woo-stripe-vendor-split' ) );
			echo '<p style="margin-bottom:4px;"><strong>' . esc_html__( 'Správa karty pro prodejce', 'nkz-woo-stripe-vendor-split' ) . '</strong></p>';
			printf( '<input type="text" readonly value="%s" style="width:100%%;font-size:11px;" onclick="this.select();">', esc_attr( self::public_url( 'nkv_billing_portal', $vendor_id ) ) );
		}
	}

	/** Ruční označení onboardingu jako zaplaceného (stávající prodejci). Jen nastavuje, nikdy neruší. */
	public function save_meta_box( int $post_id ): void {
		if ( ! isset( $_POST['nkv_vendor_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nkv_vendor_nonce'] ) ), 'nkv_vendor_save_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'yes' === ( $_POST['nkv_billing_onboarding_paid_manual'] ?? '' ) ) {
			update_post_meta( $post_id, '_nkv_billing_onboarding_paid', 'yes' );
		}
	}

	public static function status_label( string $status ): string {
		$labels = [
			self::STATUS_ACTIVE   => __( 'aktivní', 'nkz-woo-stripe-vendor-split' ),
			self::STATUS_UNPAID   => __( 'neplaceno', 'nkz-woo-stripe-vendor-split' ),
			self::STATUS_CANCELED => __( 'zrušeno', 'nkz-woo-stripe-vendor-split' ),
		];
		return $labels[ $status ] ?? __( 'nezaplaceno', 'nkz-woo-stripe-vendor-split' );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function admin_columns( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'nkv_package' === $key ) {
				$out['nkv_billing'] = __( 'Platby', 'nkz-woo-stripe-vendor-split' );
			}
		}
		return $out;
	}

	public function render_admin_column( string $column, int $post_id ): void {
		if ( 'nkv_billing' !== $column ) {
			return;
		}
		$package = Vendors::package_of( $post_id );
		if ( '' === $package ) {
			echo '—';
			return;
		}
		$parts = [ self::onboarding_paid( $post_id ) ? __( 'onboarding ✓', 'nkz-woo-stripe-vendor-split' ) : __( 'onboarding nezaplacen', 'nkz-woo-stripe-vendor-split' ) ];
		if ( self::monthly_fee( $package ) > 0 ) {
			$parts[] = sprintf( __( 'fee: %s', 'nkz-woo-stripe-vendor-split' ), self::status_label( self::status( $post_id ) ) );
		}
		echo esc_html( implode( ' · ', $parts ) );
	}

	/* ---------------------------------------------------------------------
	 * Pomocné.
	 * ------------------------------------------------------------------- */

	private function authorize_public(): array {
		$vendor_id = (int) ( $_GET['v'] ?? 0 );
		$token     = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		$vendor    = $vendor_id > 0 ? Vendor_Repository::get( $vendor_id ) : null;
		if ( ! $vendor || ! hash_equals( Onboarding_Controller::vendor_token( $vendor_id ), $token ) ) {
			status_header( 403 );
			$this->render_page( __( 'Něco se nepovedlo', 'nkz-woo-stripe-vendor-split' ), __( 'Neplatný nebo expirovaný odkaz.', 'nkz-woo-stripe-vendor-split' ) );
		}
		return [ $vendor_id, $vendor ];
	}

	/** Jednoduchá stránka ve stylu onboardingu. S $vendor_id a něčím k zaplacení ukáže tlačítko k platbě. */
	private function render_page( string $title, string $text, int $vendor_id = 0 ): void {
		nocache_headers();
		$cta = $vendor_id > 0 && self::needs_payment( $vendor_id ) ? self::start_url( $vendor_id ) : '';
		?><!doctype html>
		<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $title ); ?></title>
		<style>
			body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 40px 20px; }
			.card { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
			h1 { margin: 0 0 12px; font-size: 24px; color: #1d2327; }
			p { color: #50575e; line-height: 1.6; }
			.btn { display: inline-block; margin-top: 12px; padding: 12px 22px; border-radius: 6px; background: #1d2327; color: #fff; text-decoration: none; font-weight: 600; }
		</style></head><body>
		<div class="card">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p><?php echo esc_html( $text ); ?></p>
			<?php if ( '' !== $cta ) : ?>
				<a class="btn" href="<?php echo esc_url( $cta ); ?>"><?php esc_html_e( 'Zaplatit kartou', 'nkz-woo-stripe-vendor-split' ); ?></a>
			<?php endif; ?>
		</div>
		</body></html><?php
		exit;
	}
}
