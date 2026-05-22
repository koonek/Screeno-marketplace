<?php
/**
 * ProductSubmitController – POST handler na admin-post.php
 *
 * Vytváří / aktualizuje WC produkt jménem vendora. Vždy nastaví status
 * na pending — admin musí schválit.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class ProductSubmitController {

	public const ACTION = 'nkzmp_vd_product_submit';
	public const NONCE  = 'nkzmp_vd_product_submit';

	private static ?ProductSubmitController $instance = null;

	public static function instance(): ProductSubmitController {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		try {
			$this->do_handle();
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] product submit fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			$this->redirect_error( sprintf( __( 'Chyba při ukládání: %s', 'nkz-mp-vendor-dashboard' ), $e->getMessage() ) );
		}
	}

	private function do_handle(): void {
		check_admin_referer( self::NONCE );

		if ( ! is_user_logged_in() || ! VendorContext::user_is_vendor() ) {
			$this->redirect_error( __( 'Nepřihlášený prodejce.', 'nkz-mp-vendor-dashboard' ) );
		}

		$vendor_id = VendorContext::current_vendor_id();
		if ( $vendor_id <= 0 ) {
			$this->redirect_error( __( 'Účet není propojený s žádným prodejcem.', 'nkz-mp-vendor-dashboard' ) );
		}

		$is_edit    = isset( $_POST['product_id'] );
		$product_id = $is_edit ? (int) $_POST['product_id'] : 0;
		$title      = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$short      = wp_kses_post( wp_unslash( $_POST['short_description'] ?? '' ) );
		$desc       = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
		$price      = (string) ( $_POST['regular_price'] ?? '' );
		$sale       = (string) ( $_POST['sale_price'] ?? '' );
		$manage     = ! empty( $_POST['manage_stock'] );
		$qty        = isset( $_POST['stock_quantity'] ) && $_POST['stock_quantity'] !== '' ? (int) $_POST['stock_quantity'] : null;
		$cats       = isset( $_POST['categories'] ) ? array_map( 'intval', (array) $_POST['categories'] ) : [];

		if ( $title === '' || $price === '' || ! is_numeric( $price ) || (float) $price < 0 ) {
			$this->redirect_error( __( 'Vyplň název a platnou cenu.', 'nkz-mp-vendor-dashboard' ) );
		}

		// Edit ownership check.
		if ( $is_edit ) {
			$existing = wc_get_product( $product_id );
			if ( ! $existing || ! $this->owns( $product_id, $vendor_id ) ) {
				$this->redirect_error( __( 'Tento produkt nemůžeš upravovat.', 'nkz-mp-vendor-dashboard' ) );
			}
			if ( $existing->get_status() === 'publish' ) {
				$this->redirect_error( __( 'Publikované produkty nelze měnit přes panel.', 'nkz-mp-vendor-dashboard' ) );
			}
			$product = $existing;
		} else {
			$product = new \WC_Product_Simple();
		}

		$product->set_name( $title );
		$product->set_short_description( $short );
		$product->set_description( $desc );
		$product->set_regular_price( $price );
		if ( $sale !== '' && is_numeric( $sale ) ) {
			$product->set_sale_price( $sale );
		} else {
			$product->set_sale_price( '' );
		}
		$product->set_manage_stock( $manage );
		if ( $manage && $qty !== null ) {
			$product->set_stock_quantity( $qty );
			$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
		} else {
			$product->set_stock_status( 'instock' );
		}
		$product->set_category_ids( $cats );
		// Vendor podmínky: vždy pending dokud admin nepublikuje.
		$product->set_status( 'pending' );

		// Vendor jako post_author aby WP věděl kdo to napsal (pro list filtering).
		if ( ! $is_edit ) {
			$product->set_props( [ 'author' => get_current_user_id() ] );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			error_log( '[NKZMP] product->save() returned 0 for vendor=' . $vendor_id . ' user=' . get_current_user_id() );
			$this->redirect_error( __( 'Produkt se nepodařilo uložit. Zkontroluj prosím všechna pole nebo se ozvi na podporu.', 'nkz-mp-vendor-dashboard' ) );
		}

		// Vendor ownership meta (both mirrors).
		update_post_meta( $product_id, '_nkzmp_vendor_id', $vendor_id );
		update_post_meta( $product_id, '_nkv_vendor_id', $vendor_id );

		// Image uploads (povinné jen při novém).
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( ! empty( $_FILES['featured_image']['name'] ) ) {
			$att_id = media_handle_upload( 'featured_image', $product_id );
			if ( is_wp_error( $att_id ) ) {
				error_log( '[NKZMP] featured image upload failed: ' . $att_id->get_error_message() );
			} else {
				set_post_thumbnail( $product_id, $att_id );
			}
		}

		$gallery_ids = [];
		for ( $i = 1; $i <= 4; $i++ ) {
			$field = 'gallery_' . $i;
			if ( ! empty( $_FILES[ $field ]['name'] ) ) {
				$att_id = media_handle_upload( $field, $product_id );
				if ( is_wp_error( $att_id ) ) {
					error_log( '[NKZMP] gallery ' . $i . ' upload failed: ' . $att_id->get_error_message() );
				} else {
					$gallery_ids[] = (int) $att_id;
				}
			}
		}
		if ( ! empty( $gallery_ids ) ) {
			// Připojit k existujícím (pokud edit).
			$existing_gallery = $is_edit ? array_map( 'intval', explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) ) : [];
			$merged = array_values( array_unique( array_filter( array_merge( $existing_gallery, $gallery_ids ) ) ) );
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $merged ) );
		}

		// Audit + hook.
		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      $is_edit ? 'product.submitted_edit' : 'product.submitted_new',
				entity_type: 'product',
				entity_id:   $product_id,
				summary:     sprintf( '%s: %s', $title, get_userdata( get_current_user_id() )->user_login ?? '?' ),
				payload:     [ 'vendor_id' => $vendor_id, 'price' => $price, 'has_image' => ! empty( $_FILES['featured_image']['name'] ) ],
				actor_label: 'vendor_self',
			);
		}
		do_action( 'nkzmp/v1/dashboard/product_submitted', $product_id, $vendor_id, $is_edit );

		// E-maily vendor + admin.
		ProductEmails::on_submitted( $product_id, $vendor_id, $is_edit );

		wp_safe_redirect( add_query_arg(
			[ 'nkzmp_msg' => $is_edit ? 'updated' : 'submitted' ],
			wc_get_account_endpoint_url( 'vendor-products' )
		) );
		exit;
	}

	private function owns( int $product_id, int $vendor_id ): bool {
		if ( (int) get_post_meta( $product_id, '_nkzmp_vendor_id', true ) === $vendor_id ) {
			return true;
		}
		return (int) get_post_meta( $product_id, '_nkv_vendor_id', true ) === $vendor_id;
	}

	private function redirect_error( string $msg ): void {
		$back = wp_get_referer() ?: wc_get_account_endpoint_url( 'vendor-products' );
		wp_safe_redirect( add_query_arg( 'nkzmp_err', rawurlencode( $msg ), $back ) );
		exit;
	}
}
