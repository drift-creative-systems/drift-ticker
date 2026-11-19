<?php
/**
 * Options, the capability and shared helpers.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capability needed to view the screen, save settings and run a refresh.
 *
 * @return string
 */
function drift_ticker_capability() {
	return (string) apply_filters( 'drift_ticker_capability', 'manage_options' );
}

/**
 * Whether a string is a valid WordPress.org plugin slug.
 *
 * @param string $slug Slug to check.
 * @return bool
 */
function drift_ticker_is_valid_slug( $slug ) {
	return is_string( $slug ) && (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) && strlen( $slug ) <= 200;
}

/**
 * Sanitises the extra slugs setting: one slug per line (or comma separated)
 * → a unique list of valid slugs.
 *
 * @param mixed $value Raw value.
 * @return string[]
 */
function drift_ticker_sanitize_slugs( $value ) {
	if ( is_array( $value ) ) {
		$value = implode( "\n", $value );
	}
	$parts = preg_split( '/[\s,]+/', strtolower( (string) $value ) );
	$slugs = array_filter( array_map( 'trim', (array) $parts ), 'drift_ticker_is_valid_slug' );
	return array_values( array_unique( $slugs ) );
}

/**
 * Extra slugs to track that have no app post (Settings → Drift: Ticker).
 *
 * @return string[]
 */
function drift_ticker_extra_slugs() {
	return drift_ticker_sanitize_slugs( get_option( 'drift_ticker_extra_slugs', array() ) );
}

/**
 * Every tracked slug → app post ID (0 for extra slugs with no app). Apps come
 * first, in menu order. Trashed and auto-draft apps are left out.
 *
 * @return array<string, int>
 */
function drift_ticker_tracked() {
	$tracked = array();

	if ( post_type_exists( 'drift_app' ) ) {
		$apps = get_posts(
			array(
				'post_type'      => 'drift_app',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'meta_key'       => DRIFT_TICKER_SLUG_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small post type, admin and cron only.
				'meta_compare'   => '!=',
				'meta_value'     => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $apps as $app_id ) {
			$slug = (string) get_post_meta( $app_id, DRIFT_TICKER_SLUG_META, true );
			if ( drift_ticker_is_valid_slug( $slug ) && ! isset( $tracked[ $slug ] ) ) {
				$tracked[ $slug ] = (int) $app_id;
			}
		}
	}

	foreach ( drift_ticker_extra_slugs() as $slug ) {
		if ( ! isset( $tracked[ $slug ] ) ) {
			$tracked[ $slug ] = 0;
		}
	}

	return (array) apply_filters( 'drift_ticker_tracked', $tracked );
}

/**
 * Per-slug state: whether it's pending or live, when it went live, the last
 * check and the last error. Stored in one option, not autoloaded.
 *
 * @return array<string, array>
 */
function drift_ticker_get_states() {
	$states = get_option( 'drift_ticker_state', array() );
	return is_array( $states ) ? $states : array();
}

/**
 * State for one slug, with defaults filled in.
 *
 * @param string $slug Plugin slug.
 * @return array{status: string, live_since: string, last_checked: string, last_error: string, name: string}
 */
function drift_ticker_get_state( $slug ) {
	$states = drift_ticker_get_states();
	$state  = isset( $states[ $slug ] ) && is_array( $states[ $slug ] ) ? $states[ $slug ] : array();
	return wp_parse_args(
		$state,
		array(
			'status'       => 'unknown', // unknown → pending | live. "closed" when WordPress.org closes it.
			'live_since'   => '',
			'last_checked' => '',
			'last_error'   => '',
			'name'         => '',
		)
	);
}

/**
 * Merges changes into one slug's state.
 *
 * @param string $slug    Plugin slug.
 * @param array  $changes Keys to set.
 * @return void
 */
function drift_ticker_update_state( $slug, array $changes ) {
	$states          = drift_ticker_get_states();
	$states[ $slug ] = array_merge( drift_ticker_get_state( $slug ), $changes );
	update_option( 'drift_ticker_state', $states, false );
}

/**
 * Adds a line to the fetch log (newest first, last 50 kept). Shown on the
 * settings screen instead of writing to the PHP error log.
 *
 * @param string $slug    Plugin slug.
 * @param string $message What happened.
 * @return void
 */
function drift_ticker_log( $slug, $message ) {
	$log = get_option( 'drift_ticker_log', array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift(
		$log,
		array(
			'time'    => time(),
			'slug'    => (string) $slug,
			'message' => (string) $message,
		)
	);
	update_option( 'drift_ticker_log', array_slice( $log, 0, 50 ), false );
}

/**
 * The Europe/London timezone used for snapshot dates and display.
 *
 * @return DateTimeZone
 */
function drift_ticker_timezone() {
	return new DateTimeZone( DRIFT_TICKER_TIMEZONE );
}

/**
 * Today's date in Europe/London, Y-m-d. One snapshot row per slug per day.
 *
 * @return string
 */
function drift_ticker_today() {
	return wp_date( 'Y-m-d', null, drift_ticker_timezone() );
}

/**
 * Formats a UTC MySQL datetime or a timestamp for display: DD/MM/YYYY, plus
 * HH:MM when $with_time, in Europe/London.
 *
 * @param string|int $value     'Y-m-d H:i:s' (UTC) or a Unix timestamp.
 * @param bool       $with_time Include the time.
 * @return string '' for an empty value.
 */
function drift_ticker_format_date( $value, $with_time = false ) {
	if ( empty( $value ) ) {
		return '';
	}
	$timestamp = is_numeric( $value ) ? (int) $value : strtotime( $value . ' UTC' );
	if ( ! $timestamp ) {
		return '';
	}
	return (string) wp_date( $with_time ? 'd/m/Y H:i' : 'd/m/Y', $timestamp, drift_ticker_timezone() );
}

/**
 * The plugin's WordPress.org page.
 *
 * @param string $slug Plugin slug.
 * @return string
 */
function drift_ticker_wporg_url( $slug ) {
	return 'https://wordpress.org/plugins/' . rawurlencode( $slug ) . '/';
}
