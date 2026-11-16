<?php
/**
 * 3-step intro karta nad registracnim formularem.
 *
 * Dostupne promenne:
 *   int    $amount        – mesicni clenstvi v CZK (z billing modulu)
 *   string $percent_str   – % provize z prodeje (z stripe split modulu, formatovane)
 *
 * @var int    $amount
 * @var string $percent_str
 */
defined( 'ABSPATH' ) || exit;
?>
<style>
.nkzmp-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin:0 0 40px;}
.nkzmp-steps__card{background:#fff;border:1px solid rgba(17,17,17,0.10);border-radius:12px;padding:32px 28px;display:flex;flex-direction:column;gap:16px;}
.nkzmp-steps__card--accent{background:var(--nkzmp-color-accent,#0060FF);color:#fff;border-color:transparent;}
.nkzmp-steps__num{width:40px;height:40px;border-radius:50%;border:1.5px solid var(--nkzmp-color-accent,#0060FF);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:16px;color:var(--nkzmp-color-accent,#0060FF);}
.nkzmp-steps__card--accent .nkzmp-steps__num{border-color:rgba(255,255,255,0.55);color:#fff;}
.nkzmp-steps__head{display:flex;align-items:center;justify-content:space-between;gap:12px;}
.nkzmp-steps__pill{display:inline-block;padding:6px 14px;border:1px solid rgba(255,255,255,0.55);border-radius:999px;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#fff;}
.nkzmp-steps__title{font-size:24px;line-height:1.2;font-weight:700;margin:8px 0 4px;color:inherit;}
.nkzmp-steps__price{font-size:24px;line-height:1;font-weight:700;letter-spacing:-0.01em;white-space:nowrap;}
.nkzmp-steps__price-meta{font-size:13px;opacity:0.85;line-height:1.4;}
.nkzmp-steps__desc{font-size:14px;line-height:1.5;color:rgba(17,17,17,0.65);margin:0;}
.nkzmp-steps__card--accent .nkzmp-steps__desc{color:rgba(255,255,255,0.92);}
.nkzmp-steps__price-row{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px 12px;margin-top:8px;}
.nkzmp-steps__price-meta{font-size:13px;opacity:0.85;line-height:1.4;}
@media (max-width:880px){.nkzmp-steps{grid-template-columns:1fr;gap:16px;}.nkzmp-steps__price{font-size:22px;}}
.nkzmp-benefits{margin:0 0 40px;padding:28px 32px;border-radius:12px;background:rgba(0,96,255,0.06);}
.nkzmp-benefits__title{margin:0 0 16px;font-size:20px;line-height:1.25;font-weight:700;color:#111;}
.nkzmp-benefits__list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,1fr);gap:12px 32px;}
.nkzmp-benefits__list li{position:relative;margin:0;padding:0 0 0 30px;font-size:15px;line-height:1.45;color:rgba(17,17,17,0.78);}
.nkzmp-benefits__list li strong{color:#111;font-weight:600;}
.nkzmp-benefits__list li::before{content:"✓";position:absolute;left:0;top:0;width:20px;height:20px;border-radius:50%;background:var(--nkzmp-color-accent,#0060FF);color:#fff;font-size:12px;font-weight:700;line-height:20px;text-align:center;}
@media (max-width:880px){.nkzmp-benefits{padding:22px 20px;}.nkzmp-benefits__list{grid-template-columns:1fr;}}
</style>

<div class="nkzmp-steps" aria-label="Jak to funguje">

	<article class="nkzmp-steps__card">
		<div class="nkzmp-steps__head"><div class="nkzmp-steps__num">1</div></div>
		<h3 class="nkzmp-steps__title"><?php esc_html_e( 'Zaregistruj se', 'nkz-mp-vendor-registration' ); ?></h3>
		<p class="nkzmp-steps__desc">
			<?php esc_html_e( 'Vytvoř si profil tvůrce a pošli ukázku své práce. Každého tvůrce vybíráme – kurátorovaný výběr je to, co Art of život drží.', 'nkz-mp-vendor-registration' ); ?>
		</p>
	</article>

	<article class="nkzmp-steps__card nkzmp-steps__card--accent">
		<div class="nkzmp-steps__head">
			<div class="nkzmp-steps__num">2</div>
			<span class="nkzmp-steps__pill"><?php esc_html_e( 'Členství', 'nkz-mp-vendor-registration' ); ?></span>
		</div>
		<h3 class="nkzmp-steps__title"><?php esc_html_e( 'Aktivuj členství', 'nkz-mp-vendor-registration' ); ?></h3>
		<div class="nkzmp-steps__price-row">
			<div class="nkzmp-steps__price"><?php echo esc_html( number_format( (int) $amount, 0, ',', ' ' ) ); ?> Kč</div>
			<div class="nkzmp-steps__price-meta">
				<?php
				/* translators: %s = percento */
				printf( esc_html__( '/ měsíc + %s %% z prodeje', 'nkz-mp-vendor-registration' ), esc_html( $percent_str ) );
				?>
			</div>
		</div>
		<p class="nkzmp-steps__desc">
			<?php
			/* translators: %s = percento */
			printf( esc_html__( 'K měsíčnímu členství platíš %s %% provize z každého prodeje. Žádné skryté poplatky, zrušit můžeš kdykoli.', 'nkz-mp-vendor-registration' ), esc_html( $percent_str ) );
			?>
		</p>
	</article>

	<article class="nkzmp-steps__card">
		<div class="nkzmp-steps__head"><div class="nkzmp-steps__num">3</div></div>
		<h3 class="nkzmp-steps__title"><?php esc_html_e( 'Zveřejni produkt', 'nkz-mp-vendor-registration' ); ?></h3>
		<p class="nkzmp-steps__desc">
			<?php esc_html_e( 'Nahraj produkty, nastav ceny a prodávej po celý rok. Platby od zákazníků ti chodí přímo na účet.', 'nkz-mp-vendor-registration' ); ?>
		</p>
	</article>

</div>

<?php
// Vysvetlivka ke Stripe poplatku – text podle toho, kdo poplatek nese.
$stripe_note = '';
if ( isset( $stripe_share ) && (int) $stripe_share > 0 ) {
	$stripe_note = sprintf(
		/* translators: 1: procento, 2: fixni castka */
		__( 'K platbám se navíc vztahuje poplatek platební brány Stripe (%1$s %% + %2$s Kč za transakci), který si Stripe účtuje za zpracování platby a odečítá se z výplaty. Není to náš poplatek — my si účtujeme jen členství a provizi výše.', 'nkz-mp-vendor-registration' ),
		esc_html( $stripe_fee_pct_str ),
		esc_html( $stripe_fee_fixed_str )
	);
} else {
	$stripe_note = __( 'Poplatky platební brány Stripe za tebe hradíme my — z výplaty se ti nestrhávají.', 'nkz-mp-vendor-registration' );
}
?>
<p class="nkzmp-steps__note" style="margin:-20px 0 40px;font-size:12.5px;line-height:1.5;color:rgba(17,17,17,0.55);max-width:760px;">
	<?php echo esc_html( $stripe_note ); ?>
</p>

<?php
// Co členství obsahuje (text od klienta). Úprava bez zásahu do kódu:
// filtr nkzmp/v1/registration/membership_benefits ([ [ tučně, zbytek ], … ]).
$nkzmp_benefits = (array) apply_filters( 'nkzmp/v1/registration/membership_benefits', [
	[ __( 'Kurátorovaný výběr.', 'nkz-mp-vendor-registration' ), __( 'Tvoje značka se neztratí mezi stovkami dalších.', 'nkz-mp-vendor-registration' ) ],
	[ __( 'Propagace tvé značky a produktů napříč kanály.', 'nkz-mp-vendor-registration' ), __( 'Web, sociální sítě, reklamní kampaně a newsletter.', 'nkz-mp-vendor-registration' ) ],
	[ __( 'Možnost účasti na festivalu za zvýhodněný poplatek.', 'nkz-mp-vendor-registration' ), __( '1 500 Kč za 3 dny.', 'nkz-mp-vendor-registration' ) ],
	[ __( 'Vlastní profil značky', 'nkz-mp-vendor-registration' ), __( 'a prodejní stránka na marketplace.', 'nkz-mp-vendor-registration' ) ],
	[ __( 'Neomezený počet produktů.', 'nkz-mp-vendor-registration' ), '' ],
	[ __( 'Doprava po ČR a SK.', 'nkz-mp-vendor-registration' ), '' ],
	[ __( 'Bezpečné platby kartou.', 'nkz-mp-vendor-registration' ), __( 'Výplaty chodí přímo na tvůj účet.', 'nkz-mp-vendor-registration' ) ],
] );
if ( $nkzmp_benefits ) :
	?>
	<section class="nkzmp-benefits" aria-labelledby="nkzmp-benefits-title">
		<h3 class="nkzmp-benefits__title" id="nkzmp-benefits-title"><?php esc_html_e( 'Co zahrnuje členství', 'nkz-mp-vendor-registration' ); ?></h3>
		<ul class="nkzmp-benefits__list">
			<?php foreach ( $nkzmp_benefits as $b ) : ?>
				<li><strong><?php echo esc_html( (string) ( $b[0] ?? '' ) ); ?></strong><?php echo ! empty( $b[1] ) ? ' ' . esc_html( (string) $b[1] ) : ''; ?></li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
