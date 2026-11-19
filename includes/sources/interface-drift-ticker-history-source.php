<?php
/**
 * Optional extra for a source that can return past daily downloads, used
 * once per slug to backfill the charts. Sources without history (a future
 * Freemius source, say) simply don't implement it.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

interface Drift_Ticker_History_Source {

	/**
	 * Daily download counts, oldest first.
	 *
	 * Only call this for a slug the source has already confirmed exists.
	 *
	 * @param string $slug Plugin slug.
	 * @param int    $days How many days back.
	 * @return array<string, int>|WP_Error Date (Y-m-d) → downloads that day.
	 */
	public function fetch_history( $slug, $days );
}
