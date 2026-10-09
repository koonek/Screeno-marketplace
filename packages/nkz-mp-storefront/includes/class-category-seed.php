<?php
/**
 * CategorySeed – jednorázově založí podkategorie produktů (zadání klienta).
 *
 * Běží JEDNOU (příznak v options). Nic nemaže ani nepřejmenovává:
 *  - hlavní kategorii najde podle názvu (bez ohledu na velikost písmen
 *    a diakritiku), chybějící založí,
 *  - podkategorii založí jen, když pod danou hlavní ještě neexistuje.
 * Co správce později smaže nebo přejmenuje (Produkty → Kategorie), se už
 * znovu nezaloží.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class CategorySeed {

	public const FLAG = 'nkzmp_category_seed_v1';

	/** Hlavní kategorie => podkategorie (pořadí = pořadí v obchodě). */
	public const TREE = [
		'Domov'           => [ 'Nábytek', 'Osvětlení', 'Textil a koberce', 'Kuchyně a stolování', 'Dekorace', 'Svíčky a vůně do bytu' ],
		'Šperky'          => [ 'Náušnice', 'Náhrdelníky', 'Prsteny', 'Náramky', 'Brože a ozdoby na oblečení' ],
		'Kosmetika'       => [ 'Péče o pleť a tělo', 'Dekorativní kosmetika', 'Parfémy' ],
		'Móda a doplňky'  => [ 'Oblečení', 'Tašky', 'Doplňky a přívěsky' ],
		'Umění a design'  => [ 'Obrazy', 'Grafika a tisky', 'Sochy a objekty' ],
		'Ostatní'         => [ 'Matcha', 'Papírnictví a přání', 'Vstupenky na festival', 'Ostatní' ],
	];

	private static ?CategorySeed $instance = null;

	public static function instance(): CategorySeed {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Po registraci taxonomie WooCommerce (init 5).
		add_action( 'init', [ $this, 'maybe_seed' ], 99 );
	}

	public function maybe_seed(): void {
		if ( get_option( self::FLAG ) || ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}
		// Zámek proti souběhu dvou požadavků (add_option selže, když už existuje).
		if ( ! add_option( self::FLAG, 'running', '', false ) ) {
			return;
		}
		$report = self::seed();
		update_option( self::FLAG, [ 'at' => time(), 'created' => $report ], false );
		if ( class_exists( ShopFilters::class ) ) {
			ShopFilters::forget_cache();
		}
	}

	/**
	 * @return string[] názvy založených kategorií
	 */
	public static function seed(): array {
		$created = [];
		foreach ( self::TREE as $parent_name => $children ) {
			$parent_id = self::find( $parent_name, 0 );
			if ( ! $parent_id ) {
				$parent_id = self::create( $parent_name, 0 );
				if ( $parent_id ) {
					$created[] = $parent_name;
				}
			}
			if ( ! $parent_id ) {
				continue;
			}
			$i = 0;
			foreach ( $children as $child_name ) {
				++$i;
				if ( self::find( $child_name, $parent_id ) ) {
					continue;
				}
				$id = self::create( $child_name, $parent_id, $parent_name );
				if ( $id ) {
					$created[] = $parent_name . ' › ' . $child_name;
					// Pořadí podle zadání (WooCommerce řadí kategorie podle „order").
					update_term_meta( $id, 'order', $i );
				}
			}
		}
		return $created;
	}

	private static function fold( string $s ): string {
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		return trim( mb_strtolower( $s ) );
	}

	/** ID kategorie se jménem pod daným rodičem, jinak 0. */
	private static function find( string $name, int $parent ): int {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'parent'     => $parent,
		] );
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		foreach ( (array) $terms as $t ) {
			if ( self::fold( (string) $t->name ) === self::fold( $name ) ) {
				return (int) $t->term_id;
			}
		}
		return 0;
	}

	private static function create( string $name, int $parent, string $parent_name = '' ): int {
		// Slug: hezký („nabytek"); když je obsazený (např. „Ostatní" pod
		// „Ostatní"), tak s rodičem („ostatni-ostatni").
		$slug = sanitize_title( $name );
		if ( get_term_by( 'slug', $slug, 'product_cat' ) && $parent_name !== '' ) {
			$slug = sanitize_title( $parent_name . ' ' . $name );
		}
		$res = wp_insert_term( $name, 'product_cat', [ 'parent' => $parent, 'slug' => $slug ] );
		if ( is_wp_error( $res ) ) {
			error_log( '[NKZMP] category seed: ' . $name . ' – ' . $res->get_error_message() );
			return 0;
		}
		return (int) $res['term_id'];
	}
}
