<?php
/**
 * Alerts: work out events from each new snapshot, record each one once in
 * the events table, and deliver it to Make, Slack and email. Plus the weekly
 * summary (Mondays 08:30 Europe/London, see cron.php).
 *
 * Every event has a key (e.g. "milestone:drift-wipe:1000"). An event whose
 * key is already in the table is never sent again. Channel deliveries that
 * fail are retried at the end of the next runs, three attempts at most, and
 * only to the channels that failed.
 *
 * Payload (Make gets it as JSON; Slack and email get a text version):
 * { event, slug, plugin_name, values, url, timestamp }.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Download milestones, ascending.
 *
 * @return int[]
 */
function drift_ticker_milestones() {
	$milestones = (array) apply_filters( 'drift_ticker_milestones', array( 100, 500, 1000, 5000, 10000, 50000, 100000, 500000, 1000000, 5000000, 10000000 ) );
	$milestones = array_map( 'absint', $milestones );
	sort( $milestones );
	return $milestones;
}

/**
 * Configured channels: name → destination. Empty ones are left out.
 *
 * @return array<string, string>
 */
function drift_ticker_alert_channels() {
	$channels = array(
		'make'  => (string) get_option( 'drift_ticker_make_webhook', '' ),
		'slack' => (string) get_option( 'drift_ticker_slack_webhook', '' ),
		'email' => (string) get_option( 'drift_ticker_alert_email', '' ),
	);
	return array_filter( $channels );
}

/**
 * Sanitises a webhook URL: https only, else ''.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function drift_ticker_sanitize_webhook( $value ) {
	$url = esc_url_raw( trim( (string) $value ), array( 'https' ) );
	return 0 === strpos( $url, 'https://' ) ? $url : '';
}

/**
 * Sanitises the alert email address.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function drift_ticker_sanitize_alert_email( $value ) {
	$email = sanitize_email( (string) $value );
	return is_email( $email ) ? $email : '';
}

// ---------------------------------------------------------------------------
// Working out events from a snapshot
// ---------------------------------------------------------------------------

add_action( 'drift_ticker_snapshot_saved', 'drift_ticker_detect_events', 30, 4 );
/**
 * Compares a new snapshot with the previous one and raises events: new
 * ratings (per star), new support threads, a download milestone, an installs
 * band change, a new version. Nothing on a slug's first snapshot: there's
 * nothing to compare with (the "live" event comes from go-live instead).
 *
 * @param string     $slug         Plugin slug.
 * @param array      $data         New stats.
 * @param array|null $previous_row Previous stored row.
 * @param int        $app_id       App post ID, or 0.
 * @return void
 */
function drift_ticker_detect_events( $slug, $data, $previous_row, $app_id = 0 ) {
	if ( ! $previous_row ) {
		return;
	}
	foreach ( drift_ticker_events_from_change( $slug, $data, $previous_row ) as $event ) {
		do_action( 'drift_ticker_event', $event['event'], $slug, $event['values'] + array( 'app_id' => (int) $app_id ), $event['key'] );
	}
}

/**
 * The events between two snapshots. Pure: no database or sending, so it's
 * easy to test.
 *
 * @param string $slug     Plugin slug.
 * @param array  $data     New stats (normalised source data).
 * @param array  $previous Previous stored row.
 * @return array<int, array{event: string, key: string, values: array}>
 */
function drift_ticker_events_from_change( $slug, array $data, array $previous ) {
	$events = array();
	$date   = drift_ticker_today();

	// New ratings: count per star went up. WordPress.org gives counts, not the
	// review text, so the alert links to the reviews page.
	$old_ratings = json_decode( (string) $previous['ratings_json'], true );
	$old_ratings = is_array( $old_ratings ) ? $old_ratings : array();
	if ( (int) $data['num_ratings'] > (int) $previous['num_ratings'] ) {
		foreach ( array( 5, 4, 3, 2, 1 ) as $star ) {
			$new = (int) ( $data['ratings'][ $star ] ?? 0 ) - (int) ( $old_ratings[ $star ] ?? 0 );
			if ( $new > 0 ) {
				$events[] = array(
					'event'  => 'review',
					'key'    => 'review:' . $slug . ':' . (int) $data['num_ratings'] . ':' . $star,
					'values' => array(
						'stars'       => $star,
						'count'       => $new,
						'low_rating'  => $star < 4,
						'rating'      => round( (int) $data['rating'] / 20, 1 ),
						'num_ratings' => (int) $data['num_ratings'],
						'reviews_url' => 'https://wordpress.org/support/plugin/' . rawurlencode( $slug ) . '/reviews/',
					),
				);
			}
		}
	}

	// New support threads. WordPress.org counts threads from the last two
	// months, so old threads dropping off can hide a new one on the same day.
	$new_threads = (int) $data['support_threads'] - (int) $previous['support_threads'];
	if ( $new_threads > 0 ) {
		$events[] = array(
			'event'  => 'support_thread',
			'key'    => 'support_thread:' . $slug . ':' . $date . ':' . (int) $data['support_threads'],
			'values' => array(
				'count'        => $new_threads,
				'open_threads' => max( 0, (int) $data['support_threads'] - (int) $data['support_threads_resolved'] ),
				'support_url'  => 'https://wordpress.org/support/plugin/' . rawurlencode( $slug ) . '/',
			),
		);
	}

	// Download milestone: the highest one crossed since the previous snapshot.
	$crossed = 0;
	foreach ( drift_ticker_milestones() as $milestone ) {
		if ( (int) $previous['downloaded'] < $milestone && (int) $data['downloaded'] >= $milestone ) {
			$crossed = $milestone;
		}
	}
	if ( $crossed ) {
		$events[] = array(
			'event'  => 'milestone',
			'key'    => 'milestone:' . $slug . ':' . $crossed,
			'values' => array(
				'milestone'  => $crossed,
				'downloaded' => (int) $data['downloaded'],
			),
		);
	}

	// Active installs band changed (up or down).
	if ( (int) $data['active_installs'] !== (int) $previous['active_installs'] ) {
		$events[] = array(
			'event'  => 'installs',
			'key'    => 'installs:' . $slug . ':' . $date . ':' . (int) $data['active_installs'],
			'values' => array(
				'from'      => (int) $previous['active_installs'],
				'to'        => (int) $data['active_installs'],
				'from_band' => drift_ticker_installs_label( (int) $previous['active_installs'] ),
				'to_band'   => drift_ticker_installs_label( (int) $data['active_installs'] ),
				'direction' => (int) $data['active_installs'] > (int) $previous['active_installs'] ? 'up' : 'down',
			),
		);
	}

	// New version.
	if ( '' !== (string) $data['version'] && (string) $data['version'] !== (string) $previous['version'] ) {
		$events[] = array(
			'event'  => 'version',
			'key'    => 'version:' . $slug . ':' . $data['version'],
			'values' => array(
				'version'  => (string) $data['version'],
				'previous' => (string) $previous['version'],
			),
		);
	}

	return $events;
}

// ---------------------------------------------------------------------------
// Recording and sending
// ---------------------------------------------------------------------------

add_action( 'drift_ticker_event', 'drift_ticker_handle_event', 10, 4 );
/**
 * Records and sends an event raised anywhere in the plugin.
 *
 * @param string $event  Event name.
 * @param string $slug   Plugin slug ('' for the weekly summary).
 * @param array  $values Event details.
 * @param string $key    Dedupe key. Default "{event}:{slug}".
 * @return void
 */
function drift_ticker_handle_event( $event, $slug, $values = array(), $key = '' ) {
	drift_ticker_send_event( $event, $slug, (array) $values, $key );
}

/**
 * Builds the payload for an event.
 *
 * @param string $event  Event name.
 * @param string $slug   Plugin slug.
 * @param array  $values Event details.
 * @return array{event: string, slug: string, plugin_name: string, values: array, url: string, timestamp: string}
 */
function drift_ticker_build_payload( $event, $slug, array $values ) {
	$name = $values['plugin_name'] ?? '';
	unset( $values['plugin_name'], $values['url'] );
	if ( ! $name && $slug ) {
		$app_id = (int) ( $values['app_id'] ?? 0 );
		$name   = $app_id ? get_the_title( $app_id ) : drift_ticker_get_state( $slug )['name'];
	}

	return array(
		'event'       => (string) $event,
		'slug'        => (string) $slug,
		'plugin_name' => $name ? (string) $name : (string) $slug,
		'values'      => $values,
		'url'         => $slug ? drift_ticker_wporg_url( $slug ) : admin_url( 'options-general.php?page=' . DRIFT_TICKER_PAGE_SLUG ),
		'timestamp'   => wp_date( 'c', null, drift_ticker_timezone() ),
	);
}

/**
 * Records an event once and sends it to every configured channel.
 *
 * @param string $event  Event name.
 * @param string $slug   Plugin slug.
 * @param array  $values Event details.
 * @param string $key    Dedupe key. Default "{event}:{slug}".
 * @return string "sent", "failed", "partial", "no_channels", "duplicate" or "error".
 */
function drift_ticker_send_event( $event, $slug, array $values, $key = '' ) {
	global $wpdb;
	$table = drift_ticker_events_table();
	$key   = substr( '' !== $key ? $key : $event . ':' . $slug, 0, 191 );

	// The run lock means only one process gets here at a time for snapshot
	// events, so check-then-insert is enough (and doesn't log duplicate-key errors).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_key = %s", $key ) ) ) {
		return 'duplicate';
	}

	$payload  = drift_ticker_build_payload( $event, $slug, $values );
	$channels = drift_ticker_alert_channels();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
	$inserted = $wpdb->insert(
		$table,
		array(
			'event_key'  => $key,
			'event'      => substr( (string) $event, 0, 40 ),
			'slug'       => (string) $slug,
			'payload'    => (string) wp_json_encode( $payload ),
			'status'     => $channels ? 'pending' : 'no_channels',
			'channels'   => '',
			'attempts'   => 0,
			'last_error' => '',
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);
	if ( ! $inserted ) {
		drift_ticker_log( $slug, __( 'Could not record an alert, so it wasn\'t sent.', 'drift-ticker' ) );
		return 'error';
	}
	if ( ! $channels ) {
		return 'no_channels';
	}

	return drift_ticker_deliver( (int) $wpdb->insert_id, $payload, array_keys( $channels ) );
}

/**
 * Delivers a recorded event to the given channels and updates its row.
 *
 * @param int      $id       Event row ID.
 * @param array    $payload  Payload.
 * @param string[] $channels Channel names to try.
 * @param string   $done     Channels already delivered (comma separated).
 * @param int      $attempts Attempts so far.
 * @return string "sent", "partial" or "failed".
 */
function drift_ticker_deliver( $id, array $payload, array $channels, $done = '', $attempts = 0 ) {
	global $wpdb;
	$configured = drift_ticker_alert_channels();
	$delivered  = array_filter( explode( ',', $done ) );
	$errors     = array();

	foreach ( $channels as $channel ) {
		if ( empty( $configured[ $channel ] ) || in_array( $channel, $delivered, true ) ) {
			continue;
		}
		$result = drift_ticker_deliver_to( $channel, $configured[ $channel ], $payload );
		if ( true === $result ) {
			$delivered[] = $channel;
		} else {
			$errors[] = $channel . ': ' . $result;
		}
	}

	$wanted = array_intersect( array_keys( $configured ), $channels );
	$status = ! $errors ? 'sent' : ( array_intersect( $wanted, $delivered ) ? 'partial' : 'failed' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	$wpdb->update(
		drift_ticker_events_table(),
		array(
			'status'     => $status,
			'channels'   => implode( ',', array_unique( $delivered ) ),
			'attempts'   => $attempts + 1,
			'last_error' => implode( '; ', $errors ),
			'sent_at'    => $delivered ? current_time( 'mysql', true ) : null,
		),
		array( 'id' => $id ),
		array( '%s', '%s', '%d', '%s', '%s' ),
		array( '%d' )
	);

	if ( $errors ) {
		/* translators: %s: error messages */
		drift_ticker_log( $payload['slug'], sprintf( __( 'Alert not delivered: %s', 'drift-ticker' ), implode( '; ', $errors ) ) );
	}
	return $status;
}

/**
 * Sends to one channel.
 *
 * @param string $channel     "make", "slack" or "email".
 * @param string $destination Webhook URL or email address.
 * @param array  $payload     Payload.
 * @return true|string True, or the error message.
 */
function drift_ticker_deliver_to( $channel, $destination, array $payload ) {
	$message = drift_ticker_event_message( $payload );

	if ( 'email' === $channel ) {
		$sent = wp_mail( $destination, '[Drift: Ticker] ' . $message['title'], $message['title'] . "\n\n" . implode( "\n", $message['lines'] ) . "\n" );
		return $sent ? true : __( 'wp_mail() failed', 'drift-ticker' );
	}

	$body = 'slack' === $channel
		? array( 'text' => drift_ticker_slack_text( $message ) )
		: $payload;

	$response = wp_remote_post(
		$destination,
		array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	/* translators: %d: HTTP status code */
	return ( $code >= 200 && $code < 300 ) ? true : sprintf( __( 'HTTP %d', 'drift-ticker' ), $code );
}

add_action( 'drift_ticker_snapshot_complete', 'drift_ticker_retry_failed_events', 20 );
/**
 * Retries failed or partly sent events from the last two days, only to the
 * channels that failed, three attempts at most.
 *
 * @return void
 */
function drift_ticker_retry_failed_events() {
	global $wpdb;
	$table = drift_ticker_events_table();
	$since = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('failed', 'partial') AND attempts < 3 AND created_at >= %s ORDER BY id ASC LIMIT 20", $since ), ARRAY_A );

	foreach ( (array) $rows as $row ) {
		$payload = json_decode( (string) $row['payload'], true );
		if ( is_array( $payload ) ) {
			drift_ticker_deliver( (int) $row['id'], $payload, array_keys( drift_ticker_alert_channels() ), (string) $row['channels'], (int) $row['attempts'] );
		}
	}
}

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------

/**
 * A short title and detail lines for Slack and email.
 *
 * @param array $payload Payload.
 * @return array{title: string, lines: string[]}
 */
function drift_ticker_event_message( array $payload ) {
	$name   = $payload['plugin_name'];
	$values = (array) $payload['values'];
	$lines  = array();

	switch ( $payload['event'] ) {
		case 'live':
			/* translators: %s: plugin name */
			$title   = sprintf( __( '%s is live on WordPress.org', 'drift-ticker' ), $name );
			$lines[] = ! empty( $values['version'] ) ? sprintf( /* translators: %s: version */ __( 'Version %s', 'drift-ticker' ), $values['version'] ) : '';
			break;

		case 'review':
			$title = sprintf(
				/* translators: 1: number of stars, 2: plugin name */
				_n( 'New %1$s-star rating for %2$s', 'New %1$s-star ratings for %2$s', (int) $values['count'], 'drift-ticker' ),
				(int) $values['stars'],
				$name
			);
			if ( ! empty( $values['low_rating'] ) ) {
				$title = __( 'Under 4 stars:', 'drift-ticker' ) . ' ' . $title;
			}
			/* translators: 1: average stars, 2: number of ratings */
			$lines[] = sprintf( __( 'Now %1$s out of 5 from %2$s ratings.', 'drift-ticker' ), number_format_i18n( (float) $values['rating'], 1 ), number_format_i18n( (int) $values['num_ratings'] ) );
			$lines[] = (string) ( $values['reviews_url'] ?? '' );
			break;

		case 'support_thread':
			/* translators: %s: plugin name */
			$title = sprintf( _n( 'New support thread for %s', 'New support threads for %s', (int) $values['count'], 'drift-ticker' ), $name );
			/* translators: %s: number of open threads */
			$lines[] = sprintf( __( 'Open threads: %s', 'drift-ticker' ), number_format_i18n( (int) $values['open_threads'] ) );
			$lines[] = (string) ( $values['support_url'] ?? '' );
			break;

		case 'milestone':
			/* translators: 1: plugin name, 2: milestone */
			$title = sprintf( __( '%1$s passed %2$s downloads', 'drift-ticker' ), $name, number_format_i18n( (int) $values['milestone'] ) );
			/* translators: %s: total downloads */
			$lines[] = sprintf( __( 'Total: %s', 'drift-ticker' ), number_format_i18n( (int) $values['downloaded'] ) );
			break;

		case 'installs':
			/* translators: 1: plugin name, 2: old band, 3: new band */
			$title = sprintf( __( '%1$s active installs: %2$s → %3$s', 'drift-ticker' ), $name, $values['from_band'], $values['to_band'] );
			break;

		case 'version':
			/* translators: 1: plugin name, 2: version */
			$title = sprintf( __( '%1$s %2$s is on WordPress.org', 'drift-ticker' ), $name, $values['version'] );
			if ( ! empty( $values['previous'] ) ) {
				/* translators: %s: previous version */
				$lines[] = sprintf( __( 'Previous version: %s', 'drift-ticker' ), $values['previous'] );
			}
			break;

		case 'weekly':
			$title = sprintf(
				/* translators: 1: downloads this week, 2: downloads last week */
				__( 'Drift plugins this week: %1$s downloads (last week %2$s)', 'drift-ticker' ),
				number_format_i18n( (int) $values['this_week'] ),
				number_format_i18n( (int) $values['last_week'] )
			);
			foreach ( (array) $values['plugins'] as $plugin ) {
				$lines[] = sprintf(
					/* translators: 1: plugin name, 2: this week, 3: last week, 4: total downloads, 5: open threads */
					__( '%1$s: %2$s this week (%3$s last week), %4$s total, %5$s open threads', 'drift-ticker' ),
					$plugin['name'],
					number_format_i18n( (int) $plugin['this_week'] ),
					number_format_i18n( (int) $plugin['last_week'] ),
					number_format_i18n( (int) $plugin['downloaded'] ),
					number_format_i18n( (int) $plugin['open_threads'] )
				);
			}
			/* translators: %s: total downloads */
			$lines[] = sprintf( __( 'All-time total: %s downloads', 'drift-ticker' ), number_format_i18n( (int) $values['downloaded'] ) );
			break;

		case 'test':
			$title   = __( 'Test alert from Drift: Ticker', 'drift-ticker' );
			$lines[] = __( 'If you can read this, alerts reach this channel.', 'drift-ticker' );
			break;

		default:
			/* translators: 1: event name, 2: plugin name */
			$title = sprintf( __( '%1$s: %2$s', 'drift-ticker' ), $payload['event'], $name );
	}

	$lines[] = $payload['url'];

	return array(
		'title' => $title,
		'lines' => array_values( array_filter( $lines, 'strlen' ) ),
	);
}

/**
 * Slack text: bold title, then the lines. Slack's control characters
 * (& < >) are escaped, since plugin names come from the API.
 *
 * @param array $message From drift_ticker_event_message().
 * @return string
 */
function drift_ticker_slack_text( array $message ) {
	$escape = static function ( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	};
	return '*' . $escape( $message['title'] ) . "*\n" . implode( "\n", array_map( $escape, $message['lines'] ) );
}

// ---------------------------------------------------------------------------
// Weekly summary
// ---------------------------------------------------------------------------

/**
 * The weekly numbers for every live tracked plugin: downloads in the last 7
 * days vs the 7 before, all-time total, open threads.
 *
 * @return array|null Null when nothing is live.
 */
function drift_ticker_weekly_values() {
	$plugins = array();
	foreach ( drift_ticker_tracked() as $slug => $app_id ) {
		if ( ! drift_ticker_is_live( $slug ) ) {
			continue;
		}
		$series    = drift_ticker_daily_series( $slug, 14 );
		$plugins[] = array(
			'slug'         => $slug,
			'name'         => $app_id ? get_the_title( $app_id ) : (string) drift_ticker_get( $slug, 'name' ),
			'this_week'    => (int) drift_ticker_sum_series( $series, 7 )['total'],
			'last_week'    => (int) drift_ticker_sum_series( array_slice( $series, 0, 7 ), 7 )['total'],
			'downloaded'   => (int) drift_ticker_get( $slug, 'downloaded' ),
			'open_threads' => (int) drift_ticker_get( $slug, 'open_threads' ),
		);
	}
	if ( ! $plugins ) {
		return null;
	}
	return array(
		'this_week'  => array_sum( wp_list_pluck( $plugins, 'this_week' ) ),
		'last_week'  => array_sum( wp_list_pluck( $plugins, 'last_week' ) ),
		'downloaded' => array_sum( wp_list_pluck( $plugins, 'downloaded' ) ),
		'plugins'    => $plugins,
	);
}

/**
 * Sends the weekly summary, once per ISO week.
 *
 * @return string Result from drift_ticker_send_event(), or "nothing_live".
 */
function drift_ticker_send_weekly_summary() {
	$values = drift_ticker_weekly_values();
	if ( null === $values ) {
		return 'nothing_live';
	}
	$values['plugin_name'] = __( 'All Drift plugins', 'drift-ticker' );
	$week                  = wp_date( 'o-\WW', null, drift_ticker_timezone() );

	return drift_ticker_send_event( 'weekly', '', $values, 'weekly:' . $week );
}
