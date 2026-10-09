<?php
/**
 * Vendor_Debt – dluh prodejce vůči platformě (provize z vráceného zboží).
 *
 * Podle podmínek jdou při vrácení zboží (odstoupení, reklamace) náklady
 * za prodejcem a platforma si nechává svou provizi. Zákazník ale ze zákona
 * dostane zpět 100 %. Bez evidence dluhu by platforma o provizi přišla:
 *
 *  - výplata prodejci ještě neodešla → výpočet vrácené zboží odečte,
 *    prodejce za něj nedostane nic; zákazník dostal 100 % zpět, takže
 *    platformě nezůstane nic – ani její provize,
 *  - výplata už odešla → prodejci stáhneme jen to, co dostal (bez
 *    provize); zákazníkovi vracíme 100 %, provizi by platforma platila
 *    ze svého.
 *
 * V obou případech tedy prodejce dluží provizi z vráceného zboží. Stripe
 * neumí stáhnout z převodu víc, než prodejci poslal, takže se dluh eviduje
 * a strhne z jeho nejbližší výplaty (Transfer_Service).
 *
 * Admin může dluh odpustit (např. když vrácení zavinila platforma).
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Vendor_Debt {

	public const META = '_nkv_vendor_debt';

	private static ?Vendor_Debt $instance = null;

	public static function instance(): Vendor_Debt {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Po Refund_Service (10), který řeší stažení již vyplacené části.
		add_action( 'woocommerce_order_refunded', [ $this, 'on_refund' ], 20, 2 );
		add_action( 'admin_post_nkv_debt_forgive', [ $this, 'handle_forgive' ] );
		add_action( 'add_meta_boxes', [ $this, 'meta_box' ] );
		add_filter( 'nkzmp/v1/admin/health_checks', [ $this, 'health_row' ] );
	}

	/** @return array<int,array> */
	public static function entries( int $vendor_id ): array {
		$e = get_post_meta( $vendor_id, self::META, true );
		return is_array( $e ) ? $e : [];
	}

	private static function save( int $vendor_id, array $entries ): void {
		update_post_meta( $vendor_id, self::META, array_values( $entries ) );
	}

	/** Nesplacený dluh v haléřích. */
	public static function outstanding( int $vendor_id ): int {
		$sum = 0;
		foreach ( self::entries( $vendor_id ) as $e ) {
			if ( empty( $e['forgiven'] ) ) {
				$sum += max( 0, (int) $e['amount_minor'] - (int) ( $e['settled_minor'] ?? 0 ) );
			}
		}
		return $sum;
	}

	/**
	 * Zapíše dluh. Idempotentní na dvojici objednávka + refundace.
	 */
	public static function add( int $vendor_id, int $order_id, int $refund_id, int $amount_minor, string $reason ): bool {
		if ( $vendor_id <= 0 || $amount_minor <= 0 ) {
			return false;
		}
		$entries = self::entries( $vendor_id );
		foreach ( $entries as $e ) {
			if ( (int) $e['order_id'] === $order_id && (int) $e['refund_id'] === $refund_id ) {
				return false;
			}
		}
		$entries[] = [
			'id'            => wp_generate_password( 10, false ),
			'order_id'      => $order_id,
			'refund_id'     => $refund_id,
			'amount_minor'  => $amount_minor,
			'settled_minor' => 0,
			'settlements'   => [],
			'reason'        => $reason,
			'at'            => time(),
			'forgiven'      => false,
		];
		self::save( $vendor_id, $entries );
		return true;
	}

	/**
	 * Splatí dluh z výplaty (nejstarší napřed).
	 *
	 * @return int kolik se splatilo (haléře)
	 */
	public static function settle( int $vendor_id, int $max_minor, int $from_order_id ): int {
		if ( $max_minor <= 0 ) {
			return 0;
		}
		$entries = self::entries( $vendor_id );
		// Idempotentní: jedna objednávka splácí dluh jen jednou.
		$done = 0;
		foreach ( $entries as $e ) {
			foreach ( (array) ( $e['settlements'] ?? [] ) as $st ) {
				if ( (int) $st['order_id'] === $from_order_id ) {
					$done += (int) $st['amount_minor'];
				}
			}
		}
		if ( $done > 0 ) {
			return $done;
		}
		$left    = $max_minor;
		foreach ( $entries as &$e ) {
			if ( $left <= 0 ) {
				break;
			}
			if ( ! empty( $e['forgiven'] ) ) {
				continue;
			}
			$open = (int) $e['amount_minor'] - (int) ( $e['settled_minor'] ?? 0 );
			if ( $open <= 0 ) {
				continue;
			}
			$pay                = min( $open, $left );
			$e['settled_minor'] = (int) ( $e['settled_minor'] ?? 0 ) + $pay;
			$e['settlements'][] = [ 'order_id' => $from_order_id, 'amount_minor' => $pay, 'at' => time() ];
			$left              -= $pay;
		}
		unset( $e );
		self::save( $vendor_id, $entries );
		return $max_minor - $left;
	}

	/* ===================================================== vznik dluhu */

	/**
	 * Refundace vráceného zboží → prodejce dluží provizi za ně.
	 *
	 * Provizi počítáme z PŮVODNÍHO rozdělení objednávky (bez odečtu
	 * refundací) poměrem vrácené částky k základu prodejce.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public function on_refund( $order_id, $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}
		if ( ! apply_filters( 'nkv_svs_filter_vendor_debt_on_refund', true, $order, $refund ) ) {
			return;
		}

		$settings    = Plugin::settings();
		$include_tax = 'yes' === ( $settings['split_includes_tax'] ?? 'no' );
		$currency    = $order->get_currency();

		// Vrácená částka po prodejcích (z položek refundace).
		$refunded = [];
		foreach ( $refund->get_items( 'line_item' ) as $ri ) {
			$vid = (int) get_post_meta( (int) $ri->get_product_id(), '_nkv_vendor_id', true );
			if ( $vid <= 0 ) {
				continue;
			}
			$amt              = abs( (float) $ri->get_total() ) + ( $include_tax ? abs( (float) $ri->get_total_tax() ) : 0 );
			$refunded[ $vid ] = ( $refunded[ $vid ] ?? 0 ) + nkvsvs_to_minor( $amt, $currency );
		}
		if ( ! $refunded ) {
			return; // vracela se jen doprava / poplatek
		}

		$calc = Split_Calculator::calculate( $order, false );
		foreach ( (array) ( $calc['vendors'] ?? [] ) as $vs ) {
			$vid = (int) $vs['vendor_id'];
			if ( empty( $refunded[ $vid ] ) || (int) $vs['base_minor'] <= 0 ) {
				continue;
			}
			$ratio = min( 1.0, $refunded[ $vid ] / (int) $vs['base_minor'] );
			$debt  = (int) floor( (int) $vs['platform_fee_minor'] * $ratio );

			$debt += $this->offset( $order, $vid, (int) $refunded[ $vid ] );

			// Celkem za objednávku nikdy víc než celá provize (+ stržená splátka).
			$already = 0;
			foreach ( self::entries( $vid ) as $e ) {
				if ( (int) $e['order_id'] === (int) $order_id ) {
					$already += (int) $e['amount_minor'];
				}
			}
			$cap  = (int) $vs['platform_fee_minor'] + $this->deducted( $order, $vid );
			$debt = min( $debt, max( 0, $cap - $already ) );
			if ( $debt <= 0 ) {
				continue;
			}

			if ( self::add( $vid, (int) $order_id, (int) $refund_id, $debt, 'refund' ) ) {
				$order->add_order_note( sprintf(
					/* translators: 1: prodejce, 2: částka */
					__( 'Prodejce „%1$s" dluží platformě provizi %2$s za vrácené zboží – strhne se z jeho příští výplaty.', 'nkz-woo-stripe-vendor-split' ),
					get_the_title( $vid ) ?: ( '#' . $vid ),
					wp_strip_all_tags( wc_price( $debt / nkvsvs_minor_factor( $currency ), [ 'currency' => $currency ] ) )
				) );
				do_action( 'nkv_svs_vendor_debt_added', $vid, $order, $debt );
			}
		}
	}

	/** Kolik se z výplaty za tuto objednávku strhlo na jiný dluh. */
	private function deducted( \WC_Order $order, int $vid ): int {
		foreach ( Transfer_Service::instance()->get_transfer_records( $order ) as $r ) {
			if ( (int) $r['vendor_id'] === $vid && 'completed' === ( $r['status'] ?? '' ) ) {
				return max( 0, (int) ( $r['debt_deducted_minor'] ?? 0 ) );
			}
		}
		return 0;
	}

	/**
	 * Výplata za tuto objednávku byla snížena o splátku dluhu. Stažení
	 * (Refund_Service) bere z převodu poměrem vráceno / základ výplaty jen
	 * to, co prodejce reálně dostal – stejným poměrem chybí i část splátky,
	 * kterou by jinak platforma vracela zákazníkovi ze svého. Přičte se
	 * k dluhu, takže prodejce skončí přesně na minus provizi.
	 */
	private function offset( \WC_Order $order, int $vid, int $refunded_minor ): int {
		foreach ( Transfer_Service::instance()->get_transfer_records( $order ) as $r ) {
			if ( (int) $r['vendor_id'] === $vid && 'completed' === ( $r['status'] ?? '' ) && (int) ( $r['base_minor'] ?? 0 ) > 0 ) {
				$ratio = min( 1.0, $refunded_minor / (int) $r['base_minor'] );
				return (int) floor( max( 0, (int) ( $r['debt_deducted_minor'] ?? 0 ) ) * $ratio );
			}
		}
		return 0;
	}

	/* ============================================================== admin */

	public function handle_forgive(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-woo-stripe-vendor-split' ) );
		}
		$vendor_id = absint( $_GET['vendor_id'] ?? 0 );
		$entry_id  = sanitize_text_field( wp_unslash( (string) ( $_GET['entry'] ?? '' ) ) );
		check_admin_referer( 'nkv_debt_forgive_' . $vendor_id . '_' . $entry_id );
		$entries = self::entries( $vendor_id );
		foreach ( $entries as &$e ) {
			if ( (string) $e['id'] === $entry_id ) {
				$e['forgiven']    = true;
				$e['forgiven_at'] = time();
				$e['forgiven_by'] = get_current_user_id();
			}
		}
		unset( $e );
		self::save( $vendor_id, $entries );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public function meta_box(): void {
		add_meta_box( 'nkv-vendor-debt', __( 'Dluh vůči platformě', 'nkz-woo-stripe-vendor-split' ), [ $this, 'render_meta_box' ], Vendors::POST_TYPE, 'side', 'default' );
	}

	public function render_meta_box( \WP_Post $post ): void {
		$vid     = (int) $post->ID;
		$entries = self::entries( $vid );
		$out     = self::outstanding( $vid );
		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'Nesplaceno:', 'nkz-woo-stripe-vendor-split' ),
			wp_kses_post( wc_price( $out / 100 ) )
		);
		if ( ! $entries ) {
			echo '<p class="description">' . esc_html__( 'Žádný dluh. Vzniká při vrácení zboží – provize z vráceného zboží se strhne z příští výplaty.', 'nkz-woo-stripe-vendor-split' ) . '</p>';
			return;
		}
		echo '<ul style="margin:0;">';
		foreach ( array_reverse( $entries ) as $e ) {
			$order = wc_get_order( (int) $e['order_id'] );
			$open  = (int) $e['amount_minor'] - (int) ( $e['settled_minor'] ?? 0 );
			printf(
				'<li style="margin:0 0 6px;">%s %s · %s%s%s</li>',
				$order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a>' : '#' . (int) $e['order_id'],
				esc_html( wp_date( 'j. n. Y', (int) $e['at'] ) ),
				wp_kses_post( wc_price( (int) $e['amount_minor'] / 100 ) ),
				! empty( $e['forgiven'] )
					? ' <em>' . esc_html__( '(odpuštěno)', 'nkz-woo-stripe-vendor-split' ) . '</em>'
					: ( $open <= 0 ? ' <em>' . esc_html__( '(splaceno)', 'nkz-woo-stripe-vendor-split' ) . '</em>' : '' ),
				( empty( $e['forgiven'] ) && $open > 0 )
					? ' <a href="' . esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'nkv_debt_forgive', 'vendor_id' => $vid, 'entry' => $e['id'] ], admin_url( 'admin-post.php' ) ), 'nkv_debt_forgive_' . $vid . '_' . $e['id'] ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Odpustit tento dluh?', 'nkz-woo-stripe-vendor-split' ) ) . '\');">' . esc_html__( 'odpustit', 'nkz-woo-stripe-vendor-split' ) . '</a>'
					: ''
			);
		}
		echo '</ul>';
	}

	/** @param array $rows */
	public function health_row( $rows ): array {
		$rows  = (array) $rows;
		$total = 0;
		$n     = 0;
		foreach ( get_posts( [ 'post_type' => Vendors::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => self::META ] ) as $vid ) {
			$o = self::outstanding( (int) $vid );
			if ( $o > 0 ) {
				$total += $o;
				++$n;
			}
		}
		if ( $n > 0 ) {
			$rows[] = [
				'label'  => __( 'Dluhy prodejců (provize z vráceného zboží)', 'nkz-woo-stripe-vendor-split' ),
				'state'  => 'ok',
				'detail' => sprintf(
					/* translators: 1: počet, 2: částka */
					__( '%1$d prodejců dluží celkem %2$s – strhne se z jejich příštích výplat', 'nkz-woo-stripe-vendor-split' ),
					$n,
					wp_strip_all_tags( wc_price( $total / 100 ) )
				),
			];
		}
		return $rows;
	}
}
