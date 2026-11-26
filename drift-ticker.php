<?php
/**
 * Plugin Name:       Drift: Ticker
 * Plugin URI:        https://github.com/drift-creative-systems/drift-ticker
 * Description:       Daily download, install, rating and support stats for Drift's free WordPress.org plugins, with alerts and front-end stats for the Drift site.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Drift Creative Systems
 * Author URI:        https://driftcreativesystems.co.uk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       drift-ticker
 * Update URI:        https://github.com/drift-creative-systems/drift-ticker
 *
 * Copyright © 2026 Drift Creative Systems Ltd
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Duplicate install guard
|--------------------------------------------------------------------------
| Same as Drift: Wipe. Functions live in includes/, not here: PHP declares a
| file's top-level functions before running it, so a return here can't skip them.
*/
if ( defined( 'DRIFT_TICKER_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Drift: Ticker is installed more than once. Please delete the duplicate plugin folder.', 'drift-ticker' ) . '</p></div>';
		}
	);
	return;
}

define( 'DRIFT_TICKER_VERSION', '0.2.0' );
define( 'DRIFT_TICKER_DB_VERSION', '2' );
define( 'DRIFT_TICKER_FILE', __FILE__ );
define( 'DRIFT_TICKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'DRIFT_TICKER_URL', plugin_dir_url( __FILE__ ) );
define( 'DRIFT_TICKER_PAGE_SLUG', 'drift-ticker' );
define( 'DRIFT_TICKER_OPTION_GROUP', 'drift_ticker_settings' );
define( 'DRIFT_TICKER_TIMEZONE', 'Europe/London' );
define( 'DRIFT_TICKER_SLUG_META', 'drift_wporg_slug' );
define( 'DRIFT_TICKER_CRON_DAILY', 'drift_ticker_daily_snapshot' );
define( 'DRIFT_TICKER_CRON_WEEKLY', 'drift_ticker_weekly_summary' );

require DRIFT_TICKER_DIR . 'includes/plugin.php';

register_activation_hook( __FILE__, 'drift_ticker_activate' );
register_deactivation_hook( __FILE__, 'drift_ticker_deactivate' );
