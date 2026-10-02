<?php
/**
 * CheckoutWidget – Packeta widget pro výběr výdejny v checkoutu.
 *
 * - Načte Packeta widget JS (jen když je vybraná metoda nkzmp_packeta)
 * - Tlačítko „Vybrat výdejní místo" + zobrazení vybrané výdejny
 * - Uloží point ID/name do session a na objednávku
 * - Validace: při zvolené Zásilkovně musí být výdejna vybraná
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class CheckoutWidget {

	private static ?CheckoutWidget $instance = null;

	public static function instance(): CheckoutWidget {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'woocommerce_review_order_after_shipping', [ $this, 'render_picker' ] );
		add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture_from_post' ] );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_to_order' ], 10, 2 );
		// AJAX uložení vybrané výdejny do session.
		add_action( 'wp_ajax_nkzmp_packeta_set_point', [ $this, 'ajax_set_point' ] );
		add_action( 'wp_ajax_nopriv_nkzmp_packeta_set_point', [ $this, 'ajax_set_point' ] );
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! Settings::is_configured() ) {
			return;
		}
		// Oficiální Packeta widget v6.
		wp_enqueue_script( 'packeta-widget', 'https://widget.packeta.com/v6/www/js/library.js', [], null, true );
		wp_enqueue_script(
			'nkzmp-packeta',
			NKZMP_PACKETA_URL . 'assets/packeta.js',
			[ 'jquery', 'packeta-widget' ],
			NKZMP_PACKETA_VERSION,
			true
		);
		wp_localize_script( 'nkzmp-packeta', 'nkzmpPacketa', [
			'apiKey'   => Settings::api_key(),
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'nkzmp_packeta' ),
			'method'   => 'nkzmp_packeta',
			'i18n'     => [
				'pick'     => __( 'Vybrat výdejní místo', 'nkz-mp-packeta' ),
				'change'   => __( 'Změnit výdejní místo', 'nkz-mp-packeta' ),
				'selected' => __( 'Vybráno:', 'nkz-mp-packeta' ),
			],
		] );
		wp_enqueue_style( 'nkzmp-packeta', NKZMP_PACKETA_URL . 'assets/packeta.css', [], NKZMP_PACKETA_VERSION );
	}

	public function render_picker(): void {
		if ( ! Settings::is_configured() ) {
			return;
		}
		$point = WC()->session ? WC()->session->get( 'nkzmp_packeta_point' ) : null;
		$name  = is_array( $point ) ? (string) ( $point['name'] ?? '' ) : '';
		?>
		<tr class="nkzmp-packeta-row" style="display:none;">
			<th><?php esc_html_e( 'Výdejní místo', 'nkz-mp-packeta' ); ?></th>
			<td>
				<button type="button" class="button nkzmp-packeta-btn" id="nkzmp-packeta-pick"><?php esc_html_e( 'Vybrat výdejní místo', 'nkz-mp-packeta' ); ?></button>
				<div class="nkzmp-packeta-selected" id="nkzmp-packeta-selected" <?php echo $name ? '' : 'style="display:none;"'; ?>>
					<strong><?php esc_html_e( 'Vybráno:', 'nkz-mp-packeta' ); ?></strong>
					<span id="nkzmp-packeta-name"><?php echo esc_html( $name ); ?></span>
				</div>
				<input type="hidden" name="nkzmp_packeta_point_id" id="nkzmp-packeta-point-id" value="<?php echo esc_attr( is_array( $point ) ? (string) ( $point['id'] ?? '' ) : '' ); ?>" />
				<input type="hidden" name="nkzmp_packeta_point_name" id="nkzmp-packeta-point-name" value="<?php echo esc_attr( $name ); ?>" />
			</td>
		</tr>
		<?php
	}

	/** Z postu během update_order_review (uchovává hidden hodnoty). */
	public function capture_from_post( $posted ): void {
		// woocommerce_checkout_update_order_review dostává query string.
		parse_str( (string) $posted, $data );
		if ( ! empty( $data['nkzmp_packeta_point_id'] ) && WC()->session ) {
			WC()->session->set( 'nkzmp_packeta_point', [
				'id'   => sanitize_text_field( (string) $data['nkzmp_packeta_point_id'] ),
				'name' => sanitize_text_field( (string) ( $data['nkzmp_packeta_point_name'] ?? '' ) ),
			] );
		}
	}

	public function ajax_set_point(): void {
		check_ajax_referer( 'nkzmp_packeta', 'nonce' );
		$id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( $id !== '' && WC()->session ) {
			WC()->session->set( 'nkzmp_packeta_point', [ 'id' => $id, 'name' => $name ] );
		}
		wp_send_json_success();
	}

	public function validate(): void {
		$chosen = $this->chosen_methods();
		if ( ! $this->packeta_chosen( $chosen ) ) {
			return;
		}
		$id = isset( $_POST['nkzmp_packeta_point_id'] ) ? sanitize_text_field( wp_unslash( $_POST['nkzmp_packeta_point_id'] ) ) : '';
		if ( $id === '' ) {
			wc_add_notice( __( 'Vyber prosím výdejní místo Zásilkovny.', 'nkz-mp-packeta' ), 'error' );
		}
	}

	public function save_to_order( \WC_Order $order, $data ): void {
		$chosen = $this->chosen_methods();
		if ( ! $this->packeta_chosen( $chosen ) ) {
			return;
		}
		$id   = isset( $_POST['nkzmp_packeta_point_id'] ) ? sanitize_text_field( wp_unslash( $_POST['nkzmp_packeta_point_id'] ) ) : '';
		$name = isset( $_POST['nkzmp_packeta_point_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nkzmp_packeta_point_name'] ) ) : '';
		if ( $id === '' && WC()->session ) {
			$point = WC()->session->get( 'nkzmp_packeta_point' );
			$id    = is_array( $point ) ? (string) ( $point['id'] ?? '' ) : '';
			$name  = is_array( $point ) ? (string) ( $point['name'] ?? '' ) : '';
		}
		if ( $id !== '' ) {
			$order->update_meta_data( NKZMP_PACKETA_POINT_ID_META, $id );
			$order->update_meta_data( NKZMP_PACKETA_POINT_NAME_META, $name );
		}
	}

	private function chosen_methods(): array {
		$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : [];
		return is_array( $chosen ) ? $chosen : [];
	}

	private function packeta_chosen( array $chosen ): bool {
		foreach ( $chosen as $m ) {
			if ( strpos( (string) $m, 'nkzmp_packeta' ) === 0 ) {
				return true;
			}
		}
		return false;
	}
}
