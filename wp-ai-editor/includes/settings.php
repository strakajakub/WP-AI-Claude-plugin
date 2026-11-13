<?php
/**
 * Plugin settings: API key, model and effort.
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAIE_DEFAULT_MODEL', 'claude-opus-5-5' );
define( 'WPAIE_DEFAULT_EFFORT', 'medium' );

/**
 * Models offered in the settings. Filterable so a site can add e.g. a newer model ID.
 *
 * @return array<string,string> model ID => label
 */
function wpaie_models(): array {
	return (array) apply_filters(
		'wpaie_models',
		[
			'claude-fable-5-2'  => __( 'Claude Fable 5.2 — most capable; slowest and most expensive', 'wp-ai-editor' ),
			'claude-opus-5-5'   => __( 'Claude Opus 5.5 — best balance (recommended)', 'wp-ai-editor' ),
			'claude-sonnet-5-5' => __( 'Claude Sonnet 5.5 — faster and cheaper', 'wp-ai-editor' ),
			'claude-haiku-5-5'  => __( 'Claude Haiku 5.5 — fastest and cheapest', 'wp-ai-editor' ),
		]
	);
}

/**
 * Effort levels: how much the model thinks before answering (quality vs. speed and cost).
 *
 * @return array<string,string>
 */
function wpaie_effort_levels(): array {
	return [
		'low'    => __( 'Low — fastest, for simple edits', 'wp-ai-editor' ),
		'medium' => __( 'Medium — balanced (recommended)', 'wp-ai-editor' ),
		'high'   => __( 'High — more thorough, slower', 'wp-ai-editor' ),
		'xhigh'  => __( 'Extra high — most thorough, slowest and most expensive', 'wp-ai-editor' ),
	];
}

function wpaie_get_settings(): array {
	$raw      = get_option( WPAIE_OPTION_KEY, [] );
	$settings = wp_parse_args(
		is_array( $raw ) ? $raw : [],
		[
			'api_key' => '',
			'model'   => WPAIE_DEFAULT_MODEL,
			'effort'  => WPAIE_DEFAULT_EFFORT,
		]
	);

	// Older versions stored retired model IDs (e.g. claude-opus-4-8) – move them to the default.
	if ( ! isset( wpaie_models()[ $settings['model'] ] ) ) {
		$settings['model'] = WPAIE_DEFAULT_MODEL;
	}
	if ( ! isset( wpaie_effort_levels()[ $settings['effort'] ] ) ) {
		$settings['effort'] = WPAIE_DEFAULT_EFFORT;
	}
	return $settings;
}

/**
 * Whether the API key is provided via the WPAIE_API_KEY constant in wp-config.php.
 */
function wpaie_api_key_from_constant(): bool {
	return defined( 'WPAIE_API_KEY' ) && is_string( WPAIE_API_KEY ) && trim( WPAIE_API_KEY ) !== '';
}

function wpaie_get_api_key(): string {
	if ( wpaie_api_key_from_constant() ) {
		return trim( WPAIE_API_KEY );
	}
	return (string) wpaie_get_settings()['api_key'];
}

/**
 * Mask an API key for display: "sk-ant-…a1b2".
 */
function wpaie_mask_key( string $key ): string {
	return $key === '' ? '' : substr( $key, 0, 7 ) . '…' . substr( $key, -4 );
}

add_action(
	'admin_init',
	function () {
		register_setting(
			'wpaie_settings_group',
			WPAIE_OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => 'wpaie_sanitize_settings',
				'default'           => [],
			]
		);
	}
);

function wpaie_sanitize_settings( $input ): array {
	$old   = wpaie_get_settings();
	$input = is_array( $input ) ? $input : [];

	$key = trim( sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ) );
	if ( ! empty( $input['remove_api_key'] ) ) {
		$key = '';
	} elseif ( $key === '' ) {
		// Empty field = keep the stored key (the key itself is never printed back into the form).
		$key = $old['api_key'];
	} elseif ( ! str_starts_with( $key, 'sk-ant-' ) ) {
		add_settings_error(
			WPAIE_OPTION_KEY,
			'wpaie_key_format',
			__( 'This does not look like an Anthropic API key (it must start with "sk-ant-"). The key was not changed.', 'wp-ai-editor' )
		);
		$key = $old['api_key'];
	}

	$model = (string) ( $input['model'] ?? $old['model'] );
	if ( ! isset( wpaie_models()[ $model ] ) ) {
		$model = WPAIE_DEFAULT_MODEL;
	}

	$effort = (string) ( $input['effort'] ?? $old['effort'] );
	if ( ! isset( wpaie_effort_levels()[ $effort ] ) ) {
		$effort = WPAIE_DEFAULT_EFFORT;
	}

	return [
		'api_key' => $key,
		'model'   => $model,
		'effort'  => $effort,
	];
}
