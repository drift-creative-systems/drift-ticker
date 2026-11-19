<?php
/**
 * Front end: drift_ticker_get(), the [drift_ticker] and [drift_ticker_strip]
 * shortcodes, and the stats strip for app heroes and cards.
 *
 * Everything here reads the database only and never calls an API, so it's
 * safe behind SG Optimizer page caching. Cached pages are purged after a
 * snapshot changes the numbers (drift_ticker_maybe_purge_cache()).
 * Plugins that aren't live print nothing: no zeros, no "N/A".
 *
 * Theme placement (drift-create): see README → Front end.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a slug is live on WordPress.org and has a snapshot to show.
 *
 * @param string $slug Plugin slug.
 * @return bool
 */
function drift_ticker_is_live( $slug ) {
	return drift_ticker_is_valid_slug( $slug )
		&& 'live' === drift_ticker_get_state( $slug )['status']
		&& null !== drift_ticker_get_latest( $slug );
}

/**
 * Stats that drift_ticker_get() knows.
 *
 * @return string[]
 */
function drift_ticker_stats() {
	return array( 'downloaded', 'active_installs', 'rating', 'stars', 'num_ratings', 'support_threads', 'open_threads', 'last_7', 'last_30', 'version', 'last_updated', 'url', 'name' );
}

/**
 * One stat for one plugin, raw (unformatted).
 *
 * - downloaded, active_installs, num_ratings, support_threads, open_threads,
 *   last_7, last_30: int
 * - rating: int 0–100; stars: float 0–5, one decimal
 * - version: string; last_updated: UTC "Y-m-d H:i:s"; url, name: string
 *
 * @param string $slug Plugin slug.
 * @param string $stat Stat name, see drift_ticker_stats().
 * @return int|float|string|null Null when the plugin isn't live, the stat is
 *                               unknown, or there's no value (e.g. no 7-day data yet).
 */
function drift_ticker_get( $slug, $stat = 'downloaded' ) {
	$slug = strtolower( trim( (string) $slug ) );
	if ( ! in_array( $stat, drift_ticker_stats(), true ) || ! drift_ticker_is_live( $slug ) ) {
		return null;
	}
	$latest = drift_ticker_get_latest( $slug );

	switch ( $stat ) {
		case 'stars':
			return round( (int) $latest['rating'] / 20, 1 );
		case 'open_threads':
			return max( 0, (int) $latest['support_threads'] - (int) $latest['support_threads_resolved'] );
		case 'last_7':
		case 'last_30':
			// Only these two need the series, so the other stats stay one cached query.
			$days = 'last_7' === $stat ? 7 : 30;
			return drift_ticker_sum_series( drift_ticker_daily_series( $slug, $days ), $days )['total'];
		case 'version':
			return (string) $latest['version'];
		case 'last_updated':
			return $latest['last_updated'] ? (string) $latest['last_updated'] : null;
		case 'url':
			return drift_ticker_wporg_url( $slug );
		case 'name':
			$name = drift_ticker_get_state( $slug )['name'];
			return $name ? $name : null;
		default:
			return (int) $latest[ $stat ];
	}
}

/**
 * Suite keys accepted by suite="…": the theme's drift_suites() keys, "all",
 * and aliases ("wp" → "agency", the theme's WP Suite).
 *
 * @param string $suite Suite key or alias.
 * @return string Suite key, "all", or '' when unknown.
 */
function drift_ticker_resolve_suite( $suite ) {
	$suite   = sanitize_key( (string) $suite );
	$aliases = (array) apply_filters( 'drift_ticker_suite_aliases', array( 'wp' => 'agency' ) );
	$suite   = isset( $aliases[ $suite ] ) ? (string) $aliases[ $suite ] : $suite;

	if ( 'all' === $suite ) {
		return $suite;
	}
	if ( function_exists( 'drift_suites' ) ) {
		return isset( drift_suites()[ $suite ] ) ? $suite : '';
	}
	return '' !== $suite ? $suite : '';
}

/**
 * Live slugs in a suite: apps whose drift_suite matches. "all" is every live
 * tracked slug, extra slugs included.
 *
 * @param string $suite Suite key, alias or "all".
 * @return string[]
 */
function drift_ticker_suite_slugs( $suite ) {
	$suite = drift_ticker_resolve_suite( $suite );
	if ( '' === $suite ) {
		return array();
	}
	$slugs = array();
	foreach ( drift_ticker_tracked() as $slug => $app_id ) {
		if ( 'all' !== $suite && ( ! $app_id || get_post_meta( $app_id, 'drift_suite', true ) !== $suite ) ) {
			continue;
		}
		if ( drift_ticker_is_live( $slug ) ) {
			$slugs[] = $slug;
		}
	}
	return $slugs;
}

/**
 * One stat across a suite.
 *
 * - downloaded, active_installs (sum of band floors), num_ratings,
 *   support_threads, open_threads, last_7, last_30: sum
 * - count: number of live plugins
 * - rating / stars: average weighted by number of ratings
 *
 * @param string $suite Suite key, alias ("wp") or "all".
 * @param string $stat  Stat name.
 * @return int|float|null Null when no plugin in the suite is live or there's no value.
 */
function drift_ticker_get_suite( $suite, $stat = 'downloaded' ) {
	$slugs = drift_ticker_suite_slugs( $suite );
	if ( ! $slugs ) {
		return null;
	}
	if ( 'count' === $stat ) {
		return count( $slugs );
	}

	if ( in_array( $stat, array( 'rating', 'stars' ), true ) ) {
		$weighted = 0;
		$ratings  = 0;
		foreach ( $slugs as $slug ) {
			$count     = (int) drift_ticker_get( $slug, 'num_ratings' );
			$weighted += $count * (int) drift_ticker_get( $slug, 'rating' );
			$ratings  += $count;
		}
		if ( ! $ratings ) {
			return null;
		}
		$rating = (int) round( $weighted / $ratings );
		return 'stars' === $stat ? round( $rating / 20, 1 ) : $rating;
	}

	if ( ! in_array( $stat, array( 'downloaded', 'active_installs', 'num_ratings', 'support_threads', 'open_threads', 'last_7', 'last_30' ), true ) ) {
		return null;
	}
	$total = null;
	foreach ( $slugs as $slug ) {
		$value = drift_ticker_get( $slug, $stat );
		if ( null !== $value ) {
			$total = (int) $total + (int) $value;
		}
	}
	return $total;
}

/**
 * Formats a raw stat for display: numbers with number_format_i18n(),
 * installs as a band ("100+"), stars with one decimal, dates DD/MM/YYYY.
 *
 * @param string               $stat  Stat name.
 * @param int|float|string|null $value Raw value.
 * @return string '' for null.
 */
function drift_ticker_format( $stat, $value ) {
	if ( null === $value || '' === $value ) {
		return '';
	}
	switch ( $stat ) {
		case 'active_installs':
			return drift_ticker_installs_label( (int) $value );
		case 'stars':
			return number_format_i18n( (float) $value, 1 );
		case 'last_updated':
			return drift_ticker_format_date( (string) $value );
		case 'version':
		case 'url':
		case 'name':
			return (string) $value;
		default:
			return number_format_i18n( (int) $value );
	}
}

/**
 * The WordPress.org slug set on an app, or ''.
 *
 * @param int $app_id App post ID.
 * @return string
 */
function drift_ticker_app_slug( $app_id ) {
	$slug = (string) get_post_meta( (int) $app_id, DRIFT_TICKER_SLUG_META, true );
	return drift_ticker_is_valid_slug( $slug ) ? $slug : '';
}

/**
 * Stats strip: "1,240 downloads · 100+ active installs · ★ 4.9 (12)", plus an
 * optional "Download free from WordPress.org" button. Items with nothing
 * worth showing are left out (installs under 10, no ratings yet).
 *
 * @param int|string $app_or_slug App post ID or plugin slug.
 * @param array      $args {
 *     @type string $context      "hero" or "card". Adds a modifier class. Default "hero".
 *     @type bool   $button       Show the WordPress.org button. Default true for hero, false for card
 *                                (the whole card is a link to the app page).
 *     @type string $button_class Button classes. Default the theme's "btn btn--accent".
 *     @type string $button_text  Button text.
 * }
 * @return string HTML, or '' when the plugin isn't live.
 */
function drift_ticker_strip( $app_or_slug, array $args = array() ) {
	$slug = is_numeric( $app_or_slug ) ? drift_ticker_app_slug( (int) $app_or_slug ) : strtolower( trim( (string) $app_or_slug ) );
	if ( ! $slug || ! drift_ticker_is_live( $slug ) ) {
		return '';
	}

	$context = isset( $args['context'] ) && 'card' === $args['context'] ? 'card' : 'hero';
	$args    = wp_parse_args(
		$args,
		array(
			'button'       => 'hero' === $context,
			'button_class' => 'btn btn--accent',
			'button_text'  => __( 'Download free from WordPress.org', 'drift-ticker' ),
		)
	);

	$downloads = (int) drift_ticker_get( $slug, 'downloaded' );
	$installs  = (int) drift_ticker_get( $slug, 'active_installs' );
	$ratings   = (int) drift_ticker_get( $slug, 'num_ratings' );
	$items     = array();

	if ( $downloads ) {
		$items[] = esc_html(
			sprintf(
				/* translators: %s: number of downloads */
				_n( '%s download', '%s downloads', $downloads, 'drift-ticker' ),
				number_format_i18n( $downloads )
			)
		);
	}
	if ( $installs >= 10 ) {
		$items[] = esc_html(
			sprintf(
				/* translators: %s: active installs band, e.g. "100+" */
				__( '%s active installs', 'drift-ticker' ),
				drift_ticker_installs_label( $installs )
			)
		);
	}
	if ( $ratings ) {
		$stars   = drift_ticker_format( 'stars', drift_ticker_get( $slug, 'stars' ) );
		$items[] = '<span aria-hidden="true">&#9733; ' . esc_html( $stars ) . ' (' . esc_html( number_format_i18n( $ratings ) ) . ')</span>'
			. '<span class="drift-ticker-sr">' . esc_html(
				sprintf(
					/* translators: 1: rating out of 5, 2: number of ratings */
					_n( 'Rated %1$s out of 5 from %2$s rating', 'Rated %1$s out of 5 from %2$s ratings', $ratings, 'drift-ticker' ),
					$stars,
					number_format_i18n( $ratings )
				)
			) . '</span>';
	}

	if ( ! $items && ! $args['button'] ) {
		return '';
	}

	$html = '<div class="drift-ticker-strip drift-ticker-strip--' . esc_attr( $context ) . '">';
	if ( $items ) {
		$html .= '<ul class="drift-ticker-strip__stats" aria-label="' . esc_attr__( 'WordPress.org stats', 'drift-ticker' ) . '"><li>' . implode( '</li><li>', $items ) . '</li></ul>';
	}
	if ( $args['button'] ) {
		$html .= '<p class="drift-ticker-strip__action">' . drift_ticker_button( $slug, $args['button_class'], $args['button_text'] ) . '</p>';
	}
	$html .= '</div>';

	return (string) apply_filters( 'drift_ticker_strip_html', $html, $slug, $args );
}

/**
 * Just the "Download free from WordPress.org" link, for a theme's own button
 * row (e.g. the app hero's .btns). '' when the plugin isn't live.
 *
 * @param int|string $app_or_slug App post ID or plugin slug.
 * @param string     $class       Link classes. Default the theme's "btn btn--accent".
 * @param string     $text        Link text.
 * @return string
 */
function drift_ticker_button( $app_or_slug, $class = 'btn btn--accent', $text = '' ) {
	$slug = is_numeric( $app_or_slug ) ? drift_ticker_app_slug( (int) $app_or_slug ) : strtolower( trim( (string) $app_or_slug ) );
	if ( ! $slug || ! drift_ticker_is_live( $slug ) ) {
		return '';
	}
	$text = '' !== $text ? $text : __( 'Download free from WordPress.org', 'drift-ticker' );
	return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( drift_ticker_wporg_url( $slug ) ) . '">' . esc_html( $text ) . '</a>';
}

add_action( 'init', 'drift_ticker_register_shortcodes' );
/**
 * Registers [drift_ticker] and [drift_ticker_strip].
 *
 * @return void
 */
function drift_ticker_register_shortcodes() {
	add_shortcode( 'drift_ticker', 'drift_ticker_shortcode' );
	add_shortcode( 'drift_ticker_strip', 'drift_ticker_strip_shortcode' );
}

/**
 * [drift_ticker slug="drift-wipe" stat="downloaded"] or
 * [drift_ticker suite="wp" stat="downloaded"] / [drift_ticker suite="wp" stat="count"].
 * Prints nothing when there's no value.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function drift_ticker_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'slug'  => '',
			'suite' => '',
			'stat'  => 'downloaded',
		),
		$atts,
		'drift_ticker'
	);
	$stat = sanitize_key( $atts['stat'] );

	if ( '' !== $atts['suite'] ) {
		$value = drift_ticker_get_suite( $atts['suite'], $stat );
	} else {
		$value = drift_ticker_get( sanitize_key( $atts['slug'] ), $stat );
	}

	$text = drift_ticker_format( $stat, $value );
	if ( '' === $text ) {
		return '';
	}
	if ( 'url' === $stat ) {
		return esc_url( $text );
	}
	return '<span class="drift-ticker-value drift-ticker-value--' . esc_attr( $stat ) . '">' . esc_html( $text ) . '</span>';
}

/**
 * [drift_ticker_strip slug="drift-wipe" button="yes"]. Without a slug, uses
 * the current app's slug.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function drift_ticker_strip_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'slug'   => '',
			'button' => 'yes',
		),
		$atts,
		'drift_ticker_strip'
	);
	$slug = '' !== $atts['slug'] ? sanitize_key( $atts['slug'] ) : drift_ticker_app_slug( (int) get_the_ID() );

	return drift_ticker_strip(
		$slug,
		array(
			'context' => 'hero',
			'button'  => ! in_array( strtolower( (string) $atts['button'] ), array( 'no', 'false', '0' ), true ),
		)
	);
}

add_action( 'wp_enqueue_scripts', 'drift_ticker_enqueue_styles' );
/**
 * Small stylesheet for the strip and values. Loaded only while at least one
 * slug is live. Return false from drift_ticker_enqueue_styles to style it
 * in the theme instead.
 *
 * @return void
 */
function drift_ticker_enqueue_styles() {
	$has_live = in_array( 'live', wp_list_pluck( drift_ticker_get_states(), 'status' ), true );
	if ( ! apply_filters( 'drift_ticker_enqueue_styles', $has_live ) ) {
		return;
	}
	wp_enqueue_style( 'drift-ticker', DRIFT_TICKER_URL . 'assets/ticker.css', array(), DRIFT_TICKER_VERSION );
}

// ---------------------------------------------------------------------------
// Page cache
// ---------------------------------------------------------------------------

add_action( 'drift_ticker_snapshot_saved', 'drift_ticker_note_display_change', 10, 3 );
/**
 * Notes when a snapshot changed a number the front end shows, so the page
 * cache is purged once at the end of the run.
 *
 * @param string     $slug         Plugin slug.
 * @param array      $data         New stats.
 * @param array|null $previous_row Previous row.
 * @return void
 */
function drift_ticker_note_display_change( $slug, $data, $previous_row ) {
	$changed = ! $previous_row;
	foreach ( array( 'downloaded', 'active_installs', 'rating', 'num_ratings' ) as $key ) {
		if ( $previous_row && (int) $previous_row[ $key ] !== (int) $data[ $key ] ) {
			$changed = true;
		}
	}
	if ( $changed ) {
		$GLOBALS['drift_ticker_display_changed'] = true;
	}
}

add_action( 'drift_ticker_snapshot_complete', 'drift_ticker_maybe_purge_cache' );
/**
 * Purges the page cache after a run that changed what visitors see. Uses
 * SiteGround Optimizer when it's active; other caches can hook
 * drift_ticker_purge_cache.
 *
 * @return void
 */
function drift_ticker_maybe_purge_cache() {
	if ( empty( $GLOBALS['drift_ticker_display_changed'] ) ) {
		return;
	}
	$GLOBALS['drift_ticker_display_changed'] = false;
	drift_ticker_purge_cache();
}

/**
 * Purges the whole page cache. The stats appear on app pages, cards (home,
 * suite pages, "More from Drift" on every app page) and wherever the
 * shortcodes are used, so a targeted purge would miss pages.
 *
 * @return void
 */
function drift_ticker_purge_cache() {
	if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
		sg_cachepress_purge_everything();
	} elseif ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}
	do_action( 'drift_ticker_purge_cache' );
}
