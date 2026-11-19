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
