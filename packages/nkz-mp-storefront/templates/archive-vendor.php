<?php
/**
 * Archive vendor template – /vendors.
 *
 * @var array $vendors { items: WP_Post[], total, pages, paged, per_page }.
 *
 * @package NKZMP\Storefront
 */

defined( 'ABSPATH' ) || exit;

use NKZMP\Storefront\Settings;

$single_slug = Settings::get()['single_slug'];
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
			<?php foreach ( $vendors['items'] as $post ) :
				$bio_key = get_post_meta( $post->ID, '_nkzmp_vendor_bio', true );
				if ( $bio_key === '' ) {
					$bio_key = get_post_meta( $post->ID, '_nkv_vendor_bio', true );
				}
				$url = home_url( '/' . $single_slug . '/' . $post->post_name );
				?>
				<article class="nkzmp-vendor-card">
					<?php if ( has_post_thumbnail( $post ) ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" style="display:block;margin-bottom:12px;">
							<?php echo get_the_post_thumbnail( $post, 'medium', [ 'style' => 'width:100%;height:160px;object-fit:cover;border-radius:6px;' ] ); ?>
						</a>
					<?php endif; ?>
					<h2><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $post->post_title ); ?></a></h2>
					<?php if ( $bio_key ) : ?>
						<div class="bio"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $bio_key ), 22 ) ); ?></div>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( $vendors['pages'] > 1 ) : ?>
			<nav class="nkzmp-pagination">
				<?php
				$archive_slug = Settings::get()['archive_slug'];
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
