<?php
/**
 * WordPress.org stats for the theme's Stats page module (drift-create,
 * modules/stats/module.php), e.g. "18,000 downloads across 8 free plugins".
 *
 * Ticker adds choices to the module's "Counted from Apps" checkbox and fills
 * them through the theme filter drift_stats_counted, which the theme applies
 * with one line (README → Page modules). Without Ticker the choices aren't
 * there and the module ignores keys it doesn't know, so nothing breaks.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ticker's counted stats: key → editor label.
 *
 * @return array<string, string>
 */
function drift_ticker_counted_choices() {
	return array(
		'wporg_downloads'    => __( 'WordPress.org downloads', 'drift-ticker' ),
		'wporg_plugins'      => __( 'Free plugins on WordPress.org', 'drift-ticker' ),
		'wporg_installs'     => __( 'WordPress.org active installs', 'drift-ticker' ),
		'wporg_rating'       => __( 'WordPress.org average rating', 'drift-ticker' ),
		'wporg_downloads_30' => __( 'WordPress.org downloads, last 30 days', 'drift-ticker' ),
	);
}

add_filter( 'acf/load_field/key=field_drift_stats_counted', 'drift_ticker_add_counted_choices' );
/**
 * Adds Ticker's stats to the Stats module's "Counted from Apps" choices.
 *
 * @param array $field ACF field.
 * @return array
 */
function drift_ticker_add_counted_choices( $field ) {
	$field['choices'] = array_merge( (array) ( $field['choices'] ?? array() ), drift_ticker_counted_choices() );
	return $field;
}

/**
 * Which suite a Stats module counts: the module's Accent suite if set, else
 * the page's suite (drift_page_suite(), e.g. the WP Suite page), else all.
 *
 * @param string $suite Suite key from the module, or ''.
 * @return string
 */
function drift_ticker_module_suite( $suite ) {
	if ( '' === (string) $suite && function_exists( 'drift_page_suite' ) ) {
		$suite = (string) drift_page_suite();
	}
	return '' !== (string) $suite ? (string) $suite : 'all';
}

add_filter( 'drift_stats_counted', 'drift_ticker_add_counted_stats', 10, 2 );
/**
 * Adds Ticker's values to the theme's counted stats, in the theme's format:
 * key → [ value, singular label, plural label ]. Values are formatted strings
 * ("18,000", "1,100+", "4.8"). A stat with no value (nothing live in the
 * suite, no ratings yet, no 30-day data) isn't added, so it isn't shown.
 *
 * @param array  $counted Theme's counted stats.
 * @param string $suite   Module accent suite, or ''.
 * @return array
 */
function drift_ticker_add_counted_stats( $counted, $suite = '' ) {
	$counted = (array) $counted;
	foreach ( drift_ticker_counted_stats( drift_ticker_module_suite( $suite ) ) as $key => $stat ) {
		$counted[ $key ] = array( $stat['value'], $stat['label'], $stat['label'] );
	}
	return $counted;
}

/**
 * The counted stats for a suite: key → value and label.
 *
 * @param string $suite Suite key, "wp" or "all".
 * @return array<string, array{value: string, label: string}>
 */
function drift_ticker_counted_stats( $suite ) {
	$plugins = (int) drift_ticker_get_suite( $suite, 'count' );
	if ( ! $plugins ) {
		return array();
	}

	$stats     = array();
	$downloads = drift_ticker_get_suite( $suite, 'downloaded' );
	if ( $downloads ) {
		$stats['wporg_downloads'] = array(
			'value' => number_format_i18n( $downloads ),
			'label' => $plugins > 1
				/* translators: %s: number of plugins */
				? sprintf( __( 'downloads across %s free plugins', 'drift-ticker' ), number_format_i18n( $plugins ) )
				: _n( 'download', 'downloads', $downloads, 'drift-ticker' ),
		);
	}

	$stats['wporg_plugins'] = array(
		'value' => number_format_i18n( $plugins ),
		'label' => _n( 'free plugin on WordPress.org', 'free plugins on WordPress.org', $plugins, 'drift-ticker' ),
	);

	$installs = (int) drift_ticker_get_suite( $suite, 'active_installs' );
	if ( $installs >= 10 ) {
		$stats['wporg_installs'] = array(
			'value' => drift_ticker_installs_label( $installs ),
			'label' => __( 'active installs', 'drift-ticker' ),
		);
	}

	$stars   = drift_ticker_get_suite( $suite, 'stars' );
	$ratings = (int) drift_ticker_get_suite( $suite, 'num_ratings' );
	if ( null !== $stars && $ratings ) {
		$stats['wporg_rating'] = array(
			'value' => number_format_i18n( (float) $stars, 1 ),
			/* translators: %s: number of ratings */
			'label' => sprintf( _n( 'average rating from %s review', 'average rating from %s reviews', $ratings, 'drift-ticker' ), number_format_i18n( $ratings ) ),
		);
	}

	$recent = drift_ticker_get_suite( $suite, 'last_30' );
	if ( $recent ) {
		$stats['wporg_downloads_30'] = array(
			'value' => number_format_i18n( $recent ),
			'label' => __( 'downloads in the last 30 days', 'drift-ticker' ),
		);
	}

	return $stats;
}
