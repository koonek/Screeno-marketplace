<?php
/**
 * ProfileFormView – vendor self-service editace profilu.
 *
 * Pole: display name, bio, web, featured image, shipping paušál (pokud
 * shipping modul aktivní). Změny se ukládají rovnou (bez schvalování).
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard\Views;

defined( 'ABSPATH' ) || exit;

final class ProfileFormView {

	public static function render( array $vendor ): void {
		$vendor_id = (int) $vendor['id'];

		$flash = isset( $_GET['nkzmp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_msg'] ) ) : '';
		$error = isset( $_GET['nkzmp_err'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_err'] ) ) : '';

		$name      = (string) $vendor['name'];
		$bio       = (string) $vendor['bio'];
		$website   = (string) $vendor['website'];
		$thumb_id  = get_post_thumbnail_id( $vendor_id );
		$cover_id  = (int) get_post_meta( $vendor_id, '_nkzmp_vendor_cover_id', true );

		$shipping_active = defined( 'NKZMP_SHIPPING_VENDOR_RATE_META' );
		$shipping_rate   = $shipping_active ? get_post_meta( $vendor_id, NKZMP_SHIPPING_VENDOR_RATE_META, true ) : '';

		$packeta_active = class_exists( \NKZMP\Packeta\Settings::class );
		$snd_name   = (string) get_post_meta( $vendor_id, '_nkzmp_sender_name', true );
		$snd_street = (string) get_post_meta( $vendor_id, '_nkzmp_sender_street', true );
		$snd_city   = (string) get_post_meta( $vendor_id, '_nkzmp_sender_city', true );
		$snd_zip    = (string) get_post_meta( $vendor_id, '_nkzmp_sender_zip', true );
		$snd_phone  = (string) get_post_meta( $vendor_id, '_nkzmp_sender_phone', true );
		$snd_label  = (string) get_post_meta( $vendor_id, '_nkzmp_packeta_sender_label', true );

		?>
		<div class="nkzmp-vd nkzmp-vd-profile-form">

			<header class="nkzmp-vd-section-head">
				<h1><?php esc_html_e( 'Můj profil', 'nkz-mp-vendor-dashboard' ); ?></h1>
				<p class="nkzmp-vd-meta"><?php esc_html_e( 'Jak tě uvidí zákazníci na tvojí veřejné stránce.', 'nkz-mp-vendor-dashboard' ); ?></p>
			</header>

			<?php if ( $flash === 'profile_saved' ) : ?>
				<div class="nkzmp-vd-flash nkzmp-vd-flash--success">
					<div class="icon">✓</div>
					<div><strong><?php esc_html_e( 'Profil uložen.', 'nkz-mp-vendor-dashboard' ); ?></strong></div>
				</div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="nkzmp-vd-form-error"><strong><?php esc_html_e( 'Chyba.', 'nkz-mp-vendor-dashboard' ); ?></strong> <?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<form class="nkzmp-vd-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="nkzmp_vd_profile_submit" />
				<?php wp_nonce_field( 'nkzmp_vd_profile_submit' ); ?>

				<section class="nkzmp-vd-form-section">
					<header class="nkzmp-vd-form-shead"><span class="num">01</span><h2><?php esc_html_e( 'Veřejné info', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

					<div class="nkzmp-vd-field">
						<label for="vp_name"><?php esc_html_e( 'Název / jméno', 'nkz-mp-vendor-dashboard' ); ?> <span class="req">*</span></label>
						<input id="vp_name" type="text" name="name" required maxlength="120" value="<?php echo esc_attr( $name ); ?>" />
					</div>

					<div class="nkzmp-vd-field">
						<label for="vp_web"><?php esc_html_e( 'Web / Instagram', 'nkz-mp-vendor-dashboard' ); ?></label>
						<input id="vp_web" type="url" name="website" placeholder="https://" value="<?php echo esc_attr( $website ); ?>" />
					</div>

					<div class="nkzmp-vd-field">
						<label for="vp_bio"><?php esc_html_e( 'O tobě / o tvojí tvorbě', 'nkz-mp-vendor-dashboard' ); ?></label>
						<textarea id="vp_bio" name="bio" rows="6" maxlength="2000"><?php echo esc_textarea( $bio ); ?></textarea>
					</div>
				</section>

				<section class="nkzmp-vd-form-section">
					<header class="nkzmp-vd-form-shead"><span class="num">02</span><h2><?php esc_html_e( 'Vizuál profilu', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

					<div class="nkzmp-vd-image-featured-wrap">
						<label class="nkzmp-vd-img-label"><?php esc_html_e( 'Cover (hlavní banner)', 'nkz-mp-vendor-dashboard' ); ?></label>
						<?php if ( $cover_id ) : ?>
							<div class="nkzmp-vd-img-thumb" style="width:100%;max-width:480px;">
								<?php echo wp_get_attachment_image( $cover_id, 'large', false, [ 'style' => 'display:block;width:100%;aspect-ratio:3/1;object-fit:cover;border-radius:8px;' ] ); ?>
							</div>
						<?php endif; ?>
						<input type="file" name="cover_image" accept="image/*" />
						<small><?php esc_html_e( 'Široký banner v hlavičce tvojí stránky. Doporučené 2400 × 800 px (poměr 3:1), JPG/PNG/WebP, do 4 MB.', 'nkz-mp-vendor-dashboard' ); ?></small>
					</div>

					<div class="nkzmp-vd-image-featured-wrap" style="margin-top:24px;">
						<label class="nkzmp-vd-img-label"><?php esc_html_e( 'Avatar / logo', 'nkz-mp-vendor-dashboard' ); ?></label>
						<?php if ( $thumb_id ) : ?>
							<div class="nkzmp-vd-img-thumb"><?php echo wp_get_attachment_image( $thumb_id, [ 160, 160 ], false, [ 'style' => 'object-fit:cover;width:160px;height:160px;border-radius:50%;' ] ); ?></div>
						<?php endif; ?>
						<input type="file" name="profile_image" accept="image/*" />
						<small><?php esc_html_e( 'Logo nebo portrét. Čtvercová fotka 400 × 400 px je ideální, ale zobrazí se kruhem.', 'nkz-mp-vendor-dashboard' ); ?></small>
					</div>

					<script>
					/* Živý náhled cover/avatar – jinak prodejce před uložením
					   vidí jen název souboru. Cover 3:1, avatar kruh. */
					(function(){
						var scope = document.currentScript && document.currentScript.closest('section');
						if (!scope) return;

						scope.querySelectorAll('input[type="file"]').forEach(function(input){
							var isCover = input.name === 'cover_image';
							var style = isCover
								? 'display:block;width:100%;max-width:480px;aspect-ratio:3/1;object-fit:cover;border-radius:8px;'
								: 'display:block;width:160px;height:160px;object-fit:cover;border-radius:50%;';

							var preview = document.createElement('div');
							preview.style.cssText = 'display:none;margin:8px 0;';
							preview.innerHTML =
								'<img alt="" style="' + style + '">' +
								'<small style="display:block;margin-top:4px;color:#1b5e20;">✓ ' +
								<?php echo wp_json_encode( __( 'Vybráno – uloží se po odeslání', 'nkz-mp-vendor-dashboard' ) ); ?> +
								'</small>';
							input.insertAdjacentElement('afterend', preview);

							var img = preview.querySelector('img');
							var url = null;

							input.addEventListener('change', function(){
								if (url) { URL.revokeObjectURL(url); url = null; }
								var file = input.files && input.files[0];
								if (!file || !/^image\//.test(file.type)) {
									preview.style.display = 'none';
									return;
								}
								url = URL.createObjectURL(file);
								img.src = url;
								preview.style.display = '';
								var old = input.parentNode.querySelector('.nkzmp-vd-img-thumb');
								if (old) { old.style.opacity = '.35'; }
							});
						});
					})();
					</script>
				</section>

				<?php if ( $shipping_active ) : ?>
					<section class="nkzmp-vd-form-section">
						<header class="nkzmp-vd-form-shead"><span class="num">03</span><h2><?php esc_html_e( 'Doprava', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

						<?php $ship_min = (float) \NKZMP\Shipping\Rate::min_flat(); ?>
						<div class="nkzmp-vd-field" style="max-width:280px;">
							<label for="vp_ship"><?php esc_html_e( 'Paušál za dopravu (Kč)', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vp_ship" type="number" min="<?php echo esc_attr( (string) (int) $ship_min ); ?>" step="1" name="shipping_flat" value="<?php echo esc_attr( (string) $shipping_rate ); ?>" placeholder="<?php echo esc_attr( sprintf( __( 'výchozí %s', 'nkz-mp-vendor-dashboard' ), \NKZMP\Shipping\Rate::default_flat() ) ); ?>" />
							<small>
								<?php esc_html_e( 'Účtuje se jednou za objednávku, pokud má zákazník v košíku tvůj fyzický produkt. Prázdné = použít výchozí sazbu platformy.', 'nkz-mp-vendor-dashboard' ); ?>
								<?php if ( $ship_min > 0 ) : ?>
									<?php echo esc_html( sprintf( __( ' Minimum je %d Kč.', 'nkz-mp-vendor-dashboard' ), (int) $ship_min ) ); ?>
								<?php endif; ?>
							</small>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $packeta_active ) : ?>
					<section class="nkzmp-vd-form-section">
						<header class="nkzmp-vd-form-shead"><span class="num">04</span><h2><?php esc_html_e( 'Adresa pro odeslání', 'nkz-mp-vendor-dashboard' ); ?></h2></header>
						<p class="nkzmp-vd-meta"><?php esc_html_e( 'Odkud posíláš balíky. Použije se jako odesílatel na štítku Zásilkovny. Když nevyplníš, vezme se adresa sídla.', 'nkz-mp-vendor-dashboard' ); ?></p>

						<div class="nkzmp-vd-field">
							<label for="vp_snd_name"><?php esc_html_e( 'Jméno / firma odesílatele', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vp_snd_name" type="text" name="sender_name" maxlength="120" value="<?php echo esc_attr( $snd_name ); ?>" />
						</div>
						<div class="nkzmp-vd-field">
							<label for="vp_snd_street"><?php esc_html_e( 'Ulice a číslo', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vp_snd_street" type="text" name="sender_street" maxlength="160" value="<?php echo esc_attr( $snd_street ); ?>" />
						</div>
						<div class="nkzmp-vd-field">
							<label for="vp_snd_city"><?php esc_html_e( 'Město', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vp_snd_city" type="text" name="sender_city" maxlength="120" value="<?php echo esc_attr( $snd_city ); ?>" />
						</div>
						<div class="nkzmp-vd-field">
							<label for="vp_snd_zip"><?php esc_html_e( 'PSČ', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vp_snd_zip" type="text" name="sender_zip" maxlength="10" value="<?php echo esc_attr( $snd_zip ); ?>" />
						</div>
						<div class="nkzmp-vd-field">
							<label for="vp_snd_phone"><?php esc_html_e( 'Telefon odesílatele', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vp_snd_phone" type="text" name="sender_phone" maxlength="40" value="<?php echo esc_attr( $snd_phone ); ?>" />
						</div>
						<div class="nkzmp-vd-field">
							<label for="vp_snd_label"><?php esc_html_e( 'Packeta odesílatel (eshop label)', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vp_snd_label" type="text" name="sender_label" maxlength="120" value="<?php echo esc_attr( $snd_label ); ?>" />
							<small><?php esc_html_e( 'Vyplň jen pokud ti správce nastavil v Packetě vlastní odesílací účet. Jinak nech prázdné – použije se výchozí odesílatel platformy.', 'nkz-mp-vendor-dashboard' ); ?></small>
						</div>
					</section>
				<?php endif; ?>

				<div class="nkzmp-vd-form-foot">
					<button type="submit" class="nkzmp-vd-submit" style="background:#0060FF !important;background-color:#0060FF !important;color:#fff !important;border:0 !important;border-radius:0 !important;padding:16px 32px !important;font-weight:500 !important;font-size:15px !important;display:inline-flex !important;align-items:center !important;gap:12px !important;cursor:pointer !important;">
						<span style="color:#fff !important;"><?php esc_html_e( 'Uložit profil', 'nkz-mp-vendor-dashboard' ); ?></span>
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true" style="flex-shrink:0;color:#fff;"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<?php
					$post = get_post( $vendor_id );
					$slug = $post ? $post->post_name : '';
					if ( $slug ) : ?>
						<a class="nkzmp-vd-cancel" href="<?php echo esc_url( home_url( '/vendor/' . $slug ) ); ?>" target="_blank"><?php esc_html_e( 'Zobrazit veřejný profil', 'nkz-mp-vendor-dashboard' ); ?> →</a>
					<?php endif; ?>
				</div>
			</form>

		</div>
		<?php
	}
}
