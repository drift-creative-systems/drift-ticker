<?php
/**
 * Loads the plugin's parts. Each file holds one concern.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

require DRIFT_TICKER_DIR . 'includes/install.php';
require DRIFT_TICKER_DIR . 'includes/settings.php';
require DRIFT_TICKER_DIR . 'includes/fields.php';
require DRIFT_TICKER_DIR . 'includes/sources/interface-drift-ticker-source.php';
require DRIFT_TICKER_DIR . 'includes/sources/interface-drift-ticker-history-source.php';
require DRIFT_TICKER_DIR . 'includes/sources/class-drift-ticker-source-wporg.php';
require DRIFT_TICKER_DIR . 'includes/snapshots.php';
require DRIFT_TICKER_DIR . 'includes/stats.php';
require DRIFT_TICKER_DIR . 'includes/frontend.php';
require DRIFT_TICKER_DIR . 'includes/golive.php';
require DRIFT_TICKER_DIR . 'includes/alerts.php';
require DRIFT_TICKER_DIR . 'includes/modules.php';
require DRIFT_TICKER_DIR . 'includes/updates.php';
require DRIFT_TICKER_DIR . 'includes/cron.php';

if ( is_admin() ) {
	require DRIFT_TICKER_DIR . 'includes/admin.php';
	require DRIFT_TICKER_DIR . 'includes/dashboard.php';
	require DRIFT_TICKER_DIR . 'includes/export.php';
}
