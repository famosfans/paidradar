<?php
/**
 * Uninstall cleanup (opt-in).
 *
 * Removes the audit table and options only when the store owner has
 * explicitly opted into full cleanup via the `paidradar_delete_data_on_uninstall`
 * option. Otherwise data is preserved (audit trails are compliance-relevant).
 *
 * @package PaidRadar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'paidradar_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- teardown of custom table.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'paidradar_log' );

$options = array(
	'paidradar_lookback_days',
	'paidradar_batch_size',
	'paidradar_enabled_gateways',
	'paidradar_alert_email',
	'paidradar_db_version',
	'paidradar_last_run',
	'paidradar_last_run_summary',
	'paidradar_recovered_lifetime_total',
	'paidradar_delete_data_on_uninstall',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'paidradar_recent_recoveries' );
delete_transient( 'paidradar_ppcp_token' );
