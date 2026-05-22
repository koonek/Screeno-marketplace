<?php
/**
 * MetaWatcher — když legacy Stripe webhook (account.updated) změní
 * _nkv_vendor_status, emit nkzmp/v1/vendor/status_changed, aby Listener
 * mohl poslat welcome / další e-maily.
 *
 * Tímto se mosti pokrytí: legacy webhook nepoužívá Vendor\StatusService,
 * tak chytíme změnu meta přímo.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class MetaWatcher {

	private const MIRROR_KEYS = [ '_nkv_vendor_status', '_nkzmp_vendor_status' ];

	private static ?MetaWatcher $instance = null;

	public static function instance(): MetaWatcher {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'updated_post_meta', [ $this, 'on_meta_updated' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'on_meta_added' ], 10, 4 );
	}

	public function on_meta_updated( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		if ( ! in_array( $meta_key, self::MIRROR_KEYS, true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'nkv_vendor', 'nkzmp_vendor' ], true ) ) {
			return;
		}
		// Najdi předchozí hodnotu z druhého klíče (nebo prázdné).
		$other     = $meta_key === '_nkv_vendor_status' ? '_nkzmp_vendor_status' : '_nkv_vendor_status';
		$from      = (string) get_post_meta( $post_id, $other, true );
		$to        = (string) $meta_value;
		if ( $from === $to ) {
			return;
		}
		// Zrcadlit do druhého klíče (aby to nehmiklo nekonečnou smyčku, kontroluju hodnotu).
		if ( get_post_meta( $post_id, $other, true ) !== $to ) {
			update_post_meta( $post_id, $other, $to );
		}
		do_action( 'nkzmp/v1/vendor/status_changed', $post_id, $from, $to, [ 'source' => 'meta_watcher' ] );
	}

	public function on_meta_added( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		if ( ! in_array( $meta_key, self::MIRROR_KEYS, true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'nkv_vendor', 'nkzmp_vendor' ], true ) ) {
			return;
		}
		$other = $meta_key === '_nkv_vendor_status' ? '_nkzmp_vendor_status' : '_nkv_vendor_status';
		if ( get_post_meta( $post_id, $other, true ) !== $meta_value ) {
			update_post_meta( $post_id, $other, $meta_value );
		}
		do_action( 'nkzmp/v1/vendor/status_changed', $post_id, '', (string) $meta_value, [ 'source' => 'meta_watcher' ] );
	}
}
