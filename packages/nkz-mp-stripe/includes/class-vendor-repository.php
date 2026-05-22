<?php
/**
 * Vendor read-side repository.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Vendor_Repository {

	/**
	 * Load vendor as a flat array of fields. Returns null if not found.
	 *
	 * @return array{
	 *   id:int, name:string, stripe_account_id:string, status:string,
	 *   stripe_account_status:string, fee_percent:float, fee_fixed:int,
	 *   email:string, ico:string, currency:string, note:string
	 * }|null
	 */
	public static function get( int $vendor_id ): ?array {
		if ( $vendor_id <= 0 ) {
			return null;
		}
		$post = get_post( $vendor_id );
		if ( ! $post || Vendors::POST_TYPE !== $post->post_type ) {
			return null;
		}
		return [
			'id'                    => $post->ID,
			'name'                  => $post->post_title,
			'stripe_account_id'     => (string) get_post_meta( $post->ID, '_nkv_stripe_account_id', true ),
			'status'                => (string) ( get_post_meta( $post->ID, '_nkv_vendor_status', true ) ?: 'active' ),
			'stripe_account_status' => (string) ( get_post_meta( $post->ID, '_nkv_stripe_account_status', true ) ?: 'unknown' ),
			'fee_percent'           => (float) get_post_meta( $post->ID, '_nkv_default_fee_percent', true ),
			'fee_fixed'             => (int) get_post_meta( $post->ID, '_nkv_default_fee_fixed', true ),
			'email'                 => (string) get_post_meta( $post->ID, '_nkv_vendor_email', true ),
			'ico'                   => (string) get_post_meta( $post->ID, '_nkv_vendor_ico', true ),
			'currency'              => (string) get_post_meta( $post->ID, '_nkv_vendor_currency', true ),
			'note'                  => (string) get_post_meta( $post->ID, '_nkv_internal_note', true ),
		];
	}

	public static function is_payable( array $vendor ): bool {
		return 'active' === $vendor['status']
			&& '' !== $vendor['stripe_account_id']
			&& 'restricted' !== $vendor['stripe_account_status'];
	}
}
