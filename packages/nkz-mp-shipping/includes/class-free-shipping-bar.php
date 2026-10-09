<?php
/**
 * FreeShippingBar – „Ještě 340 Kč a máš dopravu od značky X zdarma".
 *
 * V košíku, v pokladně a v mini-košíku ukáže u každého prodejce (každý
 * posílá svůj balík), kolik zbývá do dopravy zdarma, s lištou postupu.
 * Zobrazí se jen, když je práh nastavený (Doprava → Doprava zdarma od).
 *
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

final class FreeShippingBar {

	private static ?FreeShippingBar $instance = null;

	public static function instance(): FreeShippingBar {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'woocommerce_before_cart_table', [ $this, 'render' ], 5 );
		add_action( 'woocommerce_checkout_before_order_review', [ $this, 'render' ], 5 );
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', [ $this, 'render_compact' ], 5 );
	}

	/**
	 * Řádky: [ vendor_id, název, hodnota zboží, chybí, hotovo ].
	 *
	 * @return array<int,array{0:int,1:string,2:float,3:float,4:bool}>
	 */
	public static function rows( array $contents ): array {
		$free = Rate::free_from();
		if ( $free <= 0 ) {
			return [];
		}
		$vendors = [];
		foreach ( $contents as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product || ! Rate::product_requires_shipping( $product ) ) {
				continue;
			}
			$pid = $product->get_parent_id() ?: $product->get_id();
			$vid = Rate::product_vendor_id( $pid );
			if ( $vid <= 0 ) {
				$vid = Rate::product_vendor_id( $product->get_id() );
			}
			$vendors[ max( 0, $vid ) ] = true;
		}
		$out = [];
		foreach ( array_keys( $vendors ) as $vid ) {
			$sum    = Rate::vendor_subtotal( (int) $vid, [ 'contents' => $contents ] );
			$name   = $vid > 0 ? (string) get_the_title( $vid ) : (string) get_bloginfo( 'name' );
			$out[]  = [ (int) $vid, $name, $sum, max( 0.0, $free - $sum ), $sum >= $free ];
		}
		return $out;
	}

	private static function contents(): array {
		return function_exists( 'WC' ) && WC() && WC()->cart ? (array) WC()->cart->get_cart() : [];
	}

	public function render(): void {
		$rows = self::rows( self::contents() );
		if ( ! $rows ) {
			return;
		}
		$free  = Rate::free_from();
		$multi = count( $rows ) > 1;
		echo '<div class="nkzmp-freeship" style="margin:0 0 20px;padding:14px 16px;border-radius:14px;background:#f2f6ff;">';
		foreach ( $rows as [ $vid, $name, $sum, $left, $done ] ) {
			$pct = (int) min( 100, round( $sum / $free * 100 ) );
			if ( $done ) {
				$text = $multi
					/* translators: %s: značka */
					? sprintf( __( '✓ Doprava od %s je zdarma', 'nkz-mp-shipping' ), '<strong>' . esc_html( $name ) . '</strong>' )
					: __( '✓ Máš dopravu zdarma', 'nkz-mp-shipping' );
			} else {
				$amount = '<strong>' . wp_strip_all_tags( wc_price( $left ) ) . '</strong>';
				$text   = $multi
					/* translators: 1: částka, 2: značka */
					? sprintf( __( 'Ještě %1$s od %2$s a doprava za tenhle balík je zdarma', 'nkz-mp-shipping' ), $amount, '<strong>' . esc_html( $name ) . '</strong>' )
					/* translators: %s: částka */
					: sprintf( __( 'Ještě %s a máš dopravu zdarma', 'nkz-mp-shipping' ), $amount );
			}
			printf(
				'<div class="nkzmp-freeship__row" style="margin:6px 0;"><div style="font-size:14px;color:#1f2937;margin:0 0 6px;">%s</div><div style="height:6px;border-radius:999px;background:#d9e3fb;overflow:hidden;"><span style="display:block;height:100%%;width:%d%%;background:%s;border-radius:999px;"></span></div></div>',
				wp_kses( $text, [ 'strong' => [] ] ),
				$pct,
				$done ? '#1a7f37' : '#0060FF'
			);
		}
		echo '</div>';
	}

	/** Mini-košík: jen jeden řádek textu. */
	public function render_compact(): void {
		$rows = self::rows( self::contents() );
		if ( ! $rows ) {
			return;
		}
		$open = array_filter( $rows, static fn( $r ) => ! $r[4] );
		if ( ! $open ) {
			echo '<p class="nkzmp-freeship-mini" style="margin:0 0 10px;font-size:13px;color:#1a7f37;">' . esc_html__( '✓ Doprava zdarma', 'nkz-mp-shipping' ) . '</p>';
			return;
		}
		$r = reset( $open );
		/* translators: %s: částka */
		echo '<p class="nkzmp-freeship-mini" style="margin:0 0 10px;font-size:13px;color:#0060FF;">' . esc_html( sprintf( __( 'Ještě %s do dopravy zdarma', 'nkz-mp-shipping' ), wp_strip_all_tags( wc_price( $r[3] ) ) ) ) . '</p>';
	}
}
