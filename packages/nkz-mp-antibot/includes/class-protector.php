<?php
/**
 * Vsechny heuristicke vrstvy + verejne API pro renderovani + verifikaci.
 *
 * Pouziti:
 *   Protector::render_fields( 'form_key' );  -- v form templatu
 *   $check = Protector::verify( 'form_key' ); -- v handleru
 *   if ( is_wp_error( $check ) ) wp_die( $check->get_error_message() );
 *
 * @package NKZMP\Antibot
 */

namespace NKZMP\Antibot;

defined( 'ABSPATH' ) || exit;

final class Protector {

	private const HP_FIELD   = 'nkzmp_ab_url';   // honeypot
	private const TIME_FIELD = 'nkzmp_ab_t';     // timestamp open
	private const FORM_FIELD = 'nkzmp_ab_form';

	/** Render hidden fields + Turnstile widget. */
	public static function render_fields( string $form_key ): void {
		if ( ! Settings::is_form_protected( $form_key ) ) {
			return;
		}
		printf(
			'<div style="position:absolute;left:-9999px;height:0;overflow:hidden;" aria-hidden="true">'
			. '<label>Url<input type="text" name="%s" value="" autocomplete="off" tabindex="-1"></label>'
			. '</div>',
			esc_attr( self::HP_FIELD )
		);
		printf(
			'<input type="hidden" name="%s" value="%d">',
			esc_attr( self::TIME_FIELD ),
			(int) time()
		);
		printf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( self::FORM_FIELD ),
			esc_attr( $form_key )
		);
		Turnstile::instance()->render_field();
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function verify( string $form_key ) {
		if ( ! Settings::is_form_protected( $form_key ) ) {
			return true;
		}

		// 1) Honeypot.
		if ( ! empty( $_POST[ self::HP_FIELD ] ) ) {
			self::log( $form_key, 'honeypot' );
			return new \WP_Error( 'antibot_honeypot', __( 'Spam ochrana. Pokud nejsi bot, kontaktuj nás.', 'nkz-mp-antibot' ) );
		}

		// 2) Time gate.
		$s         = Settings::get();
		$min       = max( 0, (int) $s['min_time_seconds'] );
		$opened_at = isset( $_POST[ self::TIME_FIELD ] ) ? (int) $_POST[ self::TIME_FIELD ] : 0;
		if ( $min > 0 && ( $opened_at === 0 || ( time() - $opened_at ) < $min ) ) {
			self::log( $form_key, 'time_gate', [ 'elapsed' => time() - $opened_at ] );
			return new \WP_Error( 'antibot_time', __( 'Formulář byl odeslán příliš rychle. Zkus to ještě jednou.', 'nkz-mp-antibot' ) );
		}

		// 3) Rate limit per IP.
		$limit = (int) $s['rate_limit_per_hour'];
		if ( $limit > 0 ) {
			$ip  = self::client_ip();
			$key = 'nkzmp_ab_rl_' . md5( $form_key . '|' . $ip );
			$cnt = (int) get_transient( $key );
			if ( $cnt >= $limit ) {
				self::log( $form_key, 'rate_limit', [ 'ip' => $ip, 'count' => $cnt ] );
				return new \WP_Error( 'antibot_rate', __( 'Příliš mnoho odeslání z tvojí sítě. Zkus to za hodinu.', 'nkz-mp-antibot' ) );
			}
			set_transient( $key, $cnt + 1, HOUR_IN_SECONDS );
		}

		// 4) Turnstile (pokud zapnuto).
		$ts = Turnstile::instance()->verify();
		if ( is_wp_error( $ts ) ) {
			self::log( $form_key, 'turnstile', [ 'code' => $ts->get_error_code() ] );
			return $ts;
		}

		return true;
	}

	public static function client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = trim( explode( ',', (string) $_SERVER[ $h ] )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	private static function log( string $form_key, string $reason, array $ctx = [] ): void {
		error_log( sprintf(
			'[NKZMP Antibot] blocked form=%s reason=%s ctx=%s',
			$form_key,
			$reason,
			wp_json_encode( $ctx )
		) );
	}
}
