<?php
/**
 * Admin Vendor detail – jedna obrazovka per prodejce.
 *
 * Read-only konsolidace: identita + status, Stripe Connect, adresa pro
 * odeslání, finance (ledger balance + poslední pohyby), produkty. Moduly
 * (billing, …) můžou přidat vlastní panel přes
 * `do_action( 'nkzmp/v1/admin/vendor_detail/panels', $vendor_id, $vendor )`.
 *
 * Skrytá submenu stránka (slug nkz-marketplace-vendor), přístup přes
 * řádkovou akci „NKZ detail" ve výpisu vendorů nebo přímo ?vendor_id=.
 *
 * @package NKZMP
 */

namespace NKZMP\Admin;

use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Ledger\Repository as LedgerRepository;
use NKZMP\Support\Capabilities;
use NKZMP\Support\Money;
use NKZMP\Vendor\Repository as VendorRepository;

defined( 'ABSPATH' ) || exit;

final class VendorDetailPage {

	public const SLUG = 'nkz-marketplace-vendor';

	private static ?VendorDetailPage $instance = null;

	public static function instance(): VendorDetailPage {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register' ], 6 );
		add_filter( 'post_row_actions', [ $this, 'row_action' ], 10, 2 );
	}

	public function register(): void {
		add_submenu_page(
			NKZMP_ADMIN_MENU_SLUG,
			__( 'Vendor detail', 'nkz-marketplace' ),
			__( 'Vendor detail', 'nkz-marketplace' ),
			Capabilities::MANAGE_VENDORS,
			self::SLUG,
			[ self::class, 'render_static' ]
		);
		// Skrýt z menu – je to kontextová stránka.
		remove_submenu_page( NKZMP_ADMIN_MENU_SLUG, self::SLUG );
	}

	/** Odkaz na detail pro daného prodejce. */
	public static function url( int $vendor_id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&vendor_id=' . $vendor_id );
	}

	/** Řádková akce „NKZ detail" ve výpisu vendorů. */
	public function row_action( array $actions, \WP_Post $post ): array {
		if ( in_array( $post->post_type, [ 'nkv_vendor', 'nkzmp_vendor' ], true ) ) {
			$actions['nkzmp_detail'] = '<a href="' . esc_url( self::url( $post->ID ) ) . '">' . esc_html__( 'NKZ detail', 'nkz-marketplace' ) . '</a>';
		}
		return $actions;
	}

	public static function render_static(): void {
		self::instance()->render();
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}
		$vendor_id = isset( $_GET['vendor_id'] ) ? (int) $_GET['vendor_id'] : 0;
		$vendor    = $vendor_id > 0 ? ( new VendorRepository() )->find( $vendor_id ) : null;

		echo '<div class="wrap nkzmp-vdetail">';
		if ( ! $vendor ) {
			echo '<h1>' . esc_html__( 'Vendor detail', 'nkz-marketplace' ) . '</h1>';
			echo '<p>' . esc_html__( 'Prodejce nenalezen.', 'nkz-marketplace' ) . '</p></div>';
			return;
		}

		$currency = (string) ( $vendor['currency'] ?: get_option( 'woocommerce_currency', 'CZK' ) );

		$this->styles();
		$this->header( $vendor );

		echo '<div class="nkzmp-vd-grid">';
		$this->panel_identity( $vendor );
		$this->panel_stripe( $vendor_id );
		$this->panel_sender( $vendor_id );
		$this->panel_finance( $vendor_id, $currency );
		echo '</div>';

		$this->panel_products( $vendor_id );

		/** Moduly můžou přidat vlastní panel (billing apod.). */
		do_action( 'nkzmp/v1/admin/vendor_detail/panels', $vendor_id, $vendor );

		echo '</div>';
	}

	private function header( array $vendor ): void {
		$status = (string) $vendor['status'];
		echo '<header class="nkzmp-vd-head">';
		echo '<div><span class="nkzmp-vd-kicker">' . esc_html__( 'Prodejce', 'nkz-marketplace' ) . '</span>';
		echo '<h1>' . esc_html( (string) $vendor['name'] ) . '</h1></div>';
		echo '<div class="nkzmp-vd-headmeta">';
		echo '<span class="nkzmp-vd-badge nkzmp-vd-badge--' . esc_attr( $status ) . '">' . esc_html( $this->status_label( $status ) ) . '</span>';
		$edit = get_edit_post_link( (int) $vendor['id'], '' );
		if ( $edit ) {
			echo ' <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Upravit vendora', 'nkz-marketplace' ) . ' →</a>';
		}
		echo '</div></header>';
	}

	private function panel_identity( array $vendor ): void {
		$rows = [
			__( 'E-mail', 'nkz-marketplace' )   => $vendor['email'] ? '<a href="mailto:' . esc_attr( $vendor['email'] ) . '">' . esc_html( $vendor['email'] ) . '</a>' : '—',
			__( 'IČO', 'nkz-marketplace' )       => $vendor['ico'] ? esc_html( $vendor['ico'] ) : '—',
			__( 'Web', 'nkz-marketplace' )       => $vendor['website'] ? '<a href="' . esc_url( $vendor['website'] ) . '" target="_blank" rel="noopener">' . esc_html( $vendor['website'] ) . '</a>' : '—',
			__( 'Provize', 'nkz-marketplace' )   => $this->fee_label( $vendor ),
		];
		$wp_user = (int) ( $vendor['wp_user_id'] ?? 0 );
		if ( $wp_user > 0 ) {
			$rows[ __( 'WP účet', 'nkz-marketplace' ) ] = '<a href="' . esc_url( get_edit_user_link( $wp_user ) ) . '">#' . $wp_user . '</a>';
		}
		$this->panel( __( 'Identita', 'nkz-marketplace' ), $rows );
	}

	private function panel_stripe( int $vendor_id ): void {
		$account = (string) get_post_meta( $vendor_id, '_nkv_stripe_account_id', true );
		if ( $account === '' ) {
			$this->panel( __( 'Stripe Connect', 'nkz-marketplace' ), [
				__( 'Stav', 'nkz-marketplace' ) => '<span class="nkzmp-vd-warn">' . esc_html__( 'Nepropojeno', 'nkz-marketplace' ) . '</span>',
			] );
			return;
		}
		$charges = get_post_meta( $vendor_id, '_nkv_stripe_charges_enabled', true );
		$payouts = get_post_meta( $vendor_id, '_nkv_stripe_payouts_enabled', true );
		$this->panel( __( 'Stripe Connect', 'nkz-marketplace' ), [
			__( 'Účet', 'nkz-marketplace' )     => '<code>' . esc_html( $account ) . '</code>',
			__( 'Stav', 'nkz-marketplace' )     => esc_html( (string) get_post_meta( $vendor_id, '_nkv_stripe_account_status', true ) ?: '—' ),
			__( 'Platby', 'nkz-marketplace' )   => $charges ? '✓' : '—',
			__( 'Výplaty', 'nkz-marketplace' )  => $payouts ? '✓' : '—',
		] );
	}

	private function panel_sender( int $vendor_id ): void {
		$name   = (string) get_post_meta( $vendor_id, '_nkzmp_sender_name', true );
		$street = (string) get_post_meta( $vendor_id, '_nkzmp_sender_street', true );
		$city   = (string) get_post_meta( $vendor_id, '_nkzmp_sender_city', true );
		$zip    = (string) get_post_meta( $vendor_id, '_nkzmp_sender_zip', true );
		$phone  = (string) get_post_meta( $vendor_id, '_nkzmp_sender_phone', true );
		$label  = (string) get_post_meta( $vendor_id, '_nkzmp_packeta_sender_label', true );
		if ( $name === '' && $street === '' && $city === '' ) {
			$this->panel( __( 'Adresa pro odeslání', 'nkz-marketplace' ), [
				__( 'Stav', 'nkz-marketplace' ) => '<span class="nkzmp-vd-muted">' . esc_html__( 'Nevyplněno', 'nkz-marketplace' ) . '</span>',
			] );
			return;
		}
		$this->panel( __( 'Adresa pro odeslání', 'nkz-marketplace' ), [
			__( 'Odesílatel', 'nkz-marketplace' )      => esc_html( $name ?: '—' ),
			__( 'Adresa', 'nkz-marketplace' )          => esc_html( trim( $street . ', ' . $zip . ' ' . $city, ', ' ) ?: '—' ),
			__( 'Telefon', 'nkz-marketplace' )         => esc_html( $phone ?: '—' ),
			__( 'Packeta odesílatel', 'nkz-marketplace' ) => $label ? '<code>' . esc_html( $label ) . '</code>' : '<span class="nkzmp-vd-muted">' . esc_html__( 'výchozí platformy', 'nkz-marketplace' ) . '</span>',
		] );
	}

	private function panel_finance( int $vendor_id, string $currency ): void {
		$balance = ( new LedgerRepository() )->vendor_balance( $vendor_id, $currency );
		echo '<section class="nkzmp-vd-panel nkzmp-vd-panel--wide">';
		echo '<h2>' . esc_html__( 'Finance', 'nkz-marketplace' ) . '</h2>';
		echo '<div class="nkzmp-vd-balance">' . esc_html__( 'Zůstatek v ledgeru', 'nkz-marketplace' )
			. '<strong>' . esc_html( Money::from_minor_display( (int) $balance['balance_minor'], $currency ) ) . '</strong></div>';

		$entries = $this->recent_ledger( $vendor_id, 15 );
		if ( empty( $entries ) ) {
			echo '<p class="nkzmp-vd-muted">' . esc_html__( 'Zatím žádné pohyby.', 'nkz-marketplace' ) . '</p>';
		} else {
			echo '<table class="nkzmp-vd-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Datum', 'nkz-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Typ', 'nkz-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Objednávka', 'nkz-marketplace' ) . '</th>';
			echo '<th style="text-align:right;">' . esc_html__( 'Částka', 'nkz-marketplace' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $entries as $e ) {
				$amount = (int) $e['amount_minor'];
				echo '<tr>';
				echo '<td>' . esc_html( gmdate( 'j. n. Y', (int) $e['occurred_at'] ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) $e['type'] ) . '</code></td>';
				echo '<td>' . ( $e['order_id'] ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $e['order_id'] . '&action=edit' ) ) . '">#' . (int) $e['order_id'] . '</a>' : '—' ) . '</td>';
				echo '<td style="text-align:right;' . ( $amount < 0 ? 'color:#b00020;' : '' ) . '">' . esc_html( Money::from_minor_display( $amount, (string) $e['currency'] ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';
	}

	private function panel_products( int $vendor_id ): void {
		$q = new \WP_Query( [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'pending', 'draft' ],
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => '_nkzmp_vendor_id', 'value' => $vendor_id ],
				[ 'key' => '_nkv_vendor_id', 'value' => $vendor_id ],
			],
		] );

		echo '<section class="nkzmp-vd-panel nkzmp-vd-panel--full">';
		echo '<h2>' . esc_html__( 'Produkty', 'nkz-marketplace' ) . ' <span class="nkzmp-vd-count">' . (int) $q->found_posts . '</span></h2>';
		if ( empty( $q->posts ) ) {
			echo '<p class="nkzmp-vd-muted">' . esc_html__( 'Žádné produkty.', 'nkz-marketplace' ) . '</p>';
		} else {
			echo '<ul class="nkzmp-vd-products">';
			foreach ( $q->posts as $post ) {
				$edit = get_edit_post_link( $post->ID, '' );
				echo '<li><a href="' . esc_url( (string) $edit ) . '">' . esc_html( get_the_title( $post ) ) . '</a>'
					. ' <span class="nkzmp-vd-pstatus">' . esc_html( $post->post_status ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</section>';
		wp_reset_postdata();
	}

	/** @return array<int,array{type:string,amount_minor:int,currency:string,order_id:int,occurred_at:int}> */
	private function recent_ledger( int $vendor_id, int $limit ): array {
		global $wpdb;
		$table = LedgerSchema::table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT type, amount_minor, currency, order_id, occurred_at FROM {$table} WHERE vendor_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$vendor_id,
			$limit
		), ARRAY_A );
		return $rows ?: [];
	}

	private function panel( string $title, array $rows ): void {
		echo '<section class="nkzmp-vd-panel"><h2>' . esc_html( $title ) . '</h2><dl>';
		foreach ( $rows as $k => $v ) {
			echo '<dt>' . esc_html( (string) $k ) . '</dt><dd>' . wp_kses_post( (string) $v ) . '</dd>';
		}
		echo '</dl></section>';
	}

	private function status_label( string $status ): string {
		$map = [
			'pending'               => __( 'Čeká na schválení', 'nkz-marketplace' ),
			'approved_awaiting_kyc' => __( 'Schválen, čeká na KYC', 'nkz-marketplace' ),
			'active'                => __( 'Aktivní', 'nkz-marketplace' ),
			'suspended'             => __( 'Pozastaven', 'nkz-marketplace' ),
			'rejected'              => __( 'Zamítnut', 'nkz-marketplace' ),
			'terminated'            => __( 'Ukončen', 'nkz-marketplace' ),
		];
		return $map[ $status ] ?? ( $status ?: '—' );
	}

	private function fee_label( array $vendor ): string {
		$pct   = $vendor['default_fee_percent'] ?? null;
		$fixed = $vendor['default_fee_fixed'] ?? null;
		$parts = [];
		if ( $pct !== null && $pct !== '' ) {
			$parts[] = rtrim( rtrim( (string) $pct, '0' ), '.' ) . ' %';
		}
		if ( $fixed !== null && $fixed !== '' && (float) $fixed > 0 ) {
			$parts[] = (string) $fixed;
		}
		return $parts ? esc_html( implode( ' + ', $parts ) ) : '<span class="nkzmp-vd-muted">' . esc_html__( 'výchozí platformy', 'nkz-marketplace' ) . '</span>';
	}

	private function styles(): void {
		?>
		<style>
			.nkzmp-vdetail { max-width:1100px; color:#0a0a0a; }
			.nkzmp-vd-head { display:flex; justify-content:space-between; align-items:flex-end; padding:8px 0 24px; margin-bottom:24px; border-bottom:1px solid #0a0a0a; }
			.nkzmp-vd-kicker { display:block; font-size:11px; font-weight:600; letter-spacing:.14em; text-transform:uppercase; color:#0060FF; margin-bottom:6px; }
			.nkzmp-vd-head h1 { margin:0; font-size:32px; font-weight:400; letter-spacing:-.02em; }
			.nkzmp-vd-headmeta a { color:#0060FF; text-decoration:none; margin-left:12px; }
			.nkzmp-vd-badge { display:inline-block; padding:4px 12px; font-size:12px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; background:#0a0a0a; color:#fff; }
			.nkzmp-vd-badge--active { background:#0060FF; }
			.nkzmp-vd-badge--pending, .nkzmp-vd-badge--approved_awaiting_kyc { background:#dba617; }
			.nkzmp-vd-badge--suspended, .nkzmp-vd-badge--rejected, .nkzmp-vd-badge--terminated { background:#b00020; }
			.nkzmp-vd-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:1px; background:#0a0a0a; border:1px solid #0a0a0a; margin-bottom:24px; }
			.nkzmp-vd-panel { background:#fff; padding:20px 24px; }
			.nkzmp-vd-panel--wide { grid-column:1/-1; }
			.nkzmp-vd-panel--full { background:#fff; border:1px solid #0a0a0a; padding:20px 24px; margin-bottom:24px; }
			.nkzmp-vd-panel h2 { margin:0 0 16px; font-size:14px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:rgba(10,10,10,.65); }
			.nkzmp-vd-panel dl { margin:0; display:grid; grid-template-columns:auto 1fr; gap:8px 16px; }
			.nkzmp-vd-panel dt { color:rgba(10,10,10,.55); font-size:13px; }
			.nkzmp-vd-panel dd { margin:0; font-size:14px; text-align:right; }
			.nkzmp-vd-panel code { font-size:12px; }
			.nkzmp-vd-muted { color:rgba(10,10,10,.45); font-style:italic; }
			.nkzmp-vd-warn { color:#b00020; }
			.nkzmp-vd-balance { display:flex; justify-content:space-between; align-items:baseline; padding:12px 0; border-bottom:1px solid rgba(10,10,10,.1); margin-bottom:16px; font-size:14px; color:rgba(10,10,10,.6); }
			.nkzmp-vd-balance strong { font-size:24px; font-weight:400; color:#0a0a0a; }
			.nkzmp-vd-table { width:100%; border-collapse:collapse; }
			.nkzmp-vd-table th { text-align:left; font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:rgba(10,10,10,.5); padding:8px 8px; border-bottom:1px solid rgba(10,10,10,.15); }
			.nkzmp-vd-table td { padding:10px 8px; border-bottom:1px solid rgba(10,10,10,.06); font-size:13px; }
			.nkzmp-vd-table a { color:#0060FF; text-decoration:none; }
			.nkzmp-vd-count { display:inline-block; min-width:20px; padding:0 6px; background:#0060FF; color:#fff; font-size:12px; font-weight:600; }
			.nkzmp-vd-products { margin:0; list-style:none; padding:0; columns:2; }
			.nkzmp-vd-products li { padding:6px 0; break-inside:avoid; }
			.nkzmp-vd-products a { color:#0a0a0a; text-decoration:none; border-bottom:1px solid transparent; }
			.nkzmp-vd-products a:hover { border-bottom-color:#0060FF; color:#0060FF; }
			.nkzmp-vd-pstatus { font-size:11px; color:rgba(10,10,10,.45); }
		</style>
		<?php
	}
}
