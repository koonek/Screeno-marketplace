<?php
/**
 * Single vendor template – plugin fallback.
 *
 * Vyvolá se z template_include filteru POUZE pokud theme ani Elementor
 * Theme Builder nemají vlastní template pro singular nkv_vendor / nkzmp_vendor.
 *
 * Fetch data: vendor z aktuálního $post (singular query), produkty přes helper.
 *
 * @package NKZMP\Storefront
 */

defined( 'ABSPATH' ) || exit;

use NKZMP\Storefront\Settings;
use NKZMP\Storefront\Templates;
use NKZMP\Storefront\VendorPage;
use NKZMP\Vendor\Repository as VendorRepository;

global $post;
$vendor = VendorPage::instance()->current();
if ( ! $vendor && $post ) {
	$vendor = ( new VendorRepository() )->find( $post->ID );
}
if ( ! $vendor ) {
	return;
}

$paged       = (int) ( get_query_var( 'paged' ) ?: 1 );
$products    = VendorPage::instance()->fetch_products( (int) $vendor['id'], $paged );
$single_slug = Settings::get()['single_slug'];
$base_url    = home_url( '/' . $single_slug . '/' . get_post( (int) $vendor['id'] )->post_name );

get_header();
?>

<div class="nkzmp-vendor-page">

	<?php Templates::render( 'parts/vendor-header.php', [ 'vendor' => $vendor ] ); ?>

	<section class="nkzmp-vendor-products-section">
		<h2><?php esc_html_e( 'Produkty prodejce', 'nkz-mp-storefront' ); ?></h2>

		<?php if ( empty( $products['items'] ) ) : ?>
			<p><em><?php esc_html_e( 'Tento prodejce zatím nemá veřejné produkty.', 'nkz-mp-storefront' ); ?></em></p>
		<?php else : ?>
			<div class="nkzmp-vendor-products">
				<?php foreach ( $products['items'] as $p_post ) : setup_postdata( $p_post ); $product = wc_get_product( $p_post->ID ); if ( ! $product ) { continue; } ?>
					<a class="nkzmp-vendor-product" href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>">
						<?php echo $product->get_image( 'medium' ); // phpcs:ignore ?>
						<h3><?php echo esc_html( $product->get_name() ); ?></h3>
						<div class="price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
					</a>
				<?php endforeach; wp_reset_postdata(); ?>
			</div>

			<?php if ( $products['pages'] > 1 ) : ?>
				<nav class="nkzmp-pagination">
					<?php
					echo paginate_links( [
						'base'      => $base_url . '/page/%#%',
						'format'    => '',
						'current'   => $products['paged'],
						'total'     => $products['pages'],
						'prev_text' => '←',
						'next_text' => '→',
					] );
					?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</section>

</div>

<?php
get_footer();
