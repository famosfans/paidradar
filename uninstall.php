<?php
/**
 * Uninstall cleanup (opt-in).
 *
 * Removes the audit table and options only when the store owner has
 * explicitly opted into full cleanup via the `ordermend_delete_data_on_uninstall`
 * option. Otherwise data is preserved (audit trails are compliance-relevant).
 *
 * @package OrderMend
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'ordermend_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- teardown of custom table.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ordermend_log' );

$options = array(
	'ordermend_lookback_days',
	'ordermend_batch_size',
	'ordermend_enabled_gateways',
	'ordermend_alert_email',
	'ordermend_db_version',
	'ordermend_last_run',
	'ordermend_last_run_summary',
	'ordermend_recovered_lifetime_total',
	'ordermend_delete_data_on_uninstall',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'ordermend_recent_recoveries' );
delete_transient( 'ordermend_ppcp_token' );
