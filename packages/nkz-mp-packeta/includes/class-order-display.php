<?php
/**
 * OrderDisplay – zobrazí vybranou výdejnu v admin objednávce, na thank-you,
 * v e-mailech a ve vendor objednávkách.
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class OrderDisplay {

	private static ?OrderDisplay $instance = null;

	public static function instance(): OrderDisplay {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Admin order detail.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'admin_box' ] );
		// Thank you + e-maily + my account order.
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'frontend_block' ] );
		add_action( 'woocommerce_email_after_order_table', [ $this, 'email_block' ], 10, 1 );
	}

	public function admin_box( \WC_Order $order ): void {
		$id   = (string) $order->get_meta( NKZMP_PACKETA_POINT_ID_META );
		$name = (string) $order->get_meta( NKZMP_PACKETA_POINT_NAME_META );
		if ( $id === '' ) {
			return;
		}
		echo '<p><strong>' . esc_html__( 'Zásilkovna – výdejní místo', 'nkz-mp-packeta' ) . ':</strong><br>';
		echo esc_html( $name ) . ' <code>' . esc_html( $id ) . '</code></p>';

		$this->admin_labels( $order );
	}

	/** Štítky per vendor – admin fallback (vytvořit / stáhnout PDF). */
	private function admin_labels( \WC_Order $order ): void {
		if ( ! class_exists( Settings::class ) || ! Settings::api_configured() ) {
			return;
		}
		if ( isset( $_GET['nkzmp_packeta_err'] ) ) {
			echo '<p style="color:#b32d2e;"><strong>' . esc_html__( 'Packeta:', 'nkz-mp-packeta' ) . '</strong> '
				. esc_html( sanitize_text_field( wp_unslash( $_GET['nkzmp_packeta_err'] ) ) ) . '</p>';
		}
		if ( isset( $_GET['nkzmp_packeta_msg'] ) ) {
			echo '<p style="color:#1a7f37;"><strong>' . esc_html__( 'Packeta:', 'nkz-mp-packeta' ) . '</strong> '
				. esc_html( sanitize_text_field( wp_unslash( $_GET['nkzmp_packeta_msg'] ) ) ) . '</p>';
		}

		$service    = LabelService::instance();
		$vendor_ids = $service->order_vendor_ids( $order );
		if ( empty( $vendor_ids ) ) {
			return;
		}

		// Pokud je objednávka uzavřená (completed/cancelled/refunded), tlačítka
		// pro vytvoření nového štítku skrýváme – zboží už bylo odesláno (nebo
		// objednávka stornována). Existující štítky ale ponecháváme zobrazené
		// (Stáhnout/Zrušit). Filtr: nkzmp/v1/packeta/show_create_on_closed.
		$is_closed = $order->has_status( [ 'completed', 'cancelled', 'refunded' ] );
		$hide_create = $is_closed && apply_filters( 'nkzmp/v1/packeta/hide_create_on_closed', true, $order );

		echo '<p><strong>' . esc_html__( 'Štítky Zásilkovny', 'nkz-mp-packeta' ) . ':</strong></p>';
		foreach ( $vendor_ids as $vid ) {
			$vendor_name = get_the_title( $vid ) ?: ( '#' . $vid );
			$packet      = $service->get_packet( $order, $vid );
			$url         = LabelController::label_url( $order->get_id(), $vid );
			echo '<p style="margin:6px 0;">' . esc_html( $vendor_name ) . ': ';
			if ( $packet !== null && ! empty( $packet['barcode'] ) ) {
				$cancel = LabelController::cancel_url( $order->get_id(), $vid );

				// Vytvořený štítek NEznamená podanou zásilku. Rozhoduje stav
				// od Zásilkovny (StatusSync); dokud ho nemáme, je to jen
				// vytištěná etiketa. Dřív tu svítilo „Podáno" hned po tisku
				// a s časem tisku — podle toho pak nikdo nepoznal, že balík
				// pořád leží u prodejce.
				$state = (string) ( $packet['state'] ?? '' );
				$when  = ! empty( $packet['state_at'] ) ? (int) $packet['state_at'] : 0;

				switch ( $state ) {
					case ApiClient::STATE_DELIVERED:
						$color = '#1a7f37';
						$icon  = '✓';
						$text  = __( 'Doručeno', 'nkz-mp-packeta' );
						break;
					case ApiClient::STATE_DISPATCHED:
						$color = '#1a7f37';
						$icon  = '✓';
						$text  = __( 'Podáno', 'nkz-mp-packeta' );
						break;
					case ApiClient::STATE_RETURNED:
						$color = '#b32d2e';
						$icon  = '↩';
						$text  = __( 'Vrací se prodejci', 'nkz-mp-packeta' );
						break;
					case ApiClient::STATE_CANCELLED:
						$color = '#646970';
						$icon  = '✕';
						$text  = __( 'Zrušeno', 'nkz-mp-packeta' );
						break;
					case ApiClient::STATE_PENDING:
						$color = '#b26900';
						$icon  = '⏳';
						$text  = __( 'Štítek vytvořen — Zásilkovna zásilku zatím nepřevzala', 'nkz-mp-packeta' );
						$when  = 0; // čas vytištění tu jen mate
						break;
					default:
						// Sledování stavů ještě neproběhlo (starší zásilka).
						$color = '#646970';
						$icon  = '•';
						$text  = __( 'Štítek vytvořen — stav zjišťujeme', 'nkz-mp-packeta' );
						$when  = 0;
				}

				printf(
					'<span style="color:%s;font-weight:600;">%s %s</span>',
					esc_attr( $color ),
					esc_html( $icon ),
					esc_html( $text )
				);
				if ( $when > 0 ) {
					echo ' <span style="color:#666;">(' . esc_html( wp_date( 'j. n. Y H:i', $when ) ) . ')</span>';
				}
				if ( ! empty( $packet['created'] ) ) {
					echo ' <span style="color:#888;font-size:12px;">'
						. esc_html( sprintf(
							/* translators: %s: datum a čas */
							__( 'štítek vytištěn %s', 'nkz-mp-packeta' ),
							wp_date( 'j. n. Y H:i', (int) $packet['created'] )
						) )
						. '</span>';
				}
				echo '<br><code>' . esc_html( (string) $packet['barcode'] ) . '</code> ';
				echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Stáhnout štítek', 'nkz-mp-packeta' ) . '</a> ';
				echo '<a class="button button-small button-link-delete" href="' . esc_url( $cancel ) . '" onclick="return confirm(\'' . esc_js( __( 'Opravdu zrušit tuto zásilku v Packetě?', 'nkz-mp-packeta' ) ) . '\');">' . esc_html__( 'Zrušit zásilku', 'nkz-mp-packeta' ) . '</a>';
			} elseif ( $hide_create ) {
				// Objednávka uzavřena bez vytvořeného štítku → bez akce.
				echo '<span style="color:#646970;">' . esc_html__( 'objednávka uzavřena', 'nkz-mp-packeta' ) . '</span>';
			} else {
				echo '<span style="color:#b26900;font-weight:600;">⏳ ' . esc_html__( 'Čeká na podání', 'nkz-mp-packeta' ) . '</span> ';
				echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Vytvořit štítek (PDF)', 'nkz-mp-packeta' ) . '</a>';
			}
			echo '</p>';
		}

		// Stav se jinak doptává jednou za hodinu. Když admin potřebuje vědět
		// hned (typicky „zákazník mi psal, že si balík vyzvedl"), ať nemusí
		// čekat na cron.
		printf(
			'<p><a class="button button-small" href="%s">%s</a></p>',
			esc_url( StatusSync::manual_sync_url( $order->get_id() ) ),
			esc_html__( 'Zjistit stav u Zásilkovny', 'nkz-mp-packeta' )
		);
	}

	public function frontend_block( \WC_Order $order ): void {
		$name = (string) $order->get_meta( NKZMP_PACKETA_POINT_NAME_META );
		if ( $name === '' ) {
			return;
		}
		echo '<section class="nkzmp-packeta-order">';
		echo '<h2>' . esc_html__( 'Výdejní místo Zásilkovny', 'nkz-mp-packeta' ) . '</h2>';
		echo '<p>' . esc_html( $name ) . '</p>';
		echo '</section>';
	}

	public function email_block( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$name = (string) $order->get_meta( NKZMP_PACKETA_POINT_NAME_META );
		if ( $name === '' ) {
			return;
		}
		echo '<p style="margin:16px 0;"><strong>' . esc_html__( 'Výdejní místo Zásilkovny:', 'nkz-mp-packeta' ) . '</strong><br>' . esc_html( $name ) . '</p>';
	}
}
