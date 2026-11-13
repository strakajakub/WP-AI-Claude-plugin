<?php
/**
 * Minimal WordPress stubs so the plugin's logic can be tested without a WordPress install.
 * Only what the tested code paths call is implemented.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WPAIE_OPTION_KEY', 'wpaie_settings' );
define( 'WPAIE_HISTORY_KEY', 'wpaie_history' );

$GLOBALS['wp_test'] = [
	'options'    => [],
	'user_meta'  => [],
	'transients' => [],
	'http'       => [],   // queued responses for wp_remote_request
	'requests'   => [],   // recorded requests
	'filters'    => [],
	'custom_css' => '',
];

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = 'default' ) { return $text; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url_raw( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function sanitize_title( $str ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $str ) ); }
function wp_slash( $value ) { return is_string( $value ) ? addslashes( $value ) : $value; }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, (array) $args ); }
function apply_filters( $hook, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/wp-ai-editor/'; }
function plugin_basename( $file ) { return 'wp-ai-editor/wp-ai-editor.php'; }
function get_current_user_id() { return 1; }
function add_settings_error( $setting, $code, $message ) { $GLOBALS['wp_test']['settings_errors'][] = $code; }

function get_option( $name, $default = false ) { return $GLOBALS['wp_test']['options'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['wp_test']['options'][ $name ] = $value; return true; }

function get_user_meta( $user, $key, $single = false ) { return $GLOBALS['wp_test']['user_meta'][ $user ][ $key ] ?? ''; }
function update_user_meta( $user, $key, $value ) { $GLOBALS['wp_test']['user_meta'][ $user ][ $key ] = wp_unslash( $value ); return true; }
function delete_user_meta( $user, $key ) { unset( $GLOBALS['wp_test']['user_meta'][ $user ][ $key ] ); return true; }

function get_transient( $key ) { return $GLOBALS['wp_test']['transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['wp_test']['transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['wp_test']['transients'][ $key ] ); return true; }

function wp_get_custom_css() { return $GLOBALS['wp_test']['custom_css']; }
function wp_update_custom_css_post( $css ) { $GLOBALS['wp_test']['custom_css'] = $css; return true; }

/** Queue a fake HTTP response: [status, body array|string, headers]. */
function wpaie_test_queue_response( int $status, $body, array $headers = [] ): void {
	$GLOBALS['wp_test']['http'][] = [
		'response' => [ 'code' => $status ],
		'body'     => is_string( $body ) ? $body : json_encode( $body ),
		'headers'  => $headers,
	];
}
function wp_remote_request( $url, $args ) {
	$GLOBALS['wp_test']['requests'][] = [ 'url' => $url, 'args' => $args ];
	$next = array_shift( $GLOBALS['wp_test']['http'] );
	if ( $next === null ) {
		return new WP_Error( 'http_request_failed', 'No queued response' );
	}
	return $next;
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $name ) { return $r['headers'][ $name ] ?? ''; }

require dirname( __DIR__ ) . '/wp-ai-editor/includes/helpers.php';
require dirname( __DIR__ ) . '/wp-ai-editor/includes/settings.php';
require dirname( __DIR__ ) . '/wp-ai-editor/includes/api.php';
require dirname( __DIR__ ) . '/wp-ai-editor/includes/tools.php';
require dirname( __DIR__ ) . '/wp-ai-editor/includes/conversation.php';
require dirname( __DIR__ ) . '/wp-ai-editor/includes/agent.php';

function wpaie_test_reset(): void {
	$GLOBALS['wp_test']['options']    = [];
	$GLOBALS['wp_test']['user_meta']  = [];
	$GLOBALS['wp_test']['transients'] = [];
	$GLOBALS['wp_test']['http']       = [];
	$GLOBALS['wp_test']['requests']   = [];
	$GLOBALS['wp_test']['custom_css'] = '';
	$GLOBALS['wp_test']['settings_errors'] = [];
}
