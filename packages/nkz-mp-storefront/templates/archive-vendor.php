<?php
/**
 * Archive vendor template – plugin fallback.
 *
 * Vyvolá se z template_include POUZE pokud theme ani Elementor Theme Builder
 * Archive template neexistuje pro nkv_vendor / nkzmp_vendor.
 *
 * @package NKZMP\Storefront
 */

defined( 'ABSPATH' ) || exit;

use NKZMP\Storefront\Settings;

$single_slug  = Settings::get()['single_slug'];
$archive_slug = Settings::get()['archive_slug'];

global $wp_query;
$vendors = [
	'items' => $wp_query->posts,
	'total' => (int) $wp_query->found_posts,
	'pages' => (int) $wp_query->max_num_pages,
	'paged' => max( 1, (int) get_query_var( 'paged' ) ),
];

get_header();
?>

<div class="nkzmp-vendor-page">

	<header style="margin-bottom: 24px;">
		<h1><?php esc_html_e( 'Prodejci', 'nkz-mp-storefront' ); ?></h1>
		<p><?php echo esc_html( sprintf( __( 'Celkem %d prodejců.', 'nkz-mp-storefront' ), $vendors['total'] ) ); ?></p>
	</header>

	<?php if ( empty( $vendors['items'] ) ) : ?>
		<p><em><?php esc_html_e( 'Žádní prodejci nejsou aktuálně k dispozici.', 'nkz-mp-storefront' ); ?></em></p>
	<?php else : ?>
		<div class="nkzmp-vendor-archive">
			<?php foreach ( $vendors['items'] as $vendor_post ) :
				$bio_key = get_post_meta( $vendor_post->ID, '_nkzmp_vendor_bio', true );
				if ( $bio_key === '' ) {
					$bio_key = get_post_meta( $vendor_post->ID, '_nkv_vendor_bio', true );
				}
				$url = home_url( '/' . $single_slug . '/' . $vendor_post->post_name );
				?>
				<article class="nkzmp-vendor-card">
					<?php if ( has_post_thumbnail( $vendor_post ) ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" style="display:block;margin-bottom:12px;">
							<?php echo get_the_post_thumbnail( $vendor_post, 'medium', [ 'style' => 'width:100%;aspect-ratio:1/1;object-fit:cover;display:block;' ] ); ?>
						</a>
					<?php endif; ?>
					<h2><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $vendor_post->post_title ); ?></a></h2>
					<?php if ( $bio_key ) : ?>
						<div class="bio"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $bio_key ), 22 ) ); ?></div>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( $vendors['pages'] > 1 ) : ?>
			<nav class="nkzmp-pagination">
				<?php
				echo paginate_links( [
					'base'      => home_url( '/' . $archive_slug . '/page/%#%' ),
					'format'    => '',
					'current'   => $vendors['paged'],
					'total'     => $vendors['pages'],
					'prev_text' => '←',
					'next_text' => '→',
				] );
				?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

</div>

<?php
get_footer();
