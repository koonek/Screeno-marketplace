<?php
/**
 * Documents – vystavení dokladů k objednávce a dobropisů.
 *
 * Po zaplacení objednávka dostane:
 *  - doklad za každého prodejce – jeho zboží, vystavený jeho jménem,
 *  - doklad Art of život – doprava, servisní poplatek (a prodané poukazy).
 *
 * Doklad se po vystavení uloží jako hotový snímek (údaje dodavatele,
 * odběratele, položky, součty, číslo) a už se nepřepočítává. Když prodejce
 * později změní adresu nebo cenu, staré doklady zůstanou, jak byly – tak
 * to u dokladů musí být.
 *
 * Dárkový poukaz NENÍ položka dokladu: je to způsob úhrady. Zboží,
 * doprava i poplatek jsou na dokladech v plné výši a poukaz je uvedený
 * v úhradě. Prodejce taky dostává zaplaceno v plné výši.
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class Documents {

	public const META        = '_nkzmp_invoices';
	public const ISSUED_META = '_nkzmp_invoices_issued';

	private static ?Documents $instance = null;

	public static function instance(): Documents {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Priorita 2 = po vydání poukazů (1), před e-maily WooCommerce (10).
		add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_issue' ], 2 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_issue' ], 2 );
		add_action( 'woocommerce_order_refunded', [ $this, 'issue_credit_notes' ], 20, 2 );
	}

	/** @return array<int,array> */
	public static function all( \WC_Order $order ): array {
		$docs = $order->get_meta( self::META );
		return is_array( $docs ) ? $docs : [];
	}

	public function maybe_issue( $order_id ): void {
		if ( ! Settings::enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			self::issue( $order );
		}
	}

	/**
	 * Vystaví doklady k objednávce (jednou – opakované volání nic nedělá).
	 *
	 * @return array<int,array> všechny doklady objednávky
	 */
	public static function issue( \WC_Order $order ): array {
		if ( $order->get_meta( self::ISSUED_META ) ) {
			return self::all( $order );
		}

		$issued_at = time();
		$paid      = $order->get_date_paid();
		$duzp      = $paid ? $paid->getTimestamp() : $issued_at;
		$buyer     = self::buyer( $order );
		$currency  = $order->get_currency();
		$docs      = self::all( $order );

		$groups = self::lines_by_issuer( $order );
		foreach ( $groups as $issuer => $lines ) {
			$lines = array_values( array_filter( $lines, static fn( $l ) => abs( (float) $l['total'] ) > 0.0001 ) );
			if ( ! $lines ) {
				continue;
			}
			// Jménem prodejce jen se zmocněním (podmínky pro prodejce).
			if ( $issuer !== 'platform' && ! Mandate::allows( (int) $issuer ) ) {
				$order->add_order_note( sprintf(
					/* translators: %s: prodejce */
					__( 'Doklad za zboží prodejce %s nevystaven – prodejce zatím nepotvrdil zmocnění k samofakturaci, doklad zákazníkovi vystavuje sám.', 'nkz-mp-invoices' ),
					get_the_title( (int) $issuer ) ?: ( '#' . $issuer )
				) );
				continue;
			}
			$docs[] = self::make_doc( 'invoice', (string) $issuer, $lines, $buyer, $order, $issued_at, $duzp, $currency );
		}

		$order->update_meta_data( self::META, $docs );
		$order->update_meta_data( self::ISSUED_META, $issued_at );
		$order->save();

		if ( $docs ) {
			$order->add_order_note( sprintf(
				/* translators: %s: čísla dokladů */
				__( 'Vystaveny doklady: %s', 'nkz-mp-invoices' ),
				implode( ', ', array_map( static fn( $d ) => $d['number'], array_filter( $docs, static fn( $d ) => $d['type'] === 'invoice' ) ) )
			) );
			do_action( 'nkzmp/v1/invoices/issued', $order, $docs );
		}
		return $docs;
	}

	/**
	 * Položky objednávky rozdělené podle dodavatele.
	 *
	 * @return array<string,array<int,array>> 'platform' | vendor_id => lines
	 */
	private static function lines_by_issuer( \WC_Order $order ): array {
		$out = [];

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$vid     = self::item_vendor_id( $item );
			$issuer  = $vid > 0 ? (string) $vid : 'platform';
			$gross   = (float) $item->get_total() + (float) $item->get_total_tax();
			$out[ $issuer ][] = self::line(
				$item->get_name(),
				(float) $item->get_quantity(),
				$gross,
				self::item_rate( $item, $issuer ),
				[ 'item_id' => (int) $item_id ]
			);
		}

		// Doprava a poplatky dodává Art of život.
		$platform_rate = Settings::platform_is_vat_payer() ? (int) Settings::get()['vat_rate'] : null;
		foreach ( $order->get_items( 'shipping' ) as $id => $ship ) {
			$gross = (float) $ship->get_total() + (float) $ship->get_total_tax();
			$out['platform'][] = self::line( $ship->get_name(), 1, $gross, $platform_rate, [ 'item_id' => (int) $id ] );
		}
		foreach ( $order->get_items( 'fee' ) as $id => $fee ) {
			if ( self::is_voucher_fee( $order, $fee ) ) {
				continue; // poukaz je úhrada, ne položka
			}
			$gross = (float) $fee->get_total() + (float) $fee->get_total_tax();
			$out['platform'][] = self::line( $fee->get_name(), 1, $gross, $platform_rate, [ 'item_id' => (int) $id ] );
		}

		// Prodejci napřed, Art of život poslední.
		if ( isset( $out['platform'] ) ) {
			$p = $out['platform'];
			unset( $out['platform'] );
			$out['platform'] = $p;
		}
		return $out;
	}

	private static function item_vendor_id( \WC_Order_Item_Product $item ): int {
		$pid = (int) $item->get_product_id();
		$v   = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
		if ( $v <= 0 ) {
			$v = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		}
		return $v;
	}

	/**
	 * Sazba DPH položky, nebo null (bez DPH / není předmětem daně).
	 */
	private static function item_rate( \WC_Order_Item_Product $item, string $issuer ): ?int {
		$pid = (int) $item->get_product_id();
		// Prodaný dárkový poukaz – víceúčelový poukaz není při prodeji předmětem DPH.
		if ( get_post_meta( $pid, '_nkzmp_is_voucher', true ) === 'yes' ) {
			return null;
		}
		if ( $issuer === 'platform' ) {
			return Settings::platform_is_vat_payer() ? (int) Settings::get()['vat_rate'] : null;
		}
		return VendorBilling::is_vat_payer( (int) $issuer ) ? VendorBilling::product_rate( $pid ) : null;
	}

	private static function is_voucher_fee( \WC_Order $order, $fee ): bool {
		$code = (string) $order->get_meta( '_nkzmp_voucher_code' );
		return $code !== '' && str_contains( (string) $fee->get_name(), $code );
	}

	/** Položka dokladu s rozpočtem DPH z ceny včetně daně. */
	public static function line( string $name, float $qty, float $gross, ?int $rate, array $extra = [] ): array {
		$gross = round( $gross, 2 );
		$base  = $rate === null ? $gross : round( $gross / ( 1 + $rate / 100 ), 2 );
		return [
			'name'  => $name,
			'qty'   => $qty,
			// U dobropisu je záporné množství, cena za kus zůstává kladná
			// (−1 × 500 = −500), jinak by řádek nedával matematicky smysl.
			'unit'  => abs( $qty ) > 0 ? round( $gross / $qty, 2 ) : $gross,
			'total' => $gross,
			'rate'  => $rate,
			'base'  => $base,
			'vat'   => round( $gross - $base, 2 ),
		] + $extra;
	}

	private static function make_doc( string $type, string $issuer, array $lines, array $buyer, \WC_Order $order, int $issued_at, int $duzp, string $currency, string $related = '' ): array {
		$issuer_data = $issuer === 'platform' ? self::platform_issuer() : VendorBilling::issuer( (int) $issuer );
		$total       = round( array_sum( array_column( $lines, 'total' ) ), 2 );

		$vat = [];
		foreach ( $lines as $l ) {
			if ( $l['rate'] === null ) {
				continue;
			}
			$k = (string) $l['rate'];
			$vat[ $k ] ??= [ 'rate' => (int) $l['rate'], 'base' => 0.0, 'vat' => 0.0 ];
			$vat[ $k ]['base'] += $l['base'];
			$vat[ $k ]['vat']  += $l['vat'];
		}

		return [
			'type'         => $type,
			'number'       => Numbering::next( $issuer, $type, $issued_at ),
			'issuer_key'   => $issuer,
			'issuer'       => $issuer_data,
			'on_behalf'    => $issuer !== 'platform', // vystaveno platformou jménem prodejce
			'buyer'        => $buyer,
			'lines'        => $lines,
			'total'        => $total,
			'vat_summary'  => array_values( $vat ),
			'issued_at'    => $issued_at,
			'duzp'         => $duzp,
			'currency'     => $currency,
			'order_number' => (string) $order->get_order_number(),
			'payment'      => self::payment_note( $order ),
			'related'      => $related,
		];
	}

	/** @return array{name:string,street:string,city:string,zip:string,country:string,ico:string,dic:string,vat_payer:bool,registry:string} */
	public static function platform_issuer(): array {
		$s = Settings::get();
		return [
			'name'      => (string) $s['name'],
			'street'    => (string) $s['street'],
			'city'      => (string) $s['city'],
			'zip'       => (string) $s['zip'],
			'country'   => (string) $s['country'],
			'ico'       => (string) $s['ico'],
			'dic'       => (string) $s['dic'],
			'vat_payer' => Settings::platform_is_vat_payer(),
			'registry'  => (string) $s['registry'],
		];
	}

	/** Odběratel – i firma s IČO/DIČ, když je zákazník vyplnil. */
	public static function buyer( \WC_Order $order ): array {
		$person  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$company = trim( (string) $order->get_billing_company() );
		$meta    = static function ( array $keys ) use ( $order ): string {
			foreach ( $keys as $k ) {
				$v = trim( (string) $order->get_meta( $k ) );
				if ( $v !== '' ) {
					return $v;
				}
			}
			return '';
		};
		$countries = ( function_exists( 'WC' ) && WC() && WC()->countries ) ? WC()->countries->get_countries() : [];
		$cc        = (string) $order->get_billing_country();
		return [
			'name'    => $company !== '' ? $company : $person,
			'person'  => $company !== '' ? $person : '',
			'street'  => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'city'    => (string) $order->get_billing_city(),
			'zip'     => (string) $order->get_billing_postcode(),
			'country' => (string) ( $countries[ $cc ] ?? $cc ),
			'ico'     => $meta( [ '_billing_ic', '_billing_ico', 'billing_ic', 'billing_ico', '_billing_company_id' ] ),
			'dic'     => $meta( [ '_billing_dic', 'billing_dic', '_billing_vat', '_billing_vat_number' ] ),
			'email'   => (string) $order->get_billing_email(),
		];
	}

	/** Jak bylo zaplaceno – karta, poukaz. */
	private static function payment_note( \WC_Order $order ): array {
		return [
			'method'  => (string) $order->get_payment_method_title(),
			'paid'    => (float) $order->get_total(),
			'voucher' => (float) $order->get_meta( '_nkzmp_voucher_amount' ),
			'code'    => (string) $order->get_meta( '_nkzmp_voucher_code' ),
		];
	}

	/* ============================================================ dobropisy */

	/**
	 * Refundace → dobropis za každého dotčeného dodavatele.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public function issue_credit_notes( $order_id, $refund_id ): void {
		if ( ! Settings::enabled() ) {
			return;
		}
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}
		if ( ! $order->get_meta( self::ISSUED_META ) ) {
			return; // k objednávce nebyl vystaven doklad – není co opravovat
		}

		$platform_rate = Settings::platform_is_vat_payer() ? (int) Settings::get()['vat_rate'] : null;
		$groups        = [];

		foreach ( $refund->get_items( 'line_item' ) as $ritem ) {
			$orig = $order->get_item( (int) $ritem->get_meta( '_refunded_item_id' ) );
			if ( ! $orig instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$issuer = self::item_vendor_id( $orig ) > 0 ? (string) self::item_vendor_id( $orig ) : 'platform';
			$gross  = (float) $ritem->get_total() + (float) $ritem->get_total_tax(); // záporné
			$groups[ $issuer ][] = self::line( $orig->get_name(), (float) $ritem->get_quantity(), $gross, self::item_rate( $orig, $issuer ) );
		}
		foreach ( $refund->get_items( [ 'shipping', 'fee' ] ) as $ritem ) {
			$gross = (float) $ritem->get_total() + (float) $ritem->get_total_tax();
			if ( abs( $gross ) < 0.0001 ) {
				continue;
			}
			$groups['platform'][] = self::line( $ritem->get_name(), 1, $gross, $platform_rate );
		}

		if ( ! $groups ) {
			$order->add_order_note( __( 'Dobropis nevystaven: refundace neobsahuje položky, takže nejde určit, kterého dodavatele se týká. Pro dobropis vracej peníze po položkách (počty / částky u konkrétních položek).', 'nkz-mp-invoices' ) );
			return;
		}

		$docs    = self::all( $order );
		$by_iss  = [];
		foreach ( $docs as $d ) {
			if ( $d['type'] === 'invoice' ) {
				$by_iss[ $d['issuer_key'] ] = $d['number'];
			}
		}
		$now     = time();
		$numbers = [];
		foreach ( $groups as $issuer => $lines ) {
			// Dobropis jen k vystavenému dokladu (bez zmocnění žádný nebyl).
			if ( $issuer !== 'platform' && empty( $by_iss[ $issuer ] ) ) {
				continue;
			}
			$doc       = self::make_doc( 'credit', (string) $issuer, $lines, self::buyer( $order ), $order, $now, $now, $order->get_currency(), (string) ( $by_iss[ $issuer ] ?? '' ) );
			$doc['refund_id'] = (int) $refund_id;
			$docs[]    = $doc;
			$numbers[] = $doc['number'];
		}
		if ( ! $numbers ) {
			return;
		}
		$order->update_meta_data( self::META, $docs );
		$order->save();
		$order->add_order_note( sprintf( /* translators: %s: čísla */ __( 'Vystaveny dobropisy: %s', 'nkz-mp-invoices' ), implode( ', ', $numbers ) ) );

		do_action( 'nkzmp/v1/invoices/credit_issued', $order, (int) $refund_id, $numbers );
	}
}
