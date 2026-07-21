<?php
/**
 * Plugin Name:       OrderMend – WooCommerce Payment Recovery & Stuck Order Fix
 * Plugin URI:        https://ordermend.com/
 * Description:       Finds WooCommerce orders stuck in pending/on-hold/failed whose payment actually succeeded at the gateway (missed webhook), re-queries the gateway API read-only, completes them correctly, writes an audit trail and alerts the admin. Supports Stripe + PayPal.
 * Version:           1.0.0
 * Author:            famosMedia Technologies Ltd.
 * Author URI:        https://famosmedia.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ordermend
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.2
 * Requires Plugins:  woocommerce
 *
 * @package OrderMend
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core plugin constants.
 */
define( 'ORDERMEND_VERSION', '1.0.0' );
define( 'ORDERMEND_FILE', __FILE__ );
define( 'ORDERMEND_PATH', plugin_dir_path( __FILE__ ) );
define( 'ORDERMEND_URL', plugin_dir_url( __FILE__ ) );
define( 'ORDERMEND_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Lightweight PSR-4-ish autoloader for the `OrderMend\` namespace.
 *
 * Maps `OrderMend\Some\Thing_Name` to
 * `includes/some/class-thing-name.php` (interfaces to `interface-*.php`).
 * Kept dependency-free so the plugin loads without Composer.
 *
 * @param string $class Fully-qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'OrderMend\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );

		$parts     = explode( '/', $relative );
		$base_name = array_pop( $parts );
		$dir       = strtolower( implode( '/', $parts ) );

		// Convert StudlyCase/underscored class name to kebab-case file token.
		$file_token = strtolower( str_replace( '_', '-', $base_name ) );

		$prefixes = ( false !== strpos( $base_name, 'Interface' ) || 'Status_Adapter' === $base_name )
			? array( 'interface-', 'class-' )
			: array( 'class-', 'interface-' );

		foreach ( $prefixes as $type ) {
			$path = ORDERMEND_PATH . 'includes/' . ( '' !== $dir ? $dir . '/' : '' ) . $type . $file_token . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

/**
 * Declare WooCommerce feature compatibility (HPOS + Cart/Checkout Blocks).
 *
 * Must run on `before_woocommerce_init`.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ORDERMEND_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ORDERMEND_FILE, true );
		}
	}
);

/**
 * Activation hook — create the audit table and default options.
 */
register_activation_hook(
	__FILE__,
	static function () {
		require_once ORDERMEND_PATH . 'includes/class-activator.php';
		\OrderMend\Activator::activate();
	}
);

/**
 * Boot the plugin container once all plugins are loaded and WooCommerce is present.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'OrderMend requires WooCommerce to be installed and active.', 'ordermend' );
					echo '</p></div>';
				}
			);
			return;
		}

		\OrderMend\OrderMend::instance()->boot();
	}
);
