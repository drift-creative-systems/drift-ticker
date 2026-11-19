<?php
/**
 * The "WordPress.org slug" field on apps, registered in code.
 *
 * It's a small field group of its own in the edit screen sidebar, not a field
 * pushed into the theme's App details group: that group is loaded from the
 * theme's acf-json, and adding a local field to a JSON/database group isn't
 * reliable in ACF. The meta key is still drift_wporg_slug.
 *
 * @package Drift_Ticker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'drift_ticker_register_fields' );
/**
 * Registers the field group. Does nothing without ACF Pro; the slug can still
 * be set as the drift_wporg_slug post meta.
 *
 * @return void
 */
function drift_ticker_register_fields() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group(
		array(
			'key'                   => 'group_drift_ticker_wporg',
			'title'                 => __( 'WordPress.org', 'drift-ticker' ),
			'fields'                => array(
				array(
					'key'          => 'field_drift_app_wporg_slug',
					'label'        => __( 'WordPress.org slug', 'drift-ticker' ),
					'name'         => DRIFT_TICKER_SLUG_META,
					'type'         => 'text',
					'instructions' => __( 'The plugin\'s slug, e.g. drift-wipe for wordpress.org/plugins/drift-wipe/. Drift: Ticker tracks it daily and marks the app as available once WordPress.org approves it.', 'drift-ticker' ),
					'placeholder'  => 'drift-wipe',
					'maxlength'    => 200,
				),
			),
			'location'              => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'drift_app',
					),
				),
			),
			'position'              => 'side',
			'menu_order'            => 20,
			'active'                => true,
			'show_in_rest'          => 0,
			'description'           => __( 'Registered by the Drift: Ticker plugin.', 'drift-ticker' ),
			'hide_on_screen'        => '',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
		)
	);
}

add_filter( 'acf/validate_value/key=field_drift_app_wporg_slug', 'drift_ticker_validate_slug_field', 10, 2 );
/**
 * Rejects anything that isn't a WordPress.org slug (lowercase letters,
 * numbers and single hyphens).
 *
 * @param bool|string $valid Current validity.
 * @param mixed       $value Submitted value.
 * @return bool|string
 */
function drift_ticker_validate_slug_field( $valid, $value ) {
	if ( true !== $valid || '' === $value || null === $value ) {
		return $valid;
	}
	if ( ! drift_ticker_is_valid_slug( (string) $value ) ) {
		return __( 'Use the slug from the plugin\'s WordPress.org address: lowercase letters, numbers and hyphens.', 'drift-ticker' );
	}
	return $valid;
}

add_filter( 'acf/update_value/key=field_drift_app_wporg_slug', 'drift_ticker_clean_slug_field' );
/**
 * Saves the slug trimmed and lowercase.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function drift_ticker_clean_slug_field( $value ) {
	return strtolower( trim( (string) $value ) );
}
