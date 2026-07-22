<?php
/**
 * Plugin activation: audit table + default options.
 *
 * @package PaidRadar
 */

namespace PaidRadar;

defined( 'ABSPATH' ) || exit;

/**
 * Handles one-time activation tasks.
 */
class Activator {

	/**
	 * Default option values keyed by option name.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_options() {
		return array(
			'paidradar_lookback_days'   => 14,
			'paidradar_batch_size'      => 50,
			'paidradar_enabled_gateways' => array( 'stripe', 'stripe_cc', 'ppcp-gateway', 'paypal' ),
			'paidradar_alert_email'     => get_option( 'admin_email' ),
		);
	}

	/**
	 * Run activation: create table, seed options.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_table();
		self::seed_options();
	}

	/**
	 * Create the audit log table via dbDelta.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'paidradar_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			gateway VARCHAR(50) NOT NULL DEFAULT '',
			event VARCHAR(30) NOT NULL DEFAULT '',
			psp_status VARCHAR(30) NULL,
			status_before VARCHAR(30) NULL,
			status_after VARCHAR(30) NULL,
			amount DECIMAL(18,2) NULL,
			currency VARCHAR(3) NULL,
			psp_response LONGTEXT NULL,
			actor VARCHAR(20) NOT NULL DEFAULT 'cron',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY event (event),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'paidradar_db_version', PAIDRADAR_VERSION );
	}

	/**
	 * Seed default options without overwriting user changes.
	 *
	 * @return void
	 */
	public static function seed_options() {
		foreach ( self::default_options() as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value );
			}
		}
	}
}
