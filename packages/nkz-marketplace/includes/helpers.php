<?php
/**
 * Procedural helpers for NKZ Marketplace.
 *
 * Money helpers live in \NKZMP\Support\Money once extracted from the Stripe
 * adapter; this file exists so plugin bootstrap can require it unconditionally
 * even when no procedural helpers are needed yet.
 *
 * @package NKZMP
 */

defined( 'ABSPATH' ) || exit;
