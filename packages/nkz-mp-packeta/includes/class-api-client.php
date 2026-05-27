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
		return $msg !== '' ? $msg : __( 'Packeta odmítla požadavek (neznámá chyba).', 'nkz-mp-packeta' );
	}
}
