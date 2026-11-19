<?php
/**
 * Plugin updates from GitHub releases (Plugin Update Checker, via Composer),
 * the same setup as the drift-create theme.
 *
 * WordPress checks https://github.com/drift-creative-systems/drift-ticker for
 * new releases and installs the drift-ticker.zip release asset through
 * Dashboard → Updates, like any other plugin. The repo is public, so no
 * token is needed. Release process: README → Releasing.
 *
 * vendor/ is committed. Its autoloader has its own class suffix
 * (composer.json → config.autoloader-suffix "DriftTicker") so it can't clash
 * with the theme's copy of the same library.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

$drift_ticker_autoload = DRIFT_TICKER_DIR . 'vendor/autoload.php';

if ( file_exists( $drift_ticker_autoload ) ) {
	require_once $drift_ticker_autoload;

	if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
		try {
			$drift_ticker_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				'https://github.com/drift-creative-systems/drift-ticker',
				DRIFT_TICKER_FILE,
				'drift-ticker',
				12
			);
			$drift_ticker_update_checker->setBranch( 'main' );
			$drift_ticker_update_checker->getVcsApi()->enableReleaseAssets();
		} catch ( Throwable $e ) {
			// The plugin keeps working without updates; note it on the settings screen.
			add_action(
				'admin_init',
				static function () use ( $e ) {
					drift_ticker_log( '', 'Update checker: ' . $e->getMessage() );
				}
			);
		}
	}
}
