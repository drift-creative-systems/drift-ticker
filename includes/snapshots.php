<?php
/**
 * Daily snapshots: fetch every tracked slug, store one row per slug per day,
 * and read the rows back. Nothing here runs on a front-end page load.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * The stats source. Filterable so a Freemius source can be swapped in or
 * chosen per slug later.
 *
 * @param string $slug Plugin slug.
 * @return Drift_Ticker_Source
 */
function drift_ticker_source( $slug = '' ) {
	static $wporg = null;
	if ( null === $wporg ) {
		$wporg = new Drift_Ticker_Source_Wporg();
	}
	$source = apply_filters( 'drift_ticker_source', $wporg, $slug );
	return $source instanceof Drift_Ticker_Source ? $source : $wporg;
}

/**
 * Runs a snapshot of every tracked slug. Called by the daily cron and the
 * "Refresh now" button. A lock stops two runs overlapping.
 *
 * @param bool $force Skip the 6-hour response cache ("Refresh now").
 * @return array<string, string>|WP_Error Slug → result ("saved", "pending" or an error message),
 *                                        or WP_Error when a run is already going.
 */
function drift_ticker_run_snapshot( $force = false ) {
	if ( ! drift_ticker_acquire_lock() ) {
		return new WP_Error( 'drift_ticker_locked', __( 'A refresh is already running. Try again in a minute.', 'drift-ticker' ) );
	}

	$results = array();
	try {
		foreach ( drift_ticker_tracked() as $slug => $app_id ) {
			$results[ $slug ] = drift_ticker_snapshot_slug( $slug, (int) $app_id, $force );
		}
		update_option( 'drift_ticker_last_run', time(), false );
	} finally {
		drift_ticker_release_lock();
	}

	do_action( 'drift_ticker_snapshot_complete', $results );

	return $results;
}

/**
 * Fetches and stores one slug. On a failed fetch the last good row stays as
 * it is and the error is logged.
 *
 * @param string $slug   Plugin slug.
 * @param int    $app_id Linked app post ID, or 0.
 * @param bool   $force  Skip the response cache.
 * @return string "saved", "pending" or the error message.
 */
function drift_ticker_snapshot_slug( $slug, $app_id = 0, $force = false ) {
	$previous_status = drift_ticker_get_state( $slug )['status'];
	$now             = current_time( 'mysql', true );

	try {
		$data = drift_ticker_source( $slug )->fetch( $slug, $force );
	} catch ( Throwable $e ) {
		$data = new WP_Error( 'drift_ticker_exception', $e->getMessage() );
	}

	if ( is_wp_error( $data ) ) {
		$message = $data->get_error_message();
		drift_ticker_log( $slug, $message );
		$changes = array(
			'last_checked' => $now,
			'last_error'   => $message,
		);
		if ( 'drift_ticker_closed' === $data->get_error_code() ) {
			$changes['status'] = 'closed';
		}
		drift_ticker_update_state( $slug, $changes );
		return $message;
	}

	if ( null === $data ) {
		// Not on WordPress.org yet (or in review). A live slug that disappears
		// keeps its status and is logged instead: that's more likely an API blip.
		if ( 'live' === $previous_status ) {
			$message = __( 'WordPress.org says this plugin was not found.', 'drift-ticker' );
			drift_ticker_log( $slug, $message );
			drift_ticker_update_state(
				$slug,
				array(
					'last_checked' => $now,
					'last_error'   => $message,
				)
			);
			return $message;
		}
		drift_ticker_update_state(
			$slug,
			array(
				'status'       => 'pending',
				'last_checked' => $now,
				'last_error'   => '',
			)
		);
		return 'pending';
	}

	$previous_row = drift_ticker_get_latest( $slug );
	$saved        = drift_ticker_save_snapshot( $slug, $data );
	if ( ! $saved ) {
		$message = __( 'Could not save the snapshot to the database.', 'drift-ticker' );
		drift_ticker_log( $slug, $message );
		drift_ticker_update_state(
			$slug,
			array(
				'last_checked' => $now,
				'last_error'   => $message,
			)
		);
		return $message;
	}

	$changes = array(
		'status'       => 'live',
		'last_checked' => $now,
		'last_error'   => '',
		'name'         => $data['name'],
	);
	if ( 'live' !== $previous_status ) {
		$changes['live_since'] = $now;
	}
	drift_ticker_update_state( $slug, $changes );

	if ( 'live' !== $previous_status && 'closed' !== $previous_status ) {
		/**
		 * The first good response for a slug. $previous_status is "pending"
		 * when it was being watched and has just been approved, "unknown" when
		 * it's the first check of a slug that was already live.
		 */
		do_action( 'drift_ticker_went_live', $slug, $data, $app_id, $previous_status );
	}

	/** A snapshot was saved. $previous_row is the latest row before this one, or null. */
	do_action( 'drift_ticker_snapshot_saved', $slug, $data, $previous_row, $app_id );

	return 'saved';
}

/**
 * Inserts or updates today's row for a slug.
 *
 * @param string $slug Plugin slug.
 * @param array  $data Normalised stats from the source.
 * @param string $date Snapshot date (Y-m-d). Defaults to today in Europe/London.
 * @return bool
 */
function drift_ticker_save_snapshot( $slug, array $data, $date = '' ) {
	global $wpdb;
	$table = drift_ticker_table();
	$date  = $date ? $date : drift_ticker_today();

	$row = array(
		'downloaded'               => (int) $data['downloaded'],
		'active_installs'          => (int) $data['active_installs'],
		'rating'                   => (int) $data['rating'],
		'num_ratings'              => (int) $data['num_ratings'],
		'ratings_json'             => (string) wp_json_encode( $data['ratings'] ),
		'support_threads'          => (int) $data['support_threads'],
		'support_threads_resolved' => (int) $data['support_threads_resolved'],
		'version'                  => (string) $data['version'],
		'last_updated'             => $data['last_updated'] ? $data['last_updated'] : null,
	);
	$formats = array( '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; name from $wpdb->prefix.
	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND snap_date = %s", $slug, $date ) );

	if ( $existing ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $wpdb->update( $table, $row, array( 'id' => (int) $existing ), $formats, array( '%d' ) );
	} else {
		$row['slug']       = $slug;
		$row['snap_date']  = $date;
		$row['created_at'] = current_time( 'mysql', true );
		$formats[]         = '%s';
		$formats[]         = '%s';
		$formats[]         = '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$result = $wpdb->insert( $table, $row, $formats );
	}

	wp_cache_delete( 'latest_' . $slug, 'drift_ticker' );

	return false !== $result;
}

/**
 * Latest snapshot row for a slug, or null.
 *
 * @param string $slug Plugin slug.
 * @return array|null
 */
function drift_ticker_get_latest( $slug ) {
	$cached = wp_cache_get( 'latest_' . $slug, 'drift_ticker' );
	if ( false !== $cached ) {
		return $cached ? $cached : null;
	}

	global $wpdb;
	$table = drift_ticker_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; cached above.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s ORDER BY snap_date DESC LIMIT 1", $slug ), ARRAY_A );

	wp_cache_set( 'latest_' . $slug, $row ? $row : 0, 'drift_ticker' );
	return $row ? $row : null;
}

/**
 * Snapshot rows for a slug over the last $days days (oldest first), each with
 * a "downloads_day" key: downloads since the previous row, never negative.
 * The first row in the range is compared with the row before the range, so
 * it isn't left empty. Gaps (missed days) put the whole gap on the next row.
 *
 * @param string $slug Plugin slug.
 * @param int    $days Days back from today, e.g. 30, 90 or 365.
 * @return array[]
 */
function drift_ticker_get_rows( $slug, $days = 30 ) {
	global $wpdb;
	$table = drift_ticker_table();
	$since = gmdate( 'Y-m-d', strtotime( drift_ticker_today() . ' UTC -' . absint( $days ) . ' days' ) );

	// One extra row before the range, for the first day's difference.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; admin only.
	$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s AND snap_date < %s ORDER BY snap_date DESC LIMIT 1", $slug, $since ), ARRAY_A );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s AND snap_date >= %s ORDER BY snap_date ASC", $slug, $since ), ARRAY_A );

	return drift_ticker_add_daily_downloads( (array) $rows, $before );
}

/**
 * Adds two keys to each row:
 * - "downloads_day": this row's total minus the previous row's, floored at 0
 *   (a lower total is an API glitch, not negative downloads). null when
 *   there's nothing to compare with.
 * - "version_changed": the version differs from the previous row's. false
 *   when there's nothing to compare with.
 *
 * @param array[]    $rows   Rows, oldest first.
 * @param array|null $before Row before the first one, if any.
 * @return array[]
 */
function drift_ticker_add_daily_downloads( array $rows, $before = null ) {
	$previous         = $before ? (int) $before['downloaded'] : null;
	$previous_version = $before ? (string) $before['version'] : null;
	foreach ( $rows as $i => $row ) {
		$total = (int) $row['downloaded'];

		$rows[ $i ]['downloads_day']   = null === $previous ? null : max( 0, $total - $previous );
		$rows[ $i ]['version_changed'] = null !== $previous_version && '' !== (string) $row['version'] && (string) $row['version'] !== $previous_version;

		// Keep the highest total seen, so a dip followed by a recovery isn't counted twice.
		$previous         = null === $previous ? $total : max( $previous, $total );
		$previous_version = (string) $row['version'];
	}
	return $rows;
}

/**
 * Takes the run lock. add_option() only succeeds when the option doesn't
 * exist, so it's atomic. A lock older than 10 minutes is treated as stale.
 *
 * @return bool
 */
function drift_ticker_acquire_lock() {
	if ( add_option( 'drift_ticker_lock', time(), '', false ) ) {
		return true;
	}
	$started = (int) get_option( 'drift_ticker_lock', 0 );
	if ( $started && time() - $started < 10 * MINUTE_IN_SECONDS ) {
		return false;
	}
	update_option( 'drift_ticker_lock', time(), false );
	return true;
}

/**
 * Releases the run lock.
 *
 * @return void
 */
function drift_ticker_release_lock() {
	delete_option( 'drift_ticker_lock' );
}
