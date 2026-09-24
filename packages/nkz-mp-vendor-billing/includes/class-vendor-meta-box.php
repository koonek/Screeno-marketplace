<?php
/**
 * VendorMetaBox – per-vendor override měsíční částky předplatného.
 *
 * Meta box na editaci vendor postu (nkzmp_vendor / nkv_vendor). Pokud admin
 * vyplní částku, přebije globální Settings::get()['amount'] pro tohoto vendora
 * (čte Settings::amount_for_vendor()). Prázdné pole = globální částka.
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class VendorMetaBox {

	private const NONCE = 'nkzmp_billing_amount_override_nonce';

	private static ?VendorMetaBox $instance = null;

	public static function instance(): VendorMetaBox {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'register' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
	}

	public function register(): void {
		foreach ( [ 'nkzmp_vendor', 'nkv_vendor' ] as $pt ) {
			if ( ! post_type_exists( $pt ) ) {
				continue;
			}
			add_meta_box(
				'nkzmp-billing-amount-override',
				__( 'Předplatné – měsíční částka', 'nkz-mp-vendor-billing' ),
				[ $this, 'render' ],
				$pt,
				'side',
				'default'
			);
		}
	}

	public function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$override = get_post_meta( $post->ID, NKZMP_BILLING_AMOUNT_OVERRIDE_META, true );
		$global   = (int) Settings::get()['amount'];
		$currency = (string) Settings::get()['currency'];
		?>
		<p>
			<label for="nkzmp-bill-amount-override"><strong><?php esc_html_e( 'Vlastní částka pro tohoto prodejce', 'nkz-mp-vendor-billing' ); ?></strong></label>
		</p>
		<p>
			<input
				type="number"
				min="0"
				step="1"
				id="nkzmp-bill-amount-override"
				name="nkzmp_billing_amount_override"
				value="<?php echo esc_attr( (string) $override ); ?>"
				placeholder="<?php echo esc_attr( (string) $global ); ?>"
				style="width:100%;"
			>
			<span style="color:#666;"><?php echo esc_html( $currency ); ?> / <?php esc_html_e( 'měsíc', 'nkz-mp-vendor-billing' ); ?></span>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s = globální částka */
				esc_html__( 'Nech prázdné pro globální částku (%s). Vyplněním přebiješ cenu jen pro tohoto prodejce.', 'nkz-mp-vendor-billing' ),
				esc_html( number_format( $global, 0, ',', ' ' ) . ' ' . $currency )
			);
			?>
			<br>
			<strong><?php esc_html_e( '0 = členství zdarma', 'nkz-mp-vendor-billing' ); ?></strong>
			<?php esc_html_e( '– prodejce neplatí nic, Stripe se vůbec nevolá a předplatné se bere jako splněné. (Stripe neumí částku 0, zaokrouhlil by ji na 1 Kč – proto to řešíme takhle.)', 'nkz-mp-vendor-billing' ); ?>
		</p>
		<p class="description" style="color:#a00;">
			<?php esc_html_e( 'Pozn: změna se projeví u příští faktury. Existující Stripe předplatné je třeba upravit ručně ve Stripe nebo přes zrušení + novou aktivaci.', 'nkz-mp-vendor-billing' ); ?>
		</p>
		<?php
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public function save( $post_id, $post ): void {
		if ( ! in_array( $post->post_type, [ 'nkzmp_vendor', 'nkv_vendor' ], true ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST['nkzmp_billing_amount_override'] ) ? trim( (string) wp_unslash( $_POST['nkzmp_billing_amount_override'] ) ) : '';
		if ( $raw === '' ) {
			delete_post_meta( $post_id, NKZMP_BILLING_AMOUNT_OVERRIDE_META );
			return;
		}
		$amount = max( 0, (int) $raw );
		update_post_meta( $post_id, NKZMP_BILLING_AMOUNT_OVERRIDE_META, $amount );
	}
}
