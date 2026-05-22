<?php
/**
 * Vendor Repository – read-side přístup k vendor entitám.
 *
 * Čte přednostně z nových `_nkzmp_*` meta klíčů, fallback na legacy
 * `_nkv_*` (viz `MetaKeys::legacy_map()`). Po dokončení migrace
 * `wp nkzmp migrate-vendors` lze legacy fallback vypnout přes filter
 * `nkzmp/v1/vendor/disable_legacy_meta_fallback`.
 *
 * @package NKZMP
 */

namespace NKZMP\Vendor;

defined( 'ABSPATH' ) || exit;

final class Repository {

	/**
	 * Vrátí flat array vendora nebo null. Klíče jsou logické (status, email, ...),
	 * ne meta klíče.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $vendor_id ): ?array {
		$post = get_post( $vendor_id );
		if ( ! $post || $this->expected_post_types() && ! in_array( $post->post_type, $this->expected_post_types(), true ) ) {
			return null;
		}

		return [
			'id'                  => $post->ID,
			'name'                => $post->post_title,
			'post_type'           => $post->post_type,
			'status'              => $this->meta( $post->ID, MetaKeys::STATUS, '' ),
			'email'               => $this->meta( $post->ID, MetaKeys::EMAIL, '' ),
			'ico'                 => $this->meta( $post->ID, MetaKeys::ICO, '' ),
			'currency'            => $this->meta( $post->ID, MetaKeys::CURRENCY, '' ),
			'website'             => $this->meta( $post->ID, MetaKeys::WEBSITE, '' ),
			'bio'                 => $this->meta( $post->ID, MetaKeys::BIO, '' ),
			'internal_note'       => $this->meta( $post->ID, MetaKeys::INTERNAL_NOTE, '' ),
			'default_fee_percent' => (float) $this->meta( $post->ID, MetaKeys::DEFAULT_FEE_PERCENT, 0 ),
			'default_fee_fixed'   => (int) $this->meta( $post->ID, MetaKeys::DEFAULT_FEE_FIXED, 0 ),
			'wp_user_id'          => (int) $this->meta( $post->ID, MetaKeys::WP_USER_ID, 0 ),
		];
	}

	public function status( int $vendor_id ): ?Status {
		$raw = $this->meta( $vendor_id, MetaKeys::STATUS, '' );
		if ( $raw === '' ) {
			return null;
		}
		return Status::tryFrom( (string) $raw );
	}

	/**
	 * Read s legacy fallbackem. Nový klíč má prioritu; pokud chybí, čte z legacy.
	 *
	 * @param mixed $default
	 * @return mixed
	 */
	private function meta( int $post_id, string $new_key, $default ) {
		$value = get_post_meta( $post_id, $new_key, true );
		if ( $value !== '' && $value !== null && $value !== false ) {
			return $value;
		}

		if ( apply_filters( 'nkzmp/v1/vendor/disable_legacy_meta_fallback', false ) ) {
			return $default;
		}

		$legacy_key = array_search( $new_key, MetaKeys::legacy_map(), true );
		if ( $legacy_key !== false ) {
			$legacy = get_post_meta( $post_id, $legacy_key, true );
			if ( $legacy !== '' && $legacy !== null && $legacy !== false ) {
				return $legacy;
			}
		}

		return $default;
	}

	/**
	 * Které post_type je platný vendor. V dev/migration období akceptujeme
	 * i legacy `nkv_vendor` ze Stripe adapteru.
	 *
	 * @return string[]
	 */
	private function expected_post_types(): array {
		$types = [ Registry::POST_TYPE ];
		if ( ! apply_filters( 'nkzmp/v1/vendor/disable_legacy_post_type', false ) ) {
			$types[] = 'nkv_vendor';
		}
		return $types;
	}
}
