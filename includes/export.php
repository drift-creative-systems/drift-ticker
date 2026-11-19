<?php
/**
 * CSV export of the snapshot history: every tracked slug, or one.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_drift_ticker_export', 'drift_ticker_export_csv' );
/**
 * Streams the CSV. Nonce and capability checked; the slug must be tracked.
 *
 * @return void
 */
function drift_ticker_export_csv() {
	check_admin_referer( 'drift_ticker_export' );

	if ( ! current_user_can( drift_ticker_capability() ) ) {
		wp_die( esc_html__( 'You are not allowed to export these stats.', 'drift-ticker' ), '', array( 'response' => 403 ) );
	}

	$tracked = drift_ticker_tracked();
	$slug    = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
	if ( '' !== $slug && ! isset( $tracked[ $slug ] ) ) {
		wp_die( esc_html__( 'That plugin isn\'t tracked.', 'drift-ticker' ), '', array( 'response' => 404 ) );
	}
	$slugs = '' !== $slug ? array( $slug ) : array_keys( $tracked );

	$filename = 'drift-ticker-' . ( '' !== $slug ? $slug . '-' : '' ) . drift_ticker_today() . '.csv';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to the response.
	// UTF-8 BOM so Excel reads non-ASCII text (e.g. in version strings) correctly.
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- As above.
	fputcsv( $out, drift_ticker_csv_header() );
	foreach ( $slugs as $export_slug ) {
		foreach ( drift_ticker_csv_rows( $export_slug ) as $row ) {
			fputcsv( $out, $row );
		}
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- As above.
	exit;
}

/**
 * CSV column headings.
 *
 * @return string[]
 */
function drift_ticker_csv_header() {
	return array( 'Slug', 'Date', 'Total downloads', 'Downloads that day', 'Active installs', 'Rating (0-100)', 'Ratings', '5 star', '4 star', '3 star', '2 star', '1 star', 'Support threads', 'Resolved', 'Open', 'Version', 'Last updated' );
}

/**
 * Every snapshot row for a slug, oldest first, as CSV cells. Dates are
 * DD/MM/YYYY; "Downloads that day" is blank on the first row.
 *
 * @param string $slug Plugin slug.
 * @return array[]
 */
function drift_ticker_csv_rows( $slug ) {
	global $wpdb;
	$table = drift_ticker_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; admin export.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s ORDER BY snap_date ASC", $slug ), ARRAY_A );

	$cells = array();
	foreach ( drift_ticker_add_daily_downloads( (array) $rows ) as $row ) {
		$ratings = json_decode( (string) $row['ratings_json'], true );
		$ratings = is_array( $ratings ) ? $ratings : array();
		$cells[] = array_map(
			'drift_ticker_csv_cell',
			array(
				$row['slug'],
				drift_ticker_format_date( $row['snap_date'] ),
				(int) $row['downloaded'],
				null === $row['downloads_day'] ? '' : (int) $row['downloads_day'],
				(int) $row['active_installs'],
				(int) $row['rating'],
				(int) $row['num_ratings'],
				(int) ( $ratings[5] ?? 0 ),
				(int) ( $ratings[4] ?? 0 ),
				(int) ( $ratings[3] ?? 0 ),
				(int) ( $ratings[2] ?? 0 ),
				(int) ( $ratings[1] ?? 0 ),
				(int) $row['support_threads'],
				(int) $row['support_threads_resolved'],
				max( 0, (int) $row['support_threads'] - (int) $row['support_threads_resolved'] ),
				$row['version'],
				$row['last_updated'] ? drift_ticker_format_date( $row['last_updated'], true ) : '',
			)
		);
	}
	return $cells;
}

/**
 * Guards a cell against spreadsheet formula injection: text starting with
 * = + - @ (or a tab/CR) gets a leading apostrophe. Numbers pass through.
 *
 * @param mixed $value Cell value.
 * @return string|int
 */
function drift_ticker_csv_cell( $value ) {
	if ( is_int( $value ) ) {
		return $value;
	}
	$value = (string) $value;
	if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
		return "'" . $value;
	}
	return $value;
}
