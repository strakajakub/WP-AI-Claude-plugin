<?php
/**
 * Small, dependency-free helpers shared by the rest of the plugin.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'array_is_list' ) ) {
	// PHP 8.0 polyfill (native since 8.1).
	function array_is_list( array $array ): bool {
		return $array === [] || array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}

/**
 * Read a property from a value that may be an object (decoded API JSON) or an array.
 */
function wpaie_prop( $value, string $key, $default = null ) {
	if ( is_object( $value ) ) {
		return $value->$key ?? $default;
	}
	if ( is_array( $value ) ) {
		return $value[ $key ] ?? $default;
	}
	return $default;
}

/**
 * Convert nested arrays to the stdClass representation used for conversation storage.
 *
 * The conversation is kept as decoded-to-objects JSON so that empty objects (e.g. a tool_use
 * with input {}) survive the round-trip and are sent back to the API byte-for-byte as received.
 */
function wpaie_to_object( $value ) {
	return json_decode( wp_json_encode( $value ) );
}

/**
 * Convert an object tree (decoded JSON) to associative arrays, e.g. for tool inputs.
 */
function wpaie_to_array( $value ): array {
	$out = json_decode( (string) wp_json_encode( $value ), true );
	return is_array( $out ) ? $out : [];
}

/**
 * Write a line to the PHP error log when WP_DEBUG is on. Never pass secrets in here.
 */
function wpaie_log( string $message, array $context = [] ): void {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}
	if ( $context ) {
		$message .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( '[WP AI Editor] ' . $message );
}

/**
 * Seconds elapsed since the current PHP request started.
 */
function wpaie_request_elapsed(): float {
	$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
	return microtime( true ) - $start;
}

/**
 * Render the small subset of Markdown the assistant uses (**bold**, `code`, line breaks) as safe HTML.
 */
function wpaie_format_ai_html( string $text ): string {
	$html = esc_html( $text );
	$html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );
	$html = preg_replace( '/`([^`\n]+)`/', '<code>$1</code>', $html );
	return nl2br( $html, false );
}
