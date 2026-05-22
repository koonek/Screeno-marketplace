<?php
/**
 * Vendor Registry – CPT `nkzmp_vendor`, role `nkzmp_vendor`, capabilities.
 *
 * Aktivace probíhá opt-in přes konstantu `NKZMP_ENABLE_CORE_CPT` během
 * Fáze 0, aby Screeno produkce (která jede na `nkv_vendor` CPT v Stripe
 * adapter pluginu) nedostala dvě CPT registrace najednou. Po migraci
 * `wp nkzmp migrate-vendors` se aktivace přepne na default.
 *
 * @package NKZMP
 */

namespace NKZMP\Vendor;

use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Registry {

	public const POST_TYPE = 'nkzmp_vendor';

	private static ?Registry $instance = null;

	public static function instance(): Registry {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'              => __( 'Vendoři', 'nkz-marketplace' ),
				'public'             => true,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'menu_icon'          => 'dashicons-store',
				'menu_position'      => 56,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'capabilities'       => [
					'edit_posts'         => Capabilities::MANAGE_VENDORS,
					'edit_others_posts'  => Capabilities::MANAGE_VENDORS,
					'publish_posts'      => Capabilities::MANAGE_VENDORS,
					'read_private_posts' => Capabilities::MANAGE_VENDORS,
					'delete_posts'       => Capabilities::MANAGE_VENDORS,
				],
				'supports'           => [ 'title', 'editor', 'thumbnail' ],
			]
		);
	}

	public function register_meta(): void {
		$public_meta = [
			MetaKeys::WEBSITE  => 'string',
			MetaKeys::BIO      => 'string',
			MetaKeys::CURRENCY => 'string',
		];

		foreach ( $public_meta as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				[
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $type === 'string'
						? ( $key === MetaKeys::WEBSITE ? 'esc_url_raw' : 'sanitize_text_field' )
						: null,
					'auth_callback'     => static function () {
						return current_user_can( Capabilities::MANAGE_VENDORS );
					},
				]
			);
		}
	}

	/**
	 * Instalace + sync caps. Bezpečně volatelné opakovaně.
	 *
	 * Pokud role existuje (např. z dřívější verze pluginu), všechny caps
	 * z aktuální vendor_caps() definice se na ni add_cap-nou (idempotentní).
	 * Tím se zachová zpětná kompatibilita při upgradu, který přidá nový cap.
	 */
	public static function install_role(): void {
		$role = get_role( Capabilities::ROLE_VENDOR );
		if ( ! $role ) {
			$caps = array_fill_keys( Capabilities::vendor_caps(), true );
			add_role( Capabilities::ROLE_VENDOR, __( 'Vendor (NKZ)', 'nkz-marketplace' ), $caps );
		} else {
			foreach ( Capabilities::vendor_caps() as $cap ) {
				$role->add_cap( $cap );
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( Capabilities::admin_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
			$admin->add_cap( Capabilities::MANAGE_VENDORS );
		}

		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( Capabilities::MANAGE_VENDORS );
			$shop_manager->add_cap( Capabilities::APPROVE_VENDOR );
			$shop_manager->add_cap( Capabilities::MANAGE_PAYOUTS );
		}
	}

	/**
	 * Odebrání admin caps. Role `nkzmp_vendor` se NEMAŽE (existing users
	 * by ztratili roli a stali se nepřiřaditelní). Smazání řeší uninstall.
	 */
	public static function uninstall_caps(): void {
		foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_merge( Capabilities::admin_caps(), [ Capabilities::MANAGE_VENDORS ] ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
