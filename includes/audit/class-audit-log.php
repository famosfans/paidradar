<?php
/**
 * Audit log data access.
 *
 * @package PaidRadar
 */

namespace PaidRadar\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Insert / query / export for the {$wpdb->prefix}paidradar_log table.
 */
class Audit_Log {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'paidradar_log';
	}

	/**
	 * Insert an audit row.
	 *
	 * @param array<string,mixed> $data Row data. `psp_response` may be an array (JSON-encoded here).
	 * @return int|false Inserted id or false on failure.
	 */
	public function record( array $data ) {
		global $wpdb;

		$response = $data['psp_response'] ?? array();
		if ( is_array( $response ) || is_object( $response ) ) {
			$response = wp_json_encode( $response );
		}

		$row = array(
			'order_id'      => isset( $data['order_id'] ) ? (int) $data['order_id'] : 0,
			'gateway'       => isset( $data['gateway'] ) ? substr( (string) $data['gateway'], 0, 50 ) : '',
			'event'         => isset( $data['event'] ) ? substr( (string) $data['event'], 0, 30 ) : '',
			'psp_status'    => isset( $data['psp_status'] ) ? substr( (string) $data['psp_status'], 0, 30 ) : null,
			'status_before' => isset( $data['status_before'] ) ? substr( (string) $data['status_before'], 0, 30 ) : null,
			'status_after'  => isset( $data['status_after'] ) ? substr( (string) $data['status_after'], 0, 30 ) : null,
			'amount'        => isset( $data['amount'] ) ? (float) $data['amount'] : null,
			'currency'      => isset( $data['currency'] ) ? substr( (string) $data['currency'], 0, 3 ) : null,
			'psp_response'  => is_string( $response ) ? $response : null,
			'actor'         => isset( $data['actor'] ) ? substr( (string) $data['actor'], 0, 20 ) : 'cron',
			'created_at'    => current_time( 'mysql', true ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table.
		$ok = $wpdb->insert( $this->table(), $row, $formats );

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Query audit rows.
	 *
	 * @param array<string,mixed> $args { event, order_id, since (mysql UTC), limit, offset, orderby, order }.
	 * @return array<int,array<string,mixed>>
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'event'    => '',
			'order_id' => 0,
			'since'    => '',
			'limit'    => 50,
			'offset'   => 0,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		);
		$args = array_merge( $defaults, $args );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['event'] ) {
			$where[]  = 'event = %s';
			$params[] = $args['event'];
		}
		if ( (int) $args['order_id'] > 0 ) {
			$where[]  = 'order_id = %d';
			$params[] = (int) $args['order_id'];
		}
		if ( '' !== $args['since'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['since'];
		}

		$orderby = in_array( $args['orderby'], array( 'created_at', 'id', 'order_id', 'event' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$limit   = max( 1, (int) $args['limit'] );
		$offset  = max( 0, (int) $args['offset'] );

		$sql  = 'SELECT * FROM ' . $this->table() . ' WHERE ' . implode( ' AND ', $where );
		$sql .= " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table interpolated safely; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows matching an optional event / since filter.
	 *
	 * @param array<string,mixed> $args { event, since }.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$params[] = $args['event'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['since'];
		}

		$sql = 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE ' . implode( ' AND ', $where );

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Static query, only the internal table name is interpolated; no user input.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Export the log as a CSV string.
	 *
	 * @param array<string,mixed> $args Passed to query() (limit defaults high).
	 * @return string CSV payload.
	 */
	public function export_csv( array $args = array() ): string {
		$args = array_merge( array( 'limit' => 10000 ), $args );
		$rows = $this->query( $args );

		$columns = array( 'id', 'order_id', 'gateway', 'event', 'psp_status', 'status_before', 'status_after', 'amount', 'currency', 'actor', 'created_at' );

		$lines = array( $this->csv_row( $columns ) );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $columns as $col ) {
				$line[] = $row[ $col ] ?? '';
			}
			$lines[] = $this->csv_row( $line );
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Build a single RFC-4180 CSV line without filesystem functions.
	 *
	 * Quotes fields containing separators/quotes/newlines and neutralizes
	 * spreadsheet formula-injection by prefixing risky leading characters.
	 *
	 * @param array<int,mixed> $fields Field values.
	 * @return string
	 */
	private function csv_row( array $fields ): string {
		$escaped = array();
		foreach ( $fields as $field ) {
			$value = (string) $field;

			// CSV formula-injection guard (=, +, -, @, tab, CR).
			if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
				$value = "'" . $value;
			}

			if ( preg_match( '/["\r\n,]/', $value ) ) {
				$value = '"' . str_replace( '"', '""', $value ) . '"';
			}

			$escaped[] = $value;
		}

		return implode( ',', $escaped );
	}
}
