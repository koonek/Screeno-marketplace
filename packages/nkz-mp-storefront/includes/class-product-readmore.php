<?php
/**
 * ProductReadmore – sbalí dlouhý popis produktu do "roletky" s tlačítkem
 * „Zobrazit více / méně". Řeší dlouhé scrollování hlavně na mobilu.
 *
 * Cílí na:
 *  - .woocommerce-product-details__short-description (krátký popis v summary)
 *  - #tab-description / .woocommerce-Tabs-panel--description (dlouhý popis)
 *
 * Aktivní jen na single product. Sbalí se jen když obsah přeteče limit
 * (jinak se tlačítko neukáže). Inline JS+CSS, žádná extra dependency.
 *
 * Vypnutí: add_filter( 'nkzmp/v1/storefront/product_readmore', '__return_false' );
 * Výška:   add_filter( 'nkzmp/v1/storefront/product_readmore_height', fn() => 220 );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ProductReadmore {

	private static ?ProductReadmore $instance = null;

	public static function instance(): ProductReadmore {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 21 );
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		if ( ! apply_filters( 'nkzmp/v1/storefront/product_readmore', true ) ) {
			return;
		}

		$max_h    = (int) apply_filters( 'nkzmp/v1/storefront/product_readmore_height', 220 );
		$max_h    = max( 80, $max_h );
		$more_txt = esc_js( __( 'Zobrazit více', 'nkz-mp-storefront' ) );
		$less_txt = esc_js( __( 'Zobrazit méně', 'nkz-mp-storefront' ) );

		$css = "
.nkzmp-rm{position:relative;}
.nkzmp-rm.is-clamped .nkzmp-rm__inner{max-height:{$max_h}px;overflow:hidden;}
.nkzmp-rm.is-clamped:not(.is-open) .nkzmp-rm__inner{-webkit-mask-image:linear-gradient(180deg,#000 60%,transparent);mask-image:linear-gradient(180deg,#000 60%,transparent);}
.nkzmp-rm.is-open .nkzmp-rm__inner{max-height:none;}
.nkzmp-rm .nkzmp-rm__toggle,
.nkzmp-rm .nkzmp-rm__toggle:link,
.nkzmp-rm .nkzmp-rm__toggle:visited{display:inline-flex !important;align-items:center !important;gap:6px !important;margin-top:12px !important;padding:8px 16px !important;border:1px solid var(--nkzmp-color-border,#ddd) !important;border-radius:var(--nkzmp-radius-soft,8px) !important;background:var(--nkzmp-color-surface,#fff) !important;background-image:none !important;color:var(--nkzmp-color-accent,#0060FF) !important;font-size:14px !important;font-weight:600 !important;line-height:1.2 !important;cursor:pointer !important;box-shadow:none !important;text-shadow:none !important;transition:background .15s ease,border-color .15s ease,color .15s ease !important;}
.nkzmp-rm .nkzmp-rm__toggle:hover,
.nkzmp-rm .nkzmp-rm__toggle:focus,
.nkzmp-rm .nkzmp-rm__toggle:active{background:var(--nkzmp-color-accent,#0060FF) !important;background-image:none !important;border-color:var(--nkzmp-color-accent,#0060FF) !important;color:#fff !important;box-shadow:none !important;outline:none !important;}
.nkzmp-rm .nkzmp-rm__toggle::after{content:'▾';font-size:11px;transition:transform .2s ease;}
.nkzmp-rm.is-open .nkzmp-rm__toggle::after{transform:rotate(180deg);}
";

		$js = <<<JS
(function(){
	var MAXH = {$max_h};
	var MORE = '{$more_txt}';
	var LESS = '{$less_txt}';
	var SELECTORS = [
		'.woocommerce-product-details__short-description',
		'#tab-description',
		'.woocommerce-Tabs-panel--description',
		'.wc-block-components-product-details'
	];
	function wrap(el){
		if(!el || el.dataset.nkzmpRm){ return; }
		el.dataset.nkzmpRm = '1';
		// pokud se obsah vejde, nedelej nic
		if(el.scrollHeight <= MAXH + 24){ return; }
		el.classList.add('nkzmp-rm','is-clamped');
		// zabalit deti do __inner
		var inner = document.createElement('div');
		inner.className = 'nkzmp-rm__inner';
		while(el.firstChild){ inner.appendChild(el.firstChild); }
		el.appendChild(inner);
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'nkzmp-rm__toggle';
		btn.textContent = MORE;
		btn.setAttribute('aria-expanded','false');
		btn.addEventListener('click', function(){
			var open = el.classList.toggle('is-open');
			btn.textContent = open ? LESS : MORE;
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			if(!open){ el.scrollIntoView({block:'nearest',behavior:'smooth'}); }
		});
		el.appendChild(btn);
	}
	function run(){
		SELECTORS.forEach(function(sel){
			document.querySelectorAll(sel).forEach(wrap);
		});
	}
	if(document.readyState === 'loading'){
		document.addEventListener('DOMContentLoaded', run);
	}else{
		run();
	}
})();
JS;

		wp_register_style( 'nkzmp-product-readmore', false, [], NKZMP_STOREFRONT_VERSION );
		wp_enqueue_style( 'nkzmp-product-readmore' );
		wp_add_inline_style( 'nkzmp-product-readmore', $css );
		wp_register_script( 'nkzmp-product-readmore', '', [], NKZMP_STOREFRONT_VERSION, true );
		wp_enqueue_script( 'nkzmp-product-readmore' );
		wp_add_inline_script( 'nkzmp-product-readmore', $js );
	}
}
