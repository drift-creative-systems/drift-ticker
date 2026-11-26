<?php
/**
 * Dashboard widget: tracked plugins at a glance.
 *
 * Reads the database only (no external requests). Registered after Drift:
 * Welcome's widget sweep so it survives when Welcome replaces the dashboard.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Widget ID.
 */
const DRIFT_TICKER_DASHBOARD_WIDGET = 'drift_ticker_dashboard';

/*
 * Priority 1000: Drift: Welcome removes every dashboard widget but its own at
 * 999 (Drift_Welcome_Dashboard::strip_other_widgets()). Registering after that
 * keeps this one. Without Welcome the priority makes no difference.
 */
add_action( 'wp_dashboard_setup', 'drift_ticker_add_dashboard_widget', 1000 );
/**
 * Registers the widget for users who can see the Ticker screen.
 *
 * @return void
 */
function drift_ticker_add_dashboard_widget() {
	if ( ! current_user_can( drift_ticker_capability() ) ) {
		return;
	}

	wp_add_dashboard_widget(
		DRIFT_TICKER_DASHBOARD_WIDGET,
		__( 'Drift: Ticker', 'drift-ticker' ),
		'drift_ticker_render_dashboard_widget'
	);
}

/**
 * Prints the widget.
 *
 * @return void
 */
function drift_ticker_render_dashboard_widget() {
	echo drift_ticker_dashboard_widget_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built.
}

/**
 * Widget markup: one row per tracked plugin, then the last run and a link to
 * the Ticker screen.
 *
 * @return string Escaped HTML.
 */
function drift_ticker_dashboard_widget_html() {
	$tracked  = drift_ticker_tracked();
	$labels   = drift_ticker_status_labels();
	$last_run = (int) get_option( 'drift_ticker_last_run', 0 );
	$page_url = admin_url( 'options-general.php?page=' . DRIFT_TICKER_PAGE_SLUG );

	ob_start();
	?>
	<div class="drift-ticker-widget">
		<?php if ( ! $tracked ) : ?>
			<p><?php esc_html_e( 'Nothing is tracked yet. Add a WordPress.org slug to an app, or add extra slugs on the Ticker screen.', 'drift-ticker' ); ?></p>
		<?php else : ?>
			<div class="drift-ticker-widget__scroll">
				<table class="widefat striped drift-ticker-table drift-ticker-widget__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Plugin', 'drift-ticker' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Downloads', 'drift-ticker' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( '7 days', 'drift-ticker' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Installs', 'drift-ticker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Rating', 'drift-ticker' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Threads', 'drift-ticker' ); ?></th>
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
									<span class="drift-ticker-status drift-ticker-status--<?php echo esc_attr( $state['status'] ); ?>"><?php echo esc_html( $labels[ $state['status'] ] ?? $state['status'] ); ?></span>
									<?php if ( $state['last_error'] ) : ?>
										<br><span class="drift-ticker-error"><?php esc_html_e( 'Last check failed', 'drift-ticker' ); ?></span>
									<?php endif; ?>
								</th>
								<?php if ( $summary ) : ?>
									<td class="num"><?php echo esc_html( number_format_i18n( $summary['downloaded'] ) ); ?></td>
									<td class="num"><?php echo drift_ticker_period_cell( $summary['last_7'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?></td>
									<td class="num"><?php echo esc_html( drift_ticker_installs_label( $summary['active_installs'] ) ); ?></td>
									<td>
										<?php if ( $summary['num_ratings'] ) : ?>
											<span aria-hidden="true">&#9733;</span>
											<?php
											/* translators: 1: rating out of 5, 2: number of ratings */
											echo esc_html( sprintf( __( '%1$s (%2$s)', 'drift-ticker' ), drift_ticker_stars( $summary['rating'] ), number_format_i18n( $summary['num_ratings'] ) ) );
											?>
											<span class="screen-reader-text"><?php esc_html_e( 'out of 5, number of ratings in brackets', 'drift-ticker' ); ?></span>
										<?php else : ?>
											<span class="drift-ticker-muted"><?php esc_html_e( 'None', 'drift-ticker' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="num"><?php echo esc_html( number_format_i18n( $summary['open_threads'] ) ); ?><span class="screen-reader-text"> <?php esc_html_e( 'open', 'drift-ticker' ); ?></span></td>
								<?php else : ?>
									<td colspan="5" class="drift-ticker-muted">
										<?php
										echo 'pending' === $state['status']
											? esc_html__( 'Not on WordPress.org yet.', 'drift-ticker' )
											: esc_html__( 'No snapshot yet.', 'drift-ticker' );
										?>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<p class="drift-ticker-widget__foot">
			<span class="drift-ticker-muted">
				<?php
				echo $last_run
					/* translators: %s: date and time */
					? esc_html( sprintf( __( 'Last run: %s', 'drift-ticker' ), drift_ticker_format_date( $last_run, true ) ) )
					: esc_html__( 'Not run yet', 'drift-ticker' );
				?>
			</span>
			<a href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Open Drift: Ticker', 'drift-ticker' ); ?></a>
		</p>
	</div>
	<?php
	return (string) ob_get_clean();
}
