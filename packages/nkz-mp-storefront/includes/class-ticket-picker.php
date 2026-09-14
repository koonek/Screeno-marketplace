<?php
/**
 * Výběr vstupenek – karty s počítadlem místo rozbalovacího seznamu variant.
 *
 * Standardní WooCommerce formulář u variabilního produktu umí do košíku
 * přidat jen JEDNU variantu naráz. U vstupenek je to špatně: zákazník chce
 * typicky 2× třídenní + 1× páteční v jednom kroku. Tenhle modul proto
 * nahradí `woocommerce_template_single_add_to_cart` vlastním formulářem,
 * kde má každá varianta svoje +/− počítadlo, a všechno se přidá najednou.
 *
 * Zapíná se per produkt zaškrtávátkem v Údaje o produktu → Obecné.
 * Funguje jen u variabilních produktů.
 *
 * Nic z toho nesahá na výpočet provizí ani na Stripe split – ty pracují
 * s položkami košíku (`variation_id`) úplně stejně jako dosud.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class TicketPicker {

	/** Per-produkt přepínač. */
	public const ENABLE_META = '_nkzmp_ticket_picker';

	private static ?TicketPicker $instance = null;

	public static function instance(): TicketPicker {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'admin_field' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_admin_field' ] );

		// Výměna formuláře musí proběhnout až když WC své akce registrovalo.
		add_action( 'wp', [ $this, 'maybe_swap_form' ] );

		// Odeslání formuláře. WC_Form_Handler::add_to_cart_action sedí na
		// wp_loaded/20 a košík je v tu chvíli už inicializovaný, jedeme za ním.
		add_action( 'wp_loaded', [ $this, 'handle_submit' ], 25 );
	}

	/** Je picker u tohoto produktu zapnutý a použitelný? */
	public static function is_enabled( \WC_Product $product ): bool {
		if ( ! $product->is_type( 'variable' ) ) {
			return false;
		}
		$on = get_post_meta( $product->get_id(), self::ENABLE_META, true ) === 'yes';

		return (bool) apply_filters( 'nkzmp/v1/storefront/ticket_picker_enabled', $on, $product );
	}

	/* ---------------------------------------------------------------- admin */

	public function admin_field(): void {
		woocommerce_wp_checkbox(
			[
				'id'          => self::ENABLE_META,
				'label'       => __( 'Výběr vstupenek', 'nkz-mp-storefront' ),
				'description' => __( 'Místo rozbalovacího seznamu variant zobrazit karty s počítadlem. Zákazník může koupit více typů najednou. Platí jen pro variabilní produkt.', 'nkz-mp-storefront' ),
				'desc_tip'    => false,
			]
		);
	}

	public function save_admin_field( \WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC ověřuje nonce ve svém save handleru.
		$on = isset( $_POST[ self::ENABLE_META ] ) ? 'yes' : 'no';
		$product->update_meta_data( self::ENABLE_META, $on );
	}

	/* ------------------------------------------------------------- frontend */

	public function maybe_swap_form(): void {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof \WC_Product || ! self::is_enabled( $product ) ) {
			return;
		}
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'render' ], 30 );
	}

	/**
	 * Viditelné a koupitelné varianty produktu.
	 *
	 * @return \WC_Product_Variation[]
	 */
	private function variations( \WC_Product $product ): array {
		$out = [];
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}
			if ( ! $variation->variation_is_visible() || ! $variation->is_purchasable() ) {
				continue;
			}
			$out[] = $variation;
		}

		return $out;
	}

	/** Popisek varianty – hodnoty atributů, např. „3 dny". */
	private function label( \WC_Product_Variation $variation ): string {
		$label = wc_get_formatted_variation( $variation, true, false );
		if ( trim( wp_strip_all_tags( $label ) ) === '' ) {
			$label = $variation->get_name();
		}

		return wp_strip_all_tags( $label );
	}

	public function render(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$variations = $this->variations( $product );
		if ( ! $variations ) {
			// Nemáme co nabídnout – ať radši padne zpět na standardní formulář,
			// než aby na stránce nebylo tlačítko vůbec žádné.
			woocommerce_template_single_add_to_cart();
			return;
		}

		$brand = (string) apply_filters( 'nkzmp/v1/storefront/ticket_picker_color', '#0060FF' );

		$this->styles( $brand );

		$form_id = 'nkzmp-tickets-' . (int) $product->get_id();
		printf( '<form class="nkzmp-tickets" id="%s" method="post">', esc_attr( $form_id ) );
		wp_nonce_field( 'nkzmp_tickets_' . $product->get_id(), 'nkzmp_tickets_nonce' );
		printf( '<input type="hidden" name="nkzmp_ticket_product" value="%d" />', (int) $product->get_id() );

		foreach ( $variations as $variation ) {
			$this->row( $variation );
		}

		printf(
			'<div class="nkzmp-tickets__foot"><div class="nkzmp-tickets__total"><span>%s</span><strong data-nkzmp-total>%s</strong></div>'
			. '<button type="submit" name="nkzmp_ticket_add" value="1" class="button alt nkzmp-tickets__submit">%s</button></div>',
			esc_html__( 'Celkem', 'nkz-mp-storefront' ),
			wp_kses_post( wc_price( 0 ) ),
			esc_html__( 'Přidat do košíku', 'nkz-mp-storefront' )
		);

		echo '<p class="nkzmp-tickets__hint">' . esc_html__( 'Vyberte počet u typů, které chcete. Koupit můžete i více typů najednou.', 'nkz-mp-storefront' ) . '</p>';
		echo '</form>';

		$this->script( $form_id );
	}

	private function row( \WC_Product_Variation $variation ): void {
		$id      = (int) $variation->get_id();
		$in_cart = $variation->is_in_stock();
		$max     = (int) $variation->get_max_purchase_quantity(); // -1 = bez limitu
		$desc    = trim( (string) $variation->get_description() );

		printf(
			'<div class="nkzmp-ticket%s">',
			$in_cart ? '' : ' is-out'
		);

		echo '<div class="nkzmp-ticket__head">';
		echo '<div class="nkzmp-ticket__name">' . esc_html( $this->label( $variation ) );
		if ( $desc !== '' ) {
			echo '<span class="nkzmp-ticket__desc">' . esc_html( wp_strip_all_tags( $desc ) ) . '</span>';
		}
		echo '</div>';
		echo '<div class="nkzmp-ticket__price">' . wp_kses_post( $variation->get_price_html() ) . '</div>';
		echo '</div>';

		if ( ! $in_cart ) {
			echo '<div class="nkzmp-ticket__sold">' . esc_html__( 'Vyprodáno', 'nkz-mp-storefront' ) . '</div>';
			echo '</div>';
			return;
		}

		printf(
			'<div class="nkzmp-ticket__qty">'
			. '<button type="button" class="nkzmp-ticket__step" data-nkzmp-step="-1" aria-label="%1$s">&minus;</button>'
			. '<input type="number" inputmode="numeric" name="nkzmp_ticket_qty[%2$d]" value="0" min="0"%3$s step="1"'
			. ' data-nkzmp-qty data-price="%4$s" aria-label="%5$s" />'
			. '<button type="button" class="nkzmp-ticket__step" data-nkzmp-step="1" aria-label="%6$s">+</button>'
			. '</div>',
			esc_attr__( 'Ubrat', 'nkz-mp-storefront' ),
			$id,
			$max > 0 ? ' max="' . esc_attr( (string) $max ) . '"' : '',
			esc_attr( (string) wc_get_price_to_display( $variation ) ),
			esc_attr( sprintf( /* translators: %s: název typu vstupenky */ __( 'Počet – %s', 'nkz-mp-storefront' ), $this->label( $variation ) ) ),
			esc_attr__( 'Přidat', 'nkz-mp-storefront' )
		);

		if ( $max > 0 && $max <= 10 ) {
			printf(
				'<div class="nkzmp-ticket__left">%s</div>',
				esc_html( sprintf( /* translators: %d: počet kusů */ __( 'Zbývá už jen %d ks', 'nkz-mp-storefront' ), $max ) )
			);
		}

		echo '</div>';
	}

	/* --------------------------------------------------------------- odeslání */

	public function handle_submit(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce ověřujeme níž.
		if ( empty( $_POST['nkzmp_ticket_add'] ) || empty( $_POST['nkzmp_ticket_product'] ) ) {
			return;
		}
		$product_id = absint( wp_unslash( $_POST['nkzmp_ticket_product'] ) );
		$nonce      = isset( $_POST['nkzmp_tickets_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nkzmp_tickets_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'nkzmp_tickets_' . $product_id ) ) {
			return;
		}
		$raw = isset( $_POST['nkzmp_ticket_qty'] ) && is_array( $_POST['nkzmp_ticket_qty'] )
			? wp_unslash( $_POST['nkzmp_ticket_qty'] )
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || ! self::is_enabled( $product ) ) {
			return;
		}

		$added = 0;
		foreach ( $raw as $variation_id => $qty ) {
			$variation_id = absint( $variation_id );
			$qty          = absint( $qty );
			if ( $variation_id <= 0 || $qty <= 0 ) {
				continue;
			}
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}
			// Varianta musí patřit tomuhle produktu – jinak by šlo podstrčením
			// cizího ID přidat do košíku cokoli.
			if ( (int) $variation->get_parent_id() !== $product_id ) {
				continue;
			}
			$result = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation->get_variation_attributes() );
			if ( $result ) {
				++$added;
			}
		}

		if ( 0 === $added ) {
			// add_to_cart si vlastní chybu (vyprodáno apod.) přidá samo; tohle
			// je případ „nic nevybráno".
			if ( ! wc_notice_count( 'error' ) ) {
				wc_add_notice( __( 'Vyberte prosím alespoň jednu vstupenku.', 'nkz-mp-storefront' ), 'error' );
			}
			return;
		}

		$redirect = (string) apply_filters( 'nkzmp/v1/storefront/ticket_picker_redirect', wc_get_cart_url(), $product );
		wp_safe_redirect( $redirect );
		exit;
	}

	/* ----------------------------------------------------------------- assets */

	/**
	 * Styly píšeme inline u formuláře.
	 *
	 * Záměrně nejdou do sdíleného CSS souboru: ten se na produkci slepuje
	 * a cachuje (LiteSpeed) a změny se pak „neprojeví". Inline styl je vždy
	 * aktuální a renderuje se jen na stránce, kde picker skutečně je.
	 */
	private function styles( string $brand ): void {
		?>
		<style>
		.nkzmp-tickets{margin:0 0 24px;display:block}
		.nkzmp-ticket{border:1px solid #e6e8ee;border-radius:14px;margin:0 0 12px;overflow:hidden;background:#fff}
		.nkzmp-ticket.is-out{opacity:.55}
		.nkzmp-ticket__head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 18px;flex-wrap:wrap}
		.nkzmp-ticket__name{font-weight:700;font-size:17px;line-height:1.3;min-width:0;display:flex;flex-direction:column;gap:3px}
		.nkzmp-ticket__desc{font-weight:400;font-size:13px;color:#6b7280}
		.nkzmp-ticket__price{font-weight:700;font-size:17px;white-space:nowrap;color:<?php echo esc_html( $brand ); ?>}
		.nkzmp-ticket__price del{opacity:.5;font-weight:400}
		.nkzmp-ticket__qty{display:flex;align-items:stretch;border-top:1px solid #e6e8ee}
		.nkzmp-ticket__step{flex:1 1 0;min-width:0;background:none;border:0;font-size:22px;line-height:1;padding:12px 0;cursor:pointer;color:<?php echo esc_html( $brand ); ?>}
		.nkzmp-ticket__step:hover{background:#f4f6fb}
		.nkzmp-ticket__step:disabled{opacity:.3;cursor:default;background:none}
		.nkzmp-ticket__qty input{flex:0 0 84px;width:84px;text-align:center;border:0;border-left:1px solid #e6e8ee;border-right:1px solid #e6e8ee;border-radius:0;font-size:16px;font-weight:600;background:none;-moz-appearance:textfield;appearance:textfield}
		.nkzmp-ticket__qty input::-webkit-outer-spin-button,.nkzmp-ticket__qty input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
		.nkzmp-ticket__left{padding:0 18px 12px;font-size:13px;color:#b45309}
		.nkzmp-ticket__sold{padding:0 18px 16px;font-size:14px;color:#6b7280}
		.nkzmp-tickets__foot{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:18px 0 0}
		.nkzmp-tickets__total{display:flex;align-items:baseline;gap:10px;font-size:15px}
		.nkzmp-tickets__total strong{font-size:22px}
		.nkzmp-tickets__submit{background:<?php echo esc_html( $brand ); ?> !important;color:#fff !important;-webkit-text-fill-color:#fff !important;border-radius:999px !important;padding:14px 32px !important;font-weight:700 !important}
		.nkzmp-tickets__submit:disabled{opacity:.4;cursor:default}
		.nkzmp-tickets__hint{margin:10px 0 0;font-size:13px;color:#6b7280}
		@media(max-width:520px){
			.nkzmp-tickets__foot{flex-direction:column;align-items:stretch}
			.nkzmp-tickets__submit{width:100%}
		}
		</style>
		<?php
	}

	/**
	 * Počítadla + živý součet.
	 *
	 * Formulář funguje i bez JS (jsou to normální number inputy a POST),
	 * skript jen přidá tlačítka +/− a průběžnou cenu.
	 */
	private function script( string $form_id ): void {
		$args = [
			'symbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'decimals'  => wc_get_price_decimals(),
			'decimal'   => wc_get_price_decimal_separator(),
			'thousand'  => wc_get_price_thousand_separator(),
			'format'    => get_woocommerce_price_format(),
		];
		?>
		<script>
		(function () {
			// Hledáme podle ID, ne relativně ke <script> – relativní hledání
			// se rozbije, jakmile mezi formulář a skript něco přibude.
			var form = document.getElementById(<?php echo wp_json_encode( $form_id ); ?>);
			if (!form) { return; }

			var cfg = <?php echo wp_json_encode( $args ); ?>;
			var totalEl = form.querySelector('[data-nkzmp-total]');
			var submit = form.querySelector('.nkzmp-tickets__submit');
			var inputs = Array.prototype.slice.call(form.querySelectorAll('[data-nkzmp-qty]'));

			function money(value) {
				var n = Math.abs(value).toFixed(cfg.decimals);
				var parts = n.split('.');
				parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousand);
				var num = parts.join(cfg.decimal);
				return cfg.format.replace('%1$s', cfg.symbol).replace('%2$s', num);
			}

			function clamp(input) {
				var v = parseInt(input.value, 10);
				if (isNaN(v) || v < 0) { v = 0; }
				var max = parseInt(input.getAttribute('max'), 10);
				if (!isNaN(max) && v > max) { v = max; }
				input.value = v;
				return v;
			}

			function sync() {
				var total = 0;
				var count = 0;
				inputs.forEach(function (input) {
					var qty = clamp(input);
					total += qty * parseFloat(input.dataset.price || '0');
					count += qty;
					var row = input.closest('.nkzmp-ticket');
					var max = parseInt(input.getAttribute('max'), 10);
					var minus = row.querySelector('[data-nkzmp-step="-1"]');
					var plus = row.querySelector('[data-nkzmp-step="1"]');
					if (minus) { minus.disabled = qty <= 0; }
					if (plus) { plus.disabled = !isNaN(max) && qty >= max; }
				});
				if (totalEl) { totalEl.innerHTML = money(total); }
				if (submit) { submit.disabled = count <= 0; }
			}

			form.addEventListener('click', function (e) {
				var btn = e.target.closest('[data-nkzmp-step]');
				if (!btn || !form.contains(btn)) { return; }
				e.preventDefault();
				var input = btn.parentNode.querySelector('[data-nkzmp-qty]');
				if (!input) { return; }
				input.value = (parseInt(input.value, 10) || 0) + parseInt(btn.dataset.nkzmpStep, 10);
				sync();
			});
			form.addEventListener('input', function (e) {
				if (e.target.matches('[data-nkzmp-qty]')) { sync(); }
			});

			sync();
		})();
		</script>
		<?php
	}
}
