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
 * Styles and script, on the settings screen only.
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
function drift_ticker_admin_assets( $hook_suffix ) {
	if ( 'settings_page_' . DRIFT_TICKER_PAGE_SLUG !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style( 'drift-ticker-admin', DRIFT_TICKER_URL . 'assets/admin.css', array(), DRIFT_TICKER_VERSION );
	wp_enqueue_script( 'drift-ticker-admin', DRIFT_TICKER_URL . 'assets/admin.js', array( 'jquery' ), DRIFT_TICKER_VERSION, true );
	wp_localize_script(
		'drift-ticker-admin',
		'driftTicker',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'drift_ticker_refresh' ),
			'i18n'    => array(
				'refreshing' => __( 'Refreshing…', 'drift-ticker' ),
				'failed'     => __( 'The refresh failed. Please try again.', 'drift-ticker' ),
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
 * Tracked plugins table. Returned as HTML so "Refresh now" can swap it in.
 * Step 3 adds the 7/30-day columns and charts.
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
				<th scope="col" class="num"><?php esc_html_e( 'Active installs', 'drift-ticker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Version', 'drift-ticker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last snapshot', 'drift-ticker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $tracked as $slug => $app_id ) :
				$state  = drift_ticker_get_state( $slug );
				$latest = drift_ticker_get_latest( $slug );
				$name   = $app_id ? get_the_title( $app_id ) : $state['name'];
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $name ? $name : $slug ); ?></strong><br>
						<a href="<?php echo esc_url( drift_ticker_wporg_url( $slug ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $slug ); ?></code><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'drift-ticker' ); ?></span></a>
					</td>
					<td>
						<span class="drift-ticker-status drift-ticker-status--<?php echo esc_attr( $state['status'] ); ?>"><?php echo esc_html( $labels[ $state['status'] ] ?? $state['status'] ); ?></span>
						<?php if ( $state['last_error'] ) : ?>
							<br><span class="drift-ticker-error"><?php echo esc_html( $state['last_error'] ); ?></span>
						<?php endif; ?>
					</td>
					<td class="num"><?php echo $latest ? esc_html( number_format_i18n( (int) $latest['downloaded'] ) ) : '&ndash;'; ?></td>
					<td class="num"><?php echo $latest ? esc_html( number_format_i18n( (int) $latest['active_installs'] ) . '+' ) : '&ndash;'; ?></td>
					<td><?php echo $latest ? esc_html( $latest['version'] ) : '&ndash;'; ?></td>
					<td><?php echo $latest ? esc_html( drift_ticker_format_date( $latest['snap_date'] ) ) : '&ndash;'; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return (string) ob_get_clean();
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
	$last_run = (int) get_option( 'drift_ticker_last_run', 0 );
	$next_run = wp_next_scheduled( DRIFT_TICKER_CRON_DAILY );
	$log      = get_option( 'drift_ticker_log', array() );
	?>
	<div class="wrap drift-ticker">
		<h1 class="drift-ticker__title">
			<span class="drift-ticker__mark"><?php echo drift_ticker_mark_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup, attributes escaped in drift_ticker_mark_svg(). ?></span>
			<?php echo esc_html( get_admin_page_title() ); ?>
		</h1>

		<div class="drift-ticker__card">
			<div class="drift-ticker__card-head">
				<h2><?php esc_html_e( 'Tracked plugins', 'drift-ticker' ); ?></h2>
				<button type="button" class="button button-primary" id="drift-ticker-refresh"><?php esc_html_e( 'Refresh now', 'drift-ticker' ); ?></button>
			</div>
			<p class="drift-ticker__intro">
				<?php esc_html_e( 'WordPress.org shows active installs in bands (10+, 100+, 1,000+…), and its download count includes updates, not just new installs.', 'drift-ticker' ); ?>
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
			<div id="drift-ticker-tracked">
				<?php echo drift_ticker_tracked_table_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built. ?>
			</div>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( DRIFT_TICKER_OPTION_GROUP ); ?>
			<div class="drift-ticker__card">
				<?php do_settings_sections( DRIFT_TICKER_PAGE_SLUG ); ?>
			</div>
			<?php submit_button(); ?>
		</form>

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

add_action( 'wp_ajax_drift_ticker_refresh', 'drift_ticker_ajax_refresh' );
/**
 * "Refresh now": runs the snapshot, skipping the response cache, and returns
 * the updated table.
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
			)
		);
	} catch ( Throwable $e ) {
		drift_ticker_log( '', $e->getMessage() );
		wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'drift-ticker' ) ), 500 );
	}
}
