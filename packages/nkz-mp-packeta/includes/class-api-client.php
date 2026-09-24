<?php
/**
 * ApiClient – komunikace s Packeta REST API (zasilkovna.cz/api/rest).
 *
 * Používá API heslo (apiPassword), což je JINÝ údaj než widget API klíč.
 * Pokrývá `createPacket` (založení zásilky) a `packetLabelPdf` (štítek).
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class ApiClient {

	private const ENDPOINT = 'https://www.zasilkovna.cz/api/rest';

	private string $password;

	public function __construct( string $api_password ) {
		$this->password = $api_password;
	}

	/**
	 * Založí zásilku. Vrací ['id' => string, 'barcode' => string] nebo WP_Error.
	 *
	 * @param array $attrs Atributy zásilky (number, name, surname, email, phone,
	 *                     addressId, value, weight, cod, eshop, currency).
	 * @return array|\WP_Error
	 */
	public function create_packet( array $attrs ) {
		$inner = '';
		foreach ( $attrs as $key => $val ) {
			if ( $val === null || $val === '' ) {
				continue;
			}
			$inner .= sprintf( '<%1$s>%2$s</%1$s>', $key, htmlspecialchars( (string) $val, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) );
		}

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<createPacket>'
			. '<apiPassword>' . htmlspecialchars( $this->password, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</apiPassword>'
			. '<packetAttributes>' . $inner . '</packetAttributes>'
			. '</createPacket>';

		$xml = $this->post( $body );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		if ( (string) $xml->status !== 'ok' ) {
			return new \WP_Error(
				'nkzmp_packeta_api_fault',
				$this->fault_message( $xml )
			);
		}

		return [
			'id'      => (string) $xml->result->id,
			'barcode' => (string) $xml->result->barcode,
		];
	}

	/**
	 * Vrátí PDF štítku (raw bajty) pro zásilku, nebo WP_Error.
	 *
	 * @return string|\WP_Error
	 */
	public function label_pdf( string $packet_id, string $format = 'A6 on A4', int $offset = 0 ) {
		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<packetLabelPdf>'
			. '<apiPassword>' . htmlspecialchars( $this->password, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</apiPassword>'
			. '<packetId>' . htmlspecialchars( $packet_id, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</packetId>'
			. '<format>' . htmlspecialchars( $format, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</format>'
			. '<offset>' . (int) $offset . '</offset>'
			. '</packetLabelPdf>';

		$xml = $this->post( $body );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		if ( (string) $xml->status !== 'ok' ) {
			return new \WP_Error( 'nkzmp_packeta_api_fault', $this->fault_message( $xml ) );
		}

		$pdf = base64_decode( (string) $xml->result, true );
		if ( $pdf === false || $pdf === '' ) {
			return new \WP_Error( 'nkzmp_packeta_label_decode', __( 'Packeta vrátila prázdný štítek.', 'nkz-mp-packeta' ) );
		}
		return $pdf;
	}

	/**
	 * Zruší zásilku. Vrací true nebo WP_Error.
	 *
	 * @return true|\WP_Error
	 */
	public function cancel_packet( string $packet_id ) {
		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<cancelPacket>'
			. '<apiPassword>' . htmlspecialchars( $this->password, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</apiPassword>'
			. '<packetId>' . htmlspecialchars( $packet_id, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</packetId>'
			. '</cancelPacket>';

		$xml = $this->post( $body );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		if ( (string) $xml->status !== 'ok' ) {
			return new \WP_Error( 'nkzmp_packeta_api_fault', $this->fault_message( $xml ) );
		}
		return true;
	}

	/**
	 * Zjistí aktuální stav zásilky.
	 *
	 * Vrací normalizovaný záznam:
	 *   state → jeden z self::STATE_* (co z toho plyne pro nás)
	 *   code  → číselný statusCode Zásilkovny
	 *   text  → originální codeText (např. „delivered")
	 *   at    → timestamp poslední změny, 0 když ho Packeta nedala
	 *
	 * @return array{state:string,code:int,text:string,at:int}|\WP_Error
	 */
	public function packet_status( string $packet_id ) {
		$packet_id = trim( $packet_id );
		if ( $packet_id === '' ) {
			return new \WP_Error( 'nkzmp_packeta_no_packet', __( 'Chybí ID zásilky.', 'nkz-mp-packeta' ) );
		}

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<packetStatus>'
			. '<apiPassword>' . htmlspecialchars( $this->password, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</apiPassword>'
			. '<packetId>' . htmlspecialchars( $packet_id, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</packetId>'
			. '</packetStatus>';

		$xml = $this->post( $body );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		if ( (string) $xml->status !== 'ok' ) {
			return new \WP_Error( 'nkzmp_packeta_api_fault', $this->fault_message( $xml ) );
		}

		$text = isset( $xml->result->codeText ) ? (string) $xml->result->codeText : '';
		$code = isset( $xml->result->statusCode ) ? (int) $xml->result->statusCode : 0;
		$when = isset( $xml->result->dateTime ) ? strtotime( (string) $xml->result->dateTime ) : false;

		return [
			'state' => self::map_state( $text, $code ),
			'code'  => $code,
			'text'  => $text,
			'at'    => $when ?: 0,
		];
	}

	/* Stavy, se kterými pracujeme dál. */
	public const STATE_PENDING   = 'pending';   // štítek vytvořen, zásilka fyzicky nepodaná
	public const STATE_DISPATCHED = 'dispatched'; // prodejce podal – první naskenování
	public const STATE_DELIVERED = 'delivered'; // vyzvednuto zákazníkem
	public const STATE_RETURNED  = 'returned';  // vrací se / vráceno prodejci
	public const STATE_CANCELLED = 'cancelled'; // zásilka zrušena

	/**
	 * Převede stav Zásilkovny na jeden z našich.
	 *
	 * Záměrně stavíme jen na stavech, které známe jistě, a VŠECHNO ostatní
	 * bereme jako „podáno". Zásilkovna svoje mezistavy občas přidává
	 * (celnice, pokus o doručení, přeprava mezi depy) a kdybychom je museli
	 * vyjmenovat, každý nový by znamenal nevyplaceného prodejce. Jediný stav,
	 * který podání NEznamená, je „received data" – to je jen zaevidovaný
	 * štítek, se kterým prodejce ještě nikam nešel.
	 */
	public static function map_state( string $code_text, int $code = 0 ): string {
		$t = strtolower( trim( $code_text ) );

		if ( $t === 'received data' || 1 === $code ) {
			return self::STATE_PENDING;
		}
		if ( str_contains( $t, 'cancel' ) ) {
			return self::STATE_CANCELLED;
		}
		if ( $t === 'delivered' || str_contains( $t, 'delivered to' ) ) {
			return self::STATE_DELIVERED;
		}
		if ( str_contains( $t, 'return' ) || str_contains( $t, 'posted back' ) || str_contains( $t, 'rejected' ) ) {
			return self::STATE_RETURNED;
		}
		if ( $t === '' && 0 === $code ) {
			return self::STATE_PENDING; // nic jsme se nedozvěděli – nehýbeme s ničím
		}

		return self::STATE_DISPATCHED;
	}

	/** Český popis stavu pro poznámku u objednávky a admin výpisy. */
	public static function state_label( string $state ): string {
		switch ( $state ) {
			case self::STATE_DISPATCHED:
				return __( 'podáno u Zásilkovny', 'nkz-mp-packeta' );
			case self::STATE_DELIVERED:
				return __( 'doručeno zákazníkovi', 'nkz-mp-packeta' );
			case self::STATE_RETURNED:
				return __( 'vrací se prodejci', 'nkz-mp-packeta' );
			case self::STATE_CANCELLED:
				return __( 'zrušeno', 'nkz-mp-packeta' );
			default:
				return __( 'čeká na podání', 'nkz-mp-packeta' );
		}
	}

	/**
	 * Ověří, že odesílatel (eshop label) v účtu Zásilkovny existuje.
	 *
	 * Používá `senderGetReturnRouting` – vrací data pro existujícího odesílatele
	 * a fault pro neznámého. Díky tomu poznáme špatný název hned při nastavení,
	 * ne až když prodejce nemůže odeslat objednávku.
	 *
	 * @return true|\WP_Error
	 */
	public function validate_sender( string $sender_label ) {
		$sender_label = trim( $sender_label );
		if ( $sender_label === '' ) {
			return new \WP_Error( 'nkzmp_packeta_sender_empty', __( 'Název odesílatele je prázdný.', 'nkz-mp-packeta' ) );
		}
		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<senderGetReturnRouting>'
			. '<apiPassword>' . htmlspecialchars( $this->password, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</apiPassword>'
			. '<senderLabel>' . htmlspecialchars( $sender_label, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</senderLabel>'
			. '</senderGetReturnRouting>';

		$xml = $this->post( $body );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		if ( (string) $xml->status !== 'ok' ) {
			return new \WP_Error( 'nkzmp_packeta_sender_invalid', $this->fault_message( $xml ) );
		}
		return true;
	}

	/**
	 * @return \SimpleXMLElement|\WP_Error
	 */
	private function post( string $body ) {
		$res = wp_remote_post( self::ENDPOINT, [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'text/xml; charset=UTF-8' ],
			'body'    => $body,
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );

		if ( $raw === '' ) {
			return new \WP_Error( 'nkzmp_packeta_empty', sprintf( __( 'Packeta nevrátila odpověď (HTTP %d).', 'nkz-mp-packeta' ), $code ) );
		}

		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $raw );
		libxml_use_internal_errors( $prev );

		if ( $xml === false ) {
			return new \WP_Error( 'nkzmp_packeta_bad_xml', __( 'Packeta vrátila neplatnou odpověď.', 'nkz-mp-packeta' ) );
		}
		return $xml;
	}

	private function fault_message( \SimpleXMLElement $xml ): string {
		$parts = [];
		if ( isset( $xml->string ) && (string) $xml->string !== '' ) {
			$parts[] = (string) $xml->string;
		}
		if ( isset( $xml->fault ) && (string) $xml->fault !== '' ) {
			$parts[] = (string) $xml->fault;
		}
		// Detailní chyby u jednotlivých atributů.
		if ( isset( $xml->detail->attributes->fault ) ) {
			foreach ( $xml->detail->attributes->fault as $f ) {
				$name = isset( $f->name ) ? (string) $f->name : '';
				$msg  = isset( $f->fault ) ? (string) $f->fault : (string) $f;
				$parts[] = trim( $name . ': ' . $msg, ': ' );
			}
		}
		$msg = implode( ' — ', array_filter( $parts ) );
		if ( $msg === '' ) {
			return __( 'Zásilkovna odmítla požadavek (neznámá chyba).', 'nkz-mp-packeta' );
		}
		return self::humanize( $msg );
	}

	/**
	 * Přeloží časté chyby Zásilkovny do češtiny s návodem, co udělat.
	 *
	 * Původní hlášky jsou anglicky a technické („Sender is not given") –
	 * prodejce z nich nepozná, že se má opravit název odesílatele v nastavení.
	 * Originál necháváme v závorce kvůli podpoře Zásilkovny.
	 */
	public static function humanize( string $raw ): string {
		$l    = strtolower( $raw );
		$hint = '';

		if ( str_contains( $l, 'sender is not given' ) || str_contains( $l, 'choose a sender' ) ) {
			$hint = __( 'Zásilkovna nezná odesílatele, kterého posíláme. Nejčastěji se stane, když se odesílatel v Zásilkovně přejmenuje. Zkontroluj NKZ Marketplace → Zásilkovna → „Výchozí odesílatel" (musí sedět znak po znaku) a taky pole odesílatele v profilu prodejce — to má přednost před globálním.', 'nkz-mp-packeta' );
		} elseif ( str_contains( $l, 'sender' ) && ( str_contains( $l, 'unknown' ) || str_contains( $l, 'invalid' ) || str_contains( $l, 'not found' ) ) ) {
			$hint = __( 'Odesílatel neodpovídá žádnému v účtu Zásilkovny. Oprav jeho název v nastavení Zásilkovny (nebo v profilu prodejce).', 'nkz-mp-packeta' );
		} elseif ( str_contains( $l, 'addressid' ) || str_contains( $l, 'pickup point' ) || str_contains( $l, 'branch' ) ) {
			$hint = __( 'Výdejní místo z objednávky Zásilkovna nezná (může být zrušené). Domluv se se zákazníkem na jiném a uprav objednávku.', 'nkz-mp-packeta' );
		} elseif ( str_contains( $l, 'weight' ) ) {
			$hint = __( 'Nesedí váha zásilky. Vyplň váhu u produktu, nebo výchozí váhu v nastavení Zásilkovny.', 'nkz-mp-packeta' );
		} elseif ( str_contains( $l, 'unauthor' ) || str_contains( $l, 'api password' ) || str_contains( $l, 'wrong password' ) ) {
			$hint = __( 'Zásilkovna odmítla přihlášení — zkontroluj API heslo v nastavení.', 'nkz-mp-packeta' );
		} elseif ( str_contains( $l, 'cod' ) ) {
			$hint = __( 'Problém s dobírkou. Zkontroluj částku a měnu u objednávky.', 'nkz-mp-packeta' );
		}

		if ( $hint === '' ) {
			return $raw;
		}
		/* translators: 1: český popis, 2: originální hláška od Zásilkovny */
		return sprintf( __( '%1$s (Zásilkovna hlásí: %2$s)', 'nkz-mp-packeta' ), $hint, $raw );
	}
}
