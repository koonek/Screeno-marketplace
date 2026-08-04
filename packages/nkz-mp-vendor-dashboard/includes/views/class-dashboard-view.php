<?php
/**
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard\Views;

use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Support\Money;
use NKZMP\Vendor\Status;

defined( 'ABSPATH' ) || exit;

final class DashboardView {

	public static function render( array $vendor ): void {
		$vendor_id   = (int) $vendor['id'];
		$status_raw  = (string) $vendor['status'];
		$status      = $status_raw !== '' ? Status::tryFrom( $status_raw ) : null;
		$status_text = self::status_text( $status );
		$status_tone = self::status_tone( $status );

		$stats = self::stats( $vendor_id );

		$post     = get_post( $vendor_id );
		$slug     = $post ? $post->post_name : '';
		$profile  = $slug ? home_url( '/vendor/' . $slug ) : '';

		?>
		<div class="nkzmp-vd nkzmp-vd-dashboard">

			<header class="nkzmp-vd-hero">
				<div>
					<span class="nkzmp-vd-kicker"><?php esc_html_e( 'Vendor', 'nkz-mp-vendor-dashboard' ); ?></span>
					<h1><?php echo esc_html( (string) $vendor['name'] ); ?></h1>
				</div>
				<div class="nkzmp-vd-status nkzmp-vd-status--<?php echo esc_attr( $status_tone ); ?>">
					<?php echo esc_html( $status_text ); ?>
				</div>
			</header>

			<?php self::render_onboarding( $vendor_id, $status ); ?>

			<section class="nkzmp-vd-stats">
				<?php foreach ( $stats as $s ) : ?>
					<div class="nkzmp-vd-stat">
						<div class="nkzmp-vd-stat-label"><?php echo esc_html( $s['label'] ); ?></div>
						<div class="nkzmp-vd-stat-value"><?php echo esc_html( $s['value'] ); ?></div>
						<?php if ( ! empty( $s['hint'] ) ) : ?>
							<div class="nkzmp-vd-stat-hint"><?php echo esc_html( $s['hint'] ); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="nkzmp-vd-actions">
				<a class="nkzmp-vd-action" href="<?php echo esc_url( wc_get_account_endpoint_url( 'vendor-products' ) ); ?>">
					<strong><?php esc_html_e( 'Moje produkty', 'nkz-mp-vendor-dashboard' ); ?></strong>
					<span><?php esc_html_e( 'spravovat katalog', 'nkz-mp-vendor-dashboard' ); ?></span>
				</a>
				<a class="nkzmp-vd-action" href="<?php echo esc_url( wc_get_account_endpoint_url( 'vendor-payouts' ) ); ?>">
					<strong><?php esc_html_e( 'Moje výplaty', 'nkz-mp-vendor-dashboard' ); ?></strong>
					<span><?php esc_html_e( 'historie transferů', 'nkz-mp-vendor-dashboard' ); ?></span>
				</a>
				<?php if ( $profile ) : ?>
					<a class="nkzmp-vd-action" href="<?php echo esc_url( $profile ); ?>" target="_blank">
						<strong><?php esc_html_e( 'Veřejný profil', 'nkz-mp-vendor-dashboard' ); ?></strong>
						<span><?php esc_html_e( 'jak tě vidí zákazníci', 'nkz-mp-vendor-dashboard' ); ?> →</span>
					</a>
				<?php endif; ?>
			</section>

			<?php if ( $status === Status::APPROVED_AWAITING_KYC ) : ?>
				<aside class="nkzmp-vd-callout">
					<h3><?php esc_html_e( 'Zbývá ti registrace platby', 'nkz-mp-vendor-dashboard' ); ?></h3>
					<p><?php esc_html_e( 'Než ti pustíme produkty do prodeje, vyplň krátkou registraci u Stripe. Trvá pár minut.', 'nkz-mp-vendor-dashboard' ); ?></p>
					<?php if ( class_exists( \NKVSVS\Onboarding_Controller::class ) ) :
						$stripe_link = \NKVSVS\Onboarding_Controller::vendor_start_url( $vendor_id );
						if ( $stripe_link ) : ?>
							<a class="nkzmp-vd-cta" href="<?php echo esc_url( $stripe_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Spustit registraci u Stripe', 'nkz-mp-vendor-dashboard' ); ?> →</a>
						<?php endif;
					endif; ?>
				</aside>
			<?php endif; ?>

			<?php
			// Podmínky pro prodejce – ať je má prodejce pořád po ruce,
			// nejen v okamžiku registrace.
			$vt_url = class_exists( \NKZMP\Registration\Settings::class )
				? (string) ( \NKZMP\Registration\Settings::get()['vendor_terms_url'] ?? '' )
				: '';
			if ( $vt_url !== '' ) :
				?>
				<p class="nkzmp-vd-note" style="margin-top:28px;">
					<?php
					printf(
						/* translators: %s: odkaz na podmínky pro prodejce */
						esc_html__( 'Prodejem na Art of život se řídíš %s.', 'nkz-mp-vendor-dashboard' ),
						'<a href="' . esc_url( $vt_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'podmínkami pro prodejce', 'nkz-mp-vendor-dashboard' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * @return array<int, array{label:string,value:string,hint:string}>
	 */
	private static function stats( int $vendor_id ): array {
		global $wpdb;

		$product_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			   AND ( ( pm.meta_key = '_nkzmp_vendor_id' AND pm.meta_value = %d )
			      OR ( pm.meta_key = '_nkv_vendor_id'   AND pm.meta_value = %d ) )",
			$vendor_id,
			$vendor_id
		) );

		$ledger = LedgerSchema::table_name();
		$since  = time() - 30 * DAY_IN_SECONDS;

		$volume_30d = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(ABS(amount_minor)), 0) FROM {$ledger} WHERE vendor_id = %d AND type = 'payout' AND occurred_at >= %d", // phpcs:ignore
			$vendor_id,
			$since
		) );
		$volume_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(ABS(amount_minor)), 0) FROM {$ledger} WHERE vendor_id = %d AND type = 'payout'", // phpcs:ignore
			$vendor_id
		) );

		$currency = (string) ( get_woocommerce_currency() ?: 'CZK' );

		return [
			[
				'label' => __( 'Aktivní produkty', 'nkz-mp-vendor-dashboard' ),
				'value' => (string) $product_count,
				'hint'  => '',
			],
			[
				'label' => __( 'Vyplaceno 30 dní', 'nkz-mp-vendor-dashboard' ),
				'value' => $volume_30d > 0 ? Money::from_minor_display( $volume_30d, $currency ) : '0 ' . $currency,
				'hint'  => __( 'po Stripe poplatcích', 'nkz-mp-vendor-dashboard' ),
			],
			[
				'label' => __( 'Vyplaceno celkem', 'nkz-mp-vendor-dashboard' ),
				'value' => $volume_total > 0 ? Money::from_minor_display( $volume_total, $currency ) : '0 ' . $currency,
				'hint'  => __( 'od začátku', 'nkz-mp-vendor-dashboard' ),
			],
			[
				'label' => __( 'Provize platformy', 'nkz-mp-vendor-dashboard' ),
				'value' => self::commission_label( $vendor_id ),
				'hint'  => __( 'z každého prodeje', 'nkz-mp-vendor-dashboard' ),
			],
		];
	}

	/**
	 * Provize platformy pro vendora (per-vendor meta → globální default adapteru).
	 */
	private static function commission_label( int $vendor_id ): string {
		$pct = get_post_meta( $vendor_id, '_nkzmp_default_fee_percent', true );
		if ( $pct === '' || ! is_numeric( $pct ) ) {
			$pct = get_post_meta( $vendor_id, '_nkv_default_fee_percent', true );
		}
		if ( ( $pct === '' || ! is_numeric( $pct ) ) && class_exists( \NKVSVS\Plugin::class ) ) {
			$settings = \NKVSVS\Plugin::settings();
			$pct      = $settings['default_fee_percent'] ?? '';
		}
		if ( $pct === '' || ! is_numeric( $pct ) ) {
			return '—';
		}
		return rtrim( rtrim( number_format( (float) $pct, 2, ',', ' ' ), '0' ), ',' ) . ' %';
	}

	private static function render_onboarding( int $vendor_id, ?Status $status ): void {
		$is_active = $status === Status::ACTIVE;

		// Krok: schváleno (>= approved_awaiting_kyc).
		$approved = in_array( $status, [ Status::APPROVED_AWAITING_KYC, Status::ACTIVE ], true );
		// KYC = SKUTEČNÉ ověření Stripe účtu (charges enabled), ne celkový status
		// (ten se u nového účtu může defaultně brát jako active → falešné hotovo).
		$kyc = \NKZMP\Dashboard\VendorContext::is_kyc_done( $vendor_id );
		// Předplatné (jen pokud billing modul + zapnuto).
		$billing_on = class_exists( \NKZMP\Billing\Settings::class ) && \NKZMP\Billing\Settings::is_enabled();
		// Self-heal ze Stripe, když stav není „active" (řeší „zaplatil, ale
		// ukazuje neaktivní" – webhook nedorazil). Throttlováno uvnitř.
		if ( $billing_on && class_exists( \NKZMP\Billing\AccountSection::class ) ) {
			\NKZMP\Billing\AccountSection::reconcile_status( $vendor_id );
		}
		$billing_ok = $billing_on && (string) get_post_meta( $vendor_id, '_nkzmp_billing_status', true ) === 'active';
		// První produkt.
		$has_product = self::vendor_has_product( $vendor_id );

		$steps = [];
		$steps[] = [ 'done' => $approved, 'label' => __( 'Přihláška schválena', 'nkz-mp-vendor-dashboard' ), 'cta' => null ];

		// KYC CTA: pokud máme Stripe adapter, vendor je už schválený (approved_awaiting_kyc)
		// a KYC neproběhlo, ukážeme „Dokončit registraci“ → Stripe Connect onboarding URL.
		$kyc_cta = null;
		if ( ! $kyc && $approved && class_exists( \NKVSVS\Onboarding_Controller::class ) ) {
			$stripe_url = (string) \NKVSVS\Onboarding_Controller::vendor_start_url( $vendor_id );
			if ( $stripe_url !== '' ) {
				$kyc_cta = [ $stripe_url, __( 'Dokončit registraci', 'nkz-mp-vendor-dashboard' ), true ];
			}
		}
		$steps[] = [ 'done' => $kyc, 'label' => __( 'Ověření totožnosti pro přijímání plateb', 'nkz-mp-vendor-dashboard' ), 'cta' => $kyc_cta ];
		if ( $billing_on ) {
			$steps[] = [
				'done'  => $billing_ok,
				'label' => __( 'Aktivní předplatné', 'nkz-mp-vendor-dashboard' ),
				'cta'   => $billing_ok ? null : [ wc_get_account_endpoint_url( 'vendor-billing' ), __( 'Aktivovat', 'nkz-mp-vendor-dashboard' ) ],
			];
		}
		$steps[] = [
			'done'  => $has_product,
			'label' => __( 'První produkt', 'nkz-mp-vendor-dashboard' ),
			'cta'   => $has_product ? null : [ add_query_arg( 'new', '1', wc_get_account_endpoint_url( 'vendor-products' ) ), __( 'Přidat', 'nkz-mp-vendor-dashboard' ) ],
		];

		// Pokud vše hotové, checklist nezobrazuj.
		$all_done = true;
		foreach ( $steps as $s ) {
			if ( ! $s['done'] ) {
				$all_done = false;
				break;
			}
		}
		if ( $all_done ) {
			return;
		}

		$first_undone = true;
		?>
		<section class="nkzmp-vd-onboarding">
			<h2><?php esc_html_e( 'Než začneš prodávat', 'nkz-mp-vendor-dashboard' ); ?></h2>
			<ul class="nkzmp-vd-steps">
				<?php foreach ( $steps as $s ) :
					$cls = $s['done'] ? 'done' : ( $first_undone ? 'current' : '' );
					if ( ! $s['done'] ) { $first_undone = false; }
					?>
					<li class="<?php echo esc_attr( $cls ); ?>">
						<span class="nkzmp-vd-step-mark"><?php echo $s['done'] ? '✓' : '•'; ?></span>
						<span class="nkzmp-vd-step-label"><?php echo esc_html( $s['label'] ); ?></span>
						<?php if ( ! $s['done'] && $s['cta'] ) :
							$cta_blank = ! empty( $s['cta'][2] );
							?>
							<a class="nkzmp-vd-step-cta" href="<?php echo esc_url( $s['cta'][0] ); ?>"<?php echo $cta_blank ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $s['cta'][1] ); ?> →</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php $note = self::payments_fee_note();
			if ( $note !== '' ) : ?>
				<p class="nkzmp-vd-steps-note" style="margin:14px 0 0;font-size:12.5px;line-height:1.5;color:rgba(17,17,17,0.55);">
					<?php echo esc_html( $note ); ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Vysvětlivka ke Stripe poplatku platební brány. Text podle toho, kdo ho
	 * nese (stripe_fee_vendor_share_percent). Hodnoty filtrovatelné.
	 */
	private static function payments_fee_note(): string {
		$pct   = (float) apply_filters( 'nkzmp/v1/dashboard/stripe_fee_percent', 1.5 );
		$fixed = (float) apply_filters( 'nkzmp/v1/dashboard/stripe_fee_fixed', 6.5 );
		$share = 0;
		if ( class_exists( \NKVSVS\Plugin::class ) ) {
			$s     = \NKVSVS\Plugin::settings();
			$share = (int) ( $s['stripe_fee_vendor_share_percent'] ?? 0 );
		}
		$share   = (int) apply_filters( 'nkzmp/v1/dashboard/stripe_fee_vendor_share', $share );
		$pct_s   = rtrim( rtrim( number_format( $pct, 2, ',', '' ), '0' ), ',' );
		$fixed_s = rtrim( rtrim( number_format( $fixed, 2, ',', '' ), '0' ), ',' );

		if ( $share > 0 ) {
			return sprintf(
				/* translators: 1: procento, 2: fixní částka */
				__( 'Platby zpracovává platební brána Stripe, která si účtuje %1$s %% + %2$s Kč za transakci — odečítá se z výplaty. Ověření totožnosti vyžaduje Stripe, aby ti mohl posílat peníze (zákonná povinnost proti praní špinavých peněz).', 'nkz-mp-vendor-dashboard' ),
				$pct_s,
				$fixed_s
			);
		}
		return __( 'Platby zpracovává platební brána Stripe. Poplatky brány za tebe hradíme my. Ověření totožnosti vyžaduje Stripe, aby ti mohl posílat peníze (zákonná povinnost proti praní špinavých peněz).', 'nkz-mp-vendor-dashboard' );
	}

	private static function vendor_has_product( int $vendor_id ): bool {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
			 WHERE pm.meta_key IN ('_nkzmp_vendor_id','_nkv_vendor_id') AND pm.meta_value = %d",
			$vendor_id
		) );
		return $count > 0;
	}

	private static function status_text( ?Status $s ): string {
		if ( ! $s ) {
			return __( 'Stav neznámý', 'nkz-mp-vendor-dashboard' );
		}
		return match ( $s ) {
			Status::PENDING               => __( 'V pořadníku', 'nkz-mp-vendor-dashboard' ),
			Status::APPROVED_AWAITING_KYC => __( 'Schváleno, čeká na ověření totožnosti', 'nkz-mp-vendor-dashboard' ),
			Status::ACTIVE                => __( 'Aktivní', 'nkz-mp-vendor-dashboard' ),
			Status::SUSPENDED             => __( 'Pozastaveno', 'nkz-mp-vendor-dashboard' ),
			Status::REJECTED              => __( 'Nezařazeno', 'nkz-mp-vendor-dashboard' ),
			Status::TERMINATED            => __( 'Ukončeno', 'nkz-mp-vendor-dashboard' ),
		};
	}

	private static function status_tone( ?Status $s ): string {
		if ( ! $s ) {
			return 'neutral';
		}
		return match ( $s ) {
			Status::ACTIVE                                 => 'success',
			Status::APPROVED_AWAITING_KYC                  => 'accent',
			Status::SUSPENDED                              => 'error',
			Status::REJECTED, Status::TERMINATED           => 'muted',
			default                                        => 'neutral',
		};
	}
}
