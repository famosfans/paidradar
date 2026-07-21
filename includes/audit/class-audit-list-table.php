<?php
/**
 * Admin list table for the audit log.
 *
 * @package OrderMend
 */

namespace OrderMend\Audit;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the ordermend_log table in the admin.
 */
class Audit_List_Table extends \WP_List_Table {

	/**
	 * Audit log data access.
	 *
	 * @var Audit_Log
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param Audit_Log $log Audit log.
	 */
	public function __construct( Audit_Log $log ) {
		$this->log = $log;
		parent::__construct(
			array(
				'singular' => 'ordermend_event',
				'plural'   => 'ordermend_events',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Date', 'ordermend' ),
			'order_id'   => __( 'Order', 'ordermend' ),
			'gateway'    => __( 'Gateway', 'ordermend' ),
			'event'      => __( 'Event', 'ordermend' ),
			'psp_status' => __( 'Gateway status', 'ordermend' ),
			'transition' => __( 'Status change', 'ordermend' ),
			'amount'     => __( 'Amount', 'ordermend' ),
			'actor'      => __( 'Triggered by', 'ordermend' ),
		);
	}

	/**
	 * Prepare rows for display.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page     = 30;
		$current_page = $this->get_pagenum();

		$total = $this->log->count();

		$this->items = $this->log->query(
			array(
				'limit'  => $per_page,
				'offset' => ( $current_page - 1 ) * $per_page,
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Default column renderer.
	 *
	 * @param array<string,mixed> $item        Row.
	 * @param string              $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		$value = isset( $item[ $column_name ] ) ? (string) $item[ $column_name ] : '';
		return esc_html( $value );
	}

	/**
	 * Order column with edit link.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	public function column_order_id( $item ) {
		$order_id = (int) ( $item['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return '&mdash;';
		}
		$url = function_exists( 'wc_get_order' ) && wc_get_order( $order_id )
			? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
			: get_edit_post_link( $order_id );

		return sprintf( '<a href="%s">#%d</a>', esc_url( (string) $url ), $order_id );
	}

	/**
	 * Event column with a coloured badge.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	public function column_event( $item ) {
		$event  = (string) ( $item['event'] ?? '' );
		$labels = array(
			'recovered'        => __( 'Recovered', 'ordermend' ),
			'drift'            => __( 'Drift', 'ordermend' ),
			'confirmed_unpaid' => __( 'Confirmed unpaid', 'ordermend' ),
			'check_failed'     => __( 'Check failed', 'ordermend' ),
			'unsupported'      => __( 'Unsupported', 'ordermend' ),
			'detected'         => __( 'Detected', 'ordermend' ),
		);
		$label = $labels[ $event ] ?? $event;
		return '<code>' . esc_html( $label ) . '</code>';
	}

	/**
	 * Status transition column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	public function column_transition( $item ) {
		$before = (string) ( $item['status_before'] ?? '' );
		$after  = (string) ( $item['status_after'] ?? '' );
		if ( '' === $before && '' === $after ) {
			return '&mdash;';
		}
		if ( '' === $after ) {
			return esc_html( $before );
		}
		return esc_html( $before . ' → ' . $after );
	}

	/**
	 * Amount column with currency.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	public function column_amount( $item ) {
		$amount = $item['amount'] ?? null;
		if ( null === $amount || '' === $amount ) {
			return '&mdash;';
		}
		$currency = (string) ( $item['currency'] ?? '' );
		return esc_html( number_format_i18n( (float) $amount, 2 ) . ' ' . $currency );
	}

	/**
	 * Empty-state message.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No recovery events logged yet.', 'ordermend' );
	}
}
