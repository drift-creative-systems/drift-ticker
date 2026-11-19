<?php
/**
 * WP-Cron schedule. The daily snapshot runs at 03:15 Europe/London and the
 * weekly summary on Mondays at 08:30 Europe/London.
 *
 * A real server cron works too: set DISABLE_WP_CRON to true in wp-config.php
 * and call wp-cron.php (or `wp cron event run --due-now`) every 5–15 minutes.
 * The event is a normal WP-Cron event, so it runs whichever way cron is triggered.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

add_action( DRIFT_TICKER_CRON_DAILY, 'drift_ticker_cron_daily' );
/**
 * Daily cron callback.
 *
 * @return void
 */
function drift_ticker_cron_daily() {
	drift_ticker_run_snapshot( false );
}

/**
 * Next run time for a London wall-clock time, as a Unix timestamp.
 *
 * @param string $time Time, e.g. "03:15".
 * @return int
 */
function drift_ticker_next_london_time( $time ) {
	$now  = new DateTimeImmutable( 'now', drift_ticker_timezone() );
	$next = new DateTimeImmutable( $now->format( 'Y-m-d' ) . ' ' . $time, drift_ticker_timezone() );
	if ( $next <= $now ) {
		$next = $next->modify( '+1 day' );
	}
	return $next->getTimestamp();
}

/**
 * Next Monday 08:30 Europe/London (today, if it's Monday before 08:30).
 *
 * @return int Unix timestamp.
 */
function drift_ticker_next_weekly_time() {
	$now  = new DateTimeImmutable( 'now', drift_ticker_timezone() );
	$next = $now->modify( 'monday this week' )->setTime( 8, 30 );
	if ( $next <= $now ) {
		$next = $next->modify( '+1 week' );
	}
	return $next->getTimestamp();
}

/**
 * Schedules the cron events if they aren't already. The weekly summary is a
 * single event re-scheduled after each run, so it stays at 08:30 London
 * time across the clock changes (a fixed 7-day interval would drift an hour).
 *
 * @return void
 */
function drift_ticker_schedule_events() {
	if ( ! wp_next_scheduled( DRIFT_TICKER_CRON_DAILY ) ) {
		wp_schedule_event( drift_ticker_next_london_time( '03:15' ), 'daily', DRIFT_TICKER_CRON_DAILY );
	}
	if ( ! wp_next_scheduled( DRIFT_TICKER_CRON_WEEKLY ) ) {
		wp_schedule_single_event( drift_ticker_next_weekly_time(), DRIFT_TICKER_CRON_WEEKLY );
	}
}

add_action( DRIFT_TICKER_CRON_WEEKLY, 'drift_ticker_cron_weekly' );
/**
 * Weekly cron callback: sends the summary, then books next Monday.
 *
 * @return void
 */
function drift_ticker_cron_weekly() {
	drift_ticker_send_weekly_summary();
	wp_clear_scheduled_hook( DRIFT_TICKER_CRON_WEEKLY );
	wp_schedule_single_event( drift_ticker_next_weekly_time(), DRIFT_TICKER_CRON_WEEKLY );
}

// Puts the event back if something cleared it while the plugin is active.
add_action( 'init', 'drift_ticker_ensure_scheduled' );
/**
 * Re-schedules the daily event when it's missing. Cheap: one cached option read.
 *
 * @return void
 */
function drift_ticker_ensure_scheduled() {
	if ( ! wp_installing() ) {
		drift_ticker_schedule_events();
	}
}
