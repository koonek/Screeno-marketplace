<?php
/**
 * Shortcode + block pro registration form.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const SLUG = 'nkzmp_vendor_registration';

	private static ?Shortcode $instance = null;

	public static function instance(): Shortcode {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_shortcode( self::SLUG, [ $this, 'render' ] );
	}

	/**
	 * Success block po úspěšné submission. Konkrétní CTA odkazy jsou
	 * filterovatelné přes `nkzmp/v1/registration/success_links`.
	 */
	private static function render_success(): void {
		$s         = Settings::get();
		$site_name = (string) get_bloginfo( 'name' );

		$default_links = [
			[
				'href'    => home_url( '/' ),
				'label'   => sprintf( __( 'Zpět na %s', 'nkz-mp-vendor-registration' ), $site_name ),
				'primary' => false,
			],
			[
				'href'    => home_url( '/vendors' ),
				'label'   => __( 'Prohlédnout ostatní tvůrce', 'nkz-mp-vendor-registration' ),
				'primary' => false,
			],
		];
		$links = (array) apply_filters( 'nkzmp/v1/registration/success_links', $default_links );

		?>
		<div class="nkzmp-reg-success">
			<div class="nkzmp-reg-success__icon" aria-hidden="true">
				<svg viewBox="0 0 48 48" width="48" height="48" fill="none">
					<circle cx="24" cy="24" r="22" stroke="#0060FF" stroke-width="2"/>
					<path d="M15 24l7 7 12-14" stroke="#0060FF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>
			<span class="nkzmp-reg-success__kicker"><?php esc_html_e( 'Hotovo', 'nkz-mp-vendor-registration' ); ?></span>
			<h2 class="nkzmp-reg-success__title"><?php esc_html_e( 'Přihláška dorazila.', 'nkz-mp-vendor-registration' ); ?></h2>
			<div class="nkzmp-reg-success__body">
				<?php echo wpautop( esc_html( (string) $s['form_success'] ) ); // phpcs:ignore ?>
			</div>
			<div class="nkzmp-reg-success__hint">
				<?php esc_html_e( 'Sledování stavu jsme ti poslali na e-mail — odkaz v něm tě dovede na aktuální stav přihlášky.', 'nkz-mp-vendor-registration' ); ?>
			</div>
			<?php if ( ! empty( $links ) ) : ?>
				<div class="nkzmp-reg-success__cta">
					<?php foreach ( $links as $link ) :
						$href  = (string) ( $link['href']    ?? '' );
						$label = (string) ( $link['label']   ?? '' );
						$prim  = (bool)   ( $link['primary'] ?? false );
						if ( $href === '' || $label === '' ) {
							continue;
						}
						?>
						<a class="nkzmp-reg-success__link<?php echo $prim ? ' is-primary' : ''; ?>" href="<?php echo esc_url( $href ); ?>">
							<span><?php echo esc_html( $label ); ?></span>
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render( $atts = [] ): string {
		$flash = isset( $_GET['nkzmp_reg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_reg'] ) ) : '';

		ob_start();

		if ( 'ok' === $flash ) {
			self::render_success();
			return (string) ob_get_clean();
		}

		$error_msg = isset( $_GET['nkzmp_err'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_err'] ) ) : '';
		$lead      = Settings::get()['form_lead'];
		$terms_url = Settings::get()['terms_url'];

		$file = NKZMP_REGISTRATION_DIR . 'templates/form.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
		return (string) ob_get_clean();
	}
}
