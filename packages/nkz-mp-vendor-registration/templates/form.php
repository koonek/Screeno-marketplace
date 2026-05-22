<?php
/**
 * Registration form template (AOZ design + tone-of-voice).
 *
 * @var string $error_msg
 * @var string $terms_url
 *
 * @package NKZMP\Registration
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="nkzmp-reg-form-wrap">

	<?php if ( $error_msg !== '' ) : ?>
		<div class="nkzmp-reg-error"><?php echo esc_html( $error_msg ); ?></div>
	<?php endif; ?>

	<p class="nkzmp-reg-lead">
		<?php esc_html_e( 'Prodávat umění, vlastní tvorbu, je v pořádku. A představit ji osobně ještě víc. Vyplň přihlášku — projdeme si ji a ozveme se.', 'nkz-mp-vendor-registration' ); ?>
	</p>

	<form class="nkzmp-reg-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( \NKZMP\Registration\FormHandler::ACTION ); ?>" />
		<?php wp_nonce_field( \NKZMP\Registration\FormHandler::NONCE ); ?>

		<div class="nkzmp-reg-field">
			<label for="nkzmp_name"><?php esc_html_e( 'Jméno nebo název studia', 'nkz-mp-vendor-registration' ); ?> *</label>
			<input id="nkzmp_name" type="text" name="name" required maxlength="120" />
		</div>

		<div class="nkzmp-reg-field">
			<label for="nkzmp_email"><?php esc_html_e( 'E-mail', 'nkz-mp-vendor-registration' ); ?> *</label>
			<input id="nkzmp_email" type="email" name="email" required />
		</div>

		<div class="nkzmp-reg-field">
			<label for="nkzmp_ico"><?php esc_html_e( 'IČO', 'nkz-mp-vendor-registration' ); ?> *</label>
			<input id="nkzmp_ico" type="text" name="ico" required maxlength="20" />
			<small><?php esc_html_e( 'IČO je nutné pro registraci do platebního systému. Pokud podnikáš pod jiným identifikátorem, ozvi se nám.', 'nkz-mp-vendor-registration' ); ?></small>
		</div>

		<div class="nkzmp-reg-field">
			<label for="nkzmp_website"><?php esc_html_e( 'Web (volitelné)', 'nkz-mp-vendor-registration' ); ?></label>
			<input id="nkzmp_website" type="url" name="website" placeholder="https://" />
		</div>

		<div class="nkzmp-reg-field">
			<label for="nkzmp_bio"><?php esc_html_e( 'Pár vět o tvojí tvorbě', 'nkz-mp-vendor-registration' ); ?> *</label>
			<textarea id="nkzmp_bio" name="bio" required rows="5" maxlength="1000"></textarea>
			<small><?php esc_html_e( 'Co děláš, čím to je tvé, koho to může bavit. 3–6 vět stačí.', 'nkz-mp-vendor-registration' ); ?></small>
		</div>

		<div class="nkzmp-reg-checkboxes">
			<label>
				<input type="checkbox" name="terms" value="1" required />
				<?php if ( $terms_url ) : ?>
					<?php
					printf(
						/* translators: %s: URL podmínek */
						esc_html__( 'Přečetl(a) jsem si %s a souhlasím s nimi.', 'nkz-mp-vendor-registration' ),
						'<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'podmínky platformy', 'nkz-mp-vendor-registration' ) . '</a>'
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Souhlasím s podmínkami platformy.', 'nkz-mp-vendor-registration' ); ?>
				<?php endif; ?>
			</label>
			<label>
				<input type="checkbox" name="gdpr" value="1" required />
				<?php esc_html_e( 'Souhlasím se zpracováním osobních údajů za účelem vyřízení této přihlášky.', 'nkz-mp-vendor-registration' ); ?>
			</label>
		</div>

		<input type="text" name="nkzmp_hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" />

		<button type="submit" class="nkzmp-reg-submit">
			<?php esc_html_e( 'Odeslat přihlášku', 'nkz-mp-vendor-registration' ); ?>
		</button>

		<p class="nkzmp-reg-foot"><?php esc_html_e( 'Není to automat. Každou přihlášku si projdeme osobně.', 'nkz-mp-vendor-registration' ); ?></p>
	</form>

</div>
