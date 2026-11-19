/**
 * Checks the chart's data logic (bucketing and tick steps) without a browser.
 * Run: node tests/chart.test.js
 */
'use strict';

const assert = require( 'assert' );
const path = require( 'path' );

global.window = {};
global.jQuery = function () {};
require( path.join( __dirname, '..', 'assets', 'chart.js' ) );
const chart = global.window.driftTickerChart;

// 365 days ending 19/11/2026, 10 downloads a day, a version change on the last day.
const series = [];
const end = new Date( Date.UTC( 2026, 10, 19 ) );
for ( let i = 364; i >= 0; i-- ) {
	const d = new Date( end.getTime() - i * 86400000 );
	series.push( [ d.toISOString().slice( 0, 10 ), i > 300 ? null : 10, 0 === i ? '1.2.0' : '' ] );
}

let passed = 0;
function check( label, fn ) {
	fn();
	passed++;
	console.log( '  ok    ' + label );
}

check( '30 days → 30 daily buckets ending today', () => {
	const b = chart.buckets( series, 30 );
	assert.strictEqual( b.weekly, false );
	assert.strictEqual( b.list.length, 30 );
	assert.strictEqual( b.list[ 29 ].end, '2026-11-19' );
} );

check( '365 days → 52 weekly buckets, last ends today, oldest odd day dropped', () => {
	const b = chart.buckets( series, 365 );
	assert.strictEqual( b.weekly, true );
	assert.strictEqual( b.list.length, 52 );
	assert.strictEqual( b.list[ 51 ].end, '2026-11-19' );
	assert.strictEqual( b.list[ 51 ].start, '2026-11-13' );
	assert.strictEqual( b.list[ 51 ].value, 70 );
	assert.deepStrictEqual( b.list[ 51 ].versions, [ '1.2.0' ] );
} );

check( 'weeks with no data are null, not 0', () => {
	const b = chart.buckets( series, 365 );
	assert.strictEqual( b.list[ 0 ].value, null );
} );

check( 'tick steps are clean numbers', () => {
	assert.strictEqual( chart.niceStep( 4 ), 1 );
	assert.strictEqual( chart.niceStep( 37 ), 10 );
	assert.strictEqual( chart.niceStep( 1240 ), 500 );
	assert.strictEqual( chart.niceStep( 9000 ), 2500 );
} );

console.log( '\n' + passed + ' passed' );
