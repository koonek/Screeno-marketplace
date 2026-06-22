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
.nkzmp-steps__price{font-size:48px;line-height:1;font-weight:700;letter-spacing:-0.02em;}
.nkzmp-steps__price-meta{font-size:13px;opacity:0.85;line-height:1.4;margin-top:8px;}
.nkzmp-steps__desc{font-size:14px;line-height:1.5;color:rgba(17,17,17,0.65);margin:0;}
.nkzmp-steps__card--accent .nkzmp-steps__desc{color:rgba(255,255,255,0.92);}
.nkzmp-steps__price-row{display:flex;align-items:flex-end;gap:16px;margin-top:8px;}
@media (max-width:880px){.nkzmp-steps{grid-template-columns:1fr;gap:16px;}.nkzmp-steps__price{font-size:36px;}}
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
