/**
 * Drift: Ticker downloads chart. A small dependency-free SVG bar chart.
 *
 * Input (data-series on the plot element): [ [ "Y-m-d", downloads|null, "new version"|"" ], … ],
 * 365 days, oldest first. 30 and 90 days draw one bar per day; 365 draws
 * weekly totals (52 bars, the last one ending today), since daily bars would
 * be under 2px wide.
 *
 * Specs (dataviz): bars ≤ 24px with a 2px gap and 4px rounded tops, square at
 * the baseline; hairline solid grid; clean tick values; text in text colours,
 * never the bar colour; per-bar tooltip on hover and keyboard (arrow keys),
 * mirrored to a live region; a table view. All text is set with textContent.
 */
( function ( $ ) {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var HEIGHT = 220;
	var MARGIN = { top: 22, right: 8, bottom: 26, left: 48 };
	var numberFormat = new Intl.NumberFormat( 'en-GB' );

	function svgEl( name, attrs ) {
		var node = document.createElementNS( SVG_NS, name );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		return node;
	}

	function htmlEl( name, className, text ) {
		var node = document.createElement( name );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d+)\$s|%s/g, function ( match, position ) {
			return String( position ? args[ position - 1 ] : args.shift() );
		} );
	}

	/** "2026-11-19" → "19/11/2026", or "19/11" when short. */
	function ukDate( iso, short ) {
		var parts = iso.split( '-' );
		return short ? parts[ 2 ] + '/' + parts[ 1 ] : parts[ 2 ] + '/' + parts[ 1 ] + '/' + parts[ 0 ];
	}

	/** Axis labels: full under 10,000, compact above (10K, 1.2M). */
	function tickLabel( value, top ) {
		if ( top < 10000 ) {
			return numberFormat.format( value );
		}
		if ( value >= 1e6 ) {
			return ( Math.round( value / 1e5 ) / 10 ) + 'M';
		}
		return ( Math.round( value / 100 ) / 10 ) + 'K';
	}

	/** A clean step (1, 2, 2.5, 5 × 10ⁿ) giving about four intervals. */
	function niceStep( max ) {
		var raw = max / 4;
		var power = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
		var steps = [ 1, 2, 2.5, 5, 10 ];
		for ( var i = 0; i < steps.length; i++ ) {
			if ( raw <= steps[ i ] * power ) {
				return Math.max( 1, steps[ i ] * power );
			}
		}
		return 10 * power;
	}

	/** Series → buckets for the range: one per day, or per week for 365. */
	function buckets( series, days ) {
		var slice = series.slice( -days );
		var size = days >= 365 ? 7 : 1;
		var result = [];
		var end = slice.length;

		while ( end - size >= 0 ) {
			var chunk = slice.slice( end - size, end );
			var value = null;
			var versions = [];
			chunk.forEach( function ( entry ) {
				if ( null !== entry[ 1 ] ) {
					value = ( value || 0 ) + entry[ 1 ];
				}
				if ( entry[ 2 ] ) {
					versions.push( entry[ 2 ] );
				}
			} );
			result.unshift( {
				start: chunk[ 0 ][ 0 ],
				end: chunk[ chunk.length - 1 ][ 0 ],
				value: value,
				versions: versions
			} );
			end -= size;
		}
		return { list: result, weekly: size > 1 };
	}

	function bucketLabel( bucket, weekly ) {
		return weekly ? ukDate( bucket.start, true ) + '–' + ukDate( bucket.end ) : ukDate( bucket.end );
	}

	/** Rounded-top bar path, square at the baseline. */
	function barPath( x, y, width, height ) {
		var r = Math.min( 4, width / 2, height );
		var base = y + height;
		return 'M' + x + ',' + base +
			'V' + ( y + r ) +
			'Q' + x + ',' + y + ' ' + ( x + r ) + ',' + y +
			'H' + ( x + width - r ) +
			'Q' + ( x + width ) + ',' + y + ' ' + ( x + width ) + ',' + ( y + r ) +
			'V' + base + 'Z';
	}

	/**
	 * Draws one chart.
	 *
	 * @param {jQuery} $section .drift-ticker-chart section.
	 * @param {number} days     30, 90 or 365.
	 * @param {Object} i18n     Strings from wp_localize_script.
	 */
	function render( $section, days, i18n ) {
		var plot = $section.find( '[data-chart]' )[ 0 ];
		if ( ! plot ) {
			return;
		}
		var series = $( plot ).data( 'series' ) || [];
		var name = String( $( plot ).data( 'name' ) || '' );
		var data = buckets( series, days );
		var list = data.list;

		plot.textContent = '';
		renderTotal( $section, series, days, i18n );
		renderTable( $section, data, i18n );

		var hasData = list.some( function ( b ) {
			return null !== b.value;
		} );
		if ( ! list.length || ! hasData ) {
			plot.appendChild( htmlEl( 'p', 'drift-ticker-muted', i18n.noDataRange ) );
			return;
		}

		var width = Math.max( 280, plot.clientWidth || 600 );
		var plotWidth = width - MARGIN.left - MARGIN.right;
		var plotHeight = HEIGHT - MARGIN.top - MARGIN.bottom;
		var baseline = MARGIN.top + plotHeight;

		var max = Math.max.apply( null, list.map( function ( b ) {
			return b.value || 0;
		} ) );
		var step = niceStep( Math.max( max, 4 ) );
		var top = Math.ceil( Math.max( max, 1 ) / step ) * step;
		var yScale = function ( v ) {
			return baseline - ( v / top ) * plotHeight;
		};

		var band = plotWidth / list.length;
		var barWidth = Math.max( 1, Math.min( 24, band - 2 ) );

		var svg = svgEl( 'svg', {
			width: width,
			height: HEIGHT,
			viewBox: '0 0 ' + width + ' ' + HEIGHT,
			'aria-hidden': 'true',
			focusable: 'false',
			'class': 'drift-ticker-svg'
		} );

		// Grid and y-axis labels.
		for ( var t = 0; t <= top; t += step ) {
			var y = Math.round( yScale( t ) ) + 0.5;
			svg.appendChild( svgEl( 'line', { x1: MARGIN.left, x2: width - MARGIN.right, y1: y, y2: y, 'class': 0 === t ? 'drift-ticker-svg__baseline' : 'drift-ticker-svg__grid' } ) );
			var label = svgEl( 'text', { x: MARGIN.left - 8, y: y + 4, 'text-anchor': 'end', 'class': 'drift-ticker-svg__tick' } );
			label.textContent = tickLabel( t, top );
			svg.appendChild( label );
		}

		// X-axis labels: first, middle and last bucket.
		[ 0, Math.floor( ( list.length - 1 ) / 2 ), list.length - 1 ].forEach( function ( index, n, all ) {
			if ( n > 0 && index === all[ n - 1 ] ) {
				return;
			}
			var anchor = 0 === n ? 'start' : ( 2 === n ? 'end' : 'middle' );
			var x = 0 === n ? MARGIN.left : ( 2 === n ? width - MARGIN.right : MARGIN.left + band * ( index + 0.5 ) );
			var text = svgEl( 'text', { x: x, y: HEIGHT - 6, 'text-anchor': anchor, 'class': 'drift-ticker-svg__tick' } );
			text.textContent = ukDate( list[ index ].end, true );
			svg.appendChild( text );
		} );

		// Version markers: dashed line, label at the top unless it would collide.
		var lastLabelX = -Infinity;
		list.forEach( function ( bucket, i ) {
			if ( ! bucket.versions.length ) {
				return;
			}
			var x = Math.round( MARGIN.left + band * ( i + 0.5 ) ) + 0.5;
			svg.appendChild( svgEl( 'line', { x1: x, x2: x, y1: MARGIN.top - 4, y2: baseline, 'class': 'drift-ticker-svg__version' } ) );
			if ( x - lastLabelX > 48 ) {
				var anchor = x < MARGIN.left + 24 ? 'start' : ( x > width - MARGIN.right - 24 ? 'end' : 'middle' );
				var text = svgEl( 'text', { x: x, y: MARGIN.top - 8, 'text-anchor': anchor, 'class': 'drift-ticker-svg__version-label' } );
				text.textContent = bucket.versions[ bucket.versions.length - 1 ];
				svg.appendChild( text );
				lastLabelX = x;
			}
		} );

		// Bars, each with a full-height hit area.
		var bars = [];
		list.forEach( function ( bucket, i ) {
			var x = MARGIN.left + band * i + ( band - barWidth ) / 2;
			if ( bucket.value ) {
				var y = yScale( bucket.value );
				var path = svgEl( 'path', { d: barPath( x, y, barWidth, baseline - y ), 'class': 'drift-ticker-svg__bar' } );
				svg.appendChild( path );
				bars[ i ] = path;
			}
			var hit = svgEl( 'rect', { x: MARGIN.left + band * i, y: MARGIN.top, width: band, height: plotHeight, 'class': 'drift-ticker-svg__hit', 'data-index': i } );
			svg.appendChild( hit );
		} );

		// Wrapper: focusable for the arrow keys, labelled as one image.
		var chartLabel = data.weekly ? sprintf( i18n.weekLabel, name ) : sprintf( i18n.chartLabel, name, days );
		var frame = htmlEl( 'div', 'drift-ticker-svg-frame' );
		frame.setAttribute( 'tabindex', '0' );
		frame.setAttribute( 'role', 'img' );
		frame.setAttribute( 'aria-label', chartLabel + '. ' + i18n.keyHint );
		frame.appendChild( svg );

		var tooltip = htmlEl( 'div', 'drift-ticker-tooltip' );
		tooltip.hidden = true;
		frame.appendChild( tooltip );

		var live = htmlEl( 'div', 'screen-reader-text' );
		live.setAttribute( 'aria-live', 'polite' );

		plot.appendChild( frame );
		plot.appendChild( live );

		var active = -1;

		function show( index ) {
			if ( index < 0 || index >= list.length ) {
				return;
			}
			if ( bars[ active ] ) {
				bars[ active ].classList.remove( 'is-active' );
			}
			active = index;
			if ( bars[ index ] ) {
				bars[ index ].classList.add( 'is-active' );
			}

			var bucket = list[ index ];
			var valueText = null === bucket.value ? i18n.noData : numberFormat.format( bucket.value ) + ' ' + i18n.downloads;
			tooltip.textContent = '';
			tooltip.appendChild( htmlEl( 'strong', '', valueText ) );
			tooltip.appendChild( htmlEl( 'span', '', bucketLabel( bucket, data.weekly ) ) );
			if ( bucket.versions.length ) {
				tooltip.appendChild( htmlEl( 'span', '', i18n.version + ' ' + bucket.versions.join( ', ' ) ) );
			}
			tooltip.hidden = false;

			// Keep the tooltip inside the frame.
			var centre = MARGIN.left + band * ( index + 0.5 );
			var tipWidth = tooltip.offsetWidth;
			var left = Math.min( Math.max( 0, centre - tipWidth / 2 ), width - tipWidth );
			tooltip.style.left = left + 'px';

			live.textContent = valueText + ', ' + bucketLabel( bucket, data.weekly ) + ( bucket.versions.length ? ', ' + i18n.version + ' ' + bucket.versions.join( ', ' ) : '' );
		}

		function hide() {
			if ( bars[ active ] ) {
				bars[ active ].classList.remove( 'is-active' );
			}
			tooltip.hidden = true;
		}

		$( svg ).on( 'pointermove.drift_ticker', '.drift-ticker-svg__hit', function () {
			show( parseInt( this.getAttribute( 'data-index' ), 10 ) );
		} );
		$( frame )
			.on( 'pointerleave.drift_ticker', function () {
				if ( document.activeElement !== frame ) {
					hide();
				}
			} )
			.on( 'focus.drift_ticker', function () {
				show( active >= 0 ? active : list.length - 1 );
			} )
			.on( 'blur.drift_ticker', hide )
			.on( 'keydown.drift_ticker', function ( event ) {
				var keys = { ArrowLeft: -1, ArrowRight: 1, Home: -Infinity, End: Infinity };
				if ( ! ( event.key in keys ) ) {
					return;
				}
				event.preventDefault();
				var move = keys[ event.key ];
				var next = isFinite( move ) ? active + move : ( move < 0 ? 0 : list.length - 1 );
				show( Math.min( list.length - 1, Math.max( 0, next ) ) );
			} );
	}

	/** "1,240 downloads in the last 30 days (since 15/11/2026)". */
	function renderTotal( $section, series, days, i18n ) {
		var slice = series.slice( -days );
		var total = null;
		var since = '';
		slice.forEach( function ( entry ) {
			if ( null !== entry[ 1 ] ) {
				total = ( total || 0 ) + entry[ 1 ];
				since = since || entry[ 0 ];
			}
		} );
		var text = null === total ? '' : sprintf( i18n.total, numberFormat.format( total ), days );
		if ( total !== null && slice.length && since !== slice[ 0 ][ 0 ] ) {
			text += ' (' + sprintf( i18n.since, ukDate( since ) ) + ')';
		}
		$section.find( '[data-chart-total]' ).text( text );
	}

	/** Table view, newest first. */
	function renderTable( $section, data, i18n ) {
		var holder = $section.find( '[data-chart-table]' )[ 0 ];
		if ( ! holder ) {
			return;
		}
		holder.textContent = '';
		var table = htmlEl( 'table', 'widefat striped drift-ticker-data-table' );
		var head = table.createTHead().insertRow();
		[ data.weekly ? i18n.week : i18n.date, i18n.colDownload, i18n.version ].forEach( function ( text, i ) {
			var th = htmlEl( 'th', 1 === i ? 'num' : '', text );
			th.setAttribute( 'scope', 'col' );
			head.appendChild( th );
		} );
		var body = table.createTBody();
		data.list.slice().reverse().forEach( function ( bucket ) {
			var row = body.insertRow();
			var th = htmlEl( 'th', '', bucketLabel( bucket, data.weekly ) );
			th.setAttribute( 'scope', 'row' );
			row.appendChild( th );
			row.appendChild( htmlEl( 'td', 'num', null === bucket.value ? '–' : numberFormat.format( bucket.value ) ) );
			row.appendChild( htmlEl( 'td', '', bucket.versions.join( ', ' ) ) );
		} );
		holder.appendChild( table );
	}

	window.driftTickerChart = { render: render, buckets: buckets, niceStep: niceStep };
}( jQuery ) );
