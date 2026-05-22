<?php
/**
 * Elementor Pro integration.
 *
 * Exposes a custom query for Loop Grid via the `elementor/query/{id}` hook.
 * Users wire it up by setting Query ID = `screeno_vendors` on the Loop Grid widget
 * (Advanced tab → Query ID). The standard Source picker can stay on "Posts" — the
 * filter replaces query args entirely.
 *
 * Output is restricted to ACTIVE vendors with an ENABLED Stripe account, ordered
 * alphabetically. Pagination respects the widget's `posts_per_page` setting.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Integration {

	private static ?Elementor_Integration $instance = null;
	public static function instance(): Elementor_Integration { return self::$instance ??= new self(); }

	public const QUERY_ID = 'screeno_vendors';

	public function init(): void {
		add_action( 'elementor/query/' . self::QUERY_ID, [ $this, 'query_vendors' ] );
	}

	public function query_vendors( \WP_Query $query ): void {
		$query->set( 'post_type', Vendors::POST_TYPE );
		$query->set( 'post_status', 'publish' );
		$query->set( 'orderby', 'title' );
		$query->set( 'order', 'ASC' );

		// Only active vendors with an active Stripe account.
		$query->set( 'meta_query', [
			'relation' => 'AND',
			[
				'key'     => '_nkv_vendor_status',
				'value'   => 'active',
				'compare' => '=',
			],
			[
				'key'     => '_nkv_stripe_account_status',
				'value'   => 'enabled',
				'compare' => '=',
			],
		] );
	}
}
