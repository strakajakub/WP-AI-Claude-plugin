<?php
/**
 * Tool registry.
 *
 * Every tool is described by one array:
 *   name, description, input_schema  – sent to the model (plain English, not translated)
 *   cap        – capability the current user needs (checked when listing and when running)
 *   available  – optional callable: is the tool usable on this site (e.g. WooCommerce active)
 *   write      – whether the tool changes anything
 *   live       – bool|callable(array $input): bool – does the change show on the public site immediately
 *   label      – callable(array $input): string – translatable label shown in the chat
 *   preview    – optional callable(array $input): string – HTML shown in the approval card
 *   run        – callable(array $input): string|array|WP_Error – array = tool_result content blocks
 *
 * Tool results and errors are read by the model only, so they are plain English.
 */

defined( 'ABSPATH' ) || exit;

/** Longest text tool result sent to the model (characters). */
define( 'WPAIE_TOOL_RESULT_MAX_CHARS', 150000 );

/**
 * Collects state shared with the tool implementations while one agent step runs.
 */
final class WPAIE_Run_Context {
	/** @var string Anthropic API key (for the Files API). */
	public static $api_key = '';
	/** @var array<int|string,string> attachment ID => Files API file ID, for the current conversation. */
	public static $files = [];
	/** @var array<int,array{id:int,label:string}> changes recorded while running the current tool. */
	public static $changes = [];
}

/**
 * @return array<string,array> name => definition
 */
function wpaie_tool_registry(): array {
	static $registry = null;
	if ( $registry !== null ) {
		return $registry;
	}

	$tools = (array) apply_filters( 'wpaie_tools', wpaie_core_tools() );

	$registry = [];
	foreach ( $tools as $tool ) {
		$registry[ $tool['name'] ] = $tool + [
			'cap'       => 'edit_posts',
			'available' => null,
			'write'     => false,
			'live'      => false,
			'preview'   => null,
		];
	}
	return $registry;
}

/**
 * All built-in tools, grouped by file.
 */
function wpaie_core_tools(): array {
	return array_merge(
		wpaie_content_tools(),
		function_exists( 'wpaie_media_tools' ) ? wpaie_media_tools() : [],
		function_exists( 'wpaie_seo_tools' ) ? wpaie_seo_tools() : [],
		wpaie_design_tools(),
		function_exists( 'wpaie_woocommerce_tools' ) ? wpaie_woocommerce_tools() : []
	);
}

/**
 * Names of the tools the current user may use on this site, in registry order.
 *
 * The list is stored with each conversation when it starts and stays fixed for it: changing the
 * tool set mid-conversation would invalidate the model's earlier thinking.
 *
 * @return string[]
 */
function wpaie_available_tool_names(): array {
	$names = [];
	foreach ( wpaie_tool_registry() as $name => $tool ) {
		if ( $tool['available'] && ! call_user_func( $tool['available'] ) ) {
			continue;
		}
		if ( ! current_user_can( $tool['cap'] ) ) {
			continue;
		}
		$names[] = $name;
	}
	return $names;
}

/**
 * Tool definitions for the API request, for the given tool names.
 */
function wpaie_tool_definitions( ?array $names = null ): array {
	$registry = wpaie_tool_registry();
	$names    = $names ?? array_keys( $registry );
	$out      = [];
	foreach ( $names as $name ) {
		if ( ! isset( $registry[ $name ] ) ) {
			continue;
		}
		$tool  = $registry[ $name ];
		$out[] = [
			'name'         => $tool['name'],
			'description'  => $tool['description'],
			'input_schema' => $tool['input_schema'],
		];
	}
	return $out;
}

/**
 * Short human-readable label for a tool call, shown in the chat.
 */
function wpaie_tool_label( string $name, array $input ): string {
	$tool = wpaie_tool_registry()[ $name ] ?? null;
	if ( $tool && is_callable( $tool['label'] ?? null ) ) {
		return (string) call_user_func( $tool['label'], $input );
	}
	/* translators: %s: tool name */
	return sprintf( __( 'Running %s…', 'wp-ai-editor' ), $name );
}

/**
 * Whether the call changes something that is visible on the public site immediately.
 */
function wpaie_tool_is_live( string $name, array $input ): bool {
	$tool = wpaie_tool_registry()[ $name ] ?? null;
	if ( ! $tool || ! $tool['write'] ) {
		return false;
	}
	return is_callable( $tool['live'] ) ? (bool) call_user_func( $tool['live'], $input ) : (bool) $tool['live'];
}

/**
 * Does this call need the user's approval under the given approval mode?
 *
 * @param string $mode live (live changes only) | all (every change) | none
 */
function wpaie_tool_needs_approval( string $name, array $input, string $mode ): bool {
	$tool = wpaie_tool_registry()[ $name ] ?? null;
	if ( ! $tool || ! $tool['write'] || $mode === 'none' ) {
		return false;
	}
	return $mode === 'all' || wpaie_tool_is_live( $name, $input );
}

/**
 * HTML preview of what a call would change (for the approval card). Already escaped.
 */
function wpaie_tool_preview( string $name, array $input ): string {
	$tool = wpaie_tool_registry()[ $name ] ?? null;
	if ( $tool && is_callable( $tool['preview'] ) ) {
		try {
			return (string) call_user_func( $tool['preview'], $input );
		} catch ( Throwable $e ) {
			wpaie_log( 'Preview failed', [ 'tool' => $name, 'error' => $e->getMessage() ] );
		}
	}
	return '';
}

/**
 * Run a tool. Never throws: unexpected exceptions become a WP_Error for the model.
 *
 * @return string|array|WP_Error String or list of content blocks.
 */
function wpaie_run_tool( string $name, array $input ) {
	$tool = wpaie_tool_registry()[ $name ] ?? null;
	if ( ! $tool ) {
		return new WP_Error( 'wpaie_tool', "Unknown tool: $name" );
	}
	if ( ! current_user_can( $tool['cap'] ) ) {
		return new WP_Error( 'wpaie_tool', "The current user is not allowed to use $name." );
	}
	if ( $tool['available'] && ! call_user_func( $tool['available'] ) ) {
		return new WP_Error( 'wpaie_tool', "$name is not available on this site any more (a required plugin may have been deactivated)." );
	}

	try {
		$result = call_user_func( $tool['run'], $input );
	} catch ( Throwable $e ) {
		wpaie_log( 'Tool crashed', [ 'tool' => $name, 'error' => $e->getMessage() ] );
		return new WP_Error( 'wpaie_tool', sprintf( 'Tool %s failed: %s', $name, $e->getMessage() ) );
	}

	if ( is_string( $result ) && mb_strlen( $result ) > WPAIE_TOOL_RESULT_MAX_CHARS ) {
		$result = mb_substr( $result, 0, WPAIE_TOOL_RESULT_MAX_CHARS )
			. "\n\n[OUTPUT TRUNCATED – longer than " . WPAIE_TOOL_RESULT_MAX_CHARS . ' characters. Do not use it to overwrite the whole content; the end is missing.]';
	}
	return $result;
}

function wpaie_tool_error( string $message ): WP_Error {
	return new WP_Error( 'wpaie_tool', $message );
}

// ─── Shared schema / preview helpers ──────────────────────────────────────────

function wpaie_schema_no_input(): array {
	return [
		'type'       => 'object',
		'properties' => new stdClass(),
	];
}

/**
 * Side-by-side diff of two texts as HTML (WordPress core diff renderer).
 */
function wpaie_preview_diff( string $old, string $new, string $title = '' ): string {
	if ( ! function_exists( 'wp_text_diff' ) ) {
		require_once ABSPATH . WPINC . '/pluggable.php';
	}
	$diff = wp_text_diff(
		$old,
		$new,
		[
			'title'           => $title,
			'title_left'      => __( 'Current', 'wp-ai-editor' ),
			'title_right'     => __( 'New', 'wp-ai-editor' ),
			'show_split_view' => false,
		]
	);
	return $diff ? '<div class="wpaie-diff">' . $diff . '</div>' : '<p>' . esc_html__( 'No visible difference.', 'wp-ai-editor' ) . '</p>';
}

/**
 * Key/value table for previews: [ label => [old, new] ].
 */
function wpaie_preview_table( array $rows ): string {
	$html = '<table class="wpaie-preview-table"><thead><tr><th></th><th>' . esc_html__( 'Current', 'wp-ai-editor' ) . '</th><th>' . esc_html__( 'New', 'wp-ai-editor' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $label => [ $old, $new ] ) {
		$html .= '<tr><th>' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $old ) . '</td><td><strong>' . esc_html( (string) $new ) . '</strong></td></tr>';
	}
	return $html . '</tbody></table>';
}

function wpaie_preview_code( string $code ): string {
	return '<pre class="wpaie-code">' . esc_html( $code ) . '</pre>';
}
