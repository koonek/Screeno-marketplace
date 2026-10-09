<?php
/**
 * VendorBilling – fakturační údaje prodejce.
 *
 * Doklad se vystavuje jménem prodejce, takže na něm musí být jeho údaje:
 * název, adresa, IČO, a u plátce DPH i DIČ. Dřív jsme měli jen jedno
 * pole „IČO / DIČ" – tady jsou zvlášť a přepínač, jestli je plátce.
 *
 * Kde se vyplňují: prodejce ve svém profilu (sekce Fakturační údaje),
 * admin v boxu u prodejce. Když něco chybí, prodejce dostane upozornění
 * v přehledu.
 *
 * Výchozí stav je „neplátce DPH". Plátcem se prodejce stává ze zákona
 * po překročení obratu – proto to je přepínač, ne napevno.
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class VendorBilling {

	public const NAME   = '_nkzmp_billing_name';
	public const STREET = '_nkzmp_billing_street';
	public const CITY   = '_nkzmp_billing_city';
	public const ZIP    = '_nkzmp_billing_zip';
	public const DIC    = '_nkzmp_billing_dic';
	public const PAYER  = '_nkzmp_vat_payer';

	/** Sazba DPH produktu u plátce (21 / 12 / 0). */
	public const PRODUCT_RATE = '_nkzmp_vat_rate';

	private static ?VendorBilling $instance = null;

	public static function instance(): VendorBilling {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'meta_box' ] );
		add_action( 'save_post', [ $this, 'save_admin' ], 20, 2 );

		add_action( 'nkzmp/v1/dashboard/profile_form_sections', [ $this, 'profile_section' ] );
		add_action( 'nkzmp/v1/dashboard/profile_saved', [ $this, 'save_profile' ] );
		add_action( 'nkzmp/v1/dashboard/notices', [ $this, 'dashboard_notice' ] );

		add_action( 'nkzmp/v1/dashboard/product_form_fields', [ $this, 'product_vat_field' ], 10, 2 );
		add_action( 'nkzmp/v1/dashboard/product_submitted', [ $this, 'save_product_vat' ], 10, 2 );
		add_action( 'woocommerce_product_options_tax', [ $this, 'admin_product_vat_field' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_admin_product_vat' ] );
	}

	public static function is_vat_payer( int $vendor_id ): bool {
		return get_post_meta( $vendor_id, self::PAYER, true ) === 'yes';
	}

	/**
	 * Údaje prodejce pro doklad, s rozumnými náhradami: název = jméno
	 * prodejce, adresa = adresa pro odeslání z registrace.
	 *
	 * @return array{name:string,street:string,city:string,zip:string,country:string,ico:string,dic:string,vat_payer:bool}
	 */
	public static function issuer( int $vendor_id ): array {
		$g = static fn( string $k ) => trim( (string) get_post_meta( $vendor_id, $k, true ) );
		$ico = $g( '_nkv_vendor_ico' ) ?: $g( '_nkzmp_vendor_ico' );
		return [
			'name'      => $g( self::NAME ) ?: (string) get_the_title( $vendor_id ),
			'street'    => $g( self::STREET ) ?: $g( '_nkzmp_sender_street' ),
			'city'      => $g( self::CITY ) ?: $g( '_nkzmp_sender_city' ),
			'zip'       => $g( self::ZIP ) ?: $g( '_nkzmp_sender_zip' ),
			'country'   => strtoupper( $g( '_nkv_stripe_country' ) ?: 'CZ' ) === 'SK' ? 'Slovensko' : 'Česká republika',
			'ico'       => $ico,
			'dic'       => $g( self::DIC ),
			'vat_payer' => self::is_vat_payer( $vendor_id ),
		];
	}

	/** Co prodejci na dokladu chybí. @return string[] */
	public static function missing( int $vendor_id ): array {
		$i       = self::issuer( $vendor_id );
		$missing = [];
		// IČO záměrně NENÍ povinné – na AOZ prodávají i tvůrci bez IČO
		// (prodej vlastní tvorby). Jejich doklad se pak jmenuje „Doklad
		// o prodeji" a prodávajícího identifikuje jméno a adresa.
		foreach ( [ 'street' => __( 'ulice', 'nkz-mp-invoices' ), 'city' => __( 'město', 'nkz-mp-invoices' ), 'zip' => __( 'PSČ', 'nkz-mp-invoices' ) ] as $k => $label ) {
			if ( $i[ $k ] === '' ) {
				$missing[] = $label;
			}
		}
		if ( $i['vat_payer'] && $i['dic'] === '' ) {
			$missing[] = __( 'DIČ', 'nkz-mp-invoices' );
		}
		return $missing;
	}

	/** Sazba DPH položky u plátce (výchozí 21 %). */
	public static function product_rate( int $product_id ): int {
		$r = get_post_meta( $product_id, self::PRODUCT_RATE, true );
		return ( $r === '' || $r === false ) ? 21 : (int) $r;
	}

	/* ------------------------------------------------------------- formuláře */

	/** Pole formuláře – sdílené adminem i profilem prodejce. */
	private static function fields(): array {
		return [
			self::NAME   => __( 'Obchodní jméno / jméno na dokladu', 'nkz-mp-invoices' ),
			self::STREET => __( 'Ulice a číslo (sídlo / místo podnikání)', 'nkz-mp-invoices' ),
			self::CITY   => __( 'Město', 'nkz-mp-invoices' ),
			self::ZIP    => __( 'PSČ', 'nkz-mp-invoices' ),
			self::DIC    => __( 'DIČ (jen plátce DPH)', 'nkz-mp-invoices' ),
		];
	}

	private static function save_fields( int $vendor_id, array $src ): void {
		foreach ( array_keys( self::fields() ) as $k ) {
			if ( array_key_exists( $k, $src ) ) {
				update_post_meta( $vendor_id, $k, sanitize_text_field( wp_unslash( (string) $src[ $k ] ) ) );
			}
		}
		if ( array_key_exists( 'nkzmp_billing_ico', $src ) ) {
			$ico = sanitize_text_field( wp_unslash( (string) $src['nkzmp_billing_ico'] ) );
			update_post_meta( $vendor_id, '_nkv_vendor_ico', $ico );
			update_post_meta( $vendor_id, '_nkzmp_vendor_ico', $ico );
		}
		update_post_meta( $vendor_id, self::PAYER, ! empty( $src[ self::PAYER ] ) ? 'yes' : 'no' );
	}

	public function meta_box(): void {
		foreach ( [ 'nkv_vendor', 'nkzmp_vendor' ] as $pt ) {
			add_meta_box( 'nkzmp-billing', __( 'Fakturační údaje (na dokladech)', 'nkz-mp-invoices' ), [ $this, 'render_meta_box' ], $pt, 'normal', 'default' );
		}
	}

	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'nkzmp_billing_' . $post->ID, 'nkzmp_billing_nonce' );
		$this->render_fields( (int) $post->ID, 'admin' );
	}

	public function save_admin( int $post_id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, [ 'nkv_vendor', 'nkzmp_vendor' ], true ) ) {
			return;
		}
		$nonce = isset( $_POST['nkzmp_billing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nkzmp_billing_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'nkzmp_billing_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// IČO v adminu edituje box Stripe modulu – tady ho nepřepisujeme.
		$src = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ověřeno výše.
		unset( $src['nkzmp_billing_ico'] );
		self::save_fields( $post_id, $src );
	}

	public function profile_section( $vendor_id ): void {
		$vendor_id = (int) $vendor_id;
		?>
		<section class="nkzmp-vd-form-section">
			<header class="nkzmp-vd-form-shead"><span class="num">05</span><h2><?php esc_html_e( 'Fakturační údaje', 'nkz-mp-vendor-dashboard' ); ?></h2></header>
			<p style="margin:0 0 12px;"><?php esc_html_e( 'Za každou objednávku za tebe zákazníkovi vystavíme doklad – tvým jménem, na základě souhlasu v podmínkách pro prodejce. Tyhle údaje na něm budou, takže je prosím vyplň přesně.', 'nkz-mp-invoices' ); ?></p>
			<?php $this->render_fields( $vendor_id, 'profile' ); ?>
		</section>
		<?php
	}

	private function render_fields( int $vendor_id, string $ctx ): void {
		$i     = self::issuer( $vendor_id );
		$payer = self::is_vat_payer( $vendor_id );
		$vals  = [
			self::NAME   => (string) get_post_meta( $vendor_id, self::NAME, true ),
			self::STREET => (string) get_post_meta( $vendor_id, self::STREET, true ),
			self::CITY   => (string) get_post_meta( $vendor_id, self::CITY, true ),
			self::ZIP    => (string) get_post_meta( $vendor_id, self::ZIP, true ),
			self::DIC    => (string) get_post_meta( $vendor_id, self::DIC, true ),
		];
		$ph = [
			self::NAME   => $i['name'],
			self::STREET => $i['street'],
			self::CITY   => $i['city'],
			self::ZIP    => $i['zip'],
			self::DIC    => 'CZ12345678',
		];

		if ( $ctx === 'admin' ) {
			echo '<table class="form-table">';
			foreach ( self::fields() as $k => $label ) {
				printf(
					'<tr><th><label for="%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s" /></td></tr>',
					esc_attr( $k ), esc_html( $label ), esc_attr( $vals[ $k ] ), esc_attr( $ph[ $k ] )
				);
			}
			printf(
				'<tr><th>%s</th><td><label><input type="checkbox" name="%s" value="1" %s /> %s</label></td></tr>',
				esc_html__( 'DPH', 'nkz-mp-invoices' ),
				esc_attr( self::PAYER ),
				checked( $payer, true, false ),
				esc_html__( 'Prodejce je plátce DPH', 'nkz-mp-invoices' )
			);
			echo '</table>';
			echo '<p class="description">' . esc_html__( 'Prázdná pole se na dokladu doplní z adresy pro odeslání a jména prodejce (šedý text). IČO se edituje výše v boxu prodejce.', 'nkz-mp-invoices' ) . '</p>';
			$missing = self::missing( $vendor_id );
			if ( $missing ) {
				echo '<p style="color:#b32d2e;">' . esc_html( sprintf( /* translators: %s: seznam */ __( 'Na dokladu bude chybět: %s', 'nkz-mp-invoices' ), implode( ', ', $missing ) ) ) . '</p>';
			}
			return;
		}

		// Profil prodejce.
		foreach ( self::fields() as $k => $label ) {
			if ( $k === self::DIC ) {
				continue; // DIČ až za přepínačem plátce
			}
			printf(
				'<div class="nkzmp-vd-field"><label for="%1$s">%2$s</label><input id="%1$s" type="text" name="%1$s" maxlength="160" value="%3$s" placeholder="%4$s" /></div>',
				esc_attr( $k ), esc_html( $label ), esc_attr( $vals[ $k ] ), esc_attr( $ph[ $k ] )
			);
			if ( $k === self::NAME ) {
				printf(
					'<div class="nkzmp-vd-field"><label for="nkzmp_billing_ico">%s</label><input id="nkzmp_billing_ico" type="text" name="nkzmp_billing_ico" maxlength="12" inputmode="numeric" value="%s" /><small>%s</small></div>',
					esc_html__( 'IČO (pokud máš)', 'nkz-mp-invoices' ),
					esc_attr( $i['ico'] ),
					esc_html__( 'Když IČO nemáš, nech prázdné – doklad pak bude vystaven na tvoje jméno a adresu.', 'nkz-mp-invoices' )
				);
			}
		}
		printf(
			'<div class="nkzmp-vd-field"><label class="nkzmp-vd-check"><input type="checkbox" name="%s" value="1" %s data-nkzmp-payer /> <span>%s</span></label><small>%s</small></div>',
			esc_attr( self::PAYER ),
			checked( $payer, true, false ),
			esc_html__( 'Jsem plátce DPH', 'nkz-mp-invoices' ),
			esc_html__( 'Zaškrtni, jen pokud jsi registrovaný k DPH. U každého produktu pak zvolíš sazbu.', 'nkz-mp-invoices' )
		);
		printf(
			'<div class="nkzmp-vd-field" data-nkzmp-dic%s><label for="%2$s">%3$s</label><input id="%2$s" type="text" name="%2$s" maxlength="14" value="%4$s" placeholder="CZ12345678" /></div>',
			$payer ? '' : ' style="display:none;"',
			esc_attr( self::DIC ),
			esc_html__( 'DIČ', 'nkz-mp-invoices' ),
			esc_attr( $vals[ self::DIC ] )
		);
		?>
		<script>
		(function () {
			var c = document.querySelector('[data-nkzmp-payer]');
			var d = document.querySelector('[data-nkzmp-dic]');
			if (!c || !d) { return; }
			c.addEventListener('change', function () { d.style.display = c.checked ? '' : 'none'; });
		})();
		</script>
		<?php
	}

	public function save_profile( $vendor_id ): void {
		// Nonce ověřil profilový controller před zavoláním hooku.
		self::save_fields( (int) $vendor_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	public function dashboard_notice( $vendor_id ): void {
		if ( ! Settings::enabled() ) {
			return;
		}
		$missing = self::missing( (int) $vendor_id );
		if ( ! $missing ) {
			return;
		}
		printf(
			'<div style="margin:0 0 20px;padding:14px 18px;border-radius:12px;background:#fff7e6;color:#7a4b00;"><strong>%s</strong> %s <a href="%s" style="font-weight:600;">%s →</a></div>',
			esc_html__( 'Doplň fakturační údaje.', 'nkz-mp-invoices' ),
			esc_html( sprintf( /* translators: %s: seznam */ __( 'Zákazníkům za tebe vystavujeme doklady a chybí na nich: %s.', 'nkz-mp-invoices' ), implode( ', ', $missing ) ) ),
			esc_url( wc_get_account_endpoint_url( 'vendor-profile' ) ),
			esc_html__( 'Doplnit', 'nkz-mp-invoices' )
		);
	}

	/* -------------------------------------------------- sazba DPH produktu */

	public function product_vat_field( $product, $vendor_id ): void {
		if ( ! self::is_vat_payer( (int) $vendor_id ) ) {
			return; // neplátce sazbu neřeší
		}
		$rate = $product instanceof \WC_Product ? self::product_rate( $product->get_id() ) : 21;
		?>
		<div class="nkzmp-vd-field" style="max-width:240px;">
			<label for="vd_vat_rate"><?php esc_html_e( 'Sazba DPH', 'nkz-mp-invoices' ); ?></label>
			<select id="vd_vat_rate" name="nkzmp_vat_rate">
				<?php foreach ( [ 21, 12, 0 ] as $r ) : ?>
					<option value="<?php echo (int) $r; ?>" <?php selected( $rate, $r ); ?>><?php echo (int) $r; ?> %</option>
				<?php endforeach; ?>
			</select>
			<small><?php esc_html_e( 'Cenu zadávej včetně DPH. Na dokladu ji rozpočítáme na základ a daň.', 'nkz-mp-invoices' ); ?></small>
		</div>
		<?php
	}

	public function save_product_vat( $product_id, $vendor_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ověřeno v ProductSubmitController.
		if ( isset( $_POST['nkzmp_vat_rate'] ) && self::is_vat_payer( (int) $vendor_id ) ) {
			$r = (int) $_POST['nkzmp_vat_rate']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( (int) $product_id, self::PRODUCT_RATE, in_array( $r, [ 21, 12, 0 ], true ) ? $r : 21 );
		}
	}

	public function admin_product_vat_field(): void {
		woocommerce_wp_select( [
			'id'          => self::PRODUCT_RATE,
			'label'       => __( 'Sazba DPH na dokladu', 'nkz-mp-invoices' ),
			'options'     => [ '' => __( 'výchozí (21 %)', 'nkz-mp-invoices' ), '21' => '21 %', '12' => '12 %', '0' => '0 %' ],
			'desc_tip'    => true,
			'description' => __( 'Použije se jen u prodejce, který je plátce DPH.', 'nkz-mp-invoices' ),
		] );
	}

	public function save_admin_product_vat( \WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC ověřuje nonce.
		$raw = isset( $_POST[ self::PRODUCT_RATE ] ) ? (string) wp_unslash( $_POST[ self::PRODUCT_RATE ] ) : '';
		$product->update_meta_data( self::PRODUCT_RATE, in_array( $raw, [ '21', '12', '0' ], true ) ? $raw : '' );
	}
}
