<?php
/**
 * @var array $vendor
 *
 * @package NKZMP\Storefront
 */

defined( 'ABSPATH' ) || exit;

$thumb_id = get_post_thumbnail_id( (int) $vendor['id'] );
?>

<header class="nkzmp-vendor-header">
	<?php if ( $thumb_id ) : ?>
		<div class="nkzmp-vendor-header__image">
			<?php echo wp_get_attachment_image( $thumb_id, 'large' ); ?>
		</div>
	<?php endif; ?>

	<div class="nkzmp-vendor-header__body">
		<h1 class="nkzmp-vendor-header__name"><?php echo esc_html( $vendor['name'] ); ?></h1>

		<?php if ( ! empty( $vendor['website'] ) ) : ?>
			<a class="nkzmp-vendor-header__web" href="<?php echo esc_url( $vendor['website'] ); ?>" rel="nofollow noopener" target="_blank">
				🌐 <?php echo esc_html( preg_replace( '#^https?://#', '', (string) $vendor['website'] ) ); ?>
			</a>
		<?php endif; ?>

		<?php if ( ! empty( $vendor['bio'] ) ) : ?>
			<div class="nkzmp-vendor-header__bio">
				<?php echo wp_kses_post( wpautop( (string) $vendor['bio'] ) ); ?>
			</div>
		<?php endif; ?>
	</div>
</header>
