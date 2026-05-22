<?php
/**
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard\Views;

defined( 'ABSPATH' ) || exit;

final class ProductsView {

	public static function render( array $vendor ): void {
		$vendor_id = (int) $vendor['id'];

		$query = new \WP_Query( [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => 50,
			'paged'          => max( 1, (int) get_query_var( 'paged' ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => '_nkzmp_vendor_id', 'value' => $vendor_id, 'compare' => '=' ],
				[ 'key' => '_nkv_vendor_id',   'value' => $vendor_id, 'compare' => '=' ],
			],
		] );

		?>
		<div class="nkzmp-vd nkzmp-vd-products">

			<header class="nkzmp-vd-section-head">
				<h1><?php esc_html_e( 'Moje produkty', 'nkz-mp-vendor-dashboard' ); ?></h1>
				<p class="nkzmp-vd-meta"><?php echo esc_html( sprintf( __( '%d produktů', 'nkz-mp-vendor-dashboard' ), (int) $query->found_posts ) ); ?></p>
			</header>

			<?php if ( ! $query->have_posts() ) : ?>
				<p class="nkzmp-vd-empty-msg"><?php esc_html_e( 'Zatím nemáš žádné produkty. Ozvi se nám a založíme ti je.', 'nkz-mp-vendor-dashboard' ); ?></p>
			<?php else : ?>
				<table class="nkzmp-vd-table">
					<thead>
						<tr>
							<th class="col-img"></th>
							<th><?php esc_html_e( 'Produkt', 'nkz-mp-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Cena', 'nkz-mp-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Stav', 'nkz-mp-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Sklad', 'nkz-mp-vendor-dashboard' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php while ( $query->have_posts() ) : $query->the_post(); $product = wc_get_product( get_the_ID() ); if ( ! $product ) { continue; } ?>
							<tr>
								<td class="col-img"><?php echo $product->get_image( [ 56, 56 ] ); // phpcs:ignore ?></td>
								<td>
									<a href="<?php the_permalink(); ?>" target="_blank" class="nkzmp-vd-product-name"><?php echo esc_html( $product->get_name() ); ?></a>
								</td>
								<td><?php echo wp_kses_post( $product->get_price_html() ); ?></td>
								<td><span class="nkzmp-vd-pill nkzmp-vd-pill--<?php echo esc_attr( get_post_status() ); ?>"><?php echo esc_html( self::status_label( get_post_status() ) ); ?></span></td>
								<td>
									<?php if ( $product->managing_stock() ) :
										echo esc_html( (string) $product->get_stock_quantity() );
									else :
										echo esc_html( $product->get_stock_status() === 'instock' ? __( 'Na skladě', 'nkz-mp-vendor-dashboard' ) : __( 'Není', 'nkz-mp-vendor-dashboard' ) );
									endif; ?>
								</td>
								<td class="col-action">
									<?php if ( current_user_can( 'edit_post', get_the_ID() ) ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link() ); ?>" class="nkzmp-vd-edit-link"><?php esc_html_e( 'Upravit', 'nkz-mp-vendor-dashboard' ); ?></a>
									<?php else : ?>
										<span class="nkzmp-vd-muted"><?php esc_html_e( 'Read-only', 'nkz-mp-vendor-dashboard' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endwhile; wp_reset_postdata(); ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="nkzmp-vd-note">
				<?php esc_html_e( 'Pro úpravu produktů ti rozšíříme oprávnění do wp-adminu. Ozvi se na podporu.', 'nkz-mp-vendor-dashboard' ); ?>
			</p>

		</div>
		<?php
	}

	private static function status_label( string $status ): string {
		return match ( $status ) {
			'publish' => __( 'Publikováno', 'nkz-mp-vendor-dashboard' ),
			'draft'   => __( 'Koncept', 'nkz-mp-vendor-dashboard' ),
			'pending' => __( 'Čeká schválení', 'nkz-mp-vendor-dashboard' ),
			'private' => __( 'Soukromé', 'nkz-mp-vendor-dashboard' ),
			default   => $status,
		};
	}
}
