<?php
/**
 * Minimal WordPress stubs so the plugin's logic can be tested without a WordPress install.
 * Only what the tested code paths call is implemented.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WPAIE_FILE', dirname( __DIR__ ) . '/wp-ai-editor/wp-ai-editor.php' );
define( 'WPAIE_OPTION_KEY', 'wpaie_settings' );
define( 'WPAIE_CAPABILITY', 'manage_options' );
define( 'WPAIE_TEST_API_PORT', 8765 );
define( 'WPAIE_API_BASE', 'http://127.0.0.1:' . WPAIE_TEST_API_PORT . '/v1' );

$GLOBALS['wp_test'] = [];

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

/** In-memory stand-in for $wpdb, enough for the change log. */
class WPAIE_Test_DB {
	public $prefix     = 'wp_';
	public $insert_id  = 0;
	public $last_error = '';
	public $rows       = [];
	public function get_charset_collate() { return ''; }
	public function insert( $table, $data ) {
		$this->insert_id                = count( $this->rows ) + 1;
		$this->rows[ $this->insert_id ] = (object) ( [ 'id' => $this->insert_id, 'reverted_at' => null, 'reverted_by' => null ] + $data );
		return 1;
	}
	public function update( $table, $data, $where ) {
		foreach ( $data as $k => $v ) {
			$this->rows[ $where['id'] ]->$k = $v;
		}
		return 1;
	}
	public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
	public function get_row( $query ) { return $this->rows[ (int) $query[1][0] ] ?? null; }
	public function query( $query ) { return 0; }
	public function get_var( $query ) { return count( $this->rows ); }
	public function get_results( $query ) { return array_reverse( array_values( $this->rows ) ); }
	public $posts = 'wp_posts';
	public function esc_like( $text ) { return $text; }
	/** Search stub: returns IDs of test posts whose title or content contains the LIKE needle. */
	public function get_col( $query ) {
		$needle = trim( (string) $query[1][0], '%' );
		$ids    = [];
		foreach ( $GLOBALS['wp_test']['posts'] as $p ) {
			if ( stripos( $p->post_content, $needle ) !== false || stripos( $p->post_title, $needle ) !== false ) {
				$ids[] = $p->ID;
			}
		}
		return $ids;
	}
}
$GLOBALS['wpdb'] = new WPAIE_Test_DB();

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return (string) $url; }
function esc_url_raw( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function sanitize_title( $str ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $str ) ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_text_diff( $a, $b, $args = [] ) { return $a === $b ? '' : '<table class="diff"><tr><td>' . htmlspecialchars( $a ) . '</td><td>' . htmlspecialchars( $b ) . '</td></tr></table>'; }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, (array) $args ); }
function apply_filters( $hook, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/wp-ai-editor/'; }
function plugin_basename( $file ) { return 'wp-ai-editor/wp-ai-editor.php'; }
function get_current_user_id() { return 1; }
function current_user_can( $cap, ...$args ) { return ! in_array( $cap, $GLOBALS['wp_test']['denied_caps'] ?? [], true ); }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_rand( $min = 0, $max = 0 ) { return $max; }
class WP_Post {
	public $ID = 0, $post_type = 'post', $post_status = 'draft', $post_title = '', $post_content = '', $post_excerpt = '', $post_modified = '', $post_parent = 0;
	public function __construct( array $data = [] ) { foreach ( $data as $k => $v ) { $this->$k = $v; } }
}
function get_post( $id ) { $p = $GLOBALS['wp_test']['posts'][ (int) ( is_object( $id ) ? $id->ID : $id ) ] ?? null; return $p ? clone $p : null; }
function wpaie_test_add_post( array $data ): WP_Post { $p = new WP_Post( $data ); $GLOBALS['wp_test']['posts'][ $p->ID ] = $p; return $p; }
function wp_update_post( $args, $wp_error = false ) {
	$args = wp_unslash_deep( $args );
	$post = $GLOBALS['wp_test']['posts'][ (int) $args['ID'] ] ?? null;
	if ( ! $post ) { return new WP_Error( 'invalid_post', 'Invalid post ID.' ); }
	foreach ( $args as $k => $v ) { if ( $k !== 'ID' ) { $post->$k = $v; } }
	return $post->ID;
}
function wp_unslash_deep( $v ) { return is_array( $v ) ? array_map( 'wp_unslash_deep', $v ) : wp_unslash( $v ); }
function get_edit_post_link( $id, $context = '' ) { return ''; }
function get_preview_post_link( $id ) { return ''; }
function get_the_title( $id ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['wp_test']['meta'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['wp_test']['meta'][ (int) $id ][ $key ] = wp_unslash( $value ); return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['wp_test']['meta'][ (int) $id ][ $key ] ); return true; }
function absint( $v ) { return abs( (int) $v ); }
function _n( $single, $plural, $n, $domain = 'default' ) { return $n === 1 ? $single : $plural; }
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function add_settings_error( $setting, $code, $message ) { $GLOBALS['wp_test']['settings_errors'][] = $code; }

function get_option( $name, $default = false ) { return $GLOBALS['wp_test']['options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['wp_test']['options'][ $name ] = $value; return true; }

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

$wpaie_dir = dirname( __DIR__ ) . '/wp-ai-editor/includes/';
foreach ( [ 'helpers', 'settings', 'api', 'tools/registry', 'tools/content', 'tools/design', 'changes', 'conversation', 'agent' ] as $wpaie_file ) {
	require $wpaie_dir . $wpaie_file . '.php';
}
foreach ( [ 'pricing', 'stream', 'images', 'tools/taxonomy-search', 'tools/media', 'tools/seo', 'tools/woocommerce' ] as $wpaie_file ) {
	if ( file_exists( $wpaie_dir . $wpaie_file . '.php' ) ) {
		require $wpaie_dir . $wpaie_file . '.php';
	}
}

function wpaie_test_reset(): void {
	$GLOBALS['wp_test'] = [
		'options'         => [],
		'user_meta'       => [],
		'transients'      => [],
		'http'            => [],
		'requests'        => [],
		'custom_css'      => '',
		'settings_errors' => [],
		'denied_caps'     => [],
		'posts'           => [],
		'meta'            => [],
	];
	$GLOBALS['wpdb']->rows      = [];
	$GLOBALS['wpdb']->insert_id = 0;
}
wpaie_test_reset();
