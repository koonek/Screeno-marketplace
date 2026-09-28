<?php
/**
 * Account_State – vyhodnocení stavu Stripe Connect účtu.
 *
 * Doteď jsme všechno slévali do tří hodnot (enabled / pending / restricted),
 * takže účet, kterému Stripe reálně chyběly údaje (`currently_due`), skončil
 * jako „pending" a prodejkyni jsme napsali „tvoje údaje se ověřují". Stripe
 * jí mezitím psal, ať ověření dokončí. Dva protichůdné vzkazy nad stejným
 * účtem – proto tahle třída rozlišuje, co po nás Stripe doopravdy chce.
 *
 * Třída je čistá funkce nad polem účtu ze Stripe API. Nic nezapisuje,
 * nevolá Stripe a neobsahuje žádné osobní údaje – do snapshotu jdou jen
 * NÁZVY chybějících polí (`individual.dob.day`), nikdy jejich hodnoty.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Account_State {

	/** Vše hotovo, účet plně funkční. */
	public const VERIFIED = 'verified';

	/** Stripe zpracovává, co dostal. Uživatel NEMÁ nic vyplňovat. */
	public const PENDING_VERIFICATION = 'pending_verification';

	/** Stripe chce další údaje. */
	public const ACTION_REQUIRED = 'action_required';

	/** Termín prošel – Stripe už omezuje platby/výplaty. */
	public const PAST_DUE = 'past_due';

	/** Stripe konkrétní údaj odmítl (nečitelný doklad, neshoda…). */
	public const VERIFICATION_ERROR = 'verification_error';

	/** Účet omezen a nic není „due" – typicky under_review / rejected. */
	public const ACCOUNT_RESTRICTED = 'account_restricted';

	/** Účet ještě neexistuje nebo se ho nepodařilo načíst. */
	public const UNKNOWN = 'unknown';

	/**
	 * Vyhodnotí účet.
	 *
	 * Pořadí je záměrné: nejdřív to, co uživatel může vyřešit a co mu
	 * nejpřesněji popíše situaci. `charges_enabled`/`payouts_enabled` se
	 * do stavu neslévají – vracíme je zvlášť, aby šlo nad jakýmkoli stavem
	 * ukázat „Stripe omezil platby". Kdyby omezení přebilo stav, každý
	 * nedokončený účet by hlásil jen „omezeno" a uživatel by nevěděl proč.
	 *
	 * @param array $account Odpověď Stripe API (GET /v1/accounts/:id).
	 * @return array{
	 *   state:string, charges_enabled:bool, payouts_enabled:bool,
	 *   transfers_active:bool, details_submitted:bool, disabled_reason:?string,
	 *   currently_due:string[], past_due:string[], eventually_due:string[],
	 *   pending_verification:string[], errors:array<int,array{requirement:string,code:string,reason:string}>,
	 *   current_deadline:?int, alternatives:array,
	 *   future_currently_due:string[], future_eventually_due:string[],
	 *   future_pending_verification:string[]
	 * }
	 */
	public static function evaluate( array $account ): array {
		$req    = is_array( $account['requirements'] ?? null ) ? $account['requirements'] : [];
		$future = is_array( $account['future_requirements'] ?? null ) ? $account['future_requirements'] : [];

		$currently_due        = self::str_list( $req['currently_due'] ?? [] );
		$past_due             = self::str_list( $req['past_due'] ?? [] );
		$eventually_due       = self::str_list( $req['eventually_due'] ?? [] );
		$pending_verification = self::str_list( $req['pending_verification'] ?? [] );
		$errors               = self::errors( $req['errors'] ?? [] );
		$disabled_reason      = isset( $req['disabled_reason'] ) && $req['disabled_reason'] !== ''
			? (string) $req['disabled_reason']
			: null;

		$charges  = ! empty( $account['charges_enabled'] );
		$payouts  = ! empty( $account['payouts_enabled'] );
		$submitted = ! empty( $account['details_submitted'] );

		// `capabilities.transfers` je string: active / pending / inactive.
		$transfers_active = 'active' === (string) ( $account['capabilities']['transfers'] ?? '' );

		$state = self::pick_state(
			$errors,
			$past_due,
			$currently_due,
			$pending_verification,
			$disabled_reason,
			$charges,
			$payouts,
			$transfers_active
		);

		return [
			'state'                       => $state,
			'charges_enabled'             => $charges,
			'payouts_enabled'             => $payouts,
			'transfers_active'            => $transfers_active,
			'details_submitted'           => $submitted,
			'disabled_reason'             => $disabled_reason,
			'currently_due'               => $currently_due,
			'past_due'                    => $past_due,
			'eventually_due'              => $eventually_due,
			'pending_verification'        => $pending_verification,
			'errors'                      => $errors,
			'current_deadline'            => isset( $req['current_deadline'] ) ? (int) $req['current_deadline'] : null,
			'alternatives'                => is_array( $req['alternatives'] ?? null ) ? $req['alternatives'] : [],
			'future_currently_due'        => self::str_list( $future['currently_due'] ?? [] ),
			'future_eventually_due'       => self::str_list( $future['eventually_due'] ?? [] ),
			'future_pending_verification' => self::str_list( $future['pending_verification'] ?? [] ),
		];
	}

	private static function pick_state(
		array $errors,
		array $past_due,
		array $currently_due,
		array $pending_verification,
		?string $disabled_reason,
		bool $charges,
		bool $payouts,
		bool $transfers_active
	): string {
		// Chyba ověření je nejkonkrétnější informace, jakou od Stripe dostaneme
		// („doklad je nečitelný"). Kdyby ji přebil obecnější stav, uživatel by
		// znovu nahrával to samé a znovu by to neprošlo.
		if ( $errors ) {
			return self::VERIFICATION_ERROR;
		}
		if ( $past_due ) {
			return self::PAST_DUE;
		}
		if ( $currently_due ) {
			return self::ACTION_REQUIRED;
		}
		if ( $pending_verification ) {
			return self::PENDING_VERIFICATION;
		}
		// Nic není potřeba, a přesto účet nefunguje → rozhoduje Stripe
		// (under_review, rejected, platform_paused…).
		if ( $disabled_reason || ! $charges || ! $payouts || ! $transfers_active ) {
			return self::ACCOUNT_RESTRICTED;
		}
		return self::VERIFIED;
	}

	/** Musí uživatel něco vyplnit, nebo jen čekat? */
	public static function needs_user_action( string $state ): bool {
		return in_array(
			$state,
			[ self::ACTION_REQUIRED, self::PAST_DUE, self::VERIFICATION_ERROR ],
			true
		);
	}

	/**
	 * Mapování na starý tříhodnotový status.
	 *
	 * Na `_nkv_stripe_account_status` visí výplaty, Elementor podmínky
	 * i gate na přidávání produktů. Píšeme ho dál, aby se nový detailní stav
	 * dal zavést bez přepisování všech konzumentů.
	 */
	public static function to_legacy( string $state ): string {
		switch ( $state ) {
			case self::VERIFIED:
				return 'enabled';
			case self::PAST_DUE:
			case self::VERIFICATION_ERROR:
			case self::ACCOUNT_RESTRICTED:
				return 'restricted';
			case self::ACTION_REQUIRED:
			case self::PENDING_VERIFICATION:
				return 'pending';
			default:
				return 'unknown';
		}
	}

	/** Krátký titulek pro prodejce. */
	public static function label( string $state ): string {
		switch ( $state ) {
			case self::VERIFIED:
				return __( 'Ověřeno', 'nkz-woo-stripe-vendor-split' );
			case self::PENDING_VERIFICATION:
				return __( 'Stripe ověřuje zadané údaje', 'nkz-woo-stripe-vendor-split' );
			case self::ACTION_REQUIRED:
				return __( 'Stripe potřebuje doplnit údaje', 'nkz-woo-stripe-vendor-split' );
			case self::PAST_DUE:
				return __( 'Termín pro doplnění vypršel', 'nkz-woo-stripe-vendor-split' );
			case self::VERIFICATION_ERROR:
				return __( 'Ověření neprošlo', 'nkz-woo-stripe-vendor-split' );
			case self::ACCOUNT_RESTRICTED:
				return __( 'Účet je omezený', 'nkz-woo-stripe-vendor-split' );
			default:
				return __( 'Neznámý stav', 'nkz-woo-stripe-vendor-split' );
		}
	}

	/** Vysvětlení pro prodejce – co se děje a co má (ne)dělat. */
	public static function description( array $snapshot ): string {
		$state = (string) ( $snapshot['state'] ?? self::UNKNOWN );

		switch ( $state ) {
			case self::VERIFIED:
				return __( 'Tvůj účet je plně ověřený. Můžeš přijímat platby i výplaty.', 'nkz-woo-stripe-vendor-split' );

			case self::PENDING_VERIFICATION:
				return __( 'Stripe teď kontroluje údaje, které jsi poslal/a. Nemusíš nic dělat ani nic vyplňovat znovu — obvykle to trvá pár minut, někdy až pár dní. Dáme ti vědět.', 'nkz-woo-stripe-vendor-split' );

			case self::ACTION_REQUIRED:
				return __( 'Stripe po tobě chce ještě něco doplnit. Otevři prosím ověření a projdi zbývající kroky — vyplněné údaje už tam budeš mít.', 'nkz-woo-stripe-vendor-split' );

			case self::PAST_DUE:
				return __( 'Stripe si vyžádal doplnění údajů a termín už uplynul, takže může mít pozastavené platby nebo výplaty. Dokonči prosím ověření co nejdřív.', 'nkz-woo-stripe-vendor-split' );

			case self::VERIFICATION_ERROR:
				$hint = self::error_hint( $snapshot );
				$alt  = self::alternative_labels( $snapshot );
				if ( $alt ) {
					// Když Stripe nabízí náhradní cestu, je to JEDINÁ rozumná
					// instrukce. Znovu vyplňovat to samé vede na stejnou chybu
					// donekonečna – proto o původních polích ani nemluvíme.
					return $hint . ' ' . sprintf(
						/* translators: %s: seznam dokladů */
						__( 'Nevyplňuj prosím údaje znovu — Stripe místo nich přijme %s. Otevři ověření a zvol nahrání dokladů.', 'nkz-woo-stripe-vendor-split' ),
						self::join_list( $alt )
					);
				}
				return $hint !== ''
					? $hint
					: __( 'Stripe zadané údaje nepřijal. Otevři prosím ověření — uvidíš tam, co je potřeba opravit.', 'nkz-woo-stripe-vendor-split' );

			case self::ACCOUNT_RESTRICTED:
				$reason = self::disabled_reason_text( (string) ( $snapshot['disabled_reason'] ?? '' ) );
				return $reason !== ''
					? $reason
					: __( 'Stripe účet je momentálně omezený. Ozvi se prosím provozovateli platformy.', 'nkz-woo-stripe-vendor-split' );

			default:
				return __( 'Stav ověření zatím neznáme.', 'nkz-woo-stripe-vendor-split' );
		}
	}

	/** První chyba ověření přeložená do lidské řeči (bez PII). */
	public static function first_error_reason( array $snapshot ): string {
		$errors = $snapshot['errors'] ?? [];
		if ( ! is_array( $errors ) || ! $errors ) {
			return '';
		}
		$first = $errors[0];
		// `reason` od Stripe je anglicky, ale konkrétní a bez osobních údajů.
		return trim( (string) ( $first['reason'] ?? '' ) );
	}

	/**
	 * Česky, co se pokazilo — podle KÓDU chyby, ne podle anglického textu.
	 *
	 * Nejčastější je `verification_failed_keyed_identity`: Stripe se pokusil
	 * dohledat člověka v registrech podle ručně vyplněných údajů a nenašel
	 * dost záznamů. V ČR a na SK je pokrytí těchhle registrů slabé, takže
	 * to potkává i lidi, kteří mají všechno vyplněné správně. Stripe pak
	 * údaje zneplatní a vyžádá si je znovu — a znovu je nedohledá. Bez
	 * náhradní cesty (doklady) se z té smyčky nedá dostat.
	 */
	public static function error_hint( array $snapshot ): string {
		$errors = $snapshot['errors'] ?? [];
		if ( ! is_array( $errors ) || ! $errors ) {
			return '';
		}
		$code = (string) ( $errors[0]['code'] ?? '' );

		$map = [
			'verification_failed_keyed_identity'  => __( 'Stripe si nedokázal ověřit tvoji totožnost podle vyplněných údajů — v registrech, do kterých vidí, tě nenašel. Není to chyba na tvé straně, v Česku se to stává běžně.', 'nkz-woo-stripe-vendor-split' ),
			'verification_failed_keyed_match'     => __( 'Vyplněné údaje se neshodují se záznamy, které Stripe našel.', 'nkz-woo-stripe-vendor-split' ),
			'verification_failed_name_match'      => __( 'Jméno se neshoduje se záznamy, které Stripe našel.', 'nkz-woo-stripe-vendor-split' ),
			'verification_failed_address_match'   => __( 'Adresa se neshoduje se záznamy, které Stripe našel.', 'nkz-woo-stripe-vendor-split' ),
			'verification_failed_document_match'  => __( 'Údaje se neshodují s nahraným dokladem.', 'nkz-woo-stripe-vendor-split' ),
			'verification_document_not_readable'  => __( 'Nahraný doklad je nečitelný. Vyfoť ho prosím znovu za lepšího světla.', 'nkz-woo-stripe-vendor-split' ),
			'verification_document_failed_copy'   => __( 'Stripe nepřijal kopii dokladu — potřebuje fotku originálu.', 'nkz-woo-stripe-vendor-split' ),
			'verification_document_expired'       => __( 'Nahraný doklad má prošlou platnost.', 'nkz-woo-stripe-vendor-split' ),
			'verification_document_incomplete'    => __( 'Na fotce dokladu není vidět celý doklad.', 'nkz-woo-stripe-vendor-split' ),
			'verification_document_failed_greyscale' => __( 'Doklad musí být nafocený barevně, ne černobíle.', 'nkz-woo-stripe-vendor-split' ),
			'verification_document_country_not_supported' => __( 'Stripe tenhle typ dokladu z dané země nepřijímá.', 'nkz-woo-stripe-vendor-split' ),
			'invalid_dob_age_under_18'            => __( 'Podle data narození je majitel účtu mladší 18 let.', 'nkz-woo-stripe-vendor-split' ),
			'invalid_street_address'              => __( 'Stripe neuznal zadanou adresu.', 'nkz-woo-stripe-vendor-split' ),
			'invalid_address_city_state_postal_code' => __( 'Město a PSČ si neodpovídají.', 'nkz-woo-stripe-vendor-split' ),
		];

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		// Neznámý kód – radši anglický originál od Stripe než nic.
		return self::first_error_reason( $snapshot );
	}

	/**
	 * Náhradní cesta, kterou Stripe sám nabízí (`requirements.alternatives`).
	 *
	 * Když se ověření podle vyplněných údajů nepovede, Stripe typicky
	 * napíše: „místo tohohle všeho mi nahraj doklad totožnosti a doklad
	 * o adrese". Tuhle informaci jsme dřív zahazovali, takže prodejci
	 * neměli jak zjistit, že existuje jiná cesta než pořád dokola vyplňovat
	 * ta samá pole.
	 *
	 * @return string[] Přeložené názvy dokladů, které Stripe přijme místo původních polí.
	 */
	public static function alternative_labels( array $snapshot ): array {
		$alts = $snapshot['alternatives'] ?? [];
		if ( ! is_array( $alts ) || ! $alts ) {
			return [];
		}
		$fields = [];
		foreach ( $alts as $alt ) {
			if ( ! is_array( $alt ) ) {
				continue;
			}
			foreach ( (array) ( $alt['alternative_fields_due'] ?? [] ) as $f ) {
				$fields[] = (string) $f;
			}
		}
		return self::requirement_labels( $fields );
	}

	/** „a, b a c" – pro plynulou větu v češtině. */
	private static function join_list( array $items ): string {
		$items = array_values( array_filter( $items ) );
		$n     = count( $items );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return $items[0];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' ' . __( 'a', 'nkz-woo-stripe-vendor-split' ) . ' ' . $last;
	}

	/** Proč Stripe účet omezil. */
	public static function disabled_reason_text( string $reason ): string {
		$map = [
			'requirements.past_due'             => __( 'Stripe čeká na doplnění údajů a termín už uplynul.', 'nkz-woo-stripe-vendor-split' ),
			'requirements.pending_verification' => __( 'Stripe ověřuje zadané údaje. Platby se pustí, jakmile to dokončí.', 'nkz-woo-stripe-vendor-split' ),
			'listed'                            => __( 'Účet je v kontrole Stripe. Ozvi se prosím provozovateli platformy.', 'nkz-woo-stripe-vendor-split' ),
			'under_review'                      => __( 'Stripe účet prověřuje. Nemusíš nic dělat, jen to počkat.', 'nkz-woo-stripe-vendor-split' ),
			'rejected.fraud'                    => __( 'Stripe účet zamítl. Ozvi se prosím provozovateli platformy.', 'nkz-woo-stripe-vendor-split' ),
			'rejected.terms_of_service'         => __( 'Stripe účet zamítl kvůli podmínkám použití. Ozvi se prosím provozovateli platformy.', 'nkz-woo-stripe-vendor-split' ),
			'rejected.listed'                   => __( 'Stripe účet zamítl. Ozvi se prosím provozovateli platformy.', 'nkz-woo-stripe-vendor-split' ),
			'rejected.other'                    => __( 'Stripe účet zamítl. Ozvi se prosím provozovateli platformy.', 'nkz-woo-stripe-vendor-split' ),
			'platform_paused'                   => __( 'Účet je pozastavený platformou.', 'nkz-woo-stripe-vendor-split' ),
			'action_required.requested_capabilities' => __( 'Stripe ještě nepovolil potřebné funkce účtu.', 'nkz-woo-stripe-vendor-split' ),
		];
		return $map[ $reason ] ?? '';
	}

	/**
	 * Lidský popis názvu požadovaného pole.
	 *
	 * Prodejkyni nic neříká `individual.verification.proof_of_liveness`.
	 * Překládáme jen názvy polí – hodnoty se sem nikdy nedostanou.
	 */
	public static function requirement_label( string $key ): string {
		$map = [
			'individual.first_name'                    => __( 'jméno', 'nkz-woo-stripe-vendor-split' ),
			'individual.last_name'                     => __( 'příjmení', 'nkz-woo-stripe-vendor-split' ),
			'individual.dob.day'                       => __( 'datum narození', 'nkz-woo-stripe-vendor-split' ),
			'individual.dob.month'                     => __( 'datum narození', 'nkz-woo-stripe-vendor-split' ),
			'individual.dob.year'                      => __( 'datum narození', 'nkz-woo-stripe-vendor-split' ),
			'individual.address.line1'                 => __( 'adresa', 'nkz-woo-stripe-vendor-split' ),
			'individual.address.city'                  => __( 'město', 'nkz-woo-stripe-vendor-split' ),
			'individual.address.postal_code'           => __( 'PSČ', 'nkz-woo-stripe-vendor-split' ),
			'individual.phone'                         => __( 'telefon', 'nkz-woo-stripe-vendor-split' ),
			'individual.email'                         => __( 'e-mail', 'nkz-woo-stripe-vendor-split' ),
			'individual.verification.document'         => __( 'doklad totožnosti', 'nkz-woo-stripe-vendor-split' ),
			'individual.verification.additional_document' => __( 'doklad o adrese', 'nkz-woo-stripe-vendor-split' ),
			'individual.verification.proof_of_liveness' => __( 'ověření selfie / naskenování obličeje', 'nkz-woo-stripe-vendor-split' ),
			'individual.id_number'                     => __( 'rodné číslo', 'nkz-woo-stripe-vendor-split' ),
			'business_profile.url'                     => __( 'web', 'nkz-woo-stripe-vendor-split' ),
			'business_profile.mcc'                     => __( 'obor podnikání', 'nkz-woo-stripe-vendor-split' ),
			'external_account'                         => __( 'bankovní účet', 'nkz-woo-stripe-vendor-split' ),
			'tos_acceptance.date'                      => __( 'souhlas s podmínkami Stripe', 'nkz-woo-stripe-vendor-split' ),
			'tos_acceptance.ip'                        => __( 'souhlas s podmínkami Stripe', 'nkz-woo-stripe-vendor-split' ),
		];
		return $map[ $key ] ?? $key;
	}

	/**
	 * Seznam požadavků přeložený a bez duplicit (dob.day/month/year → jednou).
	 *
	 * @param string[] $keys
	 * @return string[]
	 */
	public static function requirement_labels( array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			$label = self::requirement_label( (string) $key );
			if ( ! in_array( $label, $out, true ) ) {
				$out[] = $label;
			}
		}
		return $out;
	}

	/**
	 * Vyžaduje některý z požadavků Stripe-hosted krok, který vlastním
	 * formulářem nenahradíme?
	 *
	 * `proof_of_liveness` (selfie / naskenování obličeje) i nahrání dokladu
	 * umí jen Stripe – my pro ně žádné pole nabízet nesmíme.
	 *
	 * @param string[] $keys
	 */
	public static function needs_hosted_flow( array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( str_contains( (string) $key, 'verification.proof_of_liveness' )
				|| str_contains( (string) $key, 'verification.document' )
				|| str_contains( (string) $key, 'verification.additional_document' ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------ pomocné */

	/** @return string[] */
	private static function str_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_map( 'strval', $value ) );
	}

	/**
	 * Chyby ověření. Bereme jen requirement/code/reason – Stripe sem osobní
	 * údaje nedává, ale držíme se whitelistu, ne „co přijde".
	 *
	 * @return array<int,array{requirement:string,code:string,reason:string}>
	 */
	private static function errors( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $err ) {
			if ( ! is_array( $err ) ) {
				continue;
			}
			$out[] = [
				'requirement' => (string) ( $err['requirement'] ?? '' ),
				'code'        => (string) ( $err['code'] ?? '' ),
				'reason'      => (string) ( $err['reason'] ?? '' ),
			];
		}
		return $out;
	}
}
