<?php
/**
 * Admin notices after a recovery run.
 *
 * @package OrderMend
 */

namespace OrderMend\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a plain-language admin notice when a run recovered orders,
 * with order links and the recovered total.
 */
class Notices {

	const TRANSIENT = 'ordermend_recent_recoveries';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render the recovery notice if there is a recent batch to report.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$recoveries = get_transient( self::TRANSIENT );
		if ( empty( $recoveries ) || ! is_array( $recoveries ) ) {
			return;
		}

		$count = count( $recoveries );
		$total = 0.0;
		$links = array();
		foreach ( $recoveries as $rec ) {
			$order_id = (int) ( $rec['order_id'] ?? 0 );
			$total   += (float) ( $rec['amount'] ?? 0 );
			if ( $order_id > 0 ) {
				$url     = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
				$links[] = '<a href="' . esc_url( $url ) . '">#' . $order_id . '</a>';
			}
		}

		$currency = isset( $recoveries[0]['currency'] ) ? (string) $recoveries[0]['currency'] : '';

		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: 1: number of orders, 2: total amount, 3: currency. */
			esc_html( _n( 'OrderMend recovered %1$d paid-but-stuck order (%2$s %3$s).', 'OrderMend recovered %1$d paid-but-stuck orders (%2$s %3$s).', $count, 'ordermend' ) ),
			$count,
			esc_html( number_format_i18n( $total, 2 ) ),
			esc_html( $currency )
		);
		if ( $links ) {
			echo ' ' . wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) );
		}
		echo '</p></div>';

		// One-shot: clear so it doesn't repeat.
		delete_transient( self::TRANSIENT );
	}
}
