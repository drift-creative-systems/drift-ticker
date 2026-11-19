<?php
/**
 * Settings → Drift: Ticker.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'drift_ticker_add_menu' );
/**
 * Adds Settings → Drift: Ticker.
 *
 * @return void
 */
function drift_ticker_add_menu() {
	add_options_page(
		__( 'Drift: Ticker', 'drift-ticker' ),
		__( 'Drift: Ticker', 'drift-ticker' ),
		drift_ticker_capability(),
		DRIFT_TICKER_PAGE_SLUG,
		'drift_ticker_render_settings_page'
	);
}

add_filter( 'option_page_capability_' . DRIFT_TICKER_OPTION_GROUP, 'drift_ticker_capability' );

add_filter( 'plugin_action_links_' . plugin_basename( DRIFT_TICKER_FILE ), 'drift_ticker_add_settings_link' );
/**
 * Adds a Settings link on the Plugins screen.
 *
 * @param array $links Action links.
 * @return array
 */
function drift_ticker_add_settings_link( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . DRIFT_TICKER_PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'drift-ticker' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

add_action( 'admin_init', 'drift_ticker_register_settings' );
/**
 * Registers the settings.
 *
 * @return void
 */
function drift_ticker_register_settings() {
	register_setting(
		DRIFT_TICKER_OPTION_GROUP,
		'drift_ticker_extra_slugs',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'drift_ticker_sanitize_slugs',
			'default'           => array(),
			'show_in_rest'      => false,
		)
	);

	add_settings_section( 'drift_ticker_tracking', __( 'Tracking', 'drift-ticker' ), '__return_false', DRIFT_TICKER_PAGE_SLUG );

	add_settings_field(
		'drift_ticker_extra_slugs',
		__( 'Extra slugs', 'drift-ticker' ),
		'drift_ticker_render_extra_slugs_field',
		DRIFT_TICKER_PAGE_SLUG,
		'drift_ticker_tracking',
		array( 'label_for' => 'drift_ticker_extra_slugs' )
	);

	// Alerts.
	$alert_fields = array(
		'drift_ticker_make_webhook'  => array( __( 'Make webhook URL', 'drift-ticker' ), 'drift_ticker_sanitize_webhook', 'url', __( 'Receives every alert as JSON: event, slug, plugin_name, values, url, timestamp.', 'drift-ticker' ) ),
		'drift_ticker_slack_webhook' => array( __( 'Slack webhook URL', 'drift-ticker' ), 'drift_ticker_sanitize_webhook', 'url', __( 'Optional. A Slack incoming webhook (https://hooks.slack.com/…).', 'drift-ticker' ) ),
		'drift_ticker_alert_email'   => array( __( 'Email address', 'drift-ticker' ), 'drift_ticker_sanitize_alert_email', 'email', __( 'Optional. Sent with wp_mail().', 'drift-ticker' ) ),
	);

	add_settings_section( 'drift_ticker_alerts', __( 'Alerts', 'drift-ticker' ), 'drift_ticker_render_alerts_intro', DRIFT_TICKER_PAGE_SLUG );

	foreach ( $alert_fields as $option => $field ) {
		register_setting(
			DRIFT_TICKER_OPTION_GROUP,
			$option,
			array(
				'type'              => 'string',
				'sanitize_callback' => $field[1],
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		add_settings_field(
			$option,
			$field[0],
			'drift_ticker_render_alert_field',
			DRIFT_TICKER_PAGE_SLUG,
			'drift_ticker_alerts',
			array(
				'label_for'   => $option,
				'type'        => $field[2],
				'description' => $field[3],
			)
		);
	}
}

/**
 * Alerts section intro.
 *
 * @return void
 */
function drift_ticker_render_alerts_intro() {
	echo '<p>' . esc_html__( 'Alerts go out when a plugin goes live, gets a new rating (flagged under 4 stars) or support thread, passes a download milestone (100, 500, 1k, 5k, 10k…), changes active installs band or releases a new version, plus a summary every Monday at 08:30 UK time. Each one is sent once.', 'drift-ticker' ) . '</p>';
}

/**
 * A URL or email field for the alerts section.
 *
 * @param array $args label_for, type, description.
 * @return void
 */
function drift_ticker_render_alert_field( $args ) {
	$option = $args['label_for'];
	printf(
		'<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="regular-text code" aria-describedby="%2$s-help" autocomplete="off"><p class="description" id="%2$s-help">%4$s</p>',
		esc_attr( $args['type'] ),
		esc_attr( $option ),
		esc_attr( (string) get_option( $option, '' ) ),
		esc_html( $args['description'] )
	);
}

/**
 * Extra slugs textarea.
 *
 * @return void
 */
function drift_ticker_render_extra_slugs_field() {
	?>
	<textarea id="drift_ticker_extra_slugs" name="drift_ticker_extra_slugs" rows="4" cols="40" class="code" aria-describedby="drift-ticker-extra-slugs-help"><?php echo esc_textarea( implode( "\n", drift_ticker_extra_slugs() ) ); ?></textarea>
	<p class="description" id="drift-ticker-extra-slugs-help">
		<?php esc_html_e( 'One WordPress.org slug per line, for plugins without an app page. Apps are tracked from their WordPress.org slug field.', 'drift-ticker' ); ?>
	</p>
	<?php
}

add_filter( 'admin_footer_text', 'drift_ticker_admin_footer_text' );
/**
 * Copyright and company details in the admin footer, on this screen only.
 *
 * @param string $text Footer text.
 * @return string
 */
function drift_ticker_admin_footer_text( $text ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'settings_page_' . DRIFT_TICKER_PAGE_SLUG !== $screen->id ) {
		return $text;
	}
	return 'Copyright &copy; ' . esc_html( wp_date( 'Y' ) ) . ' Drift Creative Systems Ltd &middot; Registered in England and Wales, company number 17548220. Registered office: 167-169 Great Portland Street, 5th Floor, London W1W 5PF';
}

add_action( 'admin_enqueue_scripts', 'drift_ticker_admin_assets' );
/**
 * Styles and scripts, on the settings screen only.
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
function drift_ticker_admin_assets( $hook_suffix ) {
	if ( 'settings_page_' . DRIFT_TICKER_PAGE_SLUG !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style( 'drift-ticker-admin', DRIFT_TICKER_URL . 'assets/admin.css', array(), DRIFT_TICKER_VERSION );
	wp_enqueue_script( 'drift-ticker-chart', DRIFT_TICKER_URL . 'assets/chart.js', array( 'jquery' ), DRIFT_TICKER_VERSION, true );
	wp_enqueue_script( 'drift-ticker-admin', DRIFT_TICKER_URL . 'assets/admin.js', array( 'jquery', 'drift-ticker-chart' ), DRIFT_TICKER_VERSION, true );
	wp_localize_script(
		'drift-ticker-admin',
		'driftTicker',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'drift_ticker_refresh' ),
			'i18n'    => array(
				'sending'     => __( 'Sending…', 'drift-ticker' ),
				'refreshing'  => __( 'Refreshing…', 'drift-ticker' ),
				'failed'      => __( 'The refresh failed. Please try again.', 'drift-ticker' ),
				/* translators: 1: number of downloads, 2: number of days */
				'total'       => __( '%1$s downloads in the last %2$s days', 'drift-ticker' ),
				/* translators: %s: date */
				'since'       => __( 'since %s', 'drift-ticker' ),
				'noData'      => __( 'No data', 'drift-ticker' ),
				'noDataRange' => __( 'No download data for this period yet.', 'drift-ticker' ),
				'downloads'   => __( 'downloads', 'drift-ticker' ),
				'version'     => __( 'Version', 'drift-ticker' ),
				'date'        => __( 'Date', 'drift-ticker' ),
				'week'        => __( 'Week', 'drift-ticker' ),
				'colDownload' => __( 'Downloads', 'drift-ticker' ),
				/* translators: 1: plugin name, 2: number of days */
				'chartLabel'  => __( 'Daily downloads of %1$s, last %2$s days', 'drift-ticker' ),
				/* translators: %s: plugin name */
				'weekLabel'   => __( 'Weekly downloads of %s, last 52 weeks', 'drift-ticker' ),
				'keyHint'     => __( 'Use the left and right arrow keys to read each bar.', 'drift-ticker' ),
			),
		)
	);
}

/**
 * Drift monogram, same as Drift: Wipe. Fixed markup; only the fill is variable.
 *
 * @param string $fill Fill colour.
 * @param int    $size Width and height in px.
 * @return string
 */
function drift_ticker_mark_svg( $fill = 'currentColor', $size = 32 ) {
	$paths = array(
		'M17 12H54C77 12 91 29 91 50C91 72 76 88 54 88H45L55 76H54C70 76 79 64 79 50C79 35 69 24 54 24H29V44H17Z',
		'M17 55H29L17 67Z',
		'M41 55H54L25 88H12Z',
		'M55 76H70L59 88H43Z',
	);

	$svg = sprintf( '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 100 100" aria-hidden="true" focusable="false">', (int) $size );
	foreach ( $paths as $d ) {
		$svg .= '<path fill="' . esc_attr( $fill ) . '" d="' . esc_attr( $d ) . '"/>';
	}

	return $svg . '</svg>';
}

/**
 * Labels for slug statuses.
 *
 * @return array<string, string>
 */
function drift_ticker_status_labels() {
	return array(
		'unknown' => __( 'Not checked yet', 'drift-ticker' ),
		'pending' => __( 'Pending', 'drift-ticker' ),
		'live'    => __( 'Live', 'drift-ticker' ),
		'closed'  => __( 'Closed', 'drift-ticker' ),
	);
}

/**
 * Display name for a tracked slug: the app title, else the WordPress.org name, else the slug.
 *
 * @param string $slug   Plugin slug.
 * @param int    $app_id App post ID, or 0.
 * @return string
 */
function drift_ticker_display_name( $slug, $app_id ) {
	$name = $app_id ? get_the_title( $app_id ) : drift_ticker_get_state( $slug )['name'];
	return $name ? $name : $slug;
}

/**
 * A 7/30-day total for a table cell: "1,240", "1,240 since 15/11/2026" when the
 * data starts inside the period, or an en dash when there's none.
 *
 * @param array $sum From drift_ticker_sum_series().
 * @return string Escaped HTML.
 */
function drift_ticker_period_cell( array $sum ) {
	if ( null === $sum['total'] ) {
		return '&ndash;';
	}
	$html = esc_html( number_format_i18n( $sum['total'] ) );
	if ( $sum['partial'] ) {
		/* translators: %s: date */
		$html .= '<br><span class="drift-ticker-muted">' . esc_html( sprintf( __( 'since %s', 'drift-ticker' ), drift_ticker_format_date( $sum['since'] ) ) ) . '</span>';
	}
	return $html;
}

/**
 * Tracked plugins table. Returned as HTML so "Refresh now" can swap it in.
 *
 * @return string
 */
function drift_ticker_tracked_table_html() {
	$tracked = drift_ticker_tracked();
	$labels  = drift_ticker_status_labels();

	if ( ! $tracked ) {
		return '<p>' . esc_html__( 'Nothing is tracked yet. Add a WordPress.org slug to an app, or add extra slugs below.', 'drift-ticker' ) . '</p>';
	}

	ob_start();
	?>
	<table class="widefat striped drift-ticker-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Plugin', 'drift-ticker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'drift-ticker' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Downloads', 'drift-ticker' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Last 7 days', 'drift-ticker' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Last 30 days', 'drift-ticker' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Active installs', 'drift-ticker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Rating', 'drift-ticker' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Open threads', 'drift-ticker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Version', 'drift-ticker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last updated', 'drift-ticker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $tracked as $slug => $app_id ) :
				$state   = drift_ticker_get_state( $slug );
				$summary = drift_ticker_summary( $slug );
				?>
				<tr>
					<th scope="row">
						<strong><?php echo esc_html( drift_ticker_display_name( $slug, (int) $app_id ) ); ?></strong><br>
						<a href="<?php echo esc_url( drift_ticker_wporg_url( $slug ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $slug ); ?></code><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'drift-ticker' ); ?></span></a>
					</th>
					<td>
						<span class="drift-ticker-status drift-ticker-status--<?php echo esc_attr( $state['status'] ); ?>"><?php echo esc_html( $labels[ $state['status'] ] ?? $state['status'] ); ?></span>
						<?php if ( $state['last_checked'] ) : ?>
							<br><span class="drift-ticker-muted">
								<?php
								/* translators: %s: date and time */
								echo esc_html( sprintf( __( 'Checked %s', 'drift-ticker' ), drift_ticker_format_date( $state['last_checked'], true ) ) );
								?>
							</span>
						<?php endif; ?>
						<?php if ( $state['last_error'] ) : ?>
							<br><span class="drift-ticker-error"><?php echo esc_html( $state['last_error'] ); ?></span>
						<?php endif; ?>
					</td>
					<?php if ( $summary ) : ?>
						<td class="num"><?php echo esc_html( number_format_i18n( $summary['downloaded'] ) ); ?></td>
						<td class="num"><?php echo drift_ticker_period_cell( $summary['last_7'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?></td>
						<td class="num"><?php echo drift_ticker_period_cell( $summary['last_30'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?></td>
						<td class="num"><?php echo esc_html( drift_ticker_installs_label( $summary['active_installs'] ) ); ?></td>
						<td>
							<?php if ( $summary['num_ratings'] ) : ?>
								<span aria-hidden="true">&#9733;</span>
								<?php
								/* translators: 1: rating out of 5, 2: number of ratings */
								$drift_ticker_rating = sprintf( __( '%1$s (%2$s)', 'drift-ticker' ), drift_ticker_stars( $summary['rating'] ), number_format_i18n( $summary['num_ratings'] ) );
								echo esc_html( $drift_ticker_rating );
								?>
								<span class="screen-reader-text"><?php esc_html_e( 'out of 5, number of ratings in brackets', 'drift-ticker' ); ?></span>
							<?php else : ?>
								<span class="drift-ticker-muted"><?php esc_html_e( 'No ratings', 'drift-ticker' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num">
							<?php echo esc_html( number_format_i18n( $summary['open_threads'] ) ); ?>
							<?php if ( $summary['support_threads'] ) : ?>
								<span class="drift-ticker-muted">
									<?php
									/* translators: %s: number of support threads */
									echo esc_html( sprintf( __( 'of %s', 'drift-ticker' ), number_format_i18n( $summary['support_threads'] ) ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $summary['version'] ); ?></td>
						<td><?php echo $summary['last_updated'] ? esc_html( drift_ticker_format_date( $summary['last_updated'] ) ) : '&ndash;'; ?></td>
					<?php else : ?>
						<td colspan="8" class="drift-ticker-muted">
							<?php
							echo 'pending' === $state['status']
								? esc_html__( 'Not on WordPress.org yet. Checked daily; stats start the day it goes live.', 'drift-ticker' )
								: esc_html__( 'No snapshot yet.', 'drift-ticker' );
							?>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return (string) ob_get_clean();
}

/**
 * One chart card per tracked slug that has snapshots. The series (365 days)
 * is in data-series; assets/chart.js draws it for the chosen range.
 *
 * @return string
 */
function drift_ticker_charts_html() {
	$charts = '';
	foreach ( drift_ticker_tracked() as $slug => $app_id ) {
		if ( ! drift_ticker_get_latest( $slug ) ) {
			continue;
		}
		$name = drift_ticker_display_name( $slug, (int) $app_id );
		$id   = 'drift-ticker-chart-' . $slug;

		// Compact: [ date, downloads|null, new version|"" ].
		$series = array_map(
			static function ( $entry ) {
				return array( $entry['date'], $entry['downloads'], $entry['version'] );
			},
			drift_ticker_daily_series( $slug, 365 )
		);

		$export = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'drift_ticker_export',
					'slug'   => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			'drift_ticker_export'
		);

		ob_start();
		?>
		<section class="drift-ticker-chart" aria-labelledby="<?php echo esc_attr( $id ); ?>">
			<div class="drift-ticker-chart__head">
				<h3 id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></h3>
				<p class="drift-ticker-chart__total" data-chart-total></p>
				<a class="drift-ticker-chart__csv" href="<?php echo esc_url( $export ); ?>">
					<?php esc_html_e( 'CSV', 'drift-ticker' ); ?><span class="screen-reader-text"> <?php echo esc_html( sprintf( /* translators: %s: plugin name */ __( 'export for %s', 'drift-ticker' ), $name ) ); ?></span>
				</a>
			</div>
			<div class="drift-ticker-chart__plot" data-chart data-name="<?php echo esc_attr( $name ); ?>" data-series="<?php echo esc_attr( (string) wp_json_encode( $series ) ); ?>"></div>
			<details class="drift-ticker-chart__data">
				<summary><?php esc_html_e( 'Show as a table', 'drift-ticker' ); ?></summary>
				<div data-chart-table></div>
			</details>
		</section>
		<?php
		$charts .= ob_get_clean();
	}

	if ( '' === $charts ) {
		return '<p class="drift-ticker-muted">' . esc_html__( 'Charts appear here once a tracked plugin is live on WordPress.org and has its first snapshot.', 'drift-ticker' ) . '</p>';
	}
	return $charts;
}

/**
 * Settings screen.
 *
 * @return void
 */
function drift_ticker_render_settings_page() {
	if ( ! current_user_can( drift_ticker_capability() ) ) {
		return;
	}
	$last_run   = (int) get_option( 'drift_ticker_last_run', 0 );
	$next_run   = wp_next_scheduled( DRIFT_TICKER_CRON_DAILY );
	$log        = get_option( 'drift_ticker_log', array() );
	$export_all = wp_nonce_url( add_query_arg( 'action', 'drift_ticker_export', admin_url( 'admin-post.php' ) ), 'drift_ticker_export' );
	?>
	<div class="wrap drift-ticker">
		<h1 class="drift-ticker__title">
			<span class="drift-ticker__mark"><?php echo drift_ticker_mark_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup, attributes escaped in drift_ticker_mark_svg(). ?></span>
			<?php echo esc_html( get_admin_page_title() ); ?>
		</h1>

		<div class="drift-ticker__card">
			<div class="drift-ticker__card-head">
				<h2><?php esc_html_e( 'Tracked plugins', 'drift-ticker' ); ?></h2>
				<div class="drift-ticker__actions">
					<a class="button" href="<?php echo esc_url( $export_all ); ?>"><?php esc_html_e( 'Export CSV', 'drift-ticker' ); ?></a>
					<button type="button" class="button button-primary" id="drift-ticker-refresh"><?php esc_html_e( 'Refresh now', 'drift-ticker' ); ?></button>
				</div>
			</div>
			<p class="drift-ticker__intro">
				<?php esc_html_e( 'WordPress.org shows active installs in bands (10+, 100+, 1,000+…), and its download count includes updates, not just new installs. Open threads are from the last two months, as WordPress.org counts them.', 'drift-ticker' ); ?>
			</p>
			<p class="drift-ticker__meta" id="drift-ticker-message" role="status" aria-live="polite">
				<?php
				if ( $last_run ) {
					/* translators: %s: date and time */
					printf( esc_html__( 'Last run: %s.', 'drift-ticker' ), esc_html( drift_ticker_format_date( $last_run, true ) ) );
					echo ' ';
				}
				if ( $next_run ) {
					/* translators: %s: date and time */
					printf( esc_html__( 'Next run: %s.', 'drift-ticker' ), esc_html( drift_ticker_format_date( $next_run, true ) ) );
				}
				?>
			</p>
			<div class="drift-ticker__scroll" id="drift-ticker-tracked">
				<?php echo drift_ticker_tracked_table_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built. ?>
			</div>
		</div>

		<div class="drift-ticker__card">
			<div class="drift-ticker__card-head">
				<h2><?php esc_html_e( 'Daily downloads', 'drift-ticker' ); ?></h2>
				<fieldset class="drift-ticker-range">
					<legend class="screen-reader-text"><?php esc_html_e( 'Period', 'drift-ticker' ); ?></legend>
					<?php
					$drift_ticker_ranges = array(
						30  => __( '30 days', 'drift-ticker' ),
						90  => __( '90 days', 'drift-ticker' ),
						365 => __( '12 months', 'drift-ticker' ),
					);
					foreach ( $drift_ticker_ranges as $drift_ticker_days => $drift_ticker_label ) :
						?>
						<label>
							<input type="radio" name="drift_ticker_range" value="<?php echo esc_attr( $drift_ticker_days ); ?>" <?php checked( 30, $drift_ticker_days ); ?>>
							<span><?php echo esc_html( $drift_ticker_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</div>
			<p class="drift-ticker-muted"><?php esc_html_e( '12 months shows weekly totals. Dashed lines mark the day a new version appeared.', 'drift-ticker' ); ?></p>
			<div id="drift-ticker-charts">
				<?php echo drift_ticker_charts_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built. ?>
			</div>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( DRIFT_TICKER_OPTION_GROUP ); ?>
			<div class="drift-ticker__card">
				<?php do_settings_sections( DRIFT_TICKER_PAGE_SLUG ); ?>
				<p>
					<button type="button" class="button" id="drift-ticker-test-alert"><?php esc_html_e( 'Send a test alert', 'drift-ticker' ); ?></button>
					<span class="drift-ticker-muted" id="drift-ticker-test-message" role="status" aria-live="polite"><?php esc_html_e( 'Uses the saved settings: save first.', 'drift-ticker' ); ?></span>
				</p>
			</div>
			<?php submit_button(); ?>
		</form>

		<?php echo drift_ticker_recent_alerts_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built. ?>

		<?php if ( is_array( $log ) && $log ) : ?>
			<div class="drift-ticker__card">
				<h2><?php esc_html_e( 'Fetch log', 'drift-ticker' ); ?></h2>
				<ul class="drift-ticker-log">
					<?php foreach ( array_slice( $log, 0, 20 ) as $entry ) : ?>
						<li>
							<time><?php echo esc_html( drift_ticker_format_date( (int) $entry['time'], true ) ); ?></time>
							<code><?php echo esc_html( $entry['slug'] ); ?></code>
							<?php echo esc_html( $entry['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Recent alerts card: the last 20 events and where they went.
 *
 * @return string
 */
function drift_ticker_recent_alerts_html() {
	global $wpdb;
	$table = drift_ticker_events_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; admin only.
	$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 20", ARRAY_A );
	if ( ! $rows ) {
		return '';
	}

	$statuses = array(
		'sent'        => __( 'Sent', 'drift-ticker' ),
		'partial'     => __( 'Partly sent', 'drift-ticker' ),
		'failed'      => __( 'Failed', 'drift-ticker' ),
		'pending'     => __( 'Sending', 'drift-ticker' ),
		'no_channels' => __( 'Not sent (no channels set)', 'drift-ticker' ),
	);

	ob_start();
	?>
	<div class="drift-ticker__card">
		<h2><?php esc_html_e( 'Recent alerts', 'drift-ticker' ); ?></h2>
		<div class="drift-ticker__scroll">
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'drift-ticker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Alert', 'drift-ticker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'drift-ticker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sent to', 'drift-ticker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $rows as $row ) :
						$payload = json_decode( (string) $row['payload'], true );
						$message = is_array( $payload ) ? drift_ticker_event_message( $payload ) : array( 'title' => $row['event_key'] );
						?>
						<tr>
							<td><?php echo esc_html( drift_ticker_format_date( $row['created_at'], true ) ); ?></td>
							<td><?php echo esc_html( $message['title'] ); ?></td>
							<td>
								<?php echo esc_html( $statuses[ $row['status'] ] ?? $row['status'] ); ?>
								<?php if ( $row['last_error'] ) : ?>
									<br><span class="drift-ticker-error"><?php echo esc_html( $row['last_error'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( str_replace( ',', ', ', (string) $row['channels'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

add_action( 'wp_ajax_drift_ticker_test_alert', 'drift_ticker_ajax_test_alert' );
/**
 * "Send a test alert": sends a "test" event to the saved channels. Each test
 * has its own key, so it always sends.
 *
 * @return void
 */
function drift_ticker_ajax_test_alert() {
	try {
		check_ajax_referer( 'drift_ticker_refresh', 'nonce' );

		if ( ! current_user_can( drift_ticker_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'drift-ticker' ) ), 403 );
		}
		if ( ! drift_ticker_alert_channels() ) {
			wp_send_json_error( array( 'message' => __( 'No channels saved yet. Add a webhook URL or email address and save.', 'drift-ticker' ) ), 400 );
		}

		$status   = drift_ticker_send_event( 'test', '', array( 'plugin_name' => 'Drift: Ticker' ), 'test:' . wp_generate_uuid4() );
		$messages = array(
			'sent'    => __( 'Test alert sent to every channel.', 'drift-ticker' ),
			'partial' => __( 'Sent to some channels only. See Recent alerts and the fetch log.', 'drift-ticker' ),
			'failed'  => __( 'The test alert failed. See the fetch log.', 'drift-ticker' ),
		);
		$message  = $messages[ $status ] ?? __( 'The test alert could not be sent.', 'drift-ticker' );

		if ( 'sent' === $status ) {
			wp_send_json_success( array( 'message' => $message ) );
		}
		wp_send_json_error( array( 'message' => $message ), 502 );
	} catch ( Throwable $e ) {
		drift_ticker_log( '', $e->getMessage() );
		wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'drift-ticker' ) ), 500 );
	}
}

add_action( 'wp_ajax_drift_ticker_refresh', 'drift_ticker_ajax_refresh' );
/**
 * "Refresh now": runs the snapshot, skipping the response cache, and returns
 * the updated table and charts.
 *
 * @return void
 */
function drift_ticker_ajax_refresh() {
	try {
		check_ajax_referer( 'drift_ticker_refresh', 'nonce' );

		if ( ! current_user_can( drift_ticker_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'drift-ticker' ) ), 403 );
		}

		$results = drift_ticker_run_snapshot( true );
		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ), 409 );
		}

		$saved   = count( array_keys( $results, 'saved', true ) );
		$pending = count( array_keys( $results, 'pending', true ) );
		$failed  = count( $results ) - $saved - $pending;

		wp_send_json_success(
			array(
				/* translators: 1: number saved, 2: number pending, 3: number failed */
				'message' => sprintf( __( 'Updated %1$d, pending %2$d, failed %3$d.', 'drift-ticker' ), $saved, $pending, $failed ),
				'html'    => drift_ticker_tracked_table_html(),
				'charts'  => drift_ticker_charts_html(),
			)
		);
	} catch ( Throwable $e ) {
		drift_ticker_log( '', $e->getMessage() );
		wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'drift-ticker' ) ), 500 );
	}
}
