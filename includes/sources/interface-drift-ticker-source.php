<?php
/**
 * A stats source. WordPress.org is the only one in v1; a Freemius source can
 * implement the same interface later and be returned by drift_ticker_source().
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

interface Drift_Ticker_Source {

	/**
	 * Short ID, e.g. "wporg". Used in logs and cache keys.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Fetches the current stats for one slug.
	 *
	 * @param string $slug  Plugin slug.
	 * @param bool   $force Skip the response cache.
	 * @return array|null|WP_Error Normalised stats (see Drift_Ticker_Source_Wporg::normalise()),
	 *                             null when the source says the slug doesn't exist (yet),
	 *                             WP_Error when the request or response failed.
	 */
	public function fetch( $slug, $force = false );
}
