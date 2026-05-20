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
		$this->render_onboarding_panel( $post->ID );
		$fields = [
			'_nkv_vendor_status'          => [ 'label' => 'Vendor status', 'type' => 'select', 'options' => [ 'active' => 'active', 'inactive' => 'inactive' ] ],
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

	private function render_onboarding_panel( int $vendor_id ): void {
		$account_id = (string) get_post_meta( $vendor_id, '_nkv_stripe_account_id', true );
		$status     = (string) ( get_post_meta( $vendor_id, '_nkv_stripe_account_status', true ) ?: 'unknown' );
		$due_json   = (string) get_post_meta( $vendor_id, '_nkv_stripe_requirements_due', true );
		$due        = $due_json ? (array) json_decode( $due_json, true ) : [];

		$flash = isset( $_GET['nkv_onboarding'] ) ? sanitize_text_field( wp_unslash( $_GET['nkv_onboarding'] ) ) : '';
		$msg   = isset( $_GET['nkv_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkv_msg'] ) ) : '';

		echo '<div class="nkv-onboarding" style="padding:12px;border:1px solid #ccd0d4;background:#fff;margin-bottom:12px;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Stripe Connect', 'nkz-woo-stripe-vendor-split' ) . '</h3>';

		if ( 'returned' === $flash ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Onboarding session finished — status refreshed below.', 'nkz-woo-stripe-vendor-split' ) . '</p></div>';
		} elseif ( 'synced' === $flash ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Status refreshed from Stripe.', 'nkz-woo-stripe-vendor-split' ) . '</p></div>';
		} elseif ( 'error' === $flash && '' !== $msg ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( '' === $account_id ) {
			echo '<p>' . esc_html__( 'No Stripe account connected yet.', 'nkz-woo-stripe-vendor-split' ) . '</p>';
			printf(
				'<a href="%s" class="button button-primary" target="_blank" rel="noopener">%s</a>',
				esc_url( Onboarding_Controller::connect_url( $vendor_id ) ),
				esc_html__( 'Connect to Stripe', 'nkz-woo-stripe-vendor-split' )
			);
		} else {
			$labels = [
				'enabled'    => [ 'Enabled', '#46b450' ],
				'pending'    => [ 'Pending', '#ffb900' ],
				'restricted' => [ 'Restricted', '#dc3232' ],
				'unknown'    => [ 'Unknown', '#888' ],
			];
			$badge = $labels[ $status ] ?? $labels['unknown'];
			printf(
				'<p><strong>%s:</strong> <code>%s</code><br><strong>%s:</strong> <span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;background:%s;">%s</span></p>',
				esc_html__( 'Account', 'nkz-woo-stripe-vendor-split' ),
				esc_html( $account_id ),
				esc_html__( 'Status', 'nkz-woo-stripe-vendor-split' ),
				esc_attr( $badge[1] ),
				esc_html( $badge[0] )
			);

			if ( ! empty( $due ) ) {
				echo '<p><strong>' . esc_html__( 'Requirements due', 'nkz-woo-stripe-vendor-split' ) . ':</strong><br><code>' . esc_html( implode( ', ', array_map( 'strval', $due ) ) ) . '</code></p>';
			}

			if ( in_array( $status, [ 'pending', 'restricted' ], true ) ) {
				printf(
					'<a href="%s" class="button button-primary" target="_blank" rel="noopener">%s</a> ',
					esc_url( Onboarding_Controller::connect_url( $vendor_id ) ),
					esc_html__( 'Continue onboarding', 'nkz-woo-stripe-vendor-split' )
				);
			}
			printf(
				'<a href="%s" class="button" target="_blank" rel="noopener">%s</a> ',
				esc_url( Onboarding_Controller::dashboard_url( $vendor_id ) ),
				esc_html__( 'Open Stripe Dashboard', 'nkz-woo-stripe-vendor-split' )
			);
			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( Onboarding_Controller::sync_url( $vendor_id ) ),
				esc_html__( 'Refresh status', 'nkz-woo-stripe-vendor-split' )
			);
		}

		echo '</div>';
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
			'_nkv_vendor_status'         => 'enum:active,inactive',
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
