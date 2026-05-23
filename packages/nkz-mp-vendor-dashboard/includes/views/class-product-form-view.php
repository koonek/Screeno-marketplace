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
			if ( $product->get_status() === 'publish' ) {
				echo '<div class="nkzmp-vd-empty"><h2>' . esc_html__( 'Publikované produkty se neupravují přes panel.', 'nkz-mp-vendor-dashboard' ) . '</h2>'
					. '<p>' . esc_html__( 'Pokud potřebuješ úpravu, ozvi se nám.', 'nkz-mp-vendor-dashboard' ) . '</p></div>';
				return;
			}
			$is_edit = true;
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

		$all_cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );

		?>
		<div class="nkzmp-vd nkzmp-vd-product-form">

			<header class="nkzmp-vd-section-head">
				<h1><?php echo $is_edit ? esc_html__( 'Upravit produkt', 'nkz-mp-vendor-dashboard' ) : esc_html__( 'Nový produkt', 'nkz-mp-vendor-dashboard' ); ?></h1>
				<p class="nkzmp-vd-meta">
					<?php esc_html_e( 'Všechny produkty jdou na schválení k provozovateli. Po publikaci ti dáme vědět.', 'nkz-mp-vendor-dashboard' ); ?>
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

					<div class="nkzmp-vd-grid-2">
						<div class="nkzmp-vd-field">
							<label class="nkzmp-vd-check">
								<input type="checkbox" name="manage_stock" value="1" <?php checked( $manage_stock ); ?> />
								<span><?php esc_html_e( 'Spravovat sklad', 'nkz-mp-vendor-dashboard' ); ?></span>
							</label>
						</div>
						<div class="nkzmp-vd-field">
							<label for="vd_qty"><?php esc_html_e( 'Počet kusů', 'nkz-mp-vendor-dashboard' ); ?></label>
							<input id="vd_qty" type="number" name="stock_quantity" min="0" step="1" value="<?php echo esc_attr( (string) $stock_qty ); ?>" />
						</div>
					</div>
				</section>

				<section class="nkzmp-vd-form-section">
					<header class="nkzmp-vd-form-shead"><span class="num">03</span><h2><?php esc_html_e( 'Fotografie', 'nkz-mp-vendor-dashboard' ); ?></h2></header>

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
								<?php endif; ?>
								<input type="file" name="gallery_<?php echo $i; ?>" accept="image/*" />
							</div>
						<?php endfor; ?>
					</div>
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
						<span style="color:#fff !important;"><?php echo $is_edit ? esc_html__( 'Uložit a poslat na schválení', 'nkz-mp-vendor-dashboard' ) : esc_html__( 'Poslat na schválení', 'nkz-mp-vendor-dashboard' ); ?></span>
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true" style="flex-shrink:0;color:#fff;"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<a class="nkzmp-vd-cancel" href="<?php echo esc_url( wc_get_account_endpoint_url( 'vendor-products' ) ); ?>"><?php esc_html_e( 'Zrušit', 'nkz-mp-vendor-dashboard' ); ?></a>
				</div>
			</form>

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
