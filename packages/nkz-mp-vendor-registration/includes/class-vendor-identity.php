<?php
/**
 * VendorIdentity – IČO / rodné číslo u stávajících prodejců.
 *
 * Krok „Mám IČO / Nemám IČO" přibyl do registrace až později. Starší
 * prodejci ho neprošli, takže u nich chybí rodné číslo a prohlášení
 * o podnikateli. Řeší to dvě místa:
 *
 *  - administrace prodejce: přepínač „Má IČO / Nemá IČO" a pole pro
 *    rodné číslo (správce opraví, když prodejce IČO ve skutečnosti nemá),
 *  - přehled prodejce bez IČO: výzva „Doplň rodné číslo a potvrď
 *    prohlášení" – jedno pole a jedno zaškrtnutí, uloží se kdy a odkud.
 *
 * Rodné číslo je citlivý údaj: jen pro správce, nikde veřejně ani v e-mailech.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class VendorIdentity {

	private const POST_TYPES = [ 'nkv_vendor', 'nkzmp_vendor' ];

	private static ?VendorIdentity $instance = null;

	public static function instance(): VendorIdentity {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'meta_box' ] );
		// Až po ostatních boxech (fakturační údaje, Stripe), které IČO
		// ukládají ze svých polí – „Nemá IČO" ho pak spolehlivě smaže.
		add_action( 'save_post', [ $this, 'save_admin' ], 99, 2 );
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		add_action( 'nkzmp/v1/dashboard/notices', [ $this, 'dashboard_notice' ] );
		add_action( 'admin_post_nkzmp_identity_confirm', [ $this, 'handle_confirm' ] );
	}

	/* --------------------------------------------------------------- stav */

	public static function ico( int $vendor_id ): string {
		return trim( (string) ( get_post_meta( $vendor_id, '_nkv_vendor_ico', true ) ?: get_post_meta( $vendor_id, '_nkzmp_vendor_ico', true ) ) );
	}

	/** Prodejce bez IČO (vyplněné IČO má přednost před uloženou volbou). */
	public static function without_ico( int $vendor_id ): bool {
		return self::ico( $vendor_id ) === '';
	}

	public static function declaration_text(): string {
		return trim( (string) ( Settings::get()['business_declaration'] ?? '' ) );
	}

	/** @return array{accepted?:bool,text?:string,at?:int,ip?:string,source?:string} */
	public static function declaration( int $vendor_id ): array {
		$c = get_post_meta( $vendor_id, '_nkzmp_consents', true );
		return is_array( $c ) && isset( $c['business_declaration'] ) ? (array) $c['business_declaration'] : [];
	}

	/** Cizinec bez českého rodného čísla (identifikuje ho datum narození)? */
	public static function foreigner( int $vendor_id ): bool {
		return get_post_meta( $vendor_id, '_nkzmp_foreigner', true ) === 'yes';
	}

	/** Má rodné číslo, nebo (cizinec) datum narození? */
	public static function identified( int $vendor_id ): bool {
		if ( (string) get_post_meta( $vendor_id, '_nkzmp_birth_number', true ) !== '' ) {
			return true;
		}
		return self::foreigner( $vendor_id ) && (string) get_post_meta( $vendor_id, '_nkzmp_birth_date', true ) !== '';
	}

	/** Co prodejci bez IČO chybí: 'rc' (rodné číslo / datum narození), 'declaration'. */
	public static function missing( int $vendor_id ): array {
		if ( $vendor_id <= 0 || ! self::without_ico( $vendor_id ) ) {
			return [];
		}
		$missing = [];
		if ( ! self::identified( $vendor_id ) ) {
			$missing[] = 'rc';
		}
		if ( self::declaration_text() !== '' && empty( self::declaration( $vendor_id )['accepted'] ) ) {
			$missing[] = 'declaration';
		}
		return $missing;
	}

	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/* ------------------------------------------------------- administrace */

	public function meta_box(): void {
		foreach ( self::POST_TYPES as $pt ) {
			add_meta_box( 'nkzmp-identity', __( 'Identifikace prodejce', 'nkz-mp-vendor-registration' ), [ $this, 'render_meta_box' ], $pt, 'side', 'high' );
		}
	}

	public function render_meta_box( \WP_Post $post ): void {
		$vid  = (int) $post->ID;
		$mode = self::without_ico( $vid ) ? 'none' : 'ico';
		$rc   = (string) get_post_meta( $vid, '_nkzmp_birth_number', true );
		$bd   = self::declaration( $vid );
		wp_nonce_field( 'nkzmp_identity_admin', 'nkzmp_identity_nonce' );
		?>
		<p style="display:flex;flex-direction:column;gap:6px;margin-top:0;">
			<label><input type="radio" name="nkzmp_id_mode" value="ico" <?php checked( $mode, 'ico' ); ?>> <?php esc_html_e( 'Má IČO', 'nkz-mp-vendor-registration' ); ?></label>
			<label><input type="radio" name="nkzmp_id_mode" value="none" <?php checked( $mode, 'none' ); ?>> <?php esc_html_e( 'Nemá IČO', 'nkz-mp-vendor-registration' ); ?></label>
		</p>
		<?php if ( $mode === 'ico' ) : ?>
			<p style="margin-top:0;"><strong><?php esc_html_e( 'IČO', 'nkz-mp-vendor-registration' ); ?>:</strong> <?php echo esc_html( self::ico( $vid ) ); ?><br><?php echo IcoCheck::label( $vid ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno v label() ?></p>
		<?php endif; ?>
		<p class="description" style="margin-top:0;"><?php esc_html_e( 'IČO se vyplňuje ve Fakturačních údajích. „Nemá IČO" uložené IČO smaže, „Má IČO" smaže rodné číslo.', 'nkz-mp-vendor-registration' ); ?></p>
		<p>
			<label for="nkzmp_birth_number"><strong><?php esc_html_e( 'Rodné číslo', 'nkz-mp-vendor-registration' ); ?></strong></label><br>
			<input type="text" id="nkzmp_birth_number" name="nkzmp_birth_number" value="" autocomplete="off" inputmode="numeric" style="width:100%;"
				placeholder="<?php echo esc_attr( $rc !== '' ? substr( $rc, 0, 2 ) . '••••/•••• ' . __( '(uloženo)', 'nkz-mp-vendor-registration' ) : '123456/7890' ); ?>">
			<span class="description"><?php esc_html_e( 'Jen u prodejce bez IČO. Prázdné = ponechat uložené.', 'nkz-mp-vendor-registration' ); ?></span>
		</p>
		<p>
			<label><input type="checkbox" name="nkzmp_foreigner" value="1" <?php checked( self::foreigner( $vid ), true ); ?>> <?php esc_html_e( 'Cizinec bez českého rodného čísla', 'nkz-mp-vendor-registration' ); ?></label><br>
			<label for="nkzmp_birth_date"><?php esc_html_e( 'Datum narození', 'nkz-mp-vendor-registration' ); ?></label><br>
			<input type="date" id="nkzmp_birth_date" name="nkzmp_birth_date" value="<?php echo esc_attr( (string) get_post_meta( $vid, '_nkzmp_birth_date', true ) ); ?>" style="width:100%;">
			<?php $fid = (string) get_post_meta( $vid, '_nkzmp_foreign_id', true ); ?>
			<?php if ( $fid !== '' ) : ?>
				<span class="description"><?php esc_html_e( 'Zahraniční identifikační číslo', 'nkz-mp-vendor-registration' ); ?>: <code><?php echo esc_html( $fid ); ?></code></span>
			<?php endif; ?>
		</p>
		<p style="margin-bottom:0;">
			<strong><?php esc_html_e( 'Prohlášení o podnikateli', 'nkz-mp-vendor-registration' ); ?>:</strong><br>
			<?php
			if ( ! empty( $bd['accepted'] ) ) {
				echo '✓ ' . esc_html( ! empty( $bd['at'] )
					/* translators: %s: datum */
					? sprintf( __( 'potvrzeno %s', 'nkz-mp-vendor-registration' ), wp_date( 'j. n. Y H:i', (int) $bd['at'] ) )
					: __( 'potvrzeno při registraci', 'nkz-mp-vendor-registration' ) );
			} elseif ( $mode === 'none' && self::declaration_text() !== '' ) {
				echo '<span style="color:#b32d2e;">' . esc_html__( 'zatím nepotvrdil – vidí výzvu v přehledu prodejce', 'nkz-mp-vendor-registration' ) . '</span>';
			} else {
				esc_html_e( 'nepožaduje se', 'nkz-mp-vendor-registration' );
			}
			?>
			<br><span class="description"><?php esc_html_e( 'Potvrdit ho může jen prodejce sám (v přehledu prodejce).', 'nkz-mp-vendor-registration' ); ?></span>
		</p>
		<?php
	}

	public function save_admin( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( empty( $_POST['nkzmp_identity_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nkzmp_identity_nonce'] ) ), 'nkzmp_identity_admin' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$vid  = (int) $post_id;
		$mode = ( $_POST['nkzmp_id_mode'] ?? '' ) === 'none' ? 'none' : 'ico';
		update_post_meta( $vid, '_nkzmp_id_mode', $mode );

		if ( $mode === 'ico' ) {
			foreach ( [ '_nkzmp_birth_number', '_nkzmp_foreigner', '_nkzmp_birth_date', '_nkzmp_foreign_id' ] as $k ) {
				delete_post_meta( $vid, $k );
			}
			$ico = self::ico( $vid );
			if ( $ico === '' ) {
				$this->flash( __( 'Prodejce je nastaven jako „Má IČO", ale IČO není vyplněné – doplň ho ve Fakturačních údajích.', 'nkz-mp-vendor-registration' ), 'warning' );
			} elseif ( ! IcoCheck::valid( $ico ) ) {
				/* translators: %s: IČO */
				$this->flash( sprintf( __( 'IČO %s není platné (nesedí kontrolní číslice) – zkontroluj překlep.', 'nkz-mp-vendor-registration' ), $ico ), 'warning' );
			}
			return;
		}

		foreach ( [ '_nkv_vendor_ico', '_nkzmp_vendor_ico' ] as $k ) {
			delete_post_meta( $vid, $k );
		}
		if ( ! empty( $_POST['nkzmp_foreigner'] ) ) {
			update_post_meta( $vid, '_nkzmp_foreigner', 'yes' );
			$bd_raw = trim( (string) wp_unslash( $_POST['nkzmp_birth_date'] ?? '' ) );
			if ( $bd_raw !== '' ) {
				$bdate = FormHandler::normalize_birth_date( $bd_raw );
				if ( $bdate === null ) {
					$this->flash( __( 'Datum narození není platné – neuložilo se.', 'nkz-mp-vendor-registration' ), 'error' );
				} else {
					update_post_meta( $vid, '_nkzmp_birth_date', $bdate );
				}
			}
		} else {
			delete_post_meta( $vid, '_nkzmp_foreigner' );
		}
		$raw = trim( (string) wp_unslash( $_POST['nkzmp_birth_number'] ?? '' ) );
		if ( $raw !== '' ) {
			$rc = FormHandler::normalize_birth_number( $raw );
			if ( $rc === null ) {
				$this->flash( __( 'Rodné číslo není platné – neuložilo se. Zkontroluj ho prosím.', 'nkz-mp-vendor-registration' ), 'error' );
			} else {
				update_post_meta( $vid, '_nkzmp_birth_number', $rc );
			}
		}
	}

	private function flash( string $msg, string $type ): void {
		set_transient( 'nkzmp_identity_notice_' . get_current_user_id(), [ 'msg' => $msg, 'type' => $type ], 60 );
	}

	public function admin_notice(): void {
		$key = 'nkzmp_identity_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( ! is_array( $n ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n['type'] === 'error' ? 'error' : 'warning' ), esc_html( (string) $n['msg'] ) );
	}

	/* ------------------------------------------------------ přehled prodejce */

	public function dashboard_notice( $vendor_id ): void {
		$vendor_id = (int) $vendor_id;
		$missing   = self::missing( $vendor_id );
		if ( ! $missing ) {
			return;
		}
		$need_rc   = in_array( 'rc', $missing, true );
		$need_decl = in_array( 'declaration', $missing, true );
		$state     = isset( $_GET['nkzmp_identity'] ) ? sanitize_key( wp_unslash( $_GET['nkzmp_identity'] ) ) : '';

		$title = $need_rc && $need_decl
			? __( 'Doplň rodné číslo a potvrď prohlášení', 'nkz-mp-vendor-registration' )
			: ( $need_rc ? __( 'Doplň rodné číslo', 'nkz-mp-vendor-registration' ) : __( 'Potvrď prohlášení o podnikateli', 'nkz-mp-vendor-registration' ) );

		echo '<div class="nkzmp-vd-flash" data-nkzmp-identity style="border-color:rgba(0,96,255,.25);"><div class="icon">!</div><div style="flex:1;">';
		echo '<strong>' . esc_html( $title ) . '</strong>';
		echo '<p>' . esc_html__( 'Prodáváš bez IČO. Abychom tě mohli jako prodejce identifikovat (zákonné hlášení prodejců platformám), potřebujeme rodné číslo – nebo datum narození, pokud české rodné číslo nemáš. Nikde se nezobrazuje, vidí ho jen správce obchodu.', 'nkz-mp-vendor-registration' ) . '</p>';
		if ( $state === 'bad_bdate' ) {
			echo '<p style="color:#b32d2e;font-weight:600;">' . esc_html__( 'Datum narození není platné. Zkontroluj ho prosím.', 'nkz-mp-vendor-registration' ) . '</p>';
		} elseif ( $state === 'bad_rc' ) {
			echo '<p style="color:#b32d2e;font-weight:600;">' . esc_html__( 'Rodné číslo není platné. Zkontroluj ho prosím (formát 123456/7890).', 'nkz-mp-vendor-registration' ) . '</p>';
		} elseif ( $state === 'no_decl' ) {
			echo '<p style="color:#b32d2e;font-weight:600;">' . esc_html__( 'Zaškrtni prosím prohlášení.', 'nkz-mp-vendor-registration' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;display:flex;flex-direction:column;gap:10px;max-width:640px;">';
		echo '<input type="hidden" name="action" value="nkzmp_identity_confirm">';
		wp_nonce_field( 'nkzmp_identity_confirm' );
		if ( $need_rc ) {
			$inp = 'padding:10px 12px;border:1px solid #d0d5dd;border-radius:10px;font-size:15px;';
			echo '<label data-id-rc style="display:flex;flex-direction:column;gap:4px;font-size:14px;max-width:260px;"><span>' . esc_html__( 'Rodné číslo', 'nkz-mp-vendor-registration' ) . '</span>';
			echo '<input type="text" name="birth_number" required autocomplete="off" inputmode="numeric" placeholder="123456/7890" style="' . esc_attr( $inp ) . '"></label>';
			echo '<label style="display:flex;gap:8px;align-items:center;font-size:14px;"><input type="checkbox" name="foreigner" value="1" data-id-foreigner> <span>' . esc_html__( 'Nemám české rodné číslo (jsem cizinec/cizinka)', 'nkz-mp-vendor-registration' ) . '</span></label>';
			echo '<label data-id-bdate hidden style="flex-direction:column;gap:4px;font-size:14px;max-width:260px;"><span>' . esc_html__( 'Datum narození', 'nkz-mp-vendor-registration' ) . '</span>';
			echo '<input type="date" name="birth_date" disabled autocomplete="bday" style="' . esc_attr( $inp ) . '"></label>';
			echo '<label data-id-bdate hidden style="flex-direction:column;gap:4px;font-size:14px;max-width:360px;"><span>' . esc_html__( 'Identifikační číslo ze tvé země (nepovinné)', 'nkz-mp-vendor-registration' ) . '</span>';
			echo '<input type="text" name="foreign_id" disabled maxlength="40" autocomplete="off" style="' . esc_attr( $inp ) . '"></label>';
		}
		if ( $need_decl ) {
			echo '<label style="display:flex;gap:8px;align-items:flex-start;font-size:14px;"><input type="checkbox" name="business_declaration" value="1" required style="margin-top:3px;"> <span>' . esc_html( self::declaration_text() ) . '</span></label>';
		}
		echo '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">';
		echo '<button type="submit" class="nkzmp-vd-cta" style="background:#0060FF!important;color:#fff!important;border:0!important;border-radius:999px!important;padding:10px 18px!important;">' . esc_html__( 'Uložit', 'nkz-mp-vendor-registration' ) . '</button>';
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			echo '<a href="' . esc_url( wc_get_account_endpoint_url( 'vendor-profile' ) ) . '" style="font-size:13px;">' . esc_html__( 'Mám IČO – doplním ho v profilu', 'nkz-mp-vendor-registration' ) . '</a>';
		}
		echo '</div></form></div></div>';
		if ( $need_rc ) {
			// Cizinec: místo rodného čísla datum narození.
			echo "<script>(function(){var b=document.querySelector('[data-nkzmp-identity]');if(!b)return;var f=b.querySelector('[data-id-foreigner]');if(!f)return;f.addEventListener('change',function(){var on=f.checked;var rc=b.querySelector('[data-id-rc]');rc.hidden=on;rc.querySelector('input').disabled=on;b.querySelectorAll('[data-id-bdate]').forEach(function(l){l.hidden=!on;l.style.display=on?'flex':'';var i=l.querySelector('input');i.disabled=!on;i.required=on&&i.name==='birth_date';});});})();</script>";
		}
	}

	public function handle_confirm(): void {
		check_admin_referer( 'nkzmp_identity_confirm' );
		$uid  = get_current_user_id();
		$vid  = ( $uid > 0 && class_exists( \NKZMP\Vendor\OwnershipGuard::class ) ) ? (int) \NKZMP\Vendor\OwnershipGuard::user_vendor_id( $uid ) : 0;
		$back = remove_query_arg( 'nkzmp_identity', wp_get_referer() ?: home_url( '/' ) );
		if ( $vid <= 0 ) {
			wp_safe_redirect( $back );
			exit;
		}
		$missing = self::missing( $vid );
		$rc      = null;
		$bdate   = null;
		$foreign = in_array( 'rc', $missing, true ) && ! empty( $_POST['foreigner'] );
		if ( $foreign ) {
			$bdate = FormHandler::normalize_birth_date( (string) wp_unslash( $_POST['birth_date'] ?? '' ) );
			if ( $bdate === null ) {
				wp_safe_redirect( add_query_arg( 'nkzmp_identity', 'bad_bdate', $back ) );
				exit;
			}
		} elseif ( in_array( 'rc', $missing, true ) ) {
			$rc = FormHandler::normalize_birth_number( (string) wp_unslash( $_POST['birth_number'] ?? '' ) );
			if ( $rc === null ) {
				wp_safe_redirect( add_query_arg( 'nkzmp_identity', 'bad_rc', $back ) );
				exit;
			}
		}
		$decl = in_array( 'declaration', $missing, true );
		if ( $decl && empty( $_POST['business_declaration'] ) ) {
			wp_safe_redirect( add_query_arg( 'nkzmp_identity', 'no_decl', $back ) );
			exit;
		}

		if ( $rc !== null ) {
			update_post_meta( $vid, '_nkzmp_birth_number', $rc );
		}
		if ( $bdate !== null ) {
			update_post_meta( $vid, '_nkzmp_foreigner', 'yes' );
			update_post_meta( $vid, '_nkzmp_birth_date', $bdate );
			$fid = substr( sanitize_text_field( wp_unslash( $_POST['foreign_id'] ?? '' ) ), 0, 40 );
			if ( $fid !== '' ) {
				update_post_meta( $vid, '_nkzmp_foreign_id', $fid );
			}
		}
		if ( $decl ) {
			// Důkaz: přesné znění, kdy, odkud a kdo. Ostatní souhlasy
			// z registrace (a jejich čas) zůstávají beze změny.
			$c = get_post_meta( $vid, '_nkzmp_consents', true );
			$c = is_array( $c ) ? $c : [];
			$c['business_declaration'] = [
				'accepted' => true,
				'text'     => self::declaration_text(),
				'at'       => time(),
				'ip'       => self::ip(),
				'user_id'  => $uid,
				'source'   => 'dashboard',
			];
			update_post_meta( $vid, '_nkzmp_consents', $c );
		}
		update_post_meta( $vid, '_nkzmp_id_mode', 'none' );
		wp_safe_redirect( add_query_arg( 'nkzmp_identity', 'ok', $back ) );
		exit;
	}
}
