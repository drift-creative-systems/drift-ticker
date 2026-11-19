<?php
/**
 * Go-live: when a tracked slug first gets a good response from WordPress.org,
 * mark its app as available, raise the "live" event (sent as an alert in
 * step 6) and backfill the download history once.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'drift_ticker_went_live', 'drift_ticker_handle_went_live', 10, 4 );
/**
 * Handles the first good response for a slug.
 *
 * - "pending" → live (just approved): update the app's status and raise the
 *   "live" event.
 * - "unknown" → live (a slug that was already live when it was first
 *   tracked): update the app's status, but no "is live" event, since it
 *   isn't news.
 *
 * @param string $slug            Plugin slug.
 * @param array  $data            Stats from the source.
 * @param int    $app_id          App post ID, or 0.
 * @param string $previous_status "pending" or "unknown".
 * @return void
 */
function drift_ticker_handle_went_live( $slug, $data, $app_id, $previous_status ) {
	if ( $app_id ) {
		drift_ticker_set_app_live( (int) $app_id );
	}

	if ( 'pending' === $previous_status ) {
		$name = $app_id ? get_the_title( $app_id ) : ( $data['name'] ?? $slug );
		/**
		 * Something alert-worthy happened. Step 6 sends these to Make, Slack
		 * and email, once each.
		 *
		 * @param string $event  Event name, here "live".
		 * @param string $slug   Plugin slug.
		 * @param array  $values Event details.
		 */
		do_action(
			'drift_ticker_event',
			'live',
			$slug,
			array(
				'plugin_name' => $name ? $name : $slug,
				'version'     => $data['version'] ?? '',
				'url'         => drift_ticker_wporg_url( $slug ),
				'app_id'      => (int) $app_id,
			)
		);
	}
}

/**
 * The drift_status value for "live on WordPress.org": "wporg" when the theme
 * knows it, otherwise "live" (Available now). The theme's drift_app_meta()
 * falls back to "soon" for keys it doesn't know, so an unknown key must
 * never be written.
 *
 * @return string
 */
function drift_ticker_live_status() {
	$status = 'live';
	if ( function_exists( 'drift_app_statuses' ) && isset( drift_app_statuses()['wporg'] ) ) {
		$status = 'wporg';
	}
	return (string) apply_filters( 'drift_ticker_live_status', $status );
}

/**
 * Sets the app's drift_status to the live status, unless it's already that
 * (or "live", which a person chose on purpose). "Coming soon", "In
 * development" and "Demo available" are all replaced. Uses ACF's
 * update_field() when the theme's field is loaded, so ACF's field reference
 * stays right; plain post meta otherwise (the theme reads it either way).
 *
 * @param int $app_id App post ID.
 * @return bool Whether the status changed.
 */
function drift_ticker_set_app_live( $app_id ) {
	$target  = drift_ticker_live_status();
	$current = (string) get_post_meta( $app_id, 'drift_status', true );
	if ( in_array( $current, array( $target, 'live', 'wporg' ), true ) ) {
		return false;
	}

	if ( function_exists( 'update_field' ) && function_exists( 'acf_get_field' ) && acf_get_field( 'field_drift_app_status' ) ) {
		update_field( 'field_drift_app_status', $target, $app_id );
	} else {
		update_post_meta( $app_id, 'drift_status', $target );
	}

	/* translators: 1: old status, 2: new status */
	drift_ticker_log( drift_ticker_app_slug( $app_id ), sprintf( __( 'App status changed from "%1$s" to "%2$s".', 'drift-ticker' ), $current ? $current : '—', $target ) );
	// The status pill and buttons are in cached pages too.
	$GLOBALS['drift_ticker_display_changed'] = true;

	return true;
}

// ---------------------------------------------------------------------------
// History backfill
// ---------------------------------------------------------------------------

add_action( 'drift_ticker_snapshot_saved', 'drift_ticker_maybe_backfill', 20, 2 );
/**
 * Backfills a live slug's download history once, after a snapshot confirmed
 * it exists. A failed attempt is retried on the next runs, three times at most.
 *
 * @param string $slug Plugin slug.
 * @param array  $data Stats just saved (the confirmation; its total is the sanity check).
 * @return void
 */
function drift_ticker_maybe_backfill( $slug, $data ) {
	$state = drift_ticker_get_state( $slug );
	if ( 'live' !== $state['status'] || ! empty( $state['backfilled'] ) || (int) ( $state['backfill_attempts'] ?? 0 ) >= 3 ) {
		return;
	}
	drift_ticker_backfill( $slug, (int) $data['downloaded'] );
}

/**
 * Fetches up to 730 days of daily downloads and stores them in the backfill
 * table. Rejects the response if it adds up to more than the confirmed total
 * (made-up numbers), and doesn't store days on or after the first snapshot,
 * which the snapshots already cover.
 *
 * @param string $slug  Plugin slug, already confirmed live by the info API.
 * @param int    $total The info API's all-time download total.
 * @return int|WP_Error Rows stored.
 */
function drift_ticker_backfill( $slug, $total ) {
	$source = drift_ticker_source( $slug );
	if ( ! $source instanceof Drift_Ticker_History_Source ) {
		drift_ticker_update_state( $slug, array( 'backfilled' => current_time( 'mysql', true ) ) );
		return 0;
	}

	$state = drift_ticker_get_state( $slug );
	drift_ticker_update_state( $slug, array( 'backfill_attempts' => (int) ( $state['backfill_attempts'] ?? 0 ) + 1 ) );

	$history = $source->fetch_history( $slug, 730 );
	if ( ! is_wp_error( $history ) ) {
		// Allow some slack: the two endpoints aren't updated at the same moment.
		$sum = array_sum( $history );
		if ( $sum > $total * 1.1 + 100 ) {
			$history = new WP_Error(
				'drift_ticker_history_mismatch',
				/* translators: 1: sum of the history, 2: all-time total */
				sprintf( __( 'Download history ignored: it adds up to %1$s, more than the %2$s total.', 'drift-ticker' ), number_format_i18n( $sum ), number_format_i18n( $total ) )
			);
		}
	}
	if ( is_wp_error( $history ) ) {
		drift_ticker_log( $slug, $history->get_error_message() );
		return $history;
	}

	$first = drift_ticker_first_snapshot_date( $slug );
	$rows  = array();
	foreach ( $history as $date => $count ) {
		if ( $first && $date >= $first ) {
			continue;
		}
		$rows[ $date ] = $count;
	}

	$stored = drift_ticker_store_backfill( $slug, $rows );
	if ( false === $stored ) {
		$error = new WP_Error( 'drift_ticker_backfill_db', __( 'Could not save the download history.', 'drift-ticker' ) );
		drift_ticker_log( $slug, $error->get_error_message() );
		return $error;
	}

	drift_ticker_update_state( $slug, array( 'backfilled' => current_time( 'mysql', true ) ) );
	/* translators: %d: number of days */
	drift_ticker_log( $slug, sprintf( _n( 'Download history loaded: %d day.', 'Download history loaded: %d days.', $stored, 'drift-ticker' ), $stored ) );
	$GLOBALS['drift_ticker_display_changed'] = true;

	return $stored;
}

/**
 * Date of a slug's first snapshot, or ''.
 *
 * @param string $slug Plugin slug.
 * @return string
 */
function drift_ticker_first_snapshot_date( $slug ) {
	global $wpdb;
	$table = drift_ticker_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; once per backfill.
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(snap_date) FROM {$table} WHERE slug = %s", $slug ) );
}

/**
 * Writes backfill rows in batches of 100 with REPLACE, so a rerun overwrites
 * instead of failing on the unique key.
 *
 * @param string             $slug Plugin slug.
 * @param array<string, int> $rows Date → downloads.
 * @return int|false Rows written, or false on a database error.
 */
function drift_ticker_store_backfill( $slug, array $rows ) {
	global $wpdb;
	$table   = drift_ticker_backfill_table();
	$now     = current_time( 'mysql', true );
	$written = 0;

	foreach ( array_chunk( $rows, 100, true ) as $chunk ) {
		$values = array();
		foreach ( $chunk as $date => $count ) {
			$values[] = $wpdb->prepare( '(%s, %s, %d, %s)', $slug, $date, $count, $now );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; each row prepared above.
		$result = $wpdb->query( "REPLACE INTO {$table} (slug, stat_date, downloads, created_at) VALUES " . implode( ', ', $values ) );
		if ( false === $result ) {
			return false;
		}
		$written += count( $chunk );
	}
	return $written;
}
