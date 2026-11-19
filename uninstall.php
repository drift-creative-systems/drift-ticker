<?php
/**
 * Removes the tables, settings, cache and cron events when the plugin is deleted.
 *
 * @package Drift_Ticker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes this plugin's data on the current site. Names are written out
 * because drift-ticker.php (and its constants) isn't loaded here. The app
 * slug post meta (drift_wporg_slug) is left alone: it belongs to the app.
 *
 * @return void
 */
function drift_ticker_delete_data() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own tables; names from $wpdb->prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drift_ticker_snapshots" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drift_ticker_backfill" );

	// Cached API responses: drift_ticker_wporg_{md5}.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_drift_ticker_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_drift_ticker_' ) . '%'
		)
	);
	// phpcs:enable

	foreach ( array( 'drift_ticker_db_version', 'drift_ticker_extra_slugs', 'drift_ticker_state', 'drift_ticker_log', 'drift_ticker_last_run', 'drift_ticker_lock' ) as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'drift_ticker_daily_snapshot' );
}

if ( is_multisite() ) {
	$drift_ticker_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $drift_ticker_site_ids as $drift_ticker_site_id ) {
		switch_to_blog( $drift_ticker_site_id );
		drift_ticker_delete_data();
		restore_current_blog();
	}
} else {
	drift_ticker_delete_data();
}
