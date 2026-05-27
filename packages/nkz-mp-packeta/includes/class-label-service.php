<?php
/**
 * LabelService – orchestrace zásilek Packety pro objednávku.
 *
 * Objednávka se zbožím od více prodejců = jedna zásilka na prodejce.
 * Hodnota a váha se počítají jen z položek daného prodejce. Výsledek
 * (packetId + barcode) se ukládá na objednávku idempotentně.
 *
 * Odesílatel: Packeta `createPacket` neumí libovolnou adresu odesílatele –
 * odesílatel se v Packetě určuje hodnotou `eshop` (label nakonfigurovaný
 * v účtu). Per-vendor odesílatele tedy řeší vendorův `eshop` label
 * (meta `_nkzmp_packeta_sender_label`), fallback je globální z nastavení.
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class LabelService {

	private static ?LabelService $instance = null;

	public static function instance(): LabelService {
		return self::$instance ??= new self();
	}

	/** Distinct vendor ID, které mají v objednávce fyzickou položku. */
	public function order_vendor_ids( \WC_Order $order ): array {
		$ids = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$vid = $this->item_vendor_id( $item );
			if ( $vid > 0 ) {
				$ids[ $vid ] = true;
			}
		}
		return array_keys( $ids );
	}

	/** Už vytvořená zásilka pro daného prodejce, nebo null. */
	public function get_packet( \WC_Order $order, int $vendor_id ): ?array {
		$all = $order->get_meta( NKZMP_PACKETA_PACKETS_META );
		if ( is_array( $all ) && isset( $all[ $vendor_id ] ) && is_array( $all[ $vendor_id ] ) ) {
			return $all[ $vendor_id ];
		}
		return null;
	}

	/**
	 * Založí (nebo vrátí existující) zásilku pro prodejce v objednávce.
	 *
	 * @return array|\WP_Error ['id','barcode','created'] nebo chyba.
	 */
	public function create_for_vendor( \WC_Order $order, int $vendor_id ) {
		if ( ! Settings::api_configured() ) {
			return new \WP_Error( 'nkzmp_packeta_no_api', __( 'Není vyplněné Packeta API heslo (NKZ Marketplace → Zásilkovna).', 'nkz-mp-packeta' ) );
		}

		// Idempotence – už existuje.
		$existing = $this->get_packet( $order, $vendor_id );
		if ( $existing !== null && ! empty( $existing['id'] ) ) {
			return $existing;
		}

		$point_id = (string) $order->get_meta( NKZMP_PACKETA_POINT_ID_META );
		if ( $point_id === '' ) {
			return new \WP_Error( 'nkzmp_packeta_no_point', __( 'Objednávka nemá vybrané výdejní místo Zásilkovny.', 'nkz-mp-packeta' ) );
		}

		[ $value, $weight ] = $this->value_and_weight( $order, $vendor_id );
		if ( $value <= 0 ) {
			return new \WP_Error( 'nkzmp_packeta_no_items', __( 'Tento prodejce nemá v objednávce žádné položky.', 'nkz-mp-packeta' ) );
		}

		$is_cod = $order->get_payment_method() === 'cod';

		$attrs = [
			'number'    => $order->get_order_number() . '-' . $vendor_id,
			'name'      => $order->get_billing_first_name(),
			'surname'   => $order->get_billing_last_name(),
			'email'     => $order->get_billing_email(),
			'phone'     => $order->get_billing_phone(),
			'addressId' => $point_id,
			'cod'       => $is_cod ? $value : 0,
			'value'     => $value,
			'weight'    => $weight,
			'currency'  => $order->get_currency(),
			'eshop'     => $this->sender_label( $vendor_id ),
		];

		$client = new ApiClient( Settings::api_password() );
		$result = $client->create_packet( $attrs );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$record = [
			'id'      => $result['id'],
			'barcode' => $result['barcode'],
			'created' => time(),
		];

		$all                 = $order->get_meta( NKZMP_PACKETA_PACKETS_META );
		$all                 = is_array( $all ) ? $all : [];
		$all[ $vendor_id ]   = $record;
		$order->update_meta_data( NKZMP_PACKETA_PACKETS_META, $all );
		$order->add_order_note(
			sprintf(
				/* translators: 1: vendor id, 2: barcode */
				__( 'Packeta: založena zásilka pro prodejce #%1$d (kód %2$s).', 'nkz-mp-packeta' ),
				$vendor_id,
				$record['barcode']
			)
		);
		$order->save();

		return $record;
	}

	/**
	 * Zruší zásilku prodejce v Packetě a odstraní ji z objednávky.
	 *
	 * @return true|\WP_Error
	 */
	public function cancel_for_vendor( \WC_Order $order, int $vendor_id ) {
		if ( ! Settings::api_configured() ) {
			return new \WP_Error( 'nkzmp_packeta_no_api', __( 'Není vyplněné Packeta API heslo.', 'nkz-mp-packeta' ) );
		}
		$packet = $this->get_packet( $order, $vendor_id );
		if ( $packet === null || empty( $packet['id'] ) ) {
			return new \WP_Error( 'nkzmp_packeta_no_packet', __( 'Pro tohoto prodejce není založená žádná zásilka.', 'nkz-mp-packeta' ) );
		}

		$client = new ApiClient( Settings::api_password() );
		$res    = $client->cancel_packet( (string) $packet['id'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$all = $order->get_meta( NKZMP_PACKETA_PACKETS_META );
		if ( is_array( $all ) ) {
			unset( $all[ $vendor_id ] );
			$order->update_meta_data( NKZMP_PACKETA_PACKETS_META, $all );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: vendor id, 2: barcode */
				__( 'Packeta: zrušena zásilka prodejce #%1$d (%2$s).', 'nkz-mp-packeta' ),
				$vendor_id,
				(string) ( $packet['barcode'] ?? '' )
			)
		);
		$order->save();
		return true;
	}

	/** Hodnota (zaokrouhleno) a váha (kg) pro položky prodejce. */
	private function value_and_weight( \WC_Order $order, int $vendor_id ): array {
		$value  = 0.0;
		$weight = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			if ( $this->item_vendor_id( $item ) !== $vendor_id ) {
				continue;
			}
			$value += (float) $item->get_total() + (float) $item->get_total_tax();
			$qty    = (float) $item->get_quantity();
			$product = $item->get_product();
			if ( $product instanceof \WC_Product && $product->get_weight() !== '' ) {
				$weight += (float) $product->get_weight() * $qty;
			}
		}
		if ( $weight <= 0 ) {
			$weight = Settings::default_weight();
		}
		return [ round( $value, 2 ), round( $weight, 3 ) ];
	}

	private function item_vendor_id( \WC_Order_Item_Product $item ): int {
		$pid = $item->get_product_id();
		$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		if ( $vid <= 0 ) {
			$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
		}
		return $vid;
	}

	/** Per-vendor eshop label, fallback globální z nastavení. */
	private function sender_label( int $vendor_id ): string {
		$label = (string) get_post_meta( $vendor_id, NKZMP_PACKETA_VENDOR_SENDER_LABEL_META, true );
		return $label !== '' ? $label : Settings::sender_label();
	}
}
