<?php
/**
 * GDPR eraser.
 *
 * Princip: účetní záznamy (ledger, payouts) MUSÍ být zachované pro
 * splnění zákonné archivační povinnosti (CZ účetní zákon, AML, daň).
 *  - Vendor profil se pseudonymizuje (e-mail, jméno, IČO, web, bio).
 *  - Mapping na WP usera se odstraní.
 *  - Audit log se zachová, ale actor_label se anonymizuje (pseudonym).
 *  - Ledger a payouts zůstávají beze změny.
 *
 * Uživatel je v `messages` informován o tom, co zůstalo a proč.
 *
 * @package NKZMP
 */

namespace NKZMP\Gdpr;

use NKZMP\Audit\Recorder as AuditRecorder;
use NKZMP\Audit\Schema as AuditSchema;
use NKZMP\Vendor\MetaKeys;
use NKZMP\Vendor\OwnershipGuard;

defined( 'ABSPATH' ) || exit;

final class Eraser {

	public static function register( array $erasers ): array {
		$erasers['nkz-marketplace-vendor'] = [
			'eraser_friendly_name' => __( 'NKZ Marketplace – Vendor pseudonymizace', 'nkz-marketplace' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	public static function erase( string $email, int $page = 1 ): array {
		$result = [
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];

		$user       = get_user_by( 'email', $email );
		$vendor_ids = Exporter::vendor_ids_for_email( $email );

		foreach ( $vendor_ids as $vendor_id ) {
			self::pseudonymize_vendor( $vendor_id );
			$result['items_removed'] = true;
		}

		if ( $user && ( $vid = OwnershipGuard::user_vendor_id( (int) $user->ID ) ) > 0 ) {
			delete_post_meta( $vid, MetaKeys::WP_USER_ID );
			delete_post_meta( $vid, '_nkv_wp_user_id' );
			$result['items_removed'] = true;
		}

		// Pseudonymizace actor_label v auditu, ale eventy zůstávají.
		if ( $user ) {
			global $wpdb;
			$wpdb->update(
				AuditSchema::table_name(),
				[ 'actor_label' => 'anonymized' ],
				[ 'actor_user_id' => (int) $user->ID ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		// Záznam o erasure do auditu (zachovává transparency log).
		( new AuditRecorder() )->record(
			action:      'gdpr.erasure',
			entity_type: 'gdpr',
			entity_id:   $user ? (int) $user->ID : 0,
			summary:     'GDPR erasure – vendor profile pseudonymized, financial records retained',
			payload:     [ 'email_hash' => hash( 'sha256', $email ), 'vendor_ids' => $vendor_ids ],
			actor_label: 'gdpr',
		);

		if ( $vendor_ids ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'Účetní záznamy (ledger, výplaty) musí být zachované dle účetního zákona — byly anonymizovány v rozsahu, který zákon umožňuje.', 'nkz-marketplace' );
		}

		return $result;
	}

	private static function pseudonymize_vendor( int $vendor_id ): void {
		$post = get_post( $vendor_id );
		if ( ! $post ) {
			return;
		}
		wp_update_post(
			[
				'ID'         => $vendor_id,
				'post_title' => 'Anonymized vendor #' . $vendor_id,
			]
		);
		$wipe = [
			MetaKeys::EMAIL,
			MetaKeys::ICO,
			MetaKeys::WEBSITE,
			MetaKeys::BIO,
			MetaKeys::INTERNAL_NOTE,
			MetaKeys::WP_USER_ID,
			// Legacy klíče (Stripe adapter):
			'_nkv_vendor_email',
			'_nkv_vendor_ico',
			'_nkv_vendor_website',
			'_nkv_vendor_bio',
			'_nkv_internal_note',
		];
		foreach ( $wipe as $key ) {
			delete_post_meta( $vendor_id, $key );
		}
	}
}

