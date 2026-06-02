<?php
/**
 * @var array $vendor
 *
 * @package NKZMP\Storefront
 */

defined( 'ABSPATH' ) || exit;

$vendor_id = (int) $vendor['id'];
$avatar_id = get_post_thumbnail_id( $vendor_id );
$cover_id  = (int) get_post_meta( $vendor_id, '_nkzmp_vendor_cover_id', true );
?>

<header class="nkzmp-vendor-header<?php echo $cover_id ? ' has-cover' : ''; ?>">
	<?php if ( $cover_id ) : ?>
		<div class="nkzmp-vendor-header__cover">
			<?php echo wp_get_attachment_image( $cover_id, 'full', false, [ 'alt' => esc_attr( $vendor['name'] ) ] ); ?>
		</div>
	<?php endif; ?>

	<div class="nkzmp-vendor-header__body">
		<?php if ( $avatar_id ) : ?>
			<div class="nkzmp-vendor-header__avatar">
				<?php echo wp_get_attachment_image( $avatar_id, [ 280, 280 ], false, [ 'alt' => esc_attr( $vendor['name'] ) ] ); ?>
			</div>
		<?php endif; ?>

		<div class="nkzmp-vendor-header__meta">
			<h1 class="nkzmp-vendor-header__name"><?php echo esc_html( $vendor['name'] ); ?></h1>

			<?php if ( ! empty( $vendor['website'] ) ) : ?>
				<a class="nkzmp-vendor-header__web" href="<?php echo esc_url( $vendor['website'] ); ?>" rel="nofollow noopener" target="_blank">
					<?php echo esc_html( preg_replace( '#^https?://#', '', (string) $vendor['website'] ) ); ?>
				</a>
			<?php endif; ?>

			<?php if ( ! empty( $vendor['bio'] ) ) : ?>
				<div class="nkzmp-vendor-header__bio">
					<?php echo wp_kses_post( wpautop( (string) $vendor['bio'] ) ); ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</header>
