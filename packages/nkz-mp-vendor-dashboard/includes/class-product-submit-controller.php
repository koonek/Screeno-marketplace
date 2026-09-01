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
		// Diagnostic – uvidíš v debug.log že akce skutečně dorazila.
		error_log( sprintf(
			'[NKZMP] product submit invoked. user=%d, has_files=%s, post_keys=%s',
			get_current_user_id(),
			! empty( $_FILES ) ? 'yes' : 'no',
			implode( ',', array_keys( $_POST ) )
		) );
		try {
			$this->do_handle();
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] product submit fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			$this->redirect_error( sprintf( __( 'Chyba: %s', 'nkz-mp-vendor-dashboard' ), $e->getMessage() ) );
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

		// Přidání NOVÉHO produktu je zamčené dokud prodejce nedokončí Stripe
		// (KYC) a nezaplatí členství. Editace existujícího je povolená.
		if ( ! $is_edit && ! VendorContext::can_add_products( $vendor_id ) ) {
			$this->redirect_error( __( 'Přidávání produktů se odemkne po dokončení ověření Stripe a aktivaci členství. Dokonči prosím oba kroky v přehledu.', 'nkz-mp-vendor-dashboard' ) );
		}
		$title      = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$short      = wp_kses_post( wp_unslash( $_POST['short_description'] ?? '' ) );
		$desc       = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
		$price      = (string) ( $_POST['regular_price'] ?? '' );
		$sale       = (string) ( $_POST['sale_price'] ?? '' );
		// Sklad: množství je povinné, pokud prodejce neoznačí „na objednávku".
		// Bez toho WC prodá libovolný počet kusů (reálně: objednávka na 2 ks,
		// prodejkyně měla jeden).
		$unlimited  = ! empty( $_POST['stock_unlimited'] );
		// Lhůta na výrobu (jen u „na objednávku"). Validujeme proti nabídce.
		$preorder_days = 0;
		if ( $unlimited ) {
			$raw_days      = isset( $_POST['preorder_days'] ) ? (int) $_POST['preorder_days'] : 0;
			$allowed_days  = array_keys( ShipDeadline::preorder_options() );
			$preorder_days = in_array( $raw_days, $allowed_days, true ) ? $raw_days : (int) min( $allowed_days );
		}
		$manage     = ! $unlimited;
		$qty        = isset( $_POST['stock_quantity'] ) && $_POST['stock_quantity'] !== '' ? (int) $_POST['stock_quantity'] : null;
		$requires_shipping = ! empty( $_POST['requires_shipping'] );
		$ship_override     = isset( $_POST['shipping_override'] ) && $_POST['shipping_override'] !== '' && is_numeric( $_POST['shipping_override'] ) ? (float) $_POST['shipping_override'] : null;
		$cats       = isset( $_POST['categories'] ) ? array_map( 'intval', (array) $_POST['categories'] ) : [];

		// ── Varianty (1 atribut + cena/sklad per volba) ──────────────────
		$has_variations = ! empty( $_POST['has_variations'] );
		$var_attr       = sanitize_text_field( wp_unslash( $_POST['variation_attribute'] ?? '' ) );
		$variations     = [];
		if ( $has_variations ) {
			$labels = isset( $_POST['var_label'] ) ? (array) wp_unslash( $_POST['var_label'] ) : [];
			$prices = isset( $_POST['var_price'] ) ? (array) wp_unslash( $_POST['var_price'] ) : [];
			$sales  = isset( $_POST['var_sale'] ) ? (array) wp_unslash( $_POST['var_sale'] ) : [];
			$stocks = isset( $_POST['var_stock'] ) ? (array) wp_unslash( $_POST['var_stock'] ) : [];
			foreach ( $labels as $i => $label ) {
				$label = sanitize_text_field( $label );
				$vp    = (string) ( $prices[ $i ] ?? '' );
				if ( $label === '' || $vp === '' || ! is_numeric( $vp ) || (float) $vp < 0 ) {
					continue; // neúplný řádek přeskočíme
				}
				$vs = (string) ( $sales[ $i ] ?? '' );
				$vq = isset( $stocks[ $i ] ) && $stocks[ $i ] !== '' ? (int) $stocks[ $i ] : null;
				$variations[] = [
					'label' => $label,
					'price' => $vp,
					'sale'  => ( $vs !== '' && is_numeric( $vs ) ) ? $vs : '',
					'stock' => $vq,
				];
			}
		}
		$use_variations = $has_variations && $var_attr !== '' && count( $variations ) >= 1;

		// Validace: u variant nepožadujeme základní cenu (definují ji varianty).
		if ( $title === '' ) {
			$this->redirect_error( __( 'Vyplň název produktu.', 'nkz-mp-vendor-dashboard' ) );
		}
		if ( ! $use_variations && ( $price === '' || ! is_numeric( $price ) || (float) $price < 0 ) ) {
			$this->redirect_error( __( 'Vyplň platnou cenu (nebo přidej varianty).', 'nkz-mp-vendor-dashboard' ) );
		}
		if ( $has_variations && ! $use_variations ) {
			$this->redirect_error( __( 'U variant vyplň název atributu (např. „Velikost") a alespoň jednu variantu s cenou.', 'nkz-mp-vendor-dashboard' ) );
		}
		// Množství povinné (kromě tvorby na objednávku). Kontrola i na serveru –
		// atribut `required` v prohlížeči jde obejít.
		if ( ! $unlimited ) {
			if ( $use_variations ) {
				foreach ( $variations as $v ) {
					if ( $v['stock'] === null ) {
						$this->redirect_error( __( 'U každé varianty vyplň počet kusů skladem, nebo zaškrtni „Vyrábím na objednávku".', 'nkz-mp-vendor-dashboard' ) );
					}
				}
			} elseif ( $qty === null || $qty < 0 ) {
				$this->redirect_error( __( 'Vyplň počet kusů skladem, nebo zaškrtni „Vyrábím na objednávku". Bez toho by si někdo mohl objednat víc kusů, než máš.', 'nkz-mp-vendor-dashboard' ) );
			}
		}

		// Edit ownership check.
		$was_publish = false;
		if ( $is_edit ) {
			$existing = wc_get_product( $product_id );
			if ( ! $existing || ! $this->owns( $product_id, $vendor_id ) ) {
				$this->redirect_error( __( 'Tento produkt nemůžeš upravovat.', 'nkz-mp-vendor-dashboard' ) );
			}
			$was_publish = $existing->get_status() === 'publish';
		}

		// Instantiace podle typu. Při editu s přepnutím typu nastavíme term a
		// vezmeme čerstvý objekt, aby WC pracoval se správnou třídou.
		$target_type = $use_variations ? 'variable' : 'simple';
		if ( $is_edit ) {
			wp_set_object_terms( $product_id, $target_type, 'product_type' );
			$product = $use_variations ? new \WC_Product_Variable( $product_id ) : new \WC_Product_Simple( $product_id );
		} else {
			$product = $use_variations ? new \WC_Product_Variable() : new \WC_Product_Simple();
		}

		$product->set_name( $title );
		$product->set_short_description( $short );
		$product->set_description( $desc );
		$product->set_category_ids( $cats );

		if ( $use_variations ) {
			// Cenu/sklad drží varianty. Nastavíme atribut pro varianty.
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( $var_attr );
			$attribute->set_options( array_values( array_map( static fn( $v ) => $v['label'], $variations ) ) );
			$attribute->set_position( 0 );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$product->set_attributes( [ $attribute ] );
		} else {
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
			$product->set_virtual( ! $requires_shipping );
		}

		// Stav:
		//  - nový produkt → pending (admin schválí)
		//  - edit publikovaného → zůstává publish (důvěřujeme aktivnímu vendorovi),
		//    pokud filter nevyžaduje re-approval
		//  - edit pending/draft → pending
		$reapprove = (bool) apply_filters( 'nkzmp/v1/dashboard/edit_needs_reapproval', false );
		if ( $is_edit && $was_publish && ! $reapprove ) {
			$product->set_status( 'publish' );
		} else {
			$product->set_status( 'pending' );
		}

		// Vendor jako post_author aby WP věděl kdo to napsal (pro list filtering).
		if ( ! $is_edit ) {
			$product->set_props( [ 'author' => get_current_user_id() ] );
		}

		$product_id = $product->save();

		error_log( sprintf( '[NKZMP] product->save() returned %d for vendor=%d', (int) $product_id, $vendor_id ) );

		if ( ! $product_id ) {
			error_log( '[NKZMP] product->save() returned 0. Last DB error: ' . ( $GLOBALS['wpdb']->last_error ?? 'none' ) );
			$this->redirect_error( __( 'Produkt se nepodařilo uložit. Zkontroluj prosím všechna pole nebo se ozvi na podporu.', 'nkz-mp-vendor-dashboard' ) );
		}

		// Vendor ownership meta (both mirrors).
		update_post_meta( $product_id, '_nkzmp_vendor_id', $vendor_id );
		update_post_meta( $product_id, '_nkv_vendor_id', $vendor_id );

		// Shipping flag. Digital = virtual (WC nepožaduje dopravu).
		update_post_meta( $product_id, '_nkzmp_requires_shipping', $requires_shipping ? 'yes' : 'no' );
		// Lhůta „na objednávku" – prázdné meta = skladová položka (5 dní).
		if ( $preorder_days > 0 ) {
			update_post_meta( $product_id, ShipDeadline::PREORDER_META, $preorder_days );
		} else {
			delete_post_meta( $product_id, ShipDeadline::PREORDER_META );
		}
		// Per-produkt override poštovného (prázdné = smazat → použije se paušál).
		// Hodnotu pod minimem zvedneme na minimum (Rate::set_… clampuje).
		if ( $ship_override === null ) {
			delete_post_meta( $product_id, '_nkzmp_shipping_override' );
		} elseif ( class_exists( \NKZMP\Shipping\Rate::class ) ) {
			\NKZMP\Shipping\Rate::set_product_shipping_override( $product_id, $ship_override );
		} else {
			update_post_meta( $product_id, '_nkzmp_shipping_override', $ship_override );
		}
		// (virtual je nastavené přímo na produktu/variantách výše.)

		// ── Vytvoření variant (children) ─────────────────────────────────
		if ( $use_variations ) {
			// Smaž stávající varianty (edit) a vytvoř nové ze zadání.
			$existing_children = get_children( [
				'post_parent' => $product_id,
				'post_type'   => 'product_variation',
				'numberposts' => -1,
				'fields'      => 'ids',
			] );
			foreach ( $existing_children as $cid ) {
				wp_delete_post( (int) $cid, true );
			}

			$attr_key = sanitize_title( $var_attr );
			foreach ( $variations as $v ) {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_attributes( [ $attr_key => $v['label'] ] );
				$variation->set_regular_price( (string) $v['price'] );
				$variation->set_sale_price( $v['sale'] !== '' ? (string) $v['sale'] : '' );
				if ( $v['stock'] !== null ) {
					$variation->set_manage_stock( true );
					$variation->set_stock_quantity( (int) $v['stock'] );
					$variation->set_stock_status( (int) $v['stock'] > 0 ? 'instock' : 'outofstock' );
				} else {
					$variation->set_manage_stock( false );
					$variation->set_stock_status( 'instock' );
				}
				$variation->set_virtual( ! $requires_shipping );
				$variation->save();
			}
			// Přepočítá cenový rozsah + skladový stav parenta z variant.
			\WC_Product_Variable::sync( $product_id );
		} elseif ( $is_edit ) {
			// Přepnutí zpět na simple – ukliď osiřelé varianty.
			$orphans = get_children( [
				'post_parent' => $product_id,
				'post_type'   => 'product_variation',
				'numberposts' => -1,
				'fields'      => 'ids',
			] );
			foreach ( $orphans as $cid ) {
				wp_delete_post( (int) $cid, true );
			}
		}

		// Image uploads (povinné jen při novém).
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Chyby nahrávání sbíráme a ukážeme prodejci (dřív se jen logovaly –
		// produkt se uložil bez fotky a nikdo nevěděl proč). Typicky HEIC.
		$upload_errors = [];

		if ( ! empty( $_FILES['featured_image']['name'] ) ) {
			$att_id = media_handle_upload( 'featured_image', $product_id );
			if ( is_wp_error( $att_id ) ) {
				error_log( '[NKZMP] featured image upload failed: ' . $att_id->get_error_message() );
				$upload_errors[] = sprintf(
					/* translators: %s: důvod */
					__( 'Hlavní fotka se nenahrála: %s', 'nkz-mp-vendor-dashboard' ),
					$att_id->get_error_message()
				);
			} else {
				set_post_thumbnail( $product_id, $att_id );
			}
		}

		// Galerie: sloty jsou poziční – slot N odpovídá N-té fotce v galerii.
		// Nahrání do slotu tu fotku PŘEPÍŠE (dřív se přidávala na konec, což
		// mátlo). Zaškrtnutí „Odebrat" ji vyhodí. Obojí lze kombinovat.
		$gallery = $is_edit
			? array_values( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) ) ) )
			: [];

		$remove_ids = [];
		if ( ! empty( $_POST['gallery_remove'] ) && is_array( $_POST['gallery_remove'] ) ) {
			$remove_ids = array_filter( array_map( 'intval', (array) wp_unslash( $_POST['gallery_remove'] ) ) );
		}

		$gallery_touched = ! empty( $remove_ids );
		for ( $i = 1; $i <= 4; $i++ ) {
			$field = 'gallery_' . $i;
			if ( empty( $_FILES[ $field ]['name'] ) ) {
				continue;
			}
			$att_id = media_handle_upload( $field, $product_id );
			if ( is_wp_error( $att_id ) ) {
				error_log( '[NKZMP] gallery ' . $i . ' upload failed: ' . $att_id->get_error_message() );
				$upload_errors[] = sprintf(
					/* translators: 1: číslo slotu, 2: důvod */
					__( 'Fotka v poli Galerie %1$d se nenahrála: %2$s', 'nkz-mp-vendor-dashboard' ),
					$i,
					$att_id->get_error_message()
				);
				continue;
			}
			$gallery_touched = true;
			$slot            = $i - 1;
			if ( isset( $gallery[ $slot ] ) ) {
				$gallery[ $slot ] = (int) $att_id; // přepis konkrétního slotu
			} else {
				$gallery[] = (int) $att_id;        // volný slot → přidat
			}
		}

		if ( $gallery_touched ) {
			// Odebíráme jen z galerie produktu; soubor v Médiích zůstává
			// (mohl by být použitý jinde) – bezpečnější než mazat natvrdo.
			if ( ! empty( $remove_ids ) ) {
				$gallery = array_diff( $gallery, $remove_ids );
			}
			$final = array_values( array_unique( array_filter( $gallery ) ) );
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $final ) );
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

		$stayed_live = $is_edit && $was_publish && get_post_status( $product_id ) === 'publish';

		// E-maily vendor + admin (jen když jde produkt na schválení, ne při live editu).
		if ( ! $stayed_live ) {
			ProductEmails::on_submitted( $product_id, $vendor_id, $is_edit );
		}

		// Pročistit cache – jinak by se stará verze stránky (a stará fotka)
		// servírovala dál a prodejce by si myslel, že se nahrání nepovedlo.
		CacheFlush::purge();

		$msg  = $stayed_live ? 'live_updated' : ( $is_edit ? 'updated' : 'submitted' );
		$args = [ 'nkzmp_msg' => $msg ];
		if ( ! empty( $upload_errors ) ) {
			$args['nkzmp_upload_err'] = rawurlencode( implode( ' | ', $upload_errors ) );
		}
		wp_safe_redirect( add_query_arg( $args, wc_get_account_endpoint_url( 'vendor-products' ) ) );
		exit;
	}

	/* ── Unpublish / Delete ──────────────────────────────────────── */

	public function init_actions(): void {
		add_action( 'admin_post_nkzmp_vd_product_unpublish', [ $this, 'unpublish' ] );
		add_action( 'admin_post_nkzmp_vd_product_delete', [ $this, 'delete' ] );
	}

	public function unpublish(): void {
		[ $vendor_id, $product_id ] = $this->require_owned_product();
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->set_status( 'draft' );
			$product->save();
			$this->audit_action( 'product.unpublished', $product_id, $vendor_id );
		}
		$this->redirect_ok( 'unpublished' );
	}

	public function delete(): void {
		[ $vendor_id, $product_id ] = $this->require_owned_product();
		wp_trash_post( $product_id );
		$this->audit_action( 'product.deleted', $product_id, $vendor_id );
		$this->redirect_ok( 'deleted' );
	}

	/**
	 * @return array{0:int,1:int} [vendor_id, product_id]
	 */
	private function require_owned_product(): array {
		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		check_admin_referer( 'nkzmp_vd_product_action_' . $product_id );
		if ( ! is_user_logged_in() || ! VendorContext::user_is_vendor() ) {
			$this->redirect_error( __( 'Nepřihlášený prodejce.', 'nkz-mp-vendor-dashboard' ) );
		}
		$vendor_id = VendorContext::current_vendor_id();
		if ( $vendor_id <= 0 || ! $this->owns( $product_id, $vendor_id ) ) {
			$this->redirect_error( __( 'Tento produkt ti nepatří.', 'nkz-mp-vendor-dashboard' ) );
		}
		return [ $vendor_id, $product_id ];
	}

	private function audit_action( string $action, int $product_id, int $vendor_id ): void {
		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      $action,
				entity_type: 'product',
				entity_id:   $product_id,
				summary:     sprintf( 'vendor #%d', $vendor_id ),
				actor_label: 'vendor_self',
			);
		}
	}

	private function redirect_ok( string $msg ): void {
		wp_safe_redirect( add_query_arg( 'nkzmp_msg', $msg, wc_get_account_endpoint_url( 'vendor-products' ) ) );
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
