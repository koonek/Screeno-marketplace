<?php
/**
 * StatusPage – vendor si přes magic link ověří stav přihlášky.
 *
 * URL: /vendor-status/?vid=123&t=<hmac>
 * Token = hash_hmac( 'sha256', vendor_id, wp_salt( 'nonce' ) ).
 *
 * Shortcode [nkzmp_vendor_status] vykreslí status panel. URL stránky se
 * pak nastaví v Settings → Status page URL → emaily generují odkaz tam.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

use NKZMP\Vendor\Repository as VendorRepository;
use NKZMP\Vendor\Status;

defined( 'ABSPATH' ) || exit;

final class StatusPage {

	public const SHORTCODE = 'nkzmp_vendor_status';

	private static ?StatusPage $instance = null;

	public static function instance(): StatusPage {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
	}

	public static function token( int $vendor_id ): string {
		return hash_hmac( 'sha256', 'nkzmp_vendor_status:' . $vendor_id, wp_salt( 'nonce' ) );
	}

	public static function url_for( int $vendor_id ): string {
		$page = Settings::get()['status_page_url'];
		if ( ! $page ) {
			return '';
		}
		return add_query_arg( [ 'vid' => $vendor_id, 't' => self::token( $vendor_id ) ], $page );
	}

	public function render( $atts = [] ): string {
		$vid = isset( $_GET['vid'] ) ? (int) $_GET['vid'] : 0;
		$tok = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';

		ob_start();

		if ( $vid <= 0 || $tok === '' ) {
			echo '<div class="nkzmp-status-wrap"><p>' . esc_html__( 'Tato stránka je dostupná jen přes osobní odkaz z e-mailu.', 'nkz-mp-vendor-registration' ) . '</p></div>';
			return (string) ob_get_clean();
		}
		if ( ! hash_equals( self::token( $vid ), $tok ) ) {
			echo '<div class="nkzmp-status-wrap"><p>' . esc_html__( 'Odkaz není platný. Pošli nám e-mail a my ti pošleme nový.', 'nkz-mp-vendor-registration' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		$vendor = ( new VendorRepository() )->find( $vid );
		if ( ! $vendor ) {
			echo '<div class="nkzmp-status-wrap"><p>' . esc_html__( 'Tahle přihláška už neexistuje.', 'nkz-mp-vendor-registration' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		$status_raw = (string) $vendor['status'];
		$status     = $status_raw !== '' ? Status::tryFrom( $status_raw ) : Status::PENDING;
		$step       = self::step_data( $status, $vid );

		$stripe_link = '';
		if ( $status === Status::APPROVED_AWAITING_KYC && class_exists( \NKVSVS\Onboarding_Controller::class ) ) {
			$stripe_link = \NKVSVS\Onboarding_Controller::vendor_start_url( $vid );
		}

		?>
		<div class="nkzmp-status-wrap">
			<h2 class="nkzmp-status-name"><?php echo esc_html( (string) $vendor['name'] ); ?></h2>
			<div class="nkzmp-status-badge nkzmp-status--<?php echo esc_attr( $status->value ); ?>"><?php echo esc_html( $step['label'] ); ?></div>
			<p class="nkzmp-status-headline"><?php echo esc_html( $step['headline'] ); ?></p>
			<p class="nkzmp-status-body"><?php echo wp_kses_post( $step['body'] ); ?></p>

			<?php if ( $stripe_link ) : ?>
				<p><a class="nkzmp-status-cta" href="<?php echo esc_url( $stripe_link ); ?>"><?php esc_html_e( 'Spustit registraci u Stripe', 'nkz-mp-vendor-registration' ); ?></a></p>
			<?php elseif ( $status === Status::ACTIVE && ! empty( $vendor['id'] ) ) :
				$post = get_post( (int) $vendor['id'] );
				$slug = $post ? $post->post_name : '';
				if ( $slug ) : ?>
					<p><a class="nkzmp-status-cta" href="<?php echo esc_url( home_url( '/vendor/' . $slug ) ); ?>"><?php esc_html_e( 'Otevřít můj profil', 'nkz-mp-vendor-registration' ); ?></a></p>
				<?php endif;
			endif; ?>

			<?php $this->render_timeline( $status ); ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private function render_timeline( Status $current ): void {
		$steps = [
			[ Status::PENDING,               __( 'Přijato', 'nkz-mp-vendor-registration' ) ],
			[ Status::APPROVED_AWAITING_KYC, __( 'Schváleno, čeká na ověření totožnosti', 'nkz-mp-vendor-registration' ) ],
			[ Status::ACTIVE,                __( 'Aktivní', 'nkz-mp-vendor-registration' ) ],
		];
		$current_idx = -1;
		foreach ( $steps as $i => [ $s, ] ) {
			if ( $s === $current ) {
				$current_idx = $i;
				break;
			}
		}
		echo '<ol class="nkzmp-status-timeline">';
		foreach ( $steps as $i => [ , $label ] ) {
			$class = 'nkzmp-step';
			if ( $i < $current_idx ) {
				$class .= ' is-done';
			} elseif ( $i === $current_idx ) {
				$class .= ' is-current';
			}
			echo '<li class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</li>';
		}
		echo '</ol>';
	}

	/**
	 * @return array{label:string, headline:string, body:string}
	 */
	private static function step_data( Status $status, int $vendor_id ): array {
		switch ( $status ) {
			case Status::PENDING:
				return [
					'label'    => __( 'V pořadníku', 'nkz-mp-vendor-registration' ),
					'headline' => __( 'Tvoje přihláška je u nás. Čteme ji.', 'nkz-mp-vendor-registration' ),
					'body'     => __( 'Není to automat. Každou tvorbu si projdeme osobně — proto to může chvíli trvat. Ozveme se ti e-mailem.', 'nkz-mp-vendor-registration' ),
				];
			case Status::APPROVED_AWAITING_KYC:
				return [
					'label'    => __( 'Schváleno', 'nkz-mp-vendor-registration' ),
					'headline' => __( 'Vybrali jsme tě. Zbývá jeden krok — registrace platby.', 'nkz-mp-vendor-registration' ),
					'body'     => __( 'Klikni níže, vyplníš všechno přímo u Stripe. My k tomu nemáme přístup. Až to dokončíš, dáme ti vědět.', 'nkz-mp-vendor-registration' ),
				];
			case Status::ACTIVE:
				return [
					'label'    => __( 'Aktivní', 'nkz-mp-vendor-registration' ),
					'headline' => __( 'Hotovo. Můžeš prodávat.', 'nkz-mp-vendor-registration' ),
					'body'     => __( 'Tvůj profil je živý. V adminu si přidávej produkty, uprav popis a nahraj obrázek.', 'nkz-mp-vendor-registration' ),
				];
			case Status::SUSPENDED:
				return [
					'label'    => __( 'Pozastaveno', 'nkz-mp-vendor-registration' ),
					'headline' => __( 'Tvůj účet je dočasně pozastavený.', 'nkz-mp-vendor-registration' ),
					'body'     => __( 'Ozvi se nám e-mailem, vyřešíme to.', 'nkz-mp-vendor-registration' ),
				];
			case Status::REJECTED:
				return [
					'label'    => __( 'Tentokrát ne', 'nkz-mp-vendor-registration' ),
					'headline' => __( 'Do letošního výběru jsme tě nezařadili.', 'nkz-mp-vendor-registration' ),
					'body'     => __( 'Tvorby je víc než prostoru. Děkujeme za přihlášku a důvěru.', 'nkz-mp-vendor-registration' ),
				];
			case Status::TERMINATED:
				return [
					'label'    => __( 'Ukončeno', 'nkz-mp-vendor-registration' ),
					'headline' => __( 'Tvoje spolupráce s Art of život je ukončená.', 'nkz-mp-vendor-registration' ),
					'body'     => __( 'Pokud chceš obnovit, ozvi se nám e-mailem.', 'nkz-mp-vendor-registration' ),
				];
			default:
				return [
					'label'    => __( 'Stav neznámý', 'nkz-mp-vendor-registration' ),
					'headline' => __( '—', 'nkz-mp-vendor-registration' ),
					'body'     => __( 'Pokud něco nesedí, napiš nám.', 'nkz-mp-vendor-registration' ),
				];
		}
	}
}
