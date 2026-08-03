<?php
/**
 * ProfileSubmitController – uloží vendor profil (admin-post.php).
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class ProfileSubmitController {

	public const ACTION = 'nkzmp_vd_profile_submit';
	public const NONCE  = 'nkzmp_vd_profile_submit';

	private static ?ProfileSubmitController $instance = null;

	public static function instance(): ProfileSubmitController {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		check_admin_referer( self::NONCE );

		if ( ! is_user_logged_in() || ! VendorContext::user_is_vendor() ) {
			$this->redirect_error( __( 'Nepřihlášený prodejce.', 'nkz-mp-vendor-dashboard' ) );
		}
		$vendor_id = VendorContext::current_vendor_id();
		if ( $vendor_id <= 0 ) {
			$this->redirect_error( __( 'Účet není propojený s prodejcem.', 'nkz-mp-vendor-dashboard' ) );
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$website = esc_url_raw( wp_unslash( $_POST['website'] ?? '' ) );
		$bio     = sanitize_textarea_field( wp_unslash( $_POST['bio'] ?? '' ) );

		if ( $name === '' ) {
			$this->redirect_error( __( 'Název nesmí být prázdný.', 'nkz-mp-vendor-dashboard' ) );
		}

		// Update title (pokud se změnil).
		$post = get_post( $vendor_id );
		if ( $post && $post->post_title !== $name ) {
			wp_update_post( [ 'ID' => $vendor_id, 'post_title' => $name ] );
		}

		// Meta (nové + legacy mirror).
		update_post_meta( $vendor_id, '_nkzmp_vendor_website', $website );
		update_post_meta( $vendor_id, '_nkv_vendor_website', $website );
		update_post_meta( $vendor_id, '_nkzmp_vendor_bio', $bio );
		update_post_meta( $vendor_id, '_nkv_vendor_bio', $bio );

		// Adresa pro odeslání (Packeta odesílatel) – pokud modul aktivní.
		if ( class_exists( \NKZMP\Packeta\Settings::class ) ) {
			$sender = [
				'_nkzmp_sender_name'         => sanitize_text_field( wp_unslash( $_POST['sender_name'] ?? '' ) ),
				'_nkzmp_sender_street'       => sanitize_text_field( wp_unslash( $_POST['sender_street'] ?? '' ) ),
				'_nkzmp_sender_city'         => sanitize_text_field( wp_unslash( $_POST['sender_city'] ?? '' ) ),
				'_nkzmp_sender_zip'          => sanitize_text_field( wp_unslash( $_POST['sender_zip'] ?? '' ) ),
				'_nkzmp_sender_phone'        => sanitize_text_field( wp_unslash( $_POST['sender_phone'] ?? '' ) ),
				'_nkzmp_packeta_sender_label' => sanitize_text_field( wp_unslash( $_POST['sender_label'] ?? '' ) ),
			];
			foreach ( $sender as $meta_key => $meta_val ) {
				if ( $meta_val === '' ) {
					delete_post_meta( $vendor_id, $meta_key );
				} else {
					update_post_meta( $vendor_id, $meta_key, $meta_val );
				}
			}
		}

		// Shipping paušál (pokud modul aktivní).
		if ( defined( 'NKZMP_SHIPPING_VENDOR_RATE_META' ) && isset( $_POST['shipping_flat'] ) ) {
			$raw = trim( (string) wp_unslash( $_POST['shipping_flat'] ) );
			if ( $raw === '' ) {
				delete_post_meta( $vendor_id, NKZMP_SHIPPING_VENDOR_RATE_META );
			} elseif ( is_numeric( $raw ) ) {
				// Rate::set_vendor_flat zvedne částku pod minimem na minimum.
				if ( class_exists( \NKZMP\Shipping\Rate::class ) ) {
					\NKZMP\Shipping\Rate::set_vendor_flat( $vendor_id, (float) $raw );
				} else {
					update_post_meta( $vendor_id, NKZMP_SHIPPING_VENDOR_RATE_META, (float) $raw );
				}
			}
		}

		// Profilová fotka (avatar) + cover banner.
		$has_upload = ! empty( $_FILES['profile_image']['name'] ) || ! empty( $_FILES['cover_image']['name'] );
		if ( $has_upload ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			if ( ! empty( $_FILES['profile_image']['name'] ) ) {
				$att_id = media_handle_upload( 'profile_image', $vendor_id );
				if ( ! is_wp_error( $att_id ) ) {
					set_post_thumbnail( $vendor_id, $att_id );
				}
			}
			if ( ! empty( $_FILES['cover_image']['name'] ) ) {
				$cover_att_id = media_handle_upload( 'cover_image', $vendor_id );
				if ( ! is_wp_error( $cover_att_id ) ) {
					update_post_meta( $vendor_id, '_nkzmp_vendor_cover_id', (int) $cover_att_id );
				}
			}
		}

		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      'vendor.profile_updated',
				entity_type: 'vendor',
				entity_id:   $vendor_id,
				summary:     sprintf( 'Profile self-update: %s', $name ),
				actor_label: 'vendor_self',
			);
		}

		wp_safe_redirect( add_query_arg( 'nkzmp_msg', 'profile_saved', wc_get_account_endpoint_url( 'vendor-profile' ) ) );
		exit;
	}

	private function redirect_error( string $msg ): void {
		$back = wp_get_referer() ?: wc_get_account_endpoint_url( 'vendor-profile' );
		wp_safe_redirect( add_query_arg( 'nkzmp_err', rawurlencode( $msg ), $back ) );
		exit;
	}
}
