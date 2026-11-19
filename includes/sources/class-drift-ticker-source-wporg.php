<?php
/**
 * WordPress.org plugin info API (1.2).
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and normalises one plugin's stats from api.wordpress.org.
 */
class Drift_Ticker_Source_Wporg implements Drift_Ticker_Source {

	const ENDPOINT  = 'https://api.wordpress.org/plugins/info/1.2/';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const TIMEOUT   = 15;

	/**
	 * Short ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'wporg';
	}

	/**
	 * Transient key for a slug's cached response.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public function cache_key( $slug ) {
		// md5 keeps long slugs under the 172-character transient name limit.
		return 'drift_ticker_wporg_' . md5( $slug );
	}

	/**
	 * Request URL. Asks for the stats fields explicitly and turns off the
	 * heavy ones (sections, screenshots, versions…) to keep the response small.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public function request_url( $slug ) {
		$fields = array(
			'downloaded'               => 1,
			'active_installs'          => 1,
			'rating'                   => 1,
			'ratings'                  => 1,
			'num_ratings'              => 1,
			'support_threads'          => 1,
			'support_threads_resolved' => 1,
			'last_updated'             => 1,
			'sections'                 => 0,
			'screenshots'              => 0,
			'description'              => 0,
			'short_description'        => 0,
			'versions'                 => 0,
			'banners'                  => 0,
			'icons'                    => 0,
			'contributors'             => 0,
			'tags'                     => 0,
			'compatibility'            => 0,
			'donate_link'              => 0,
			'reviews'                  => 0,
			'homepage'                 => 0,
			'upgrade_notice'           => 0,
			'block_assets'             => 0,
			'blocks'                   => 0,
		);

		return self::ENDPOINT . '?' . http_build_query(
			array(
				'action'  => 'plugin_information',
				'request' => array(
					'slug'   => $slug,
					'fields' => $fields,
				),
			),
			'',
			'&'
		);
	}

	/**
	 * Fetches the stats for one slug, from the 6-hour cache unless $force.
	 * Only good responses and "not found" are cached; errors are retried next run.
	 *
	 * @param string $slug  Plugin slug.
	 * @param bool   $force Skip the cache.
	 * @return array|null|WP_Error
	 */
	public function fetch( $slug, $force = false ) {
		if ( ! drift_ticker_is_valid_slug( $slug ) ) {
			return new WP_Error( 'drift_ticker_bad_slug', __( 'Not a valid WordPress.org slug.', 'drift-ticker' ) );
		}

		$key = $this->cache_key( $slug );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && array_key_exists( 'data', $cached ) ) {
				return $cached['data'];
			}
		}

		$response = wp_remote_get(
			$this->request_url( $slug ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'drift_ticker_bad_json', sprintf( __( 'WordPress.org returned an unreadable response (HTTP %d).', 'drift-ticker' ), $code ) );
		}

		if ( isset( $body['error'] ) ) {
			// Not submitted, or submitted and still in review: "Plugin not found."
			if ( 404 === $code && 'closed' !== $body['error'] ) {
				set_transient( $key, array( 'data' => null ), self::CACHE_TTL );
				return null;
			}
			if ( 'closed' === $body['error'] ) {
				return new WP_Error( 'drift_ticker_closed', __( 'The plugin is closed on WordPress.org.', 'drift-ticker' ) );
			}
			/* translators: 1: HTTP status code, 2: error from WordPress.org */
			return new WP_Error( 'drift_ticker_api_error', sprintf( __( 'WordPress.org error (HTTP %1$d): %2$s', 'drift-ticker' ), $code, sanitize_text_field( (string) $body['error'] ) ) );
		}

		if ( 200 !== $code || ! isset( $body['slug'], $body['downloaded'] ) ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'drift_ticker_bad_response', sprintf( __( 'Unexpected response from WordPress.org (HTTP %d).', 'drift-ticker' ), $code ) );
		}

		$data = $this->normalise( $body );
		set_transient( $key, array( 'data' => $data ), self::CACHE_TTL );

		return $data;
	}

	/**
	 * Keeps only what Ticker stores, with types fixed.
	 *
	 * @param array $body Decoded API response.
	 * @return array{name: string, slug: string, downloaded: int, active_installs: int, rating: int, num_ratings: int, ratings: array<int, int>, support_threads: int, support_threads_resolved: int, version: string, last_updated: string}
	 */
	public function normalise( array $body ) {
		$ratings = array();
		foreach ( array( 5, 4, 3, 2, 1 ) as $star ) {
			$ratings[ $star ] = isset( $body['ratings'][ $star ] ) ? absint( $body['ratings'][ $star ] ) : 0;
		}

		return array(
			'name'                     => sanitize_text_field( html_entity_decode( (string) ( $body['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ),
			'slug'                     => (string) $body['slug'],
			'downloaded'               => absint( $body['downloaded'] ?? 0 ),
			'active_installs'          => absint( $body['active_installs'] ?? 0 ),
			'rating'                   => min( 100, absint( $body['rating'] ?? 0 ) ),
			'num_ratings'              => absint( $body['num_ratings'] ?? 0 ),
			'ratings'                  => $ratings,
			'support_threads'          => absint( $body['support_threads'] ?? 0 ),
			'support_threads_resolved' => absint( $body['support_threads_resolved'] ?? 0 ),
			'version'                  => substr( sanitize_text_field( (string) ( $body['version'] ?? '' ) ), 0, 40 ),
			'last_updated'             => self::parse_date( (string) ( $body['last_updated'] ?? '' ) ),
		);
	}

	/**
	 * "2026-08-18 11:42pm GMT" → "2026-08-18 23:42:00" (UTC), or '' if unreadable.
	 *
	 * @param string $value Date from the API.
	 * @return string
	 */
	public static function parse_date( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d g:ia T', $value );
		if ( ! $date ) {
			$timestamp = strtotime( $value );
			return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
		}
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
