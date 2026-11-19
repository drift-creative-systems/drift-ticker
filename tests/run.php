<?php
/**
 * Snapshot tests. Run from the plugin folder:
 *
 *   php -d extension=pdo_sqlite -d extension=curl tests/run.php          (mocked tests)
 *   php -d extension=pdo_sqlite -d extension=curl tests/run.php --live   (also hits api.wordpress.org)
 *
 * @package Drift_Ticker
 */

// phpcs:ignoreFile -- Test runner.

require __DIR__ . '/harness.php';

$dt_passed = 0;
$dt_failed = 0;

function dt_assert( $condition, $label ) {
	global $dt_passed, $dt_failed;
	if ( $condition ) {
		$dt_passed++;
		echo "  ok    {$label}\n";
	} else {
		$dt_failed++;
		echo "  FAIL  {$label}\n";
	}
}

function dt_test( $name, callable $fn ) {
	echo "\n{$name}\n";
	dt_reset();
	$fn();
}

/** A 200 response in the shape api.wordpress.org returns. */
function dt_api_ok( $overrides = array() ) {
	$body = array_merge(
		array(
			'name'                     => 'Drift: Wipe &#8211; Page Transitions',
			'slug'                     => 'drift-wipe',
			'version'                  => '1.1.0',
			'rating'                   => 96,
			'ratings'                  => array( '5' => 11, '4' => 1, '3' => 0, '2' => 0, '1' => 0 ),
			'num_ratings'              => 12,
			'support_threads'          => 3,
			'support_threads_resolved' => 2,
			'active_installs'          => 100,
			'downloaded'               => 1240,
			'last_updated'             => '2026-11-18 9:05am GMT',
		),
		$overrides
	);
	return function () use ( $body ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
	};
}

function dt_api_404() {
	return function () {
		return array( 'response' => array( 'code' => 404 ), 'body' => '{"error":"Plugin not found."}' );
	};
}

function dt_track( $slugs ) {
	update_option( 'drift_ticker_extra_slugs', $slugs );
}

function dt_rows( $slug ) {
	return $GLOBALS['wpdb']->get_results( "SELECT * FROM wp_drift_ticker_snapshots WHERE slug = '{$slug}' ORDER BY snap_date" );
}

// ---------------------------------------------------------------------------

dt_test( 'Parsing and request', function () {
	dt_assert( '2026-08-18 23:42:00' === Drift_Ticker_Source_Wporg::parse_date( '2026-08-18 11:42pm GMT' ), 'last_updated "11:42pm GMT" → 23:42:00 UTC' );
	dt_assert( '' === Drift_Ticker_Source_Wporg::parse_date( '' ), 'empty last_updated → empty string' );

	$url = ( new Drift_Ticker_Source_Wporg() )->request_url( 'drift-wipe' );
	dt_assert( false !== strpos( $url, 'request%5Bfields%5D%5Bactive_installs%5D=1' ), 'asks for active_installs' );
	dt_assert( false !== strpos( $url, 'request%5Bfields%5D%5Bsupport_threads%5D=1' ), 'asks for support_threads' );
	dt_assert( false !== strpos( $url, 'request%5Bfields%5D%5Bsections%5D=0' ), 'turns off sections' );
	dt_assert( false !== strpos( $url, 'request%5Bfields%5D%5Bscreenshots%5D=0' ), 'turns off screenshots' );

	dt_assert( array( 'drift-wipe', 'akismet' ) === drift_ticker_sanitize_slugs( "Drift-Wipe\n akismet, bad_slug!, akismet" ), 'extra slugs sanitised, lowercased, de-duplicated' );
} );

dt_test( 'Happy path: a live slug is saved', function () {
	dt_track( array( 'drift-wipe' ) );
	$GLOBALS['dt_http'] = dt_api_ok();

	$results = drift_ticker_run_snapshot();
	$rows    = dt_rows( 'drift-wipe' );
	$state   = drift_ticker_get_state( 'drift-wipe' );

	dt_assert( array( 'drift-wipe' => 'saved' ) === $results, 'result is "saved"' );
	dt_assert( 1 === count( $rows ), 'one row stored' );
	dt_assert( '1240' == $rows[0]['downloaded'] && '96' == $rows[0]['rating'] && '1.1.0' === $rows[0]['version'], 'downloaded, rating and version stored' );
	dt_assert( '{"5":11,"4":1,"3":0,"2":0,"1":0}' === $rows[0]['ratings_json'], 'ratings_json holds counts per star' );
	dt_assert( '2026-11-18 09:05:00' === $rows[0]['last_updated'], 'last_updated stored as UTC datetime' );
	dt_assert( 'live' === $state['status'] && 'Drift: Wipe – Page Transitions' === $state['name'], 'state is live, name decoded' );
	$went = dt_fired( 'drift_ticker_went_live' );
	dt_assert( 1 === count( $went ) && 'unknown' === $went[0][1][3], 'went_live fired once, previous status "unknown"' );
	dt_assert( false === get_option( 'drift_ticker_lock' ), 'lock released' );
} );

dt_test( 'Pending, then approved', function () {
	dt_track( array( 'drift-wipe' ) );
	$GLOBALS['dt_http'] = dt_api_404();

	dt_assert( array( 'drift-wipe' => 'pending' ) === drift_ticker_run_snapshot(), '404 → "pending"' );
	dt_assert( 0 === count( dt_rows( 'drift-wipe' ) ), 'no row stored while pending' );
	dt_assert( 'pending' === drift_ticker_get_state( 'drift-wipe' )['status'], 'state is pending' );
	dt_assert( 0 === count( dt_fired( 'drift_ticker_went_live' ) ), 'no go-live yet' );

	$GLOBALS['dt_http'] = dt_api_ok();
	drift_ticker_run_snapshot( true ); // Forced: the 404 is cached for 6 hours.
	$went = dt_fired( 'drift_ticker_went_live' );
	dt_assert( 1 === count( $went ) && 'pending' === $went[0][1][3], 'went_live fired with previous status "pending"' );

	drift_ticker_run_snapshot( true );
	dt_assert( 1 === count( dt_fired( 'drift_ticker_went_live' ) ), 'went_live not fired again on the next run' );
} );

dt_test( 'API down: last good row kept, error logged', function () {
	dt_track( array( 'drift-wipe' ) );
	$GLOBALS['dt_http'] = dt_api_ok();
	drift_ticker_run_snapshot();
	$before = dt_rows( 'drift-wipe' );

	$GLOBALS['dt_http'] = function () { return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ); };
	$results = drift_ticker_run_snapshot( true );
	$state   = drift_ticker_get_state( 'drift-wipe' );
	$log     = get_option( 'drift_ticker_log' );

	dt_assert( 'cURL error 28: Operation timed out' === $results['drift-wipe'], 'result is the error message' );
	dt_assert( $before === dt_rows( 'drift-wipe' ), 'stored row unchanged' );
	dt_assert( 'live' === $state['status'] && '' !== $state['last_error'], 'still live, last_error set' );
	dt_assert( 'drift-wipe' === $log[0]['slug'], 'logged' );
	dt_assert( empty( $GLOBALS['dt_transients'] ) || is_array( reset( $GLOBALS['dt_transients'] )['data'] ), 'failure not cached over the good response' );
} );

dt_test( 'Bad responses', function () {
	dt_track( array( 'drift-wipe' ) );
	$GLOBALS['dt_http'] = function () { return array( 'response' => array( 'code' => 502 ), 'body' => '<html>Bad gateway</html>' ); };
	$results = drift_ticker_run_snapshot();
	dt_assert( false !== strpos( $results['drift-wipe'], 'unreadable' ), 'HTML/502 → unreadable response error' );
	dt_assert( 'unknown' === drift_ticker_get_state( 'drift-wipe' )['status'], 'status not changed by an error' );

	$GLOBALS['dt_http'] = function () { return array( 'response' => array( 'code' => 404 ), 'body' => '{"error":"closed","name":"Drift: Wipe"}' ); };
	drift_ticker_run_snapshot( true );
	dt_assert( 'closed' === drift_ticker_get_state( 'drift-wipe' )['status'], 'closed plugin → status "closed"' );
} );

dt_test( 'A live slug that comes back "not found" stays live', function () {
	dt_track( array( 'drift-wipe' ) );
	$GLOBALS['dt_http'] = dt_api_ok();
	drift_ticker_run_snapshot();
	$GLOBALS['dt_http'] = dt_api_404();
	drift_ticker_run_snapshot( true );
	$state = drift_ticker_get_state( 'drift-wipe' );
	dt_assert( 'live' === $state['status'] && '' !== $state['last_error'], 'still live, error noted' );
} );

dt_test( 'Cache', function () {
	dt_track( array( 'drift-wipe' ) );
	$GLOBALS['dt_http'] = dt_api_ok();
	drift_ticker_run_snapshot();
	drift_ticker_run_snapshot();
	dt_assert( 1 === $GLOBALS['dt_http_calls'], 'second run served from the 6-hour transient' );
	drift_ticker_run_snapshot( true );
	dt_assert( 2 === $GLOBALS['dt_http_calls'], '"Refresh now" (force) skips the cache' );
} );

dt_test( 'One row per slug per day, daily downloads', function () {
	dt_track( array( 'drift-wipe' ) );
	$days = array(
		'2026-11-10 12:00:00' => 1000,
		'2026-11-11 12:00:00' => 1050,
		'2026-11-12 12:00:00' => 1040, // API glitch: lower total.
		'2026-11-13 12:00:00' => 1100,
		'2026-11-15 12:00:00' => 1180, // 14th missed.
	);
	foreach ( $days as $when => $total ) {
		$GLOBALS['dt_now']  = strtotime( $when . ' UTC' );
		$GLOBALS['dt_http'] = dt_api_ok( array( 'downloaded' => $total ) );
		drift_ticker_run_snapshot( true );
	}
	// Same day again with a newer total: updates, doesn't duplicate.
	$GLOBALS['dt_http'] = dt_api_ok( array( 'downloaded' => 1190 ) );
	drift_ticker_run_snapshot( true );

	$rows = dt_rows( 'drift-wipe' );
	dt_assert( 5 === count( $rows ), 'five rows, same-day rerun updated in place' );
	dt_assert( '1190' == end( $rows )['downloaded'], 'same-day rerun stored the newer total' );

	$with_daily = drift_ticker_add_daily_downloads( $rows );
	dt_assert( array( null, 50, 0, 50, 90 ) === array_column( $with_daily, 'downloads_day' ), 'daily = [–, 50, 0, 50, 90]: never negative, dip not double counted, gap rolled into next row' );

	$GLOBALS['dt_now'] = strtotime( '2026-11-15 12:00:00 UTC' );
	$range             = drift_ticker_get_rows( 'drift-wipe', 3 );
	dt_assert( '2026-11-12' === $range[0]['snap_date'] && 0 === $range[0]['downloads_day'], 'get_rows(3) starts 12/11 and compares with the row before the range' );
} );

dt_test( 'Dates in Europe/London', function () {
	// 23:30 UTC on 30 June is 00:30 BST on 1 July.
	$GLOBALS['dt_now'] = strtotime( '2026-06-30 23:30:00 UTC' );
	dt_assert( '2026-07-01' === drift_ticker_today(), 'snapshot date follows London time (BST)' );
	dt_assert( '01/07/2026 00:30' === drift_ticker_format_date( '2026-06-30 23:30:00', true ), 'display DD/MM/YYYY HH:MM in London time' );
} );

dt_test( 'Lock and database failure', function () {
	dt_track( array( 'drift-wipe' ) );
	$GLOBALS['dt_http'] = dt_api_ok();
	add_option( 'drift_ticker_lock', time() );
	$result = drift_ticker_run_snapshot();
	dt_assert( is_wp_error( $result ) && 'drift_ticker_locked' === $result->get_error_code(), 'second run while locked is refused' );

	update_option( 'drift_ticker_lock', time() - 11 * MINUTE_IN_SECONDS );
	dt_assert( is_array( drift_ticker_run_snapshot() ), 'stale lock (over 10 minutes) is taken over' );

	dt_reset();
	dt_track( array( 'drift-wipe' ) );
	$GLOBALS['dt_http']           = dt_api_ok();
	$GLOBALS['wpdb']->fail_writes = true;
	$results                      = drift_ticker_run_snapshot();
	dt_assert( 'saved' !== $results['drift-wipe'] && 'unknown' === drift_ticker_get_state( 'drift-wipe' )['status'], 'DB write failure → error, not marked live' );
	dt_assert( 0 === count( dt_fired( 'drift_ticker_went_live' ) ), 'no go-live on a failed save' );
} );

dt_test( 'Tracked list: apps first, then extra slugs', function () {
	$GLOBALS['dt_posts'] = array(
		458 => array( 'title' => 'Drift: Wipe', 'meta' => array( 'drift_wporg_slug' => 'drift-wipe' ) ),
		460 => array( 'title' => 'Drift: Tags', 'meta' => array( 'drift_wporg_slug' => '' ) ),
	);
	dt_track( array( 'akismet', 'drift-wipe' ) );
	dt_assert( array( 'drift-wipe' => 458, 'akismet' => 0 ) === drift_ticker_tracked(), 'app slug linked to its post, duplicate extra slug dropped, empty slug ignored' );
} );

/** Snapshots on given dates with given totals and versions. */
function dt_seed( $slug, array $days ) {
	foreach ( $days as $date => $values ) {
		$GLOBALS['dt_now'] = strtotime( $date . ' 12:00:00 UTC' );
		drift_ticker_save_snapshot(
			$slug,
			array(
				'downloaded'               => $values[0],
				'active_installs'          => 100,
				'rating'                   => 96,
				'num_ratings'              => 12,
				'ratings'                  => array( 5 => 11, 4 => 1, 3 => 0, 2 => 0, 1 => 0 ),
				'support_threads'          => 5,
				'support_threads_resolved' => 3,
				'version'                  => $values[1],
				'last_updated'             => '2026-11-18 09:05:00',
			)
		);
	}
}

dt_test( 'Daily series, version markers and backfill', function () {
	dt_seed(
		'drift-wipe',
		array(
			'2026-11-16' => array( 1000, '1.1.0' ),
			'2026-11-17' => array( 1030, '1.1.0' ),
			'2026-11-18' => array( 1070, '1.2.0' ),
			'2026-11-19' => array( 1100, '1.2.0' ),
		)
	);
	// Backfill for days before tracking began, and one day that snapshots also cover.
	$pdo = $GLOBALS['wpdb']->pdo;
	foreach ( array( '2026-11-13' => 7, '2026-11-14' => 9, '2026-11-15' => 11, '2026-11-17' => 999 ) as $date => $count ) {
		$pdo->exec( "INSERT INTO wp_drift_ticker_backfill (slug, stat_date, downloads, created_at) VALUES ('drift-wipe', '{$date}', {$count}, '2026-11-19')" );
	}

	$GLOBALS['dt_now'] = strtotime( '2026-11-19 12:00:00 UTC' );
	$series            = drift_ticker_daily_series( 'drift-wipe', 7 );

	dt_assert( 7 === count( $series ) && '2026-11-13' === $series[0]['date'] && '2026-11-19' === $series[6]['date'], '7 entries, 13/11 to today' );
	dt_assert( array( 7, 9, 11, null, 30, 40, 30 ) === array_column( $series, 'downloads' ), 'backfill fills days before tracking; first snapshot day has no difference (null); snapshot beats backfill' );
	dt_assert( array( '', '', '', '', '', '1.2.0', '' ) === array_column( $series, 'version' ), 'version marker only on the day it changed' );

	$sum = drift_ticker_sum_series( $series, 7 );
	dt_assert( 127 === $sum['total'] && ! $sum['partial'], '7-day total 127, not partial' );

	$GLOBALS['dt_now'] = strtotime( '2026-11-19 12:00:00 UTC' );
	$sum30             = drift_ticker_sum_series( drift_ticker_daily_series( 'drift-wipe', 30 ), 30 );
	dt_assert( 127 === $sum30['total'] && $sum30['partial'] && '2026-11-13' === $sum30['since'], '30-day total partial, since 13/11' );

	$empty = drift_ticker_sum_series( drift_ticker_daily_series( 'nothing-here', 7 ), 7 );
	dt_assert( null === $empty['total'], 'no data → null total, not 0' );
} );

dt_test( 'Summary and labels', function () {
	dt_seed( 'drift-wipe', array( '2026-11-18' => array( 1000, '1.1.0' ), '2026-11-19' => array( 1040, '1.1.0' ) ) );
	$GLOBALS['dt_now'] = strtotime( '2026-11-19 12:00:00 UTC' );
	$summary           = drift_ticker_summary( 'drift-wipe' );

	dt_assert( 1040 === $summary['downloaded'] && 40 === $summary['last_7']['total'], 'total and last 7 days' );
	dt_assert( 2 === $summary['open_threads'] && 5 === $summary['support_threads'], 'open threads = threads − resolved' );
	dt_assert( null === drift_ticker_summary( 'drift-tags' ), 'no snapshot → null summary' );

	dt_assert( 'Fewer than 10' === drift_ticker_installs_label( 0 ), '0 installs → "Fewer than 10"' );
	dt_assert( '1,000+' === drift_ticker_installs_label( 1000 ), '1000 → "1,000+"' );
	dt_assert( '4.8' === drift_ticker_stars( 96 ), 'rating 96 → 4.8 stars' );
} );

dt_test( 'CSV', function () {
	dt_seed( 'drift-wipe', array( '2026-11-18' => array( 1000, '1.1.0' ), '2026-11-19' => array( 1040, '=HYPERLINK("x")' ) ) );
	$rows = drift_ticker_csv_rows( 'drift-wipe' );

	dt_assert( count( drift_ticker_csv_header() ) === count( $rows[0] ), 'row width matches header' );
	dt_assert( '18/11/2026' === $rows[0][1] && '' === $rows[0][3] && 40 === $rows[1][3], 'DD/MM/YYYY dates; daily blank on first row, then 40' );
	dt_assert( 11 === $rows[0][7] && 2 === $rows[0][14], 'star counts and open threads' );
	dt_assert( "'=HYPERLINK(\"x\")" === $rows[1][15], 'formula-like text is neutralised' );
	dt_assert( 5 === drift_ticker_csv_cell( 5 ) && '-' !== drift_ticker_csv_cell( '-1' )[0], 'numbers untouched; "-1" as text escaped' );
} );

/** Counts SiteGround purges. */
function sg_cachepress_purge_everything() {
	$GLOBALS['dt_purges'] = ( $GLOBALS['dt_purges'] ?? 0 ) + 1;
}

/** Tracks three apps (two WP Suite, one Music) and runs one live snapshot each. */
function dt_live_apps() {
	$GLOBALS['dt_posts'] = array(
		458 => array( 'title' => 'Drift: Wipe', 'meta' => array( 'drift_wporg_slug' => 'drift-wipe', 'drift_suite' => 'agency' ) ),
		459 => array( 'title' => 'Drift: Tags', 'meta' => array( 'drift_wporg_slug' => 'drift-tags', 'drift_suite' => 'agency' ) ),
		460 => array( 'title' => 'Drift: Curtain', 'meta' => array( 'drift_wporg_slug' => 'drift-curtain', 'drift_suite' => 'music' ) ),
		461 => array( 'title' => 'Drift: Pending', 'meta' => array( 'drift_wporg_slug' => 'drift-pending', 'drift_suite' => 'agency' ) ),
	);
	$responses = array(
		'drift-wipe'    => array( 'slug' => 'drift-wipe', 'downloaded' => 1240, 'active_installs' => 100, 'rating' => 98, 'num_ratings' => 12, 'version' => '1.1.0' ),
		'drift-tags'    => array( 'slug' => 'drift-tags', 'downloaded' => 1, 'active_installs' => 0, 'rating' => 0, 'num_ratings' => 0, 'version' => '1.0.0' ),
		'drift-curtain' => array( 'slug' => 'drift-curtain', 'downloaded' => 500, 'active_installs' => 10, 'rating' => 80, 'num_ratings' => 4, 'version' => '1.0.0' ),
	);
	$GLOBALS['dt_http'] = function ( $url ) use ( $responses ) {
		foreach ( $responses as $slug => $body ) {
			if ( false !== strpos( $url, 'slug%5D=' . $slug . '&' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body + array( 'name' => $slug, 'support_threads' => 2, 'support_threads_resolved' => 1 ) ) );
			}
		}
		return array( 'response' => array( 'code' => 404 ), 'body' => '{"error":"Plugin not found."}' );
	};
	drift_ticker_run_snapshot( true );
}

dt_test( 'drift_ticker_get()', function () {
	dt_live_apps();
	dt_assert( 1240 === drift_ticker_get( 'drift-wipe', 'downloaded' ), 'downloaded' );
	dt_assert( 4.9 === drift_ticker_get( 'drift-wipe', 'stars' ) && 98 === drift_ticker_get( 'drift-wipe', 'rating' ), 'rating 98 → 4.9 stars' );
	dt_assert( 1 === drift_ticker_get( 'drift-wipe', 'open_threads' ), 'open threads' );
	dt_assert( 'https://wordpress.org/plugins/drift-wipe/' === drift_ticker_get( 'drift-wipe', 'url' ), 'url' );
	dt_assert( null === drift_ticker_get( 'drift-wipe', 'last_7' ), 'last_7 null on the first day (nothing to compare yet)' );
	dt_assert( null === drift_ticker_get( 'drift-pending', 'downloaded' ), 'pending slug → null' );
	dt_assert( null === drift_ticker_get( 'drift-wipe', 'bogus' ), 'unknown stat → null' );
	dt_assert( '100+' === drift_ticker_format( 'active_installs', 100 ) && '1,240' === drift_ticker_format( 'downloaded', 1240 ) && '' === drift_ticker_format( 'downloaded', null ), 'format' );
} );

dt_test( 'Suite totals', function () {
	dt_live_apps();
	dt_assert( 1241 === drift_ticker_get_suite( 'wp', 'downloaded' ), 'suite="wp" → agency: 1240 + 1, pending app ignored' );
	dt_assert( 2 === drift_ticker_get_suite( 'wp', 'count' ), 'count of live plugins in the suite' );
	dt_assert( 1741 === drift_ticker_get_suite( 'all', 'downloaded' ), 'suite="all" covers every live slug' );
	// (12 × 98 + 4 × 80) / 16 = 93.5 → 94 → 4.7 stars.
	dt_assert( 94 === drift_ticker_get_suite( 'all', 'rating' ) && 4.7 === drift_ticker_get_suite( 'all', 'stars' ), 'rating weighted by number of ratings' );
	dt_assert( null === drift_ticker_get_suite( 'seo', 'downloaded' ), 'suite with nothing live → null' );
	dt_assert( null === drift_ticker_get_suite( 'wp', 'version' ), 'per-plugin stat not summed → null' );
} );

dt_test( 'Strip and shortcodes', function () {
	dt_live_apps();
	$hero = drift_ticker_strip( 458 );
	dt_assert( false !== strpos( $hero, '<li>1,240 downloads</li><li>100+ active installs</li>' ), 'hero: downloads and installs' );
	dt_assert( false !== strpos( $hero, '&#9733; 4.9 (12)' ) && false !== strpos( $hero, 'Rated 4.9 out of 5 from 12 ratings' ), 'hero: rating with screen reader text' );
	dt_assert( false !== strpos( $hero, 'href="https://wordpress.org/plugins/drift-wipe/"' ) && false !== strpos( $hero, 'Download free from WordPress.org' ), 'hero: WordPress.org button' );

	$card = drift_ticker_strip( 458, array( 'context' => 'card' ) );
	dt_assert( false === strpos( $card, 'btn' ) && false !== strpos( $card, 'drift-ticker-strip--card' ), 'card: no button by default' );

	$tags = drift_ticker_strip( 'drift-tags', array( 'button' => false ) );
	dt_assert( false !== strpos( $tags, '1 download</li></ul>' ) && false === strpos( $tags, 'installs' ) && false === strpos( $tags, '9733' ), 'singular download; installs under 10 and no ratings left out' );

	dt_assert( '' === drift_ticker_strip( 461 ) && '' === drift_ticker_strip( 9999 ), 'pending app or app without slug → nothing' );
	dt_assert( '<a class="btn btn--line" href="https://wordpress.org/plugins/drift-wipe/">Download free from WordPress.org</a>' === drift_ticker_button( 458, 'btn btn--line' ), 'drift_ticker_button() on its own' );
	dt_assert( '' === drift_ticker_button( 461 ), 'no button for a pending app' );

	dt_assert( '<span class="drift-ticker-value drift-ticker-value--downloaded">1,240</span>' === drift_ticker_shortcode( array( 'slug' => 'drift-wipe' ) ), '[drift_ticker slug="drift-wipe"]' );
	dt_assert( false !== strpos( drift_ticker_shortcode( array( 'suite' => 'wp', 'stat' => 'count' ) ), '>2<' ), '[drift_ticker suite="wp" stat="count"]' );
	dt_assert( '' === drift_ticker_shortcode( array( 'slug' => 'drift-pending' ) ), 'pending → empty, no zero' );

	$GLOBALS['dt_current_post'] = 458;
	dt_assert( false !== strpos( drift_ticker_strip_shortcode( array( 'button' => 'no' ) ), '1,240 downloads' ) && false === strpos( drift_ticker_strip_shortcode( array( 'button' => 'no' ) ), 'btn' ), '[drift_ticker_strip button="no"] uses the current app' );
	unset( $GLOBALS['dt_current_post'] );
} );

dt_test( 'Front-end styles and page cache', function () {
	$GLOBALS['dt_styles'] = array();
	drift_ticker_enqueue_styles();
	dt_assert( array() === $GLOBALS['dt_styles'], 'no stylesheet while nothing is live' );

	$GLOBALS['dt_purges'] = 0;
	dt_live_apps();
	dt_assert( 1 === $GLOBALS['dt_purges'], 'first snapshot run → one purge for the whole run' );

	drift_ticker_enqueue_styles();
	dt_assert( array( 'drift-ticker' ) === $GLOBALS['dt_styles'], 'stylesheet once something is live' );

	drift_ticker_run_snapshot( true ); // Same numbers again.
	dt_assert( 1 === $GLOBALS['dt_purges'], 'nothing changed → no purge' );

	$GLOBALS['dt_now'] = strtotime( '+1 day' );
	$http              = $GLOBALS['dt_http'];
	$GLOBALS['dt_http'] = function ( $url ) use ( $http ) {
		$response = $http( $url );
		$body     = json_decode( $response['body'], true );
		if ( isset( $body['downloaded'] ) ) {
			$body['downloaded'] += 5;
		}
		$response['body'] = json_encode( $body );
		return $response;
	};
	drift_ticker_run_snapshot( true );
	dt_assert( 2 === $GLOBALS['dt_purges'], 'downloads changed next day → purge' );
} );

/**
 * Mock for both endpoints: the info API answers from $info (404 when null),
 * the stats endpoint from $history (a date → count array, or a callable).
 */
function dt_mock( $info, $history = array() ) {
	$GLOBALS['dt_http'] = function ( $url ) use ( $info, $history ) {
		if ( false !== strpos( $url, '/stats/plugin/' ) ) {
			$body = is_callable( $history ) ? $history( $url ) : $history;
			return $body instanceof WP_Error ? $body : array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
		}
		if ( null === $info ) {
			return array( 'response' => array( 'code' => 404 ), 'body' => '{"error":"Plugin not found."}' );
		}
		return dt_api_ok( $info )( $url );
	};
}

/** History: $days days ending yesterday, $per downloads each. */
function dt_history( $days, $per ) {
	$history = array();
	for ( $i = $days; $i >= 1; $i-- ) {
		$history[ gmdate( 'Y-m-d', strtotime( "-{$i} days", dt_now() ) ) ] = (string) $per;
	}
	return $history;
}

function dt_backfill_rows( $slug ) {
	return $GLOBALS['wpdb']->get_results( "SELECT stat_date, downloads FROM wp_drift_ticker_backfill WHERE slug = '{$slug}' ORDER BY stat_date" );
}

dt_test( 'Go-live: pending → live updates the app and raises the event', function () {
	$GLOBALS['dt_now']   = strtotime( '2026-11-19 03:15:00 UTC' );
	$GLOBALS['dt_posts'] = array( 458 => array( 'title' => 'Drift: Wipe', 'meta' => array( 'drift_wporg_slug' => 'drift-wipe', 'drift_status' => 'soon' ) ) );

	dt_mock( null );
	drift_ticker_run_snapshot( true );
	dt_assert( 'soon' === get_post_meta( 458, 'drift_status', true ), 'still "soon" while pending' );
	dt_assert( 0 === $GLOBALS['dt_history_calls'], 'stats endpoint never called while pending (it invents numbers for unknown slugs)' );

	dt_mock( array( 'downloaded' => 40 ), dt_history( 3, 10 ) );
	drift_ticker_run_snapshot( true );
	dt_assert( 'live' === get_post_meta( 458, 'drift_status', true ), 'status → "live" when the theme has no "wporg" status' );

	$events = dt_fired( 'drift_ticker_event' );
	dt_assert( 1 === count( $events ) && 'live' === $events[0][1][0] && 'drift-wipe' === $events[0][1][1], 'one "live" event' );
	dt_assert( 'Drift: Wipe' === $events[0][1][2]['plugin_name'] && 'https://wordpress.org/plugins/drift-wipe/' === $events[0][1][2]['url'], 'event has the app name and WordPress.org URL' );

	drift_ticker_run_snapshot( true );
	dt_assert( 1 === count( dt_fired( 'drift_ticker_event' ) ), 'no second "live" event' );
} );

dt_test( 'Go-live: "wporg" status when the theme has it; manual statuses respected', function () {
	if ( ! function_exists( 'drift_app_statuses' ) ) {
		// The theme's list with the new status added (step 5 theme change).
		function drift_app_statuses() {
			return array( 'live' => 'Available now', 'wporg' => 'Free on WordPress.org', 'demo' => 'Demo available', 'development' => 'In development', 'soon' => 'Coming soon' );
		}
	}
	dt_assert( 'wporg' === drift_ticker_live_status(), 'theme knows "wporg" → use it' );

	$GLOBALS['dt_posts'] = array(
		458 => array( 'title' => 'Drift: Wipe', 'meta' => array( 'drift_wporg_slug' => 'drift-wipe', 'drift_status' => 'demo' ) ),
		459 => array( 'title' => 'Drift: Tags', 'meta' => array( 'drift_wporg_slug' => 'drift-tags', 'drift_status' => 'live' ) ),
	);
	dt_mock( null );
	drift_ticker_run_snapshot( true );
	dt_mock( array( 'downloaded' => 40 ), array() );
	drift_ticker_run_snapshot( true );

	dt_assert( 'wporg' === get_post_meta( 458, 'drift_status', true ), '"demo" → "wporg"' );
	dt_assert( 'live' === get_post_meta( 459, 'drift_status', true ), '"live" set by hand is left alone' );
} );

dt_test( 'Go-live: a slug already live when first tracked', function () {
	$GLOBALS['dt_posts'] = array( 458 => array( 'title' => 'Drift: Wipe', 'meta' => array( 'drift_wporg_slug' => 'drift-wipe', 'drift_status' => 'soon' ) ) );
	dt_mock( array( 'downloaded' => 40 ), dt_history( 3, 10 ) );
	drift_ticker_run_snapshot( true );

	dt_assert( 'soon' !== get_post_meta( 458, 'drift_status', true ), 'app status still updated' );
	dt_assert( 0 === count( dt_fired( 'drift_ticker_event' ) ), 'no "is live" event: it isn\'t news' );
	dt_assert( 3 === count( dt_backfill_rows( 'drift-wipe' ) ), 'history still backfilled' );
} );

dt_test( 'Backfill', function () {
	$GLOBALS['dt_now'] = strtotime( '2026-11-19 03:15:00 UTC' );
	dt_track( array( 'drift-wipe' ) );
	$history                 = dt_history( 5, 10 );
	$history['2026-11-19']   = 0;        // The endpoint includes today as 0.
	$history['bad-key']      = '5';      // Ignored.
	$history['2026-11-01']   = 'lots';   // Ignored.
	dt_mock( array( 'downloaded' => 60 ), $history );
	drift_ticker_run_snapshot( true );

	$rows = dt_backfill_rows( 'drift-wipe' );
	dt_assert( 5 === count( $rows ) && '2026-11-14' === $rows[0]['stat_date'] && '2026-11-18' === end( $rows )['stat_date'], 'five days stored, up to the day before the first snapshot' );
	dt_assert( '' !== drift_ticker_get_state( 'drift-wipe' )['backfilled'], 'state marked backfilled' );
	dt_assert( 1 === $GLOBALS['dt_history_calls'], 'one history call' );

	drift_ticker_run_snapshot( true );
	dt_assert( 1 === $GLOBALS['dt_history_calls'], 'never fetched again' );

	$series = drift_ticker_daily_series( 'drift-wipe', 7 );
	dt_assert( array( null, 10, 10, 10, 10, 10, null ) === array_column( $series, 'downloads' ), 'chart series uses the backfill before tracking began' );
} );

dt_test( 'Backfill rejects made-up numbers and retries failures three times', function () {
	dt_track( array( 'drift-wipe' ) );
	dt_mock( array( 'downloaded' => 50 ), dt_history( 30, 1000 ) ); // 30,000 ≫ 50.
	drift_ticker_run_snapshot( true );
	dt_assert( 0 === count( dt_backfill_rows( 'drift-wipe' ) ), 'history adding up to more than the total is rejected' );
	dt_assert( false !== strpos( get_option( 'drift_ticker_log' )[0]['message'], 'more than the' ), 'and logged' );
	dt_assert( '' === drift_ticker_get_state( 'drift-wipe' )['backfilled'], 'not marked backfilled' );

	dt_mock( array( 'downloaded' => 50 ), function () { return new WP_Error( 'http_request_failed', 'timed out' ); } );
	drift_ticker_run_snapshot( true );
	drift_ticker_run_snapshot( true );
	drift_ticker_run_snapshot( true );
	dt_assert( 3 === $GLOBALS['dt_history_calls'], 'three attempts in total, then it stops' );
	dt_assert( 3 === drift_ticker_get_state( 'drift-wipe' )['backfill_attempts'], 'attempts counted' );
} );

dt_test( 'Backfill database failure', function () {
	dt_track( array( 'drift-wipe' ) );
	dt_mock( array( 'downloaded' => 50 ), dt_history( 3, 10 ) );
	drift_ticker_run_snapshot( true ); // Snapshot saved; backfill below.
	$GLOBALS['wpdb']->fail_writes = true;
	drift_ticker_update_state( 'drift-wipe', array( 'backfilled' => '', 'backfill_attempts' => 0 ) );
	$result = drift_ticker_backfill( 'drift-wipe', 50 );
	dt_assert( is_wp_error( $result ) && '' === drift_ticker_get_state( 'drift-wipe' )['backfilled'], 'DB error → WP_Error, not marked backfilled' );
} );

// ---------------------------------------------------------------------------

if ( in_array( '--live', $argv, true ) ) {
	echo "\nLive run against api.wordpress.org\n";
	dt_reset();
	dt_track( array( 'akismet', 'hello-dolly', 'drift-wipe' ) );
	$results = drift_ticker_run_snapshot( true );
	foreach ( $results as $slug => $result ) {
		echo "  {$slug}: {$result}\n";
	}
	echo "\nwp_drift_ticker_snapshots\n";
	foreach ( $GLOBALS['wpdb']->get_results( 'SELECT * FROM wp_drift_ticker_snapshots ORDER BY id' ) as $row ) {
		echo '  ' . json_encode( $row, JSON_UNESCAPED_SLASHES ) . "\n";
	}
	echo "\ndrift_ticker_state\n";
	foreach ( drift_ticker_get_states() as $slug => $state ) {
		echo "  {$slug}: " . json_encode( $state, JSON_UNESCAPED_UNICODE ) . "\n";
	}
}

echo "\n{$dt_passed} passed, {$dt_failed} failed\n";
exit( $dt_failed ? 1 : 0 );
