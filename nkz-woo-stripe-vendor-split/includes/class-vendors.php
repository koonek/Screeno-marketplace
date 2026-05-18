<?php
/**
 * Vendor CPT + admin meta box.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Vendors {

	public const POST_TYPE = 'nkv_vendor';

	private static ?Vendors $instance = null;
	public static function instance(): Vendors { return self::$instance ??= new self(); }

	public function init(): void {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save' ], 10, 2 );
	}

	public function register_cpt(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'        => __( 'Vendors (Stripe Split)', 'nkz-woo-stripe-vendor-split' ),
				'labels'       => [
					'name'          => __( 'Vendors', 'nkz-woo-stripe-vendor-split' ),
					'singular_name' => __( 'Vendor', 'nkz-woo-stripe-vendor-split' ),
					'add_new_item'  => __( 'Add vendor', 'nkz-woo-stripe-vendor-split' ),
					'edit_item'     => __( 'Edit vendor', 'nkz-woo-stripe-vendor-split' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-businessperson',
				'supports'     => [ 'title' ],
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			]
		);
	}

	public function add_meta_box(): void {
		add_meta_box(
			'nkv_vendor_data',
			__( 'Vendor data', 'nkz-woo-stripe-vendor-split' ),
			[ $this, 'render_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'nkv_vendor_save_' . $post->ID, 'nkv_vendor_nonce' );
		$fields = [
			'_nkv_stripe_account_id'      => [ 'label' => 'Stripe connected account ID', 'type' => 'text', 'placeholder' => 'acct_...' ],
			'_nkv_vendor_status'          => [ 'label' => 'Vendor status', 'type' => 'select', 'options' => [ 'active' => 'active', 'inactive' => 'inactive' ] ],
			'_nkv_stripe_account_status'  => [ 'label' => 'Stripe account status', 'type' => 'select', 'options' => [ 'unknown' => 'unknown', 'pending' => 'pending', 'enabled' => 'enabled', 'restricted' => 'restricted' ] ],
			'_nkv_default_fee_percent'    => [ 'label' => 'Default platform fee (%)', 'type' => 'number', 'step' => '0.01' ],
			'_nkv_default_fee_fixed'      => [ 'label' => 'Default fixed fee (minor units, optional)', 'type' => 'number', 'step' => '1' ],
			'_nkv_vendor_email'           => [ 'label' => 'Email', 'type' => 'email' ],
			'_nkv_vendor_ico'             => [ 'label' => 'IČO / tax ID', 'type' => 'text' ],
			'_nkv_vendor_currency'        => [ 'label' => 'Currency (ISO, optional)', 'type' => 'text', 'placeholder' => 'CZK' ],
			'_nkv_internal_note'          => [ 'label' => 'Internal note', 'type' => 'textarea' ],
		];
		echo '<table class="form-table">';
		foreach ( $fields as $key => $cfg ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $cfg['label'] ) . '</label></th><td>';
			switch ( $cfg['type'] ) {
				case 'textarea':
					printf( '<textarea name="%s" id="%s" rows="3" cols="50">%s</textarea>', esc_attr( $key ), esc_attr( $key ), esc_textarea( (string) $value ) );
					break;
				case 'select':
					echo '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '">';
					foreach ( $cfg['options'] as $ov => $ol ) {
						printf( '<option value="%s" %s>%s</option>', esc_attr( $ov ), selected( $value, $ov, false ), esc_html( $ol ) );
					}
					echo '</select>';
					break;
				default:
					printf(
						'<input type="%s" name="%s" id="%s" value="%s" placeholder="%s" %s class="regular-text" />',
						esc_attr( $cfg['type'] ),
						esc_attr( $key ),
						esc_attr( $key ),
						esc_attr( (string) $value ),
						esc_attr( $cfg['placeholder'] ?? '' ),
						isset( $cfg['step'] ) ? 'step="' . esc_attr( $cfg['step'] ) . '"' : ''
					);
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['nkv_vendor_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nkv_vendor_nonce'] ) ), 'nkv_vendor_save_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$map = [
			'_nkv_stripe_account_id'     => 'text',
			'_nkv_vendor_status'         => 'enum:active,inactive',
			'_nkv_stripe_account_status' => 'enum:unknown,pending,enabled,restricted',
			'_nkv_default_fee_percent'   => 'float',
			'_nkv_default_fee_fixed'     => 'int',
			'_nkv_vendor_email'          => 'email',
			'_nkv_vendor_ico'            => 'text',
			'_nkv_vendor_currency'       => 'currency',
			'_nkv_internal_note'         => 'textarea',
		];

		foreach ( $map as $key => $type ) {
			$raw = $_POST[ $key ] ?? '';
			$val = self::sanitize( $type, $raw );
			update_post_meta( $post_id, $key, $val );
		}
	}

	private static function sanitize( string $type, $raw ) {
		if ( str_starts_with( $type, 'enum:' ) ) {
			$allowed = explode( ',', substr( $type, 5 ) );
			$raw     = sanitize_text_field( (string) wp_unslash( $raw ) );
			return in_array( $raw, $allowed, true ) ? $raw : $allowed[0];
		}
		switch ( $type ) {
			case 'text':     return sanitize_text_field( (string) wp_unslash( $raw ) );
			case 'textarea': return sanitize_textarea_field( (string) wp_unslash( $raw ) );
			case 'email':    return sanitize_email( (string) wp_unslash( $raw ) );
			case 'float':    return (float) $raw;
			case 'int':      return (int) $raw;
			case 'currency': return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $raw ) );
		}
		return sanitize_text_field( (string) wp_unslash( $raw ) );
	}

	/**
	 * List active vendors for dropdowns.
	 *
	 * @return array<int,string>
	 */
	public static function dropdown_options(): array {
		$posts = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			]
		);
		$out = [ 0 => __( '— No vendor —', 'nkz-woo-stripe-vendor-split' ) ];
		foreach ( $posts as $p ) {
			$status = get_post_meta( $p->ID, '_nkv_vendor_status', true ) ?: 'active';
			$label  = $p->post_title . ( 'active' === $status ? '' : ' (' . $status . ')' );
			$out[ $p->ID ] = $label;
		}
		return $out;
	}
}
