<?php
/**
 * WP-CLI: wp nkzmp allocation preview <order_id>
 *
 * Spočítá alokaci přes legacy Split_Calculator (pokud je Stripe adapter aktivní)
 * a vrátí mapování na Allocation[]. Slouží k ověření, že core mapper sedí
 * s adapterovou matematikou.
 *
 * ## OPTIONS
 *
 * <order_id>
 * : ID WC objednávky.
 *
 * [--format=<format>]
 * : table | json | yaml. Default: table.
 *
 * @package NKZMP
 */

namespace NKZMP\CLI;

use NKZMP\Allocation\Calculator;

defined( 'ABSPATH' ) || exit;

final class AllocationCommand {

	public function preview( array $args, array $assoc_args ): void {
		$order_id = (int) ( $args[0] ?? 0 );
		if ( $order_id <= 0 ) {
			\WP_CLI::error( 'Order ID is required.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			\WP_CLI::error( sprintf( 'Order #%d not found.', $order_id ) );
		}

		if ( ! class_exists( \NKVSVS\Split_Calculator::class ) ) {
			\WP_CLI::error( 'Stripe adapter (Split_Calculator) není dostupný. Allocation pure-math zatím neexistuje.' );
		}

		$calc        = \NKVSVS\Split_Calculator::calculate( $order );
		$allocations = Calculator::from_legacy_calc( $calc, $order );

		$rows = array_map(
			static fn( $a ) => [
				'vendor_id'        => $a->vendor_id,
				'currency'         => $a->currency,
				'gross_minor'      => $a->gross_minor,
				'commission_minor' => $a->commission_minor,
				'fee_share_minor'  => $a->fee_share_minor,
				'net_minor'        => $a->net_minor,
			],
			$allocations
		);

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( array_map( static fn( $a ) => $a->to_array(), $allocations ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, [ 'vendor_id', 'currency', 'gross_minor', 'commission_minor', 'fee_share_minor', 'net_minor' ] );

		$skipped = array_filter( (array) ( $calc['vendors'] ?? [] ), static fn( $v ) => ! empty( $v['reason_skipped'] ) );
		if ( $skipped ) {
			\WP_CLI::line( '' );
			\WP_CLI::warning( sprintf( 'Skipped vendors: %d', count( $skipped ) ) );
			foreach ( $skipped as $v ) {
				\WP_CLI::line( sprintf( '  - vendor #%d: %s', $v['vendor_id'] ?? 0, $v['reason_skipped'] ) );
			}
		}
	}
}
