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

			<header class="nkzmp-vd-section-head" style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px;">
				<div>
					<h1><?php esc_html_e( 'Moje produkty', 'nkz-mp-vendor-dashboard' ); ?></h1>
					<p class="nkzmp-vd-meta"><?php echo esc_html( sprintf( __( '%d produktů', 'nkz-mp-vendor-dashboard' ), (int) $query->found_posts ) ); ?></p>
				</div>
				<a class="nkzmp-vd-cta-new" href="<?php echo esc_url( add_query_arg( 'new', '1', wc_get_account_endpoint_url( 'vendor-products' ) ) ); ?>">
					<span class="label"><?php esc_html_e( 'Nový produkt', 'nkz-mp-vendor-dashboard' ); ?></span> <span class="plus">+</span>
				</a>
			</header>

			<?php
			$flash = isset( $_GET['nkzmp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_msg'] ) ) : '';
			if ( $flash === 'submitted' ) :
				?>
				<div class="nkzmp-vd-flash nkzmp-vd-flash--success">
					<div class="icon">✓</div>
					<div>
						<strong><?php esc_html_e( 'Produkt jsme dostali.', 'nkz-mp-vendor-dashboard' ); ?></strong>
						<p><?php esc_html_e( 'Projdeme ho v týmu a publikujeme. Mezitím ho najdeš níže ve stavu „Čeká schválení". Potvrzovací e-mail je v tvojí schránce.', 'nkz-mp-vendor-dashboard' ); ?></p>
					</div>
				</div>
				<?php
			elseif ( $flash === 'updated' ) :
				?>
				<div class="nkzmp-vd-flash nkzmp-vd-flash--success">
					<div class="icon">✓</div>
					<div>
						<strong><?php esc_html_e( 'Úpravu jsme uložili.', 'nkz-mp-vendor-dashboard' ); ?></strong>
						<p><?php esc_html_e( 'Produkt jde znovu na schválení. Stav „Čeká schválení" trvá, dokud ho znovu nepublikujeme.', 'nkz-mp-vendor-dashboard' ); ?></p>
					</div>
				</div>
				<?php
			elseif ( $flash === 'live_updated' ) :
				?>
				<div class="nkzmp-vd-flash nkzmp-vd-flash--success">
					<div class="icon">✓</div>
					<div>
						<strong><?php esc_html_e( 'Změny jsou živé.', 'nkz-mp-vendor-dashboard' ); ?></strong>
						<p><?php esc_html_e( 'Produkt zůstal publikovaný, úpravy se hned projevily v obchodě.', 'nkz-mp-vendor-dashboard' ); ?></p>
					</div>
				</div>
				<?php
			elseif ( $flash === 'unpublished' ) :
				?>
				<div class="nkzmp-vd-flash"><div class="icon">↓</div><div><strong><?php esc_html_e( 'Staženo z prodeje.', 'nkz-mp-vendor-dashboard' ); ?></strong> <p><?php esc_html_e( 'Produkt je v konceptech. Úpravou a odesláním ho zase pustíš na schválení.', 'nkz-mp-vendor-dashboard' ); ?></p></div></div>
				<?php
			elseif ( $flash === 'deleted' ) :
				?>
				<div class="nkzmp-vd-flash"><div class="icon">✕</div><div><strong><?php esc_html_e( 'Produkt smazán.', 'nkz-mp-vendor-dashboard' ); ?></strong></div></div>
				<?php
			endif;
			?>

			<?php if ( ! $query->have_posts() ) : ?>
				<div class="nkzmp-vd-products-empty">
					<div class="nkzmp-vd-products-empty-art">+</div>
					<h2><?php esc_html_e( 'Zatím tu nic není', 'nkz-mp-vendor-dashboard' ); ?></h2>
					<p><?php esc_html_e( 'Přidej svůj první produkt. Projdeme ho a publikujeme.', 'nkz-mp-vendor-dashboard' ); ?></p>
					<a class="nkzmp-vd-cta-new" href="<?php echo esc_url( add_query_arg( 'new', '1', wc_get_account_endpoint_url( 'vendor-products' ) ) ); ?>">
						<span class="label"><?php esc_html_e( 'Nový produkt', 'nkz-mp-vendor-dashboard' ); ?></span> <span class="plus">+</span>
					</a>
				</div>
			<?php else : ?>
				<div class="nkzmp-vd-product-grid">
					<?php while ( $query->have_posts() ) : $query->the_post(); $product = wc_get_product( get_the_ID() ); if ( ! $product ) { continue; }
						$status   = get_post_status();
						$can_edit = in_array( $status, [ 'pending', 'draft', 'private' ], true );
						$edit_url = add_query_arg( 'edit', get_the_ID(), wc_get_account_endpoint_url( 'vendor-products' ) );
						?>
						<article class="nkzmp-vd-card nkzmp-vd-card--<?php echo esc_attr( $status ); ?>">
							<div class="nkzmp-vd-card-media">
								<?php echo $product->get_image( 'medium' ); // phpcs:ignore ?>
								<span class="nkzmp-vd-card-status nkzmp-vd-pill--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span>
							</div>
							<div class="nkzmp-vd-card-body">
								<h3 class="nkzmp-vd-card-title"><?php echo esc_html( $product->get_name() ); ?></h3>
								<div class="nkzmp-vd-card-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
								<div class="nkzmp-vd-card-stock">
									<?php if ( $product->managing_stock() ) :
										echo esc_html( sprintf( __( '%d ks skladem', 'nkz-mp-vendor-dashboard' ), (int) $product->get_stock_quantity() ) );
									else :
										echo esc_html( $product->get_stock_status() === 'instock' ? __( 'Na skladě', 'nkz-mp-vendor-dashboard' ) : __( 'Vyprodáno', 'nkz-mp-vendor-dashboard' ) );
									endif; ?>
								</div>
							</div>
							<div class="nkzmp-vd-card-actions">
								<a class="nkzmp-vd-card-edit" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Upravit', 'nkz-mp-vendor-dashboard' ); ?></a>
								<?php if ( $status === 'publish' ) : ?>
									<a class="nkzmp-vd-card-view" href="<?php the_permalink(); ?>" target="_blank"><?php esc_html_e( 'Zobrazit', 'nkz-mp-vendor-dashboard' ); ?> →</a>
								<?php endif; ?>
							</div>
							<div class="nkzmp-vd-card-subactions">
								<?php if ( $status === 'publish' ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Stáhnout produkt z prodeje?', 'nkz-mp-vendor-dashboard' ) ); ?>');">
										<input type="hidden" name="action" value="nkzmp_vd_product_unpublish" />
										<input type="hidden" name="product_id" value="<?php echo (int) get_the_ID(); ?>" />
										<?php wp_nonce_field( 'nkzmp_vd_product_action_' . get_the_ID() ); ?>
										<button type="submit" class="nkzmp-vd-link-btn"><?php esc_html_e( 'Stáhnout z prodeje', 'nkz-mp-vendor-dashboard' ); ?></button>
									</form>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Opravdu smazat tento produkt?', 'nkz-mp-vendor-dashboard' ) ); ?>');">
									<input type="hidden" name="action" value="nkzmp_vd_product_delete" />
									<input type="hidden" name="product_id" value="<?php echo (int) get_the_ID(); ?>" />
									<?php wp_nonce_field( 'nkzmp_vd_product_action_' . get_the_ID() ); ?>
									<button type="submit" class="nkzmp-vd-link-btn nkzmp-vd-link-btn--danger"><?php esc_html_e( 'Smazat', 'nkz-mp-vendor-dashboard' ); ?></button>
								</form>
							</div>
						</article>
					<?php endwhile; wp_reset_postdata(); ?>
				</div>

				<?php if ( $query->max_num_pages > 1 ) : ?>
					<nav class="nkzmp-pagination" style="margin-top:32px;">
						<?php
						echo paginate_links( [
							'base'      => add_query_arg( 'paged', '%#%', wc_get_account_endpoint_url( 'vendor-products' ) ),
							'format'    => '',
							'current'   => max( 1, (int) get_query_var( 'paged' ) ),
							'total'     => $query->max_num_pages,
							'prev_text' => '←',
							'next_text' => '→',
						] );
						?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>

			<p class="nkzmp-vd-note">
				<?php esc_html_e( 'Úpravy publikovaných produktů jsou hned veřejné. Nové produkty čekají na schválení.', 'nkz-mp-vendor-dashboard' ); ?>
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
