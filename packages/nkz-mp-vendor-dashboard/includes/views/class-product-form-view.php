<?php
/**
 * ProductFormView – formulář pro nový / editaci vlastního produktu.
 *
 * Pole: title, short/full description, regular_price, sale_price, featured
 * image, 4 gallery slots, categories (multi-select), stock (manage + qty).
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard\Views;

defined( 'ABSPATH' ) || exit;

final class ProductFormView {

	public static function render( array $vendor, ?int $edit_id = null ): void {
		$vendor_id = (int) $vendor['id'];

		$product   = null;
		$is_edit   = false;
		if ( $edit_id ) {
			$product = wc_get_product( $edit_id );
			if ( ! $product || ! self::owns_product( (int) $product->get_id(), $vendor_id ) ) {
				echo '<div class="nkzmp-vd-empty"><h2>' . esc_html__( 'Nemůžeš upravovat tento produkt.', 'nkz-mp-vendor-dashboard' ) . '</h2></div>';
				return;
			}
			$is_edit = true;
		}

		// Přidání nového produktu zamčené dokud není Stripe + členství hotové.
		if ( ! $is_edit && ! \NKZMP\Dashboard\VendorContext::can_add_products( $vendor_id ) ) {
			$kyc     = \NKZMP\Dashboard\VendorContext::is_kyc_done( $vendor_id );
			$billing = \NKZMP\Dashboard\VendorContext::is_billing_ok( $vendor_id );
			echo '<div class="nkzmp-vd nkzmp-vd-empty" style="text-align:center;padding:48px 24px;">';
			echo '<h2>' . esc_html__( 'Přidávání produktů je zatím zamčené', 'nkz-mp-vendor-dashboard' ) . '</h2>';
			echo '<p style="max-width:520px;margin:12px auto;color:#666;">' . esc_html__( 'Než začneš prodávat, dokonči prosím tyto dva kroky. Pak se ti přidávání produktů odemkne.', 'nkz-mp-vendor-dashboard' ) . '</p>';
			echo '<ul style="list-style:none;padding:0;max-width:420px;margin:20px auto;text-align:left;">';
			printf(
				'<li style="padding:10px 0;">%s %s</li>',
				$kyc ? '✅' : '⬜',
				esc_html__( 'Ověření totožnosti pro přijímání plateb (Stripe)', 'nkz-mp-vendor-dashboard' )
			);
			printf(
				'<li style="padding:10px 0;">%s %s</li>',
				$billing ? '✅' : '⬜',
				esc_html__( 'Aktivní členství (předplatné)', 'nkz-mp-vendor-dashboard' )
			);
			echo '</ul>';
			echo '<a class="nkzmp-vd-cta-new" href="' . esc_url( wc_get_account_endpoint_url( 'vendor' ) ) . '" style="display:inline-block;background:#0060FF;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:600;">' . esc_html__( 'Přejít na přehled', 'nkz-mp-vendor-dashboard' ) . '</a>';
			echo '</div>';
			return;
		}

		$error = isset( $_GET['nkzmp_err'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_err'] ) ) : '';
		$flash = isset( $_GET['nkzmp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_msg'] ) ) : '';

		$title         = $product ? $product->get_name() : '';
		$short_desc    = $product ? $product->get_short_description() : '';
		$desc          = $product ? $product->get_description() : '';
		$regular_price = $product ? $product->get_regular_price() : '';
		$sale_price    = $product ? $product->get_sale_price() : '';
		$manage_stock  = $product ? $product->get_manage_stock() : false;
		$stock_qty     = $product && $product->get_stock_quantity() ? (int) $product->get_stock_quantity() : '';
		$current_cats  = $product ? wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'ids' ] ) : [];

		$featured_id   = $product ? (int) $product->get_image_id() : 0;
		$gallery_ids   = $product ? array_values( array_filter( $product->get_gallery_image_ids() ) ) : [];

		// Existující varianty (edit variabilního produktu).
		$existing_var_attr   = '';
		$existing_variations = [];
		if ( $product && $product->is_type( 'variable' ) ) {
			foreach ( $product->get_attributes() as $attr ) {
				if ( $attr->get_variation() ) {
					$existing_var_attr = $attr->get_name();
					break;
				}
			}
			foreach ( $product->get_children() as $cid ) {
				$cv = wc_get_product( $cid );
				if ( ! $cv ) {
					continue;
				}
				$cvattrs = $cv->get_attributes();
				$existing_variations[] = [
					'label' => $cvattrs ? (string) reset( $cvattrs ) : '',
					'price' => (string) $cv->get_regular_price(),
					'sale'  => (string) $cv->get_sale_price(),
					'stock' => ( $cv->get_manage_stock() && $cv->get_stock_quantity() !== null ) ? (string) (int) $cv->get_stock_quantity() : '',
				];
			}
		}
		$has_var_checked = ! empty( $existing_variations );

		$all_cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );

		?>
		<div class="nkzmp-vd nkzmp-vd-product-form">

			<header class="nkzmp-vd-section-head">
				<h1><?php echo $is_edit ? esc_html__( 'Upravit produkt', 'nkz-mp-vendor-dashboard' ) : esc_html__( 'Nový produkt', 'nkz-mp-vendor-dashboard' ); ?></h1>
				<p class="nkzmp-vd-meta">
					<?php
					if ( $is_edit && $product && $product->get_status() === 'publish' ) {
						esc_html_e( 'Tohle je publikovaný produkt — změny se projeví v obchodě hned po uložení.', 'nkz-mp-vendor-dashboard' );
					} else {
						esc_html_e( 'Nové produkty jdou na schválení k provozovateli. Po publikaci ti dáme vědět.', 'nkz-mp-vendor-dashboard' );
					}
					?>
				</p>
			</header>

			<?php if ( $error ) : ?>
				<div class="nkzmp-vd-form-error" role="alert">
					<strong><?php esc_html_e( 'Něco chybí.', 'nkz-mp-vendor-dashboard' ); ?></strong>
					<span><?php echo esc_html( $error ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $flash === 'debug' ) : ?>
				<div class="nkzmp-vd-form-error" style="border-left-color:#dba617;background:rgba(219,166,23,0.06);">
					<strong>Debug:</strong>
					<span>Submit fired but no redirect. Check error_log for [NKZMP] entries.</span>
				</div>
			<?php endif; ?>

			<form class="nkzmp-vd-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="nkzmp_vd_product_submit" />
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="product_id" value="<?php echo (int) $product->get_id(); ?>" />
				<?php endif; ?>
				<?php wp_nonce_field( 'nkzmp_vd_product_submit' ); ?>

				<section class="nkzmp-vd-form-section">
					<header class="nkzmp-vd-form-shead"><span class="num">01</span><h2><?php esc_html_e( 'Základ', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

					<div class="nkzmp-vd-field">
						<label for="vd_title"><?php esc_html_e( 'Název produktu', 'nkz-mp-vendor-dashboard' ); ?> <span class="req">*</span></label>
						<input id="vd_title" type="text" name="title" required maxlength="200" value="<?php echo esc_attr( $title ); ?>" />
					</div>

					<div class="nkzmp-vd-field">
						<label for="vd_short"><?php esc_html_e( 'Krátký popis', 'nkz-mp-vendor-dashboard' ); ?></label>
						<textarea id="vd_short" name="short_description" rows="3" maxlength="500"><?php echo esc_textarea( $short_desc ); ?></textarea>
						<small><?php esc_html_e( '1–3 věty. Co produkt je, čím je tvůj.', 'nkz-mp-vendor-dashboard' ); ?></small>
					</div>

					<div class="nkzmp-vd-field">
						<label for="vd_desc"><?php esc_html_e( 'Plný popis', 'nkz-mp-vendor-dashboard' ); ?></label>
						<textarea id="vd_desc" name="description" rows="8"><?php echo esc_textarea( $desc ); ?></textarea>
					</div>
				</section>

				<section class="nkzmp-vd-form-section">
					<header class="nkzmp-vd-form-shead"><span class="num">02</span><h2><?php esc_html_e( 'Cena a sklad', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

					<div class="nkzmp-vd-grid-2">
						<div class="nkzmp-vd-field">
							<label for="vd_price"><?php esc_html_e( 'Cena', 'nkz-mp-vendor-dashboard' ); ?> <span class="req">*</span></label>
							<input id="vd_price" type="number" name="regular_price" required min="0" step="0.01" value="<?php echo esc_attr( $regular_price ); ?>" />
							<small><?php echo esc_html( sprintf( __( 'V měně %s', 'nkz-mp-vendor-dashboard' ), get_woocommerce_currency() ) ); ?></small>
						</div>
						<div class="nkzmp-vd-field">
							<label for="vd_sale"><?php esc_html_e( 'Akční cena', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vd_sale" type="number" name="sale_price" min="0" step="0.01" value="<?php echo esc_attr( $sale_price ); ?>" />
							<small><?php esc_html_e( 'Volitelné. Pokud chceš zlevnit.', 'nkz-mp-vendor-dashboard' ); ?></small>
						</div>
					</div>

					<?php
					// Množství je povinné. Bez něj WooCommerce prodá libovolný počet
					// kusů – prodejkyni přišla objednávka na 2 misky, měla jednu.
					// Výjimka: tvorba na objednávku (řemeslníci reálně dovyrábí).
					$made_to_order = $product ? ! $manage_stock : false;
					?>
					<div class="nkzmp-vd-grid-2" data-nkzmp-stock>
						<div class="nkzmp-vd-field">
							<label for="vd_qty"><?php esc_html_e( 'Počet kusů skladem', 'nkz-mp-vendor-dashboard' ); ?> <span class="req">*</span></label>
							<input id="vd_qty" type="number" name="stock_quantity" min="0" step="1"
								value="<?php echo esc_attr( (string) $stock_qty ); ?>"
								<?php echo $made_to_order ? 'disabled' : 'required'; ?>
								data-nkzmp-qty />
							<small><?php esc_html_e( 'Kolik kusů máš právě teď. Po vyprodání se produkt sám označí jako vyprodaný a nikdo si ho neobjedná navíc.', 'nkz-mp-vendor-dashboard' ); ?></small>
						</div>
						<div class="nkzmp-vd-field">
							<label class="nkzmp-vd-check">
								<input type="checkbox" name="stock_unlimited" value="1" <?php checked( $made_to_order ); ?> data-nkzmp-unlimited />
								<span><?php esc_html_e( 'Vyrábím na objednávku', 'nkz-mp-vendor-dashboard' ); ?></span>
							</label>
							<small><?php esc_html_e( 'Zaškrtni, když nemáš pevný počet a zboží dovyrobíš. Počet kusů se pak nehlídá.', 'nkz-mp-vendor-dashboard' ); ?></small>
						</div>
					</div>

					<?php
					// Lhůta na výrobu. Ukáže se jen u „na objednávku" – u skladových
					// položek platí standardních 5 dní. Zákazník ji vidí u produktu,
					// takže dopředu ví, na co čeká, a prodejci nechodí upomínky za
					// něco, co objektivně nestihne.
					$preorder_days    = $product ? (int) get_post_meta( $product->get_id(), '_nkzmp_preorder_days', true ) : 0;
					$preorder_options = \NKZMP\Dashboard\ShipDeadline::preorder_options();
					if ( $preorder_days <= 0 ) {
						$preorder_days = (int) array_key_first( $preorder_options );
					}
					?>
					<div class="nkzmp-vd-field" data-nkzmp-preorder style="<?php echo $made_to_order ? '' : 'display:none;'; ?>max-width:320px;">
						<label for="vd_preorder_days"><?php esc_html_e( 'Do kdy zboží odešleš', 'nkz-mp-vendor-dashboard' ); ?> <span class="req">*</span></label>
						<select id="vd_preorder_days" name="preorder_days">
							<?php foreach ( $preorder_options as $days => $label ) : ?>
								<option value="<?php echo (int) $days; ?>" <?php selected( $preorder_days, (int) $days ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<small><?php esc_html_e( 'Uvidí to zákazník ještě před koupí a podle toho se ti počítá čas na odeslání. Vyber raději s rezervou.', 'nkz-mp-vendor-dashboard' ); ?></small>
					</div>
					<script>
					(function(){
						var chk  = document.querySelector('[data-nkzmp-unlimited]');
						var pre  = document.querySelector('[data-nkzmp-preorder]');
						if (!chk || !pre) return;
						function sync(){ pre.style.display = chk.checked ? '' : 'none'; }
						chk.addEventListener('change', sync);
						sync();
					})();
					</script>
					<script>
					(function(){
						var wrap = document.currentScript.previousElementSibling;
						if (!wrap) return;
						var chk = wrap.querySelector('[data-nkzmp-unlimited]');
						var qty = wrap.querySelector('[data-nkzmp-qty]');
						if (!chk || !qty) return;
						function sync(){
							qty.disabled = chk.checked;
							qty.required = !chk.checked;
							qty.closest('.nkzmp-vd-field').style.opacity = chk.checked ? '.45' : '';
						}
						chk.addEventListener('change', sync);
						sync();
					})();
					</script>

					<?php
					$requires_raw = $product ? get_post_meta( $product->get_id(), '_nkzmp_requires_shipping', true ) : '';
					$requires     = $requires_raw !== 'no';
					?>
					<div class="nkzmp-vd-field">
						<label class="nkzmp-vd-check">
							<input type="checkbox" name="requires_shipping" value="1" <?php checked( $requires ); ?> />
							<span><?php esc_html_e( 'Fyzický produkt – vyžaduje dopravu', 'nkz-mp-vendor-dashboard' ); ?></span>
						</label>
						<small><?php esc_html_e( 'Odškrtni u digitálních produktů (e-booky, návody, vouchery). Pak se za ně neúčtuje doprava.', 'nkz-mp-vendor-dashboard' ); ?></small>
					</div>

					<?php
					$ship_override = $product ? get_post_meta( $product->get_id(), '_nkzmp_shipping_override', true ) : '';
					$ship_min      = class_exists( \NKZMP\Shipping\Rate::class ) ? (float) \NKZMP\Shipping\Rate::min_flat() : 0.0;
					?>
					<div class="nkzmp-vd-field">
						<label for="vd_ship_override"><?php esc_html_e( 'Poštovné za tento produkt (volitelné)', 'nkz-mp-vendor-dashboard' ); ?></label>
						<input id="vd_ship_override" type="number" name="shipping_override" min="<?php echo esc_attr( (string) (int) $ship_min ); ?>" step="1" value="<?php echo esc_attr( (string) $ship_override ); ?>" placeholder="<?php echo esc_attr( $ship_min > 0 ? sprintf( __( 'např. %d', 'nkz-mp-vendor-dashboard' ), (int) $ship_min + 51 ) : __( 'např. 150', 'nkz-mp-vendor-dashboard' ) ); ?>" />
						<small>
							<?php if ( $ship_min > 0 ) : ?>
								<?php echo esc_html( sprintf( __( 'Necháš prázdné = použije se tvůj běžný paušál. Vyplň jen u větších/těžších věcí, kde je doprava jiná. Minimum je %d Kč.', 'nkz-mp-vendor-dashboard' ), (int) $ship_min ) ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Necháš prázdné = použije se tvůj běžný paušál. Vyplň jen u větších/těžších věcí, kde je doprava jiná. 0 = doprava zdarma.', 'nkz-mp-vendor-dashboard' ); ?>
							<?php endif; ?>
						</small>
					</div>
				</section>

				<section class="nkzmp-vd-form-section" data-nkzmp-variations>
					<header class="nkzmp-vd-form-shead"><span class="num">2b</span><h2><?php esc_html_e( 'Varianty (volitelné)', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

					<div class="nkzmp-vd-field">
						<label class="nkzmp-vd-check">
							<input type="checkbox" name="has_variations" value="1" id="nkzmp-has-var" <?php checked( $has_var_checked ); ?> />
							<span><?php esc_html_e( 'Produkt má varianty (např. velikosti) s vlastní cenou a skladem', 'nkz-mp-vendor-dashboard' ); ?></span>
						</label>
						<small><?php esc_html_e( 'Zapni, když prodáváš víc verzí (A4 / A3 / A2, malá / velká…). Cenu a sklad pak nastavíš u každé varianty zvlášť — pole „Cena" nahoře můžeš nechat prázdné.', 'nkz-mp-vendor-dashboard' ); ?></small>
					</div>

					<div id="nkzmp-var-body" style="<?php echo $has_var_checked ? '' : 'display:none;'; ?>">
						<div class="nkzmp-vd-field">
							<label for="nkzmp-var-attr"><?php esc_html_e( 'Název atributu', 'nkz-mp-vendor-dashboard' ); ?> <span class="req">*</span></label>
							<input id="nkzmp-var-attr" type="text" name="variation_attribute" maxlength="60" value="<?php echo esc_attr( $existing_var_attr ); ?>" placeholder="<?php esc_attr_e( 'např. Velikost', 'nkz-mp-vendor-dashboard' ); ?>" />
						</div>

						<div class="nkzmp-vd-var-head" style="display:flex;gap:8px;font-size:12px;color:#777;margin-bottom:4px;">
							<span style="flex:2;"><?php esc_html_e( 'Volba', 'nkz-mp-vendor-dashboard' ); ?></span>
							<span style="flex:1;"><?php esc_html_e( 'Cena', 'nkz-mp-vendor-dashboard' ); ?></span>
							<span style="flex:1;"><?php esc_html_e( 'Akce', 'nkz-mp-vendor-dashboard' ); ?></span>
							<span style="flex:1;"><?php esc_html_e( 'Sklad', 'nkz-mp-vendor-dashboard' ); ?></span>
							<span style="width:32px;"></span>
						</div>

						<div id="nkzmp-var-rows">
							<?php
							$rows = $existing_variations ?: [ [ 'label' => '', 'price' => '', 'sale' => '', 'stock' => '' ] ];
							foreach ( $rows as $r ) {
								self::render_var_row( $r );
							}
							?>
						</div>

						<button type="button" class="button" id="nkzmp-var-add" style="margin-top:8px;">+ <?php esc_html_e( 'Přidat variantu', 'nkz-mp-vendor-dashboard' ); ?></button>
					</div>

					<template id="nkzmp-var-tpl"><?php self::render_var_row( [ 'label' => '', 'price' => '', 'sale' => '', 'stock' => '' ] ); ?></template>
				</section>

				<script>
				(function(){
					var chk  = document.getElementById('nkzmp-has-var');
					var body = document.getElementById('nkzmp-var-body');
					var rows = document.getElementById('nkzmp-var-rows');
					var tpl  = document.getElementById('nkzmp-var-tpl');
					var add  = document.getElementById('nkzmp-var-add');
					var base = document.getElementById('vd_price');
					if(!chk||!body||!rows||!tpl||!add){ return; }
					function sync(){
						body.style.display = chk.checked ? '' : 'none';
						if(base){ if(chk.checked){ base.removeAttribute('required'); } else { base.setAttribute('required','required'); } }
					}
					chk.addEventListener('change', sync);
					add.addEventListener('click', function(){
						rows.appendChild(tpl.content.cloneNode(true));
					});
					rows.addEventListener('click', function(e){
						var del = e.target.closest('.nkzmp-vd-var-del');
						if(!del){ return; }
						var row = del.closest('.nkzmp-vd-var-row');
						if(row && rows.querySelectorAll('.nkzmp-vd-var-row').length > 1){ row.remove(); }
						else if(row){ row.querySelectorAll('input').forEach(function(i){ i.value=''; }); }
					});
					sync();
				})();
				</script>

				<section class="nkzmp-vd-form-section">
					<header class="nkzmp-vd-form-shead"><span class="num">03</span><h2><?php esc_html_e( 'Fotografie', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

					<?php if ( ! ( class_exists( \NKZMP\Dashboard\HeicUploads::class ) && \NKZMP\Dashboard\HeicUploads::server_supports_heic() ) ) : ?>
						<p class="nkzmp-vd-hint" style="margin:0 0 14px;padding:10px 14px;background:#fff7e6;border-radius:8px;font-size:13px;color:#7a4b00;">
							<?php esc_html_e( 'Fotky nahrávej jako JPEG nebo PNG. Fotky z iPhonu bývají ve formátu HEIC, který prohlížeče nezobrazí — přepni si Nastavení → Fotoaparát → Formáty → „Nejkompatibilnější“.', 'nkz-mp-vendor-dashboard' ); ?>
						</p>
					<?php endif; ?>

					<div class="nkzmp-vd-image-featured-wrap">
						<label class="nkzmp-vd-img-label"><?php esc_html_e( 'Hlavní fotka', 'nkz-mp-vendor-dashboard' ); ?> <span class="req">*</span></label>
						<?php if ( $featured_id ) : ?>
							<div class="nkzmp-vd-img-thumb"><?php echo wp_get_attachment_image( $featured_id, [ 200, 200 ], false, [ 'style' => 'object-fit:cover;width:200px;height:200px;' ] ); ?></div>
						<?php endif; ?>
						<input type="file" name="featured_image" accept="image/*" <?php echo $featured_id ? '' : 'required'; ?> />
						<?php if ( $featured_id ) : ?>
							<small><?php esc_html_e( 'Pokud nahraješ novou, stará se přepíše.', 'nkz-mp-vendor-dashboard' ); ?></small>
						<?php endif; ?>
					</div>

					<div class="nkzmp-vd-gallery-grid">
						<?php for ( $i = 1; $i <= 4; $i++ ) : ?>
							<div class="nkzmp-vd-gallery-slot">
								<label class="nkzmp-vd-img-label"><?php echo esc_html( sprintf( __( 'Galerie %d', 'nkz-mp-vendor-dashboard' ), $i ) ); ?></label>
								<?php $g = $gallery_ids[ $i - 1 ] ?? 0; if ( $g ) : ?>
									<div class="nkzmp-vd-img-thumb"><?php echo wp_get_attachment_image( $g, [ 100, 100 ] ); ?></div>
									<label class="nkzmp-vd-img-remove" style="display:flex;align-items:center;gap:6px;margin:6px 0;font-size:13px;color:#b00020;cursor:pointer;">
										<input type="checkbox" name="gallery_remove[]" value="<?php echo (int) $g; ?>" />
										<span><?php esc_html_e( 'Odebrat tuto fotku', 'nkz-mp-vendor-dashboard' ); ?></span>
									</label>
								<?php endif; ?>
								<input type="file" name="gallery_<?php echo $i; ?>" accept="image/*" />
							</div>
						<?php endfor; ?>
					</div>
					<?php if ( ! empty( $gallery_ids ) ) : ?>
						<small><?php esc_html_e( 'Nahráním nové fotky do políčka se ta původní přepíše. Zaškrtnutím „Odebrat" fotku z galerie odstraníš.', 'nkz-mp-vendor-dashboard' ); ?></small>
					<?php endif; ?>

					<script>
					/* Živý náhled vybraných fotek – prodejce jinak před uložením
					   vidí jen název souboru a netuší, co vlastně nahrál. */
					(function(){
						var scope = document.currentScript && document.currentScript.closest('section');
						if (!scope) return;

						scope.querySelectorAll('input[type="file"]').forEach(function(input){
							var isFeatured = input.name === 'featured_image';
							var size = isFeatured ? 200 : 100;

							var preview = document.createElement('div');
							preview.className = 'nkzmp-vd-img-preview';
							preview.style.cssText = 'display:none;margin:8px 0;';
							preview.innerHTML =
								'<img alt="" style="width:' + size + 'px;height:' + size + 'px;object-fit:cover;border-radius:8px;display:block;">' +
								'<small style="display:block;margin-top:4px;color:#1b5e20;">✓ ' +
								<?php echo wp_json_encode( __( 'Vybráno – uloží se po odeslání', 'nkz-mp-vendor-dashboard' ) ); ?> +
								'</small>';
							input.insertAdjacentElement('afterend', preview);

							var img = preview.querySelector('img');
							var url = null;

							input.addEventListener('change', function(){
								if (url) { URL.revokeObjectURL(url); url = null; }
								var file = input.files && input.files[0];
								if (!file) { preview.style.display = 'none'; return; }
								if (!/^image\//.test(file.type)) {
									preview.style.display = 'none';
									return;
								}
								url = URL.createObjectURL(file);
								img.src = url;
								preview.style.display = '';
								// Původní (uloženou) fotku schováme, ať je jasné, co nahradí.
								var slot = input.closest('.nkzmp-vd-gallery-slot, .nkzmp-vd-image-featured-wrap');
								var old  = slot && slot.querySelector('.nkzmp-vd-img-thumb');
								if (old) { old.style.opacity = '.35'; }
							});
						});
					})();
					</script>
				</section>

				<section class="nkzmp-vd-form-section">
					<header class="nkzmp-vd-form-shead"><span class="num">04</span><h2><?php esc_html_e( 'Kategorie', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

					<?php if ( empty( $all_cats ) || is_wp_error( $all_cats ) ) : ?>
						<p class="nkzmp-vd-muted"><?php esc_html_e( 'Provozovatel ještě nenastavil kategorie.', 'nkz-mp-vendor-dashboard' ); ?></p>
					<?php else : ?>
						<div class="nkzmp-vd-cats">
							<?php foreach ( $all_cats as $cat ) : ?>
								<label class="nkzmp-vd-cat">
									<input type="checkbox" name="categories[]" value="<?php echo (int) $cat->term_id; ?>" <?php checked( in_array( (int) $cat->term_id, (array) $current_cats, true ) ); ?> />
									<span><?php echo esc_html( $cat->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>

				<div class="nkzmp-vd-form-foot">
					<button type="submit" name="nkzmp_submit" value="1" class="nkzmp-vd-submit" style="background:#0060FF !important;background-color:#0060FF !important;color:#fff !important;border:0 !important;border-radius:0 !important;padding:16px 32px !important;font-weight:500 !important;font-size:15px !important;display:inline-flex !important;align-items:center !important;gap:12px !important;cursor:pointer !important;">
						<span style="color:#fff !important;"><?php
						if ( $is_edit && $product && $product->get_status() === 'publish' ) {
							esc_html_e( 'Uložit změny', 'nkz-mp-vendor-dashboard' );
						} elseif ( $is_edit ) {
							esc_html_e( 'Uložit a poslat na schválení', 'nkz-mp-vendor-dashboard' );
						} else {
							esc_html_e( 'Poslat na schválení', 'nkz-mp-vendor-dashboard' );
						}
						?></span>
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true" style="flex-shrink:0;color:#fff;"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<a class="nkzmp-vd-cancel" href="<?php echo esc_url( wc_get_account_endpoint_url( 'vendor-products' ) ); ?>"><?php esc_html_e( 'Zrušit', 'nkz-mp-vendor-dashboard' ); ?></a>
				</div>
			</form>

		</div>
		<?php
	}

	/** Jeden řádek varianty (volba + cena + akce + sklad + smazat). */
	private static function render_var_row( array $r ): void {
		?>
		<div class="nkzmp-vd-var-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">
			<input type="text" name="var_label[]" style="flex:2;" maxlength="60" value="<?php echo esc_attr( (string) ( $r['label'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'A4', 'nkz-mp-vendor-dashboard' ); ?>" />
			<input type="number" name="var_price[]" style="flex:1;" min="0" step="0.01" value="<?php echo esc_attr( (string) ( $r['price'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'cena', 'nkz-mp-vendor-dashboard' ); ?>" />
			<input type="number" name="var_sale[]" style="flex:1;" min="0" step="0.01" value="<?php echo esc_attr( (string) ( $r['sale'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'akce', 'nkz-mp-vendor-dashboard' ); ?>" />
			<input type="number" name="var_stock[]" style="flex:1;" min="0" step="1" value="<?php echo esc_attr( (string) ( $r['stock'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'sklad (ks) *', 'nkz-mp-vendor-dashboard' ); ?>" />
			<button type="button" class="nkzmp-vd-var-del" aria-label="<?php esc_attr_e( 'Smazat variantu', 'nkz-mp-vendor-dashboard' ); ?>" style="width:32px;height:32px;border:1px solid #ddd;background:#fff;border-radius:6px;cursor:pointer;font-size:18px;line-height:1;">×</button>
		</div>
		<?php
	}

	private static function owns_product( int $product_id, int $vendor_id ): bool {
		if ( $product_id <= 0 || $vendor_id <= 0 ) {
			return false;
		}
		$pv = (int) get_post_meta( $product_id, '_nkzmp_vendor_id', true );
		if ( $pv === $vendor_id ) {
			return true;
		}
		$pv = (int) get_post_meta( $product_id, '_nkv_vendor_id', true );
		return $pv === $vendor_id;
	}
}
