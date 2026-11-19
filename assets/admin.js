/**
 * Drift: Ticker settings screen: "Refresh now", the period control and the charts.
 */
( function ( $ ) {
	'use strict';

	var settings = window.driftTicker || {};
	var i18n = settings.i18n || {};
	var $button = $( '#drift-ticker-refresh' );
	var $message = $( '#drift-ticker-message' );
	var $tracked = $( '#drift-ticker-tracked' );
	var $charts = $( '#drift-ticker-charts' );
	var $range = $( 'input[name="drift_ticker_range"]' );
	var STORE_KEY = 'driftTickerRange';

	function currentDays() {
		return parseInt( $range.filter( ':checked' ).val(), 10 ) || 30;
	}

	function drift_ticker_render_charts() {
		if ( ! window.driftTickerChart ) {
			return;
		}
		var days = currentDays();
		$charts.find( '.drift-ticker-chart' ).each( function () {
			window.driftTickerChart.render( $( this ), days, i18n );
		} );
	}

	// Remember the period per browser. Storage can be blocked; the default is 30 days.
	try {
		var saved = window.localStorage.getItem( STORE_KEY );
		if ( saved ) {
			$range.filter( '[value="' + parseInt( saved, 10 ) + '"]' ).prop( 'checked', true );
		}
	} catch ( e ) {}

	$range.on( 'change.drift_ticker', function () {
		try {
			window.localStorage.setItem( STORE_KEY, String( currentDays() ) );
		} catch ( e ) {}
		drift_ticker_render_charts();
	} );

	var resizeTimer;
	$( window ).on( 'resize.drift_ticker', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( drift_ticker_render_charts, 150 );
	} );

	drift_ticker_render_charts();

	// "Send a test alert".
	var $test = $( '#drift-ticker-test-alert' );
	var $testMessage = $( '#drift-ticker-test-message' );
	$test.on( 'click.drift_ticker', function () {
		$test.prop( 'disabled', true ).attr( 'aria-busy', 'true' );
		$testMessage.text( i18n.sending );
		$.post( settings.ajaxUrl, { action: 'drift_ticker_test_alert', nonce: settings.nonce } )
			.done( function ( response ) {
				$testMessage.text( ( response && response.data && response.data.message ) || i18n.failed );
			} )
			.fail( function ( xhr ) {
				var data = xhr.responseJSON && xhr.responseJSON.data;
				$testMessage.text( ( data && data.message ) || i18n.failed );
			} )
			.always( function () {
				$test.prop( 'disabled', false ).removeAttr( 'aria-busy' );
			} );
	} );

	if ( ! $button.length || ! settings.ajaxUrl ) {
		return;
	}

	$button.on( 'click.drift_ticker', function () {
		$button.prop( 'disabled', true ).attr( 'aria-busy', 'true' );
		$message.text( i18n.refreshing );

		$.post( settings.ajaxUrl, {
			action: 'drift_ticker_refresh',
			nonce: settings.nonce
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					$tracked.html( response.data.html );
					$charts.html( response.data.charts );
					drift_ticker_render_charts();
					$message.text( response.data.message );
				} else {
					$message.text( ( response && response.data && response.data.message ) || i18n.failed );
				}
			} )
			.fail( function ( xhr ) {
				var data = xhr.responseJSON && xhr.responseJSON.data;
				$message.text( ( data && data.message ) || i18n.failed );
			} )
			.always( function () {
				$button.prop( 'disabled', false ).removeAttr( 'aria-busy' );
			} );
	} );
}( jQuery ) );
