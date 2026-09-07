<?php
/**
 * Registration form template — AOZ brand polish.
 *
 * @var string $error_msg
 * @var string $terms_url
 * @var string $lead
 *
 * @package NKZMP\Registration
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="nkzmp-reg-form-wrap">

	<header class="nkzmp-reg-hero">
		<span class="nkzmp-reg-kicker"><?php esc_html_e( 'Pro tvůrce', 'nkz-mp-vendor-registration' ); ?></span>
		<h1 class="nkzmp-reg-title"><?php esc_html_e( 'Přihláška do Art of život', 'nkz-mp-vendor-registration' ); ?></h1>
		<?php if ( ! empty( $lead ) ) : ?>
			<p class="nkzmp-reg-lead"><?php echo esc_html( $lead ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( $error_msg !== '' ) : ?>
		<div class="nkzmp-reg-error" role="alert">
			<strong><?php esc_html_e( 'Něco chybí.', 'nkz-mp-vendor-registration' ); ?></strong>
			<span><?php echo esc_html( $error_msg ); ?></span>
		</div>
	<?php endif; ?>

	<form class="nkzmp-reg-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
		<input type="hidden" name="action" value="<?php echo esc_attr( \NKZMP\Registration\FormHandler::ACTION ); ?>" />
		<?php wp_nonce_field( \NKZMP\Registration\FormHandler::NONCE ); ?>

		<section class="nkzmp-reg-section">
			<header class="nkzmp-reg-section-head">
				<span class="nkzmp-reg-num">01</span>
				<h2><?php esc_html_e( 'O tobě', 'nkz-mp-vendor-registration' ); ?></h2>
			</header>

			<div class="nkzmp-reg-grid nkzmp-reg-grid--2">
				<div class="nkzmp-reg-field">
					<label for="nkzmp_name"><?php esc_html_e( 'Jméno nebo název studia', 'nkz-mp-vendor-registration' ); ?> <span class="req">*</span></label>
					<input id="nkzmp_name" type="text" name="name" required maxlength="120" autocomplete="name" />
				</div>
				<div class="nkzmp-reg-field">
					<label for="nkzmp_email"><?php esc_html_e( 'E-mail', 'nkz-mp-vendor-registration' ); ?> <span class="req">*</span></label>
					<input id="nkzmp_email" type="email" name="email" required autocomplete="email" />
				</div>
			</div>

			<?php
			// Země podnikání – řídí, pro kterou zemi Stripe založí výplatní účet.
			// Bereme z Stripe modulu (allowlist), fallback CZ/SK. Neměnná po
			// vytvoření Stripe účtu, proto ji chceme hned na začátku.
			$reg_countries = class_exists( \NKVSVS\Onboarding_Controller::class )
				? \NKVSVS\Onboarding_Controller::allowed_countries()
				: [ 'CZ' => 'Česko', 'SK' => 'Slovensko' ];
			?>
			<div class="nkzmp-reg-grid nkzmp-reg-grid--2">
				<div class="nkzmp-reg-field">
					<label for="nkzmp_ico"><?php esc_html_e( 'IČO', 'nkz-mp-vendor-registration' ); ?> <span class="req">*</span></label>
					<input id="nkzmp_ico" type="text" name="ico" required maxlength="10" inputmode="numeric" pattern="[0-9]{6,10}" />
					<small class="nkzmp-reg-ares-status" aria-live="polite"></small>
					<small><?php esc_html_e( 'Bez IČO ti Stripe neumí vyplácet. České IČO se samo vyplní z ARES.', 'nkz-mp-vendor-registration' ); ?></small>
				</div>
				<div class="nkzmp-reg-field">
					<label for="nkzmp_country"><?php esc_html_e( 'Země podnikání', 'nkz-mp-vendor-registration' ); ?> <span class="req">*</span></label>
					<select id="nkzmp_country" name="country" required>
						<?php foreach ( $reg_countries as $cc => $clabel ) : ?>
							<option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $cc, 'CZ' ); ?>><?php echo esc_html( $clabel ); ?></option>
						<?php endforeach; ?>
					</select>
					<small><?php esc_html_e( 'Podle země ti Stripe založí výplatní účet. Po vytvoření účtu ji nelze změnit.', 'nkz-mp-vendor-registration' ); ?></small>
				</div>
			</div>

			<div class="nkzmp-reg-field">
				<label for="nkzmp_website"><?php esc_html_e( 'Web nebo Instagram', 'nkz-mp-vendor-registration' ); ?></label>
				<input id="nkzmp_website" type="url" name="website" placeholder="https://" autocomplete="url" />
			</div>
		</section>

		<section class="nkzmp-reg-section">
			<header class="nkzmp-reg-section-head">
				<span class="nkzmp-reg-num">02</span>
				<h2><?php esc_html_e( 'Tvá tvorba', 'nkz-mp-vendor-registration' ); ?></h2>
			</header>

			<div class="nkzmp-reg-field">
				<label for="nkzmp_bio"><?php esc_html_e( 'Pár vět o tom, co děláš', 'nkz-mp-vendor-registration' ); ?> <span class="req">*</span></label>
				<textarea id="nkzmp_bio" name="bio" required rows="6" maxlength="1000" data-counter></textarea>
				<small class="nkzmp-reg-counter">
					<span><?php esc_html_e( 'Co děláš, čím to je tvé, koho to může bavit. 3–6 vět stačí.', 'nkz-mp-vendor-registration' ); ?></span>
					<span class="nkzmp-reg-charcount"><span data-count>0</span>/1000</span>
				</small>
			</div>
		</section>

		<section class="nkzmp-reg-section">
			<header class="nkzmp-reg-section-head">
				<span class="nkzmp-reg-num">03</span>
				<h2><?php esc_html_e( 'Adresa pro odeslání', 'nkz-mp-vendor-registration' ); ?></h2>
			</header>
			<p class="nkzmp-reg-lead" style="font-size:15px;"><?php esc_html_e( 'Nepovinné – odkud budeš posílat balíky (odesílatel na štítku). Můžeš doplnit i později v profilu.', 'nkz-mp-vendor-registration' ); ?></p>

			<div class="nkzmp-reg-field">
				<label for="nkzmp_sender_name"><?php esc_html_e( 'Jméno / firma odesílatele', 'nkz-mp-vendor-registration' ); ?></label>
				<input id="nkzmp_sender_name" type="text" name="sender_name" maxlength="120" autocomplete="name" />
			</div>
			<div class="nkzmp-reg-grid nkzmp-reg-grid--2">
				<div class="nkzmp-reg-field">
					<label for="nkzmp_sender_street"><?php esc_html_e( 'Ulice a číslo', 'nkz-mp-vendor-registration' ); ?></label>
					<input id="nkzmp_sender_street" type="text" name="sender_street" maxlength="160" autocomplete="address-line1" />
				</div>
				<div class="nkzmp-reg-field">
					<label for="nkzmp_sender_city"><?php esc_html_e( 'Město', 'nkz-mp-vendor-registration' ); ?></label>
					<input id="nkzmp_sender_city" type="text" name="sender_city" maxlength="120" autocomplete="address-level2" />
				</div>
			</div>
			<div class="nkzmp-reg-grid nkzmp-reg-grid--2">
				<div class="nkzmp-reg-field">
					<label for="nkzmp_sender_zip"><?php esc_html_e( 'PSČ', 'nkz-mp-vendor-registration' ); ?></label>
					<input id="nkzmp_sender_zip" type="text" name="sender_zip" maxlength="10" autocomplete="postal-code" />
				</div>
				<div class="nkzmp-reg-field">
					<label for="nkzmp_sender_phone"><?php esc_html_e( 'Telefon odesílatele', 'nkz-mp-vendor-registration' ); ?></label>
					<input id="nkzmp_sender_phone" type="text" name="sender_phone" maxlength="40" autocomplete="tel" />
				</div>
			</div>
		</section>

		<section class="nkzmp-reg-section">
			<header class="nkzmp-reg-section-head">
				<span class="nkzmp-reg-num">04</span>
				<h2><?php esc_html_e( 'Souhlasy', 'nkz-mp-vendor-registration' ); ?></h2>
			</header>

			<div class="nkzmp-reg-checkboxes">
				<label class="nkzmp-reg-check">
					<input type="checkbox" name="terms" value="1" required />
					<span>
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
					</span>
				</label>
				<?php
				$vendor_terms_url = \NKZMP\Registration\Settings::get()['vendor_terms_url'] ?? '';
				if ( $vendor_terms_url !== '' ) :
					?>
					<label class="nkzmp-reg-check">
						<input type="checkbox" name="vendor_terms" value="1" required />
						<span>
							<?php
							printf(
								/* translators: %s: odkaz na podmínky pro prodejce */
								esc_html__( 'Přečetl(a) jsem si %s a souhlasím s nimi.', 'nkz-mp-vendor-registration' ),
								'<a href="' . esc_url( $vendor_terms_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'podmínky pro prodejce', 'nkz-mp-vendor-registration' ) . '</a>'
							);
							?>
						</span>
					</label>
				<?php endif; ?>
				<label class="nkzmp-reg-check">
					<input type="checkbox" name="gdpr" value="1" required />
					<span><?php esc_html_e( 'Souhlasím se zpracováním osobních údajů za účelem vyřízení této přihlášky.', 'nkz-mp-vendor-registration' ); ?></span>
				</label>
			</div>
		</section>

		<input type="text" name="nkzmp_hp" tabindex="-1" autocomplete="off" class="nkzmp-reg-hp" />

		<div class="nkzmp-reg-submit-row">
			<button type="submit" class="nkzmp-reg-submit">
				<span><?php esc_html_e( 'Odeslat přihlášku', 'nkz-mp-vendor-registration' ); ?></span>
				<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</button>
			<p class="nkzmp-reg-foot"><?php esc_html_e( 'Není to automat. Každou přihlášku si projdeme osobně.', 'nkz-mp-vendor-registration' ); ?></p>
		</div>
	</form>

</div>

<script>
(function(){
	var form = document.querySelector('.nkzmp-reg-form');
	if (!form) return;

	var STORAGE = 'nkzmp_reg_draft_v1';
	var fields  = ['name','email','ico','website','bio'];

	// Restore (kromě úspěšného submitu, který znegoval values na ?nkzmp_reg=ok)
	var isSuccess = /[?&]nkzmp_reg=ok/.test(window.location.search);
	if (!isSuccess) {
		try {
			var saved = JSON.parse(localStorage.getItem(STORAGE) || '{}');
			fields.forEach(function(name){
				var el = form.querySelector('[name="'+name+'"]');
				if (el && saved[name] && !el.value) { el.value = saved[name]; }
			});
		} catch(e){}
	} else {
		try { localStorage.removeItem(STORAGE); } catch(e){}
	}

	// Persist na každý input
	fields.forEach(function(name){
		var el = form.querySelector('[name="'+name+'"]');
		if (!el) return;
		el.addEventListener('input', function(){
			try {
				var s = JSON.parse(localStorage.getItem(STORAGE) || '{}');
				s[name] = el.value;
				localStorage.setItem(STORAGE, JSON.stringify(s));
			} catch(e){}
		});
	});

	// Char counter pro bio
	var bio = document.getElementById('nkzmp_bio');
	var count = document.querySelector('[data-count]');
	if (bio && count) {
		var updateCount = function(){ count.textContent = bio.value.length; };
		bio.addEventListener('input', updateCount);
		updateCount();
	}

	// ARES lookup pro IČO (8 čísel → fetch → autofill name pokud prázdné)
	var ico  = document.getElementById('nkzmp_ico');
	var name = document.getElementById('nkzmp_name');
	var icoStatus = document.querySelector('.nkzmp-reg-ares-status');
	if (ico && name) {
		var lookupTimer;
		ico.addEventListener('input', function(){
			clearTimeout(lookupTimer);
			var val = ico.value.replace(/[^0-9]/g, '');
			if (val.length !== 8) {
				if (icoStatus) icoStatus.textContent = '';
				return;
			}
			if (icoStatus) icoStatus.textContent = 'Hledám v ARES…';
			lookupTimer = setTimeout(function(){
				fetch('/wp-json/nkzmp-registration/v1/ares/' + val)
					.then(function(r){ return r.json(); })
					.then(function(data){
						if (!data || !data.found) {
							if (icoStatus) icoStatus.textContent = 'V ARES jsme tě nenašli — IČO si zkontroluj.';
							return;
						}
						if (icoStatus) icoStatus.textContent = '✓ ' + (data.name || '');
						if (name && !name.value && data.name) {
							name.value = data.name;
							name.dispatchEvent(new Event('input'));
						}
					})
					.catch(function(){
						if (icoStatus) icoStatus.textContent = '';
					});
			}, 350);
		});
	}
})();
</script>
