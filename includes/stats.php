<?php
/**
 * Derived stats from the stored snapshots: daily series, 7/30-day totals,
 * display labels. Database reads only, so it's safe on the front end (step 4).
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backfilled daily download counts for a slug from $since on, keyed by date.
 *
 * @param string $slug  Plugin slug.
 * @param string $since First date (Y-m-d).
 * @return array<string, int>
 */
function drift_ticker_get_backfill( $slug, $since ) {
	global $wpdb;
	$table = drift_ticker_backfill_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; name from $wpdb->prefix.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT stat_date, downloads FROM {$table} WHERE slug = %s AND stat_date >= %s ORDER BY stat_date ASC", $slug, $since ), ARRAY_A );

	$by_date = array();
	foreach ( (array) $rows as $row ) {
		$by_date[ $row['stat_date'] ] = (int) $row['downloads'];
	}
	return $by_date;
}

/**
 * One entry per day for the last $days days (oldest first, today last):
 * downloads that day and the new version number on days it changed.
 *
 * Downloads come from the snapshots (today's total minus the previous one).
 * Days without that (before tracking started) use the backfill, if any.
 * Days with neither are null, which is "no data", not zero.
 *
 * @param string $slug Plugin slug.
 * @param int    $days Number of days, e.g. 30, 90 or 365.
 * @return array<int, array{date: string, downloads: int|null, version: string}>
 */
function drift_ticker_daily_series( $slug, $days = 30 ) {
	$days  = max( 1, absint( $days ) );
	$today = new DateTimeImmutable( drift_ticker_today(), new DateTimeZone( 'UTC' ) );
	$start = $today->modify( '-' . ( $days - 1 ) . ' days' );

	$snapshots = array();
	foreach ( drift_ticker_get_rows( $slug, $days - 1 ) as $row ) {
		$snapshots[ $row['snap_date'] ] = $row;
	}
	$backfill = drift_ticker_get_backfill( $slug, $start->format( 'Y-m-d' ) );

	$series = array();
	for ( $day = $start; $day <= $today; $day = $day->modify( '+1 day' ) ) {
		$date      = $day->format( 'Y-m-d' );
		$snapshot  = $snapshots[ $date ] ?? null;
		$downloads = null;
		if ( $snapshot && null !== $snapshot['downloads_day'] ) {
			$downloads = (int) $snapshot['downloads_day'];
		} elseif ( isset( $backfill[ $date ] ) ) {
			$downloads = $backfill[ $date ];
		}
		$series[] = array(
			'date'      => $date,
			'downloads' => $downloads,
			'version'   => $snapshot && $snapshot['version_changed'] ? (string) $snapshot['version'] : '',
		);
	}
	return $series;
}

/**
 * Sum of the last $days entries of a series, and the first date with data.
 *
 * @param array $series From drift_ticker_daily_series().
 * @param int   $days   Days to sum, counted back from today.
 * @return array{total: int|null, since: string, partial: bool} total null when there's no data at all;
 *                                                              partial when data starts after the period does.
 */
function drift_ticker_sum_series( array $series, $days ) {
	$slice = array_slice( $series, -1 * absint( $days ) );
	$total = null;
	$since = '';
	foreach ( $slice as $entry ) {
		if ( null === $entry['downloads'] ) {
			continue;
		}
		$total = (int) $total + $entry['downloads'];
		$since = $since ? $since : $entry['date'];
	}
	return array(
		'total'   => $total,
		'since'   => $since,
		'partial' => $slice && $since && $since !== $slice[0]['date'],
	);
}

/**
 * Everything the admin table (and later the front end) shows for one slug.
 *
 * @param string $slug Plugin slug.
 * @return array|null Null when there's no snapshot yet.
 */
function drift_ticker_summary( $slug ) {
	$latest = drift_ticker_get_latest( $slug );
	if ( ! $latest ) {
		return null;
	}
	$series = drift_ticker_daily_series( $slug, 30 );

	return array(
		'downloaded'      => (int) $latest['downloaded'],
		'last_7'          => drift_ticker_sum_series( $series, 7 ),
		'last_30'         => drift_ticker_sum_series( $series, 30 ),
		'active_installs' => (int) $latest['active_installs'],
		'rating'          => (int) $latest['rating'],
		'num_ratings'     => (int) $latest['num_ratings'],
		'open_threads'    => max( 0, (int) $latest['support_threads'] - (int) $latest['support_threads_resolved'] ),
		'support_threads' => (int) $latest['support_threads'],
		'version'         => (string) $latest['version'],
		'last_updated'    => (string) $latest['last_updated'],
		'snap_date'       => (string) $latest['snap_date'],
	);
}

/**
 * Active installs as WordPress.org shows them: "100+", or "Fewer than 10".
 *
 * @param int $installs Band floor from the API.
 * @return string
 */
function drift_ticker_installs_label( $installs ) {
	$installs = absint( $installs );
	if ( $installs < 10 ) {
		return __( 'Fewer than 10', 'drift-ticker' );
	}
	return number_format_i18n( $installs ) . '+';
}

/**
 * Rating out of 5 from the API's 0–100, one decimal: 96 → "4.8".
 *
 * @param int $rating 0–100.
 * @return string
 */
function drift_ticker_stars( $rating ) {
	return number_format_i18n( min( 100, absint( $rating ) ) / 20, 1 );
}
