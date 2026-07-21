<?php
/**
 * PHPUnit bootstrap for OrderMend unit tests (Brain Monkey, no WP install).
 *
 * @package OrderMend
 */

// Composer autoload (phpunit, brain/monkey, mockery).
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

// Guard against a missing ABSPATH check killing the class files when
// they are required outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Common WP constants used by the plugin sources.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Load the plugin's value objects / units under test directly (the runtime
// autoloader lives in the main plugin file which we don't boot here).
$includes = dirname( __DIR__ ) . '/includes';
require_once $includes . '/adapters/class-payment-status.php';
require_once $includes . '/adapters/interface-status-adapter.php';
require_once $includes . '/adapters/class-stripe-adapter.php';
require_once $includes . '/adapters/class-paypal-adapter.php';
require_once $includes . '/adapters/class-adapter-registry.php';
require_once $includes . '/recovery/class-recovery-lock.php';
require_once $includes . '/audit/class-audit-log.php';
require_once $includes . '/recovery/class-reconciler.php';
