<?php
/**
 * Minimal WordPress stand-in for testing the snapshot code without a full
 * install: WordPress functions are stubbed, $wpdb runs real SQL against an
 * in-memory SQLite database, and HTTP is either mocked or real (PHP curl).
 *
 * Not loaded by the plugin. Run: php -d extension=pdo_sqlite tests/run.php
 *
 * @package Drift_Ticker
 */

// phpcs:ignoreFile -- Test stubs, not plugin code.

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['dt_options']    = array();
$GLOBALS['dt_transients'] = array();
$GLOBALS['dt_actions']    = array();
$GLOBALS['dt_fired']      = array();
$GLOBALS['dt_cache']      = array();
$GLOBALS['dt_now']        = null;  // Fake "now" (Unix timestamp), or null for real time.
$GLOBALS['dt_http']       = null;  // callable( $url ) → response array|WP_Error, or null for a real request.
$GLOBALS['dt_http_calls'] = 0;
$GLOBALS['dt_posts']      = array(); // post ID → [ 'title' => , 'meta' => [] ].

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function dt_now() { return null === $GLOBALS['dt_now'] ? time() : $GLOBALS['dt_now']; }

// Hooks.
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['dt_actions'][ $hook ][] = array( $cb, $args ); return true; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return add_action( $hook, $cb, $priority, $args ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
// Records the hook, then runs the plugin's own drift_ticker_* callbacks as WordPress would.
function do_action( $hook, ...$args ) {
	$GLOBALS['dt_fired'][] = array( $hook, $args );
	if ( 0 !== strpos( $hook, 'drift_ticker_' ) ) {
		return;
	}
	foreach ( $GLOBALS['dt_actions'][ $hook ] ?? array() as $callback ) {
		call_user_func_array( $callback[0], array_slice( $args, 0, $callback[1] ) );
	}
}
function register_activation_hook() {}
function register_deactivation_hook() {}

// i18n / escaping / sanitising.
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function sanitize_text_field( $str ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) ); }
function absint( $v ) { return abs( (int) $v ); }
function number_format_i18n( $n, $decimals = 0 ) { return number_format( $n, $decimals ); }
function _n( $single, $plural, $n, $domain = '' ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_attr__( $s, $domain = '' ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function shortcode_atts( $pairs, $atts, $tag = '' ) { return array_merge( $pairs, array_intersect_key( (array) $atts, $pairs ) ); }
function add_shortcode( $tag, $cb ) {}
function get_the_ID() { return $GLOBALS['dt_current_post'] ?? 0; }
function wp_list_pluck( $list, $field ) { return array_map( function ( $item ) use ( $field ) { return $item[ $field ] ?? null; }, (array) $list ); }
function wp_enqueue_style( $handle ) { $GLOBALS['dt_styles'][] = $handle; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/drift-ticker/'; }
function is_admin() { return false; }
function wp_installing() { return true; }
function post_type_exists( $type ) { return 'drift_app' === $type && ! empty( $GLOBALS['dt_posts'] ); }

// Options and transients.
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['dt_options'] ) ? $GLOBALS['dt_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['dt_options'][ $name ] = $value; return true; }
function add_option( $name, $value = '', $dep = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['dt_options'] ) ) { return false; }
	$GLOBALS['dt_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['dt_options'][ $name ] ); return true; }
function get_transient( $key ) { return $GLOBALS['dt_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['dt_transients'][ $key ] = $value; return true; }
function wp_cache_get( $key, $group = '' ) { return $GLOBALS['dt_cache'][ $group . $key ] ?? false; }
function wp_cache_set( $key, $value, $group = '' ) { $GLOBALS['dt_cache'][ $group . $key ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['dt_cache'][ $group . $key ] ); return true; }

// Posts (apps).
function get_posts( $args ) { return array_keys( array_filter( $GLOBALS['dt_posts'], function ( $p ) { return '' !== ( $p['meta']['drift_wporg_slug'] ?? '' ); } ) ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['dt_posts'][ $id ]['meta'][ $key ] ?? ''; }
function get_the_title( $id ) { return $GLOBALS['dt_posts'][ $id ]['title'] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['dt_posts'][ $id ]['meta'][ $key ] = $value; return true; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

// Time.
function wp_date( $format, $timestamp = null, $tz = null ) {
	$date = new DateTime( '@' . ( null === $timestamp ? dt_now() : $timestamp ) );
	$date->setTimezone( $tz ? $tz : new DateTimeZone( 'Europe/London' ) );
	return $date->format( $format );
}
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s', dt_now() ); }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event() { return true; }
function wp_schedule_single_event() { return true; }
function wp_clear_scheduled_hook() { return 0; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function esc_url_raw( $url, $protocols = null ) {
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	return ( $protocols && ! in_array( $scheme, $protocols, true ) ) ? '' : filter_var( $url, FILTER_SANITIZE_URL );
}
function sanitize_email( $e ) { return trim( (string) $e ); }
function is_email( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ); }

// Outgoing alerts: recorded, answered by $GLOBALS['dt_post'] ( $url, $body ) → response|WP_Error (default 200).
$GLOBALS['dt_sent'] = array();
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['dt_sent'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
	if ( isset( $GLOBALS['dt_post'] ) && is_callable( $GLOBALS['dt_post'] ) ) {
		return call_user_func( $GLOBALS['dt_post'], $url, $args['body'] );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => 'Accepted' );
}
function wp_mail( $to, $subject, $message ) {
	$GLOBALS['dt_mail'][] = compact( 'to', 'subject', 'message' );
	return $GLOBALS['dt_mail_ok'] ?? true;
}

// HTTP.
function wp_remote_get( $url, $args = array() ) {
	// Info API calls and history calls are counted apart.
	if ( false !== strpos( $url, '/stats/plugin/' ) ) {
		$GLOBALS['dt_history_calls'] = ( $GLOBALS['dt_history_calls'] ?? 0 ) + 1;
	} else {
		$GLOBALS['dt_http_calls']++;
	}
	if ( is_callable( $GLOBALS['dt_http'] ) ) {
		return call_user_func( $GLOBALS['dt_http'], $url );
	}
	$ch = curl_init( $url );
	// HTTP/1.1, as WordPress's own HTTP client (Requests) uses.
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $args['timeout'] ?? 15, CURLOPT_USERAGENT => 'Drift-Ticker-Test', CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1 ) );
	// WordPress ships its own CA bundle; a bare PHP install may not have one. Set DT_CA_BUNDLE to a .crt file if needed.
	if ( getenv( 'DT_CA_BUNDLE' ) ) {
		curl_setopt( $ch, CURLOPT_CAINFO, getenv( 'DT_CA_BUNDLE' ) );
	}
	$body = curl_exec( $ch );
	if ( false === $body ) {
		$error = curl_error( $ch );
		curl_close( $ch );
		return new WP_Error( 'http_request_failed', $error );
	}
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

/**
 * $wpdb over SQLite. Only what the snapshot code uses.
 */
class DT_Wpdb {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $pdo;
	public $fail_writes = false;
	public $insert_id   = 0;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		// SQLite version of the dbDelta schema in includes/install.php.
		$this->pdo->exec(
			'CREATE TABLE wp_drift_ticker_snapshots (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				slug TEXT NOT NULL,
				snap_date TEXT NOT NULL,
				downloaded INTEGER NOT NULL DEFAULT 0,
				active_installs INTEGER NOT NULL DEFAULT 0,
				rating INTEGER NOT NULL DEFAULT 0,
				num_ratings INTEGER NOT NULL DEFAULT 0,
				ratings_json TEXT NOT NULL,
				support_threads INTEGER NOT NULL DEFAULT 0,
				support_threads_resolved INTEGER NOT NULL DEFAULT 0,
				version TEXT NOT NULL DEFAULT "",
				last_updated TEXT DEFAULT NULL,
				created_at TEXT NOT NULL,
				UNIQUE (slug, snap_date)
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE wp_drift_ticker_backfill (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				slug TEXT NOT NULL,
				stat_date TEXT NOT NULL,
				downloads INTEGER NOT NULL DEFAULT 0,
				created_at TEXT NOT NULL,
				UNIQUE (slug, stat_date)
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE wp_drift_ticker_events (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				event_key TEXT NOT NULL UNIQUE,
				event TEXT NOT NULL,
				slug TEXT NOT NULL DEFAULT "",
				payload TEXT NOT NULL,
				status TEXT NOT NULL DEFAULT "pending",
				channels TEXT NOT NULL DEFAULT "",
				attempts INTEGER NOT NULL DEFAULT 0,
				last_error TEXT NOT NULL,
				created_at TEXT NOT NULL,
				sent_at TEXT DEFAULT NULL
			)'
		);
	}
	public function prepare( $query, ...$args ) {
		$pdo  = $this->pdo;
		$args = array_map( function ( $a ) use ( $pdo ) { return is_int( $a ) ? $a : $pdo->quote( (string) $a ); }, $args );
		return vsprintf( str_replace( array( '%s', '%d' ), array( '%s', '%s' ), $query ), $args );
	}
	public function query( $sql ) {
		if ( $this->fail_writes ) { return false; }
		return $this->pdo->exec( $sql );
	}
	public function get_var( $sql ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
	public function get_row( $sql, $output = null ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_ASSOC ); return $r ? $r : null; }
	public function get_results( $sql, $output = null ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
	public function insert( $table, $data, $formats = null ) {
		if ( $this->fail_writes ) { return false; }
		$cols = array_keys( $data );
		$st   = $this->pdo->prepare( "INSERT INTO {$table} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')' );
		$st->execute( array_values( $data ) );
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}
	public function update( $table, $data, $where, $formats = null, $wf = null ) {
		if ( $this->fail_writes ) { return false; }
		$set = implode( ',', array_map( function ( $c ) { return "{$c} = ?"; }, array_keys( $data ) ) );
		$st  = $this->pdo->prepare( "UPDATE {$table} SET {$set} WHERE id = ?" );
		$st->execute( array_merge( array_values( $data ), array( $where['id'] ) ) );
		return $st->rowCount();
	}
}

$GLOBALS['wpdb'] = new DT_Wpdb();

/**
 * Resets all state between tests.
 */
function dt_reset() {
	$GLOBALS['dt_options']    = array();
	$GLOBALS['dt_transients'] = array();
	$GLOBALS['dt_fired']      = array();
	$GLOBALS['dt_cache']      = array();
	$GLOBALS['dt_now']        = null;
	$GLOBALS['dt_http']       = null;
	$GLOBALS['dt_http_calls'] = 0;
	$GLOBALS['dt_posts']      = array();
	$GLOBALS['wpdb']          = new DT_Wpdb();

	$GLOBALS['dt_history_calls'] = 0;
	$GLOBALS['dt_sent']          = array();
	$GLOBALS['dt_mail']          = array();
	$GLOBALS['dt_post']          = null;
	$GLOBALS['dt_mail_ok']       = true;

	$GLOBALS['drift_ticker_display_changed'] = false;
}

/**
 * Hooks fired with a given name.
 */
function dt_fired( $hook ) {
	return array_values( array_filter( $GLOBALS['dt_fired'], function ( $f ) use ( $hook ) { return $f[0] === $hook; } ) );
}

// Load the plugin (admin files aren't loaded: is_admin() is false), then the
// CSV builders, which don't need the admin screen.
require dirname( __DIR__ ) . '/drift-ticker.php';
require dirname( __DIR__ ) . '/includes/export.php';
