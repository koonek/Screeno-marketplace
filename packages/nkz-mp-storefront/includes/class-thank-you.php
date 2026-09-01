<?php
/**
 * ThankYou – tuning order-received stránky pro marketplace kontext.
 *
 * Tři vrstvy přes WC hooky (žádný template override):
 *  1) Personalizovaný headline s vokativem („Děkujeme, Ondřeji.")
 *  2) „Co se stane teď" 3-step strip nad order details
 *  3) „Od koho jsi nakoupil/a" karta(y) per vendor pod order details
 *  4) Continue shopping CTA na konec
 *
 * Bez přihlášení kupujícího – platforma je guest-checkout.
 *
 * Vypnutí: add_filter( 'nkzmp/v1/storefront/thankyou', '__return_false' );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ThankYou {

	private static ?ThankYou $instance = null;

	public static function instance(): ThankYou {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/storefront/thankyou', true ) ) {
			return;
		}
		add_filter( 'woocommerce_thankyou_order_received_text', [ $this, 'headline' ], 10, 2 );
		add_action( 'woocommerce_thankyou', [ $this, 'next_steps' ], 5 );
		add_action( 'woocommerce_thankyou', [ $this, 'vendor_cards' ], 20 );
		add_action( 'woocommerce_thankyou', [ $this, 'continue_shopping' ], 30 );
	}

	/**
	 * Headline → „Děkujeme, Ondřeji. Objednávka #1007 dorazila."
	 *
	 * @param string                $text
	 * @param \WC_Order|false|null  $order
	 */
	public function headline( $text, $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return (string) $text;
		}
		$first = trim( (string) $order->get_billing_first_name() );
		$voc   = '';
		if ( $first !== '' && class_exists( \NKZMP\Services\VocativeService::class ) ) {
			$voc = \NKZMP\Services\VocativeService::get( $first );
		}
		$number = (string) $order->get_order_number();

		if ( $voc !== '' ) {
			return sprintf(
				/* translators: 1: customer first name in vocative, 2: order number */
				__( 'Děkujeme, %1$s. Objednávka #%2$s dorazila.', 'nkz-mp-storefront' ),
				esc_html( $voc ),
				esc_html( $number )
			);
		}
		return sprintf(
			/* translators: %s: order number */
			__( 'Děkujeme. Objednávka #%s dorazila.', 'nkz-mp-storefront' ),
			esc_html( $number )
		);
	}

	/** „Co se stane teď" 3-step strip. */
	public function next_steps( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$email = $order->get_billing_email();
		$steps = [
			[
				'n'    => '1',
				'h'    => __( 'Potvrzení e-mailem', 'nkz-mp-storefront' ),
				'd'    => $email !== ''
					? sprintf(
						/* translators: %s: email */
						__( 'Posíláme na %s. Pokud nedorazí během pár minut, mrkni do spamu.', 'nkz-mp-storefront' ),
						esc_html( $email )
					)
					: __( 'Posíláme shrnutí na tvůj e-mail.', 'nkz-mp-storefront' ),
			],
			[
				'n' => '2',
				'h' => __( 'Prodejce balí', 'nkz-mp-storefront' ),
				'd' => __( 'Tvorba se balí ručně. Typicky 1–3 pracovní dny, u větších kusů déle.', 'nkz-mp-storefront' ),
			],
			[
				'n' => '3',
				'h' => __( 'Odesíláme přes Zásilkovnu', 'nkz-mp-storefront' ),
				'd' => __( 'Až prodejce zásilku podá, přijde ti tracking e-mail s odkazem na sledování.', 'nkz-mp-storefront' ),
			],
		];

		echo '<section class="nkzmp-thx-steps" aria-label="' . esc_attr__( 'Co se stane teď', 'nkz-mp-storefront' ) . '">';
		echo '<h2 class="nkzmp-thx-steps__title">' . esc_html__( 'Co se stane teď', 'nkz-mp-storefront' ) . '</h2>';
		echo '<ol class="nkzmp-thx-steps__list">';
		foreach ( $steps as $s ) {
			echo '<li class="nkzmp-thx-step">';
			echo '<span class="nkzmp-thx-step__num">' . esc_html( $s['n'] ) . '</span>';
			echo '<div class="nkzmp-thx-step__body">';
			echo '<h3 class="nkzmp-thx-step__h">' . esc_html( $s['h'] ) . '</h3>';
			echo '<p class="nkzmp-thx-step__d">' . esc_html( $s['d'] ) . '</p>';
			echo '</div></li>';
		}
		echo '</ol></section>';
	}

	/** „Od koho jsi nakoupil/a" – karta pro každého unikátního vendora v objednávce. */
	public function vendor_cards( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			return;
		}

		$seen    = [];
		$vendors = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$pid = $item->get_product_id();
			$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
			}
			if ( $vid <= 0 || isset( $seen[ $vid ] ) ) {
				continue;
			}
			$seen[ $vid ] = true;

			$v = ( new \NKZMP\Vendor\Repository() )->find( $vid );
			if ( ! $v ) {
				continue;
			}
			$post  = get_post( $vid );
			$slug  = $post ? $post->post_name : '';
			$url   = $slug !== '' ? home_url( '/vendor/' . $slug ) : '';
			$image = get_the_post_thumbnail_url( $vid, 'thumbnail' );

			$vendors[] = [
				'name'  => (string) $v['name'],
				'bio'   => (string) $v['bio'],
				'url'   => $url,
				'image' => $image ?: '',
			];
		}
		if ( empty( $vendors ) ) {
			return;
		}

		$title = count( $vendors ) === 1
			? __( 'Od koho jsi nakoupil/a', 'nkz-mp-storefront' )
			: __( 'Od koho jsi nakoupil/a', 'nkz-mp-storefront' );

		echo '<section class="nkzmp-thx-vendors">';
		echo '<h2 class="nkzmp-thx-vendors__title">' . esc_html( $title ) . '</h2>';
		echo '<div class="nkzmp-thx-vendors__grid">';
		foreach ( $vendors as $v ) {
			echo '<article class="nkzmp-thx-vendor">';
			if ( $v['image'] !== '' ) {
				printf(
					'<div class="nkzmp-thx-vendor__img" style="background-image:url(%s);"></div>',
					esc_url( $v['image'] )
				);
			} else {
				echo '<div class="nkzmp-thx-vendor__img nkzmp-thx-vendor__img--placeholder" aria-hidden="true">'
					. esc_html( mb_substr( $v['name'], 0, 1, 'UTF-8' ) )
					. '</div>';
			}
			echo '<div class="nkzmp-thx-vendor__body">';
			echo '<h3 class="nkzmp-thx-vendor__name">' . esc_html( $v['name'] ) . '</h3>';
			if ( $v['bio'] !== '' ) {
				echo '<p class="nkzmp-thx-vendor__bio">' . esc_html( wp_trim_words( $v['bio'], 22, '…' ) ) . '</p>';
			}
			if ( $v['url'] !== '' ) {
				echo '<a class="nkzmp-thx-vendor__link" href="' . esc_url( $v['url'] ) . '">'
					. esc_html__( 'Profil prodejce →', 'nkz-mp-storefront' )
					. '</a>';
			}
			echo '</div></article>';
		}
		echo '</div></section>';
	}

	/** CTA na konec – zpět do obchodu. */
	public function continue_shopping( $order_id ): void {
		$shop = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
		if ( $shop === '' ) {
			$shop = home_url( '/' );
		}
		echo '<div class="nkzmp-thx-cta">';
		echo '<a class="nkzmp-thx-cta__btn" href="' . esc_url( $shop ) . '">'
			. esc_html__( 'Pokračovat v prohlížení tvoreb', 'nkz-mp-storefront' )
			. '</a>';
		echo '</div>';
	}
}
