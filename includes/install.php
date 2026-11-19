<?php
/**
 * Activation, deactivation and the database tables.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot table name: one row per slug per day, holding the totals the
 * WordPress.org info API returned that day.
 *
 * @return string
 */
function drift_ticker_table() {
	global $wpdb;
	return $wpdb->prefix . 'drift_ticker_snapshots';
}

/**
 * Backfill table name: daily download counts from the unofficial stats
 * endpoint, loaded once when a slug goes live. Kept apart from the snapshots
 * because these are per-day counts, not running totals.
 *
 * @return string
 */
function drift_ticker_backfill_table() {
	global $wpdb;
	return $wpdb->prefix . 'drift_ticker_backfill';
}

/**
 * Events table name: every alert raised, keyed so nothing is sent twice,
 * with what was delivered where.
 *
 * @return string
 */
function drift_ticker_events_table() {
	global $wpdb;
	return $wpdb->prefix . 'drift_ticker_events';
}

/**
 * Creates or updates the tables with dbDelta and records the schema version.
 * dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
 *
 * @return void
 */
function drift_ticker_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$snapshots       = drift_ticker_table();
	$backfill        = drift_ticker_backfill_table();
	$events          = drift_ticker_events_table();

	dbDelta(
		"CREATE TABLE {$snapshots} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(200) NOT NULL,
			snap_date date NOT NULL,
			downloaded bigint(20) unsigned NOT NULL DEFAULT 0,
			active_installs bigint(20) unsigned NOT NULL DEFAULT 0,
			rating tinyint(3) unsigned NOT NULL DEFAULT 0,
			num_ratings int(10) unsigned NOT NULL DEFAULT 0,
			ratings_json text NOT NULL,
			support_threads int(10) unsigned NOT NULL DEFAULT 0,
			support_threads_resolved int(10) unsigned NOT NULL DEFAULT 0,
			version varchar(40) NOT NULL DEFAULT '',
			last_updated datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug_date (slug(150),snap_date),
			KEY snap_date (snap_date)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$backfill} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(200) NOT NULL,
			stat_date date NOT NULL,
			downloads int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug_date (slug(150),stat_date)
		) {$charset_collate};"
	);

	// event_key is at most 191 characters so the unique index fits utf8mb4.
	dbDelta(
		"CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_key varchar(191) NOT NULL,
			event varchar(40) NOT NULL,
			slug varchar(200) NOT NULL DEFAULT '',
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			channels varchar(100) NOT NULL DEFAULT '',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_error text NOT NULL,
			created_at datetime NOT NULL,
			sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY status (status)
		) {$charset_collate};"
	);

	update_option( 'drift_ticker_db_version', DRIFT_TICKER_DB_VERSION, false );
}

/**
 * Activation: tables and the daily cron event.
 *
 * @return void
 */
function drift_ticker_activate() {
	drift_ticker_install_tables();
	drift_ticker_schedule_events();
}

/**
 * Deactivation: clears the cron events. Data stays until the plugin is deleted.
 *
 * @return void
 */
function drift_ticker_deactivate() {
	wp_clear_scheduled_hook( DRIFT_TICKER_CRON_DAILY );
	wp_clear_scheduled_hook( DRIFT_TICKER_CRON_WEEKLY );
}

add_action( 'admin_init', 'drift_ticker_maybe_upgrade' );
/**
 * Re-runs the install when the schema version changes, or when the plugin
 * was network-activated and this site never ran the activation hook.
 *
 * @return void
 */
function drift_ticker_maybe_upgrade() {
	if ( get_option( 'drift_ticker_db_version' ) !== DRIFT_TICKER_DB_VERSION ) {
		drift_ticker_install_tables();
		drift_ticker_schedule_events();
	}
}
