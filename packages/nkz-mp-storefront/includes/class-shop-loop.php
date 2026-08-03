<?php
/**
 * ShopLoop – vizuální tuning archive-product stránky (/obchod/, taxonomy
 * archivy, related products loop) pro marketplace kontext.
 *
 * Tři vrstvy přes WC hooky (žádný template override):
 *  1) Intro řádek nad gridem („Tvorby od X prodejců · Y produktů")
 *  2) Vendor badge pod titulem každé karty produktu („od Jan Tvůrce")
 *  3) CTA „Chceš taky prodávat?" pod gridem (cross-link na /registrace/)
 *
 * Vypnutí: add_filter( 'nkzmp/v1/storefront/shop_loop', '__return_false' );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ShopLoop {

	private const COUNT_CACHE_KEY = 'nkzmp_active_vendor_count';
	private const COUNT_CACHE_TTL = HOUR_IN_SECONDS;

	private static ?ShopLoop $instance = null;

	public static function instance(): ShopLoop {
		return self::$instance ??= new self();
	}

	/**
	 * Vlastní „SLEVA!" badge. Vrací vždy neprázdný text, aby se nestalo, že
	 * šablona vyrenderuje prázdnou pilulku.
	 *
	 * @param string $html    Původní HTML badge.
	 * @param mixed  $post    WP_Post.
	 * @param mixed  $product WC_Product.
	 */
	public function sale_flash( $html, $post = null, $product = null ): string {
		$label = (string) apply_filters(
			'nkzmp/v1/storefront/sale_label',
			__( 'Sleva!', 'nkz-mp-storefront' ),
			$product
		);
		if ( '' === trim( $label ) ) {
			return (string) $html;
		}
		// Barvu píšeme inline s !important. Inline `!important` má nejvyšší
		// prioritu v kaskádě, takže badge zůstane čitelný i když ho přebíjí
		// jiná (klidně cachovaná) vrstva stylů nebo Elementor.
		$style = 'color:#fff !important;-webkit-text-fill-color:#fff !important;';
		return '<span class="onsale nkzmp-onsale" style="' . esc_attr( $style ) . '">' . esc_html( $label ) . '</span>';
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/storefront/shop_loop', true ) ) {
			return;
		}
		// Default WC sort dropdown + result count odstranit – nahrazujeme je
		// naším intro toolbarem ('Tvorby od X prodejců' + custom sort vpravo).
		// Deferred na 'wp', aby v té době už WC mělo akce přidané.
		add_action( 'wp', static function (): void {
			remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
			remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
		} );

		add_action( 'woocommerce_before_shop_loop', [ $this, 'intro_row' ], 5 );
		add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'vendor_badge' ], 8 );
		add_action( 'woocommerce_after_main_content', [ $this, 'become_vendor_cta' ], 5 );

		// Sale badge vlastní. Některé šablony renderují prázdný/špatně obarvený
		// `.onsale` (v obchodě z něj byla jen černá pilulka bez textu), proto si
		// obsah i markup určíme sami – text je pak vždy vidět a shodný s tím,
		// co ukazuje landing page. Text: filtr `nkzmp/v1/storefront/sale_label`.
		add_filter( 'woocommerce_sale_flash', [ $this, 'sale_flash' ], 20, 3 );

		// Single product page: vendor badge hned pod titulem (priority 6,
		// mezi title @5 a price @10). Větší varianta s 36px avatarem.
		add_action( 'woocommerce_single_product_summary', [ $this, 'single_vendor_badge' ], 6 );

		// Breadcrumb „Domů" na WC stránkách vede na marketplace landing
		// místo rootu webu – obchod je sekce marketplace, ne celého webu.
		// Override: add_filter( 'nkzmp/v1/storefront/breadcrumb_home_url', ... )
		add_filter( 'woocommerce_breadcrumb_home_url', static function ( $url ) {
			return (string) apply_filters(
				'nkzmp/v1/storefront/breadcrumb_home_url',
				home_url( '/marketplace/' ),
				$url
			);
		} );

		// Invalidace cache počtu aktivních vendorů.
		add_action( 'save_post_nkzmp_vendor', [ __CLASS__, 'forget_count' ] );
		add_action( 'deleted_post', [ __CLASS__, 'forget_count' ] );
		add_action( 'updated_post_meta', [ __CLASS__, 'maybe_forget_count' ], 10, 3 );
	}

	/** Pouze nad hlavním shop / category archivem produktů. */
	private static function applicable(): bool {
		if ( ! function_exists( 'is_shop' ) ) {
			return false;
		}
		// Shortcode [nkzmp_latest_products] může vynutit shop-loop chování i mimo /obchod/.
		if ( ! empty( $GLOBALS['nkzmp_force_shop_loop'] ) ) {
			return true;
		}
		return is_shop() || is_product_taxonomy();
	}

	public function intro_row(): void {
		if ( ! self::applicable() ) {
			return;
		}
		global $wp_query;
		$total       = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
		$vendor_n    = self::active_vendor_count();

		echo '<div class="nkzmp-shop-intro">';
		echo '<div class="nkzmp-shop-intro__left">';
		if ( $vendor_n > 0 ) {
			printf(
				'<span class="nkzmp-shop-intro__lead">%s</span>',
				esc_html(
					sprintf(
						/* translators: 1: number of active vendors, 2: number of products */
						_n(
							'Tvorby od %1$d prodejce · %2$d produktů',
							'Tvorby od %1$d prodejců · %2$d produktů',
							$vendor_n,
							'nkz-mp-storefront'
						),
						$vendor_n,
						$total
					)
				)
			);
		} elseif ( $total > 0 ) {
			printf(
				'<span class="nkzmp-shop-intro__lead">%s</span>',
				esc_html(
					sprintf(
						/* translators: %d: number of products */
						_n( '%d produkt', '%d produktů', $total, 'nkz-mp-storefront' ),
						$total
					)
				)
			);
		}
		echo '</div>'; // .nkzmp-shop-intro__left

		// Sort dropdown přesuneme do toolbaru (vpravo) – default WC ho
		// vykresluje samostatně přes woocommerce_catalog_ordering, my ho
		// tady duplikujeme do toolbaru a default skryjeme přes CSS níž.
		echo '<div class="nkzmp-shop-intro__right">';
		if ( function_exists( 'woocommerce_catalog_ordering' ) ) {
			woocommerce_catalog_ordering();
		}
		echo '</div>';

		echo '</div>'; // .nkzmp-shop-intro
	}

	public function vendor_badge(): void {
		if ( ! self::applicable() ) {
			return;
		}
		$vendor = self::resolve_current_product_vendor();
		if ( $vendor === null ) {
			return;
		}
		self::render_vendor_badge( $vendor, 'shop' );
	}

	/** Single product summary: vendor badge pod titulem produktu. */
	public function single_vendor_badge(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$vendor = self::resolve_current_product_vendor();
		if ( $vendor === null ) {
			return;
		}
		self::render_vendor_badge( $vendor, 'single' );
	}

	/**
	 * @return array{id:int,name:string,url:string,avatar_id:int}|null
	 */
	private static function resolve_current_product_vendor(): ?array {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		$pid = $product->get_parent_id() ?: $product->get_id();
		$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		if ( $vid <= 0 ) {
			$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
		}
		if ( $vid <= 0 ) {
			return null;
		}
		$post = get_post( $vid );
		if ( ! $post ) {
			return null;
		}
		$bio = (string) get_post_meta( $vid, '_nkzmp_vendor_bio', true );
		if ( $bio === '' ) {
			$bio = (string) get_post_meta( $vid, '_nkv_vendor_bio', true );
		}

		$name = (string) $post->post_title;
		if ( class_exists( TextNormalize::class ) ) {
			$name = (string) TextNormalize::instance()->nfc( $name );
			$bio  = (string) TextNormalize::instance()->nfc( $bio );
		}

		return [
			'id'        => $vid,
			'name'      => $name,
			'url'       => $post->post_name !== '' ? home_url( '/vendor/' . $post->post_name ) : '',
			'avatar_id' => (int) get_post_thumbnail_id( $vid ),
			'bio'       => $bio,
		];
	}

	/** Render badge ve 2 variantách: 'shop' (uppercase mini) nebo 'single' (větší pod titulem). */
	private static function render_vendor_badge( array $vendor, string $context ): void {
		$class    = $context === 'single' ? 'nkzmp-single-vendor' : 'nkzmp-shop-vendor';
		$avatar_size = $context === 'single' ? [ 80, 80 ] : [ 48, 48 ];
		$avatar = $vendor['avatar_id']
			? wp_get_attachment_image( $vendor['avatar_id'], $avatar_size, false, [
				'class' => $class . '__avatar',
				'alt'   => esc_attr( $vendor['name'] ),
			] )
			: '';

		// Single product: jedna sjednocena karta (avatar + jmeno + bio), cela klikatelna.
		if ( $context === 'single' ) {
			echo '<a class="nkzmp-single-vendor" href="' . esc_url( $vendor['url'] ) . '" rel="author">';
			echo $avatar; // already escaped by wp_get_attachment_image
			echo '<div class="nkzmp-single-vendor__body">';
			echo '<div class="nkzmp-single-vendor__head">';
			echo '<span class="nkzmp-single-vendor__by">' . esc_html__( 'od', 'nkz-mp-storefront' ) . '</span> ';
			echo '<span class="nkzmp-single-vendor__name">' . esc_html( $vendor['name'] ) . '</span>';
			echo '</div>';
			if ( ! empty( $vendor['bio'] ) ) {
				$bio = wp_trim_words( wp_strip_all_tags( (string) $vendor['bio'] ), 28, '…' );
				echo '<p class="nkzmp-single-vendor__bio">' . esc_html( $bio ) . '</p>';
			}
			echo '</div>';
			echo '</a>';
			return;
		}

		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $vendor['url'] ) . '" rel="author">';
		echo $avatar; // already escaped by wp_get_attachment_image
		echo '<span class="' . esc_attr( $class ) . '__by">' . esc_html__( 'od', 'nkz-mp-storefront' ) . '</span> ';
		echo '<span class="' . esc_attr( $class ) . '__name">' . esc_html( $vendor['name'] ) . '</span>';
		echo '</a>';
	}

	public function become_vendor_cta(): void {
		if ( ! self::applicable() ) {
			return;
		}
		// Konfigurovatelný cíl – default /registrace/. Screeno si přebije.
		$url = (string) apply_filters( 'nkzmp/v1/storefront/become_vendor_url', home_url( '/registrace/' ) );
		if ( $url === '' ) {
			return;
		}
		echo '<aside class="nkzmp-shop-promo">';
		echo '<div class="nkzmp-shop-promo__body">';
		echo '<h3 class="nkzmp-shop-promo__h">' . esc_html__( 'Tvoříš taky?', 'nkz-mp-storefront' ) . '</h3>';
		echo '<p class="nkzmp-shop-promo__d">' . esc_html__( 'Art of život je kurátorský marketplace. Pošli nám svoji tvorbu k posouzení a staň se jedním z prodejců.', 'nkz-mp-storefront' ) . '</p>';
		echo '</div>';
		echo '<a class="nkzmp-shop-promo__cta" href="' . esc_url( $url ) . '">' . esc_html__( 'Stát se prodejcem →', 'nkz-mp-storefront' ) . '</a>';
		echo '</aside>';
	}

	/** Počet vendorů s status=active (cached 1 h). */
	private static function active_vendor_count(): int {
		$cached = get_transient( self::COUNT_CACHE_KEY );
		if ( is_numeric( $cached ) ) {
			return (int) $cached;
		}
		$q = new \WP_Query( [
			'post_type'      => [ 'nkzmp_vendor', 'nkv_vendor' ],
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => '_nkzmp_vendor_status', 'value' => 'active', 'compare' => '=' ],
				[ 'key' => '_nkv_vendor_status', 'value' => 'active', 'compare' => '=' ],
			],
		] );
		$count = (int) $q->post_count;
		set_transient( self::COUNT_CACHE_KEY, $count, self::COUNT_CACHE_TTL );
		return $count;
	}

	public static function forget_count(): void {
		delete_transient( self::COUNT_CACHE_KEY );
	}

	/** Invalidace pouze když se mění status meta. */
	public static function maybe_forget_count( $meta_id, $post_id, $meta_key ): void {
		if ( $meta_key === '_nkzmp_vendor_status' || $meta_key === '_nkv_vendor_status' ) {
			self::forget_count();
		}
	}
}
