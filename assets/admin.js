/**
 * Drift: Ticker settings screen: the "Refresh now" button.
 */
( function ( $ ) {
	'use strict';

	var $button = $( '#drift-ticker-refresh' );
	var $message = $( '#drift-ticker-message' );
	var $tracked = $( '#drift-ticker-tracked' );
	var settings = window.driftTicker || {};

	if ( ! $button.length || ! settings.ajaxUrl ) {
		return;
	}

	$button.on( 'click.drift_ticker', function () {
		$button.prop( 'disabled', true ).attr( 'aria-busy', 'true' );
		$message.text( settings.i18n.refreshing );

		$.post( settings.ajaxUrl, {
			action: 'drift_ticker_refresh',
			nonce: settings.nonce
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					$tracked.html( response.data.html );
					$message.text( response.data.message );
				} else {
					$message.text( ( response && response.data && response.data.message ) || settings.i18n.failed );
				}
			} )
			.fail( function ( xhr ) {
				var data = xhr.responseJSON && xhr.responseJSON.data;
				$message.text( ( data && data.message ) || settings.i18n.failed );
			} )
			.always( function () {
				$button.prop( 'disabled', false ).removeAttr( 'aria-busy' );
			} );
	} );
}( jQuery ) );
