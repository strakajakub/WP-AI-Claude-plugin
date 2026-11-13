<?php
/**
 * Anthropic Messages API client (raw HTTP via the WordPress HTTP API).
 *
 * The official PHP SDK is not used on purpose: a WordPress plugin distributed as a ZIP should
 * not bundle Composer dependencies (Guzzle/PSR versions collide with other plugins), and
 * wp_remote_request respects the site's proxy and SSL configuration.
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAIE_API_BASE', 'https://api.anthropic.com/v1' );
define( 'WPAIE_API_VERSION', '2026-10-21' );
define( 'WPAIE_MAX_TOKENS', 16000 );     // Room for adaptive thinking + the reply.
define( 'WPAIE_HTTP_TIMEOUT', 240 );     // Seconds for one Messages API call.

/** Claude Fable 5.2 thinks longer per turn: more output room and a longer HTTP timeout. */
define( 'WPAIE_FABLE_MAX_TOKENS', 32000 );
define( 'WPAIE_FABLE_HTTP_TIMEOUT', 600 );
define( 'WPAIE_MAX_ATTEMPTS', 3 );       // 1 try + 2 retries on transient errors.

/** Transient that remembers the API rejected the optional beta features (graceful degradation). */
define( 'WPAIE_NO_BETA_TRANSIENT', 'wpaie_disable_beta_features' );

/**
 * Models that support server-side refusal fallbacks (fallbacks: "default").
 */
function wpaie_model_supports_fallbacks( string $model ): bool {
	return in_array( $model, [ 'claude-fable-5-2', 'claude-opus-5-5', 'claude-sonnet-5-5' ], true );
}

function wpaie_is_fable( string $model ): bool {
	return str_starts_with( $model, 'claude-fable-' ) || str_starts_with( $model, 'claude-mythos-' );
}

/**
 * Seconds to wait for one Messages API call with this model.
 */
function wpaie_http_timeout( string $model ): int {
	return wpaie_is_fable( $model ) ? WPAIE_FABLE_HTTP_TIMEOUT : WPAIE_HTTP_TIMEOUT;
}

function wpaie_max_tokens( string $model ): int {
	return wpaie_is_fable( $model ) ? WPAIE_FABLE_MAX_TOKENS : WPAIE_MAX_TOKENS;
}

/**
 * Create a message.
 *
 * Request shape notes:
 * - thinking is adaptive (always on for Opus 5.5); effort is the control for depth/latency/cost.
 * - cache_control at the top level caches the conversation prefix between agent steps.
 * - Optional beta features (graceful degradation if the API rejects them):
 *   - context_management: server-side clearing of old tool results (does not count as a
 *     history edit, so earlier thinking blocks stay valid);
 *   - thinking.block_binding drop_block: if the stored history ever diverges, the API drops the
 *     affected thinking blocks instead of failing the request with a 400;
 *   - fallbacks "default": if a safety classifier declines, the API retries on a fallback model.
 *
 * @param array  $messages List of message objects (stdClass) – sent verbatim.
 * @return object|WP_Error Decoded response.
 */
function wpaie_messages_create( string $api_key, string $model, string $effort, string $system, array $messages, array $tools, ?callable $emit = null ) {
	$use_beta = ! get_transient( WPAIE_NO_BETA_TRANSIENT );
	$result   = wpaie_send_messages_request( $api_key, $model, $effort, $system, $messages, $tools, $use_beta );

	if ( $use_beta && is_wp_error( $result ) && wpaie_is_beta_rejection( $result ) ) {
		wpaie_log( 'Beta features rejected, retrying without them.', [ 'error' => $result->get_error_message() ] );
		set_transient( WPAIE_NO_BETA_TRANSIENT, 1, DAY_IN_SECONDS );
		$result = wpaie_send_messages_request( $api_key, $model, $effort, $system, $messages, $tools, false );
	}
	return $result;
}

function wpaie_send_messages_request( string $api_key, string $model, string $effort, string $system, array $messages, array $tools, bool $use_beta ) {
	$payload = [
		'model'         => $model,
		'max_tokens'    => wpaie_max_tokens( $model ),
		'system'        => $system,
		'tools'         => $tools,
		'messages'      => $messages,
		'thinking'      => [ 'type' => 'adaptive' ],
		'output_config' => [ 'effort' => $effort ],
		'cache_control' => [ 'type' => 'ephemeral' ],
	];
	$betas = [];

	if ( $use_beta ) {
		$payload['context_management'] = [
			'edits' => [
				[
					'type'           => 'clear_tool_uses_20250919',
					'trigger'        => [ 'type' => 'input_tokens', 'value' => 40000 ],
					'keep'           => [ 'type' => 'tool_uses', 'value' => 4 ],
					'clear_at_least' => [ 'type' => 'input_tokens', 'value' => 5000 ],
				],
			],
		];
		$betas[] = 'context-management-2025-06-27';

		$payload['thinking']['block_binding'] = [ 'prefix_mismatch_behavior' => 'drop_block' ];
		$betas[]                              = 'thinking-binding-controls-2026-08-01';

		if ( wpaie_model_supports_fallbacks( $model ) ) {
			$payload['fallbacks'] = 'default';
			$betas[]              = 'server-side-fallback-2026-07-01';
		}
	}

	return wpaie_api_request( 'POST', '/messages', $api_key, $payload, $betas, wpaie_http_timeout( $model ) );
}

/**
 * Was this error the API refusing one of the optional beta parameters/headers?
 */
function wpaie_is_beta_rejection( WP_Error $error ): bool {
	$data = (array) $error->get_error_data();
	if ( (int) ( $data['status'] ?? 0 ) !== 400 ) {
		return false;
	}
	$raw = (string) ( $data['raw'] ?? '' );
	if ( stripos( $raw, 'signature' ) !== false ) {
		return false; // An invalid thinking block, handled by the agent (see wpaie_is_thinking_binding_error).
	}
	return (bool) preg_match( '/context_management|block_binding|fallbacks|anthropic-beta|beta/i', $raw );
}

/**
 * Low-level request with retries on transient failures (429, 5xx, 529, connection errors).
 *
 * @return object|WP_Error Decoded JSON body on success.
 */
function wpaie_api_request( string $method, string $path, string $api_key, ?array $payload, array $betas = [], int $timeout = 30 ) {
	$headers = [
		'x-api-key'         => $api_key,
		'anthropic-version' => WPAIE_API_VERSION,
		'content-type'      => 'application/json',
	];
	if ( $betas ) {
		$headers['anthropic-beta'] = implode( ',', array_unique( $betas ) );
	}

	$args = [
		'method'  => $method,
		'timeout' => $timeout,
		'headers' => $headers,
	];
	if ( $payload !== null ) {
		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( $body === false ) {
			return new WP_Error( 'wpaie_encode', __( 'The request could not be encoded as JSON (invalid characters in the content?).', 'wp-ai-editor' ) );
		}
		$args['body'] = $body;
	}

	for ( $attempt = 1; ; $attempt++ ) {
		$response = wp_remote_request( WPAIE_API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			$error = wpaie_network_error( $response, $timeout );
			// A timeout is not retried: another full-length attempt would exceed PHP/proxy limits.
			if ( $error->get_error_code() !== 'wpaie_timeout' && $attempt < WPAIE_MAX_ATTEMPTS && wpaie_can_wait( 2 * $attempt ) ) {
				sleep( 2 * $attempt );
				continue;
			}
			wpaie_log( 'Network error', [ 'path' => $path, 'error' => $response->get_error_message() ] );
			return $error;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ) );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_object( $data ) ) {
				return new WP_Error( 'wpaie_bad_response', __( 'The Anthropic API returned an invalid (non-JSON) response. Please try again.', 'wp-ai-editor' ) );
			}
			return $data;
		}

		$retryable = in_array( $status, [ 408, 409, 429, 500, 502, 503, 504, 529 ], true );
		if ( $retryable && $attempt < WPAIE_MAX_ATTEMPTS ) {
			$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$wait = $wait > 0 ? min( $wait, 20 ) : 2 ** $attempt;
			if ( wpaie_can_wait( $wait ) ) {
				sleep( $wait );
				continue;
			}
		}

		$error = wpaie_api_error( $status, $data, (string) wp_remote_retrieve_header( $response, 'request-id' ) );
		wpaie_log( 'API error', [ 'path' => $path ] + (array) $error->get_error_data() );
		return $error;
	}
}

/**
 * Only sleep before a retry if the PHP request still has time for another API call.
 */
function wpaie_can_wait( int $seconds ): bool {
	$limit = (int) ini_get( 'max_execution_time' );
	if ( $limit <= 0 ) {
		return true;
	}
	return wpaie_request_elapsed() + $seconds + 30 < $limit;
}

function wpaie_network_error( WP_Error $error, int $timeout ): WP_Error {
	$raw = $error->get_error_message();

	if ( stripos( $raw, 'cURL error 28' ) !== false || stripos( $raw, 'timed out' ) !== false ) {
		return new WP_Error(
			'wpaie_timeout',
			sprintf(
				/* translators: %d: timeout in seconds */
				__( 'The Anthropic API did not respond within %d s. Split the request into smaller steps, lower the "Thinking effort" in Settings, or choose a faster model.', 'wp-ai-editor' ),
				$timeout
			),
			[ 'raw' => $raw ]
		);
	}
	if ( stripos( $raw, 'cURL error 6' ) !== false || stripos( $raw, 'resolve host' ) !== false ) {
		return new WP_Error( 'wpaie_network', __( 'The server cannot resolve api.anthropic.com (DNS). Check that the server can reach the internet and that the hosting firewall allows it.', 'wp-ai-editor' ), [ 'raw' => $raw ] );
	}
	if ( stripos( $raw, 'SSL' ) !== false || stripos( $raw, 'certificate' ) !== false ) {
		return new WP_Error( 'wpaie_network', __( 'Secure connection (SSL) to api.anthropic.com failed. The hosting probably has outdated CA certificates or OpenSSL. Details:', 'wp-ai-editor' ) . ' ' . $raw, [ 'raw' => $raw ] );
	}
	return new WP_Error( 'wpaie_network', __( 'Could not connect to the Anthropic API:', 'wp-ai-editor' ) . ' ' . $raw, [ 'raw' => $raw ] );
}

/**
 * Turn an HTTP error response into a user-friendly (Czech) WP_Error.
 */
function wpaie_api_error( int $status, $data, string $request_id = '' ): WP_Error {
	$type = (string) wpaie_prop( wpaie_prop( $data, 'error' ), 'type', '' );
	$raw  = (string) wpaie_prop( wpaie_prop( $data, 'error' ), 'message', '' );

	if ( $status === 400 && stripos( $raw, 'retention' ) !== false ) {
		$message = __( 'This model requires 30-day data retention, which your Anthropic organization does not have (e.g. zero data retention). Choose another model in Settings.', 'wp-ai-editor' );
	} elseif ( $raw !== '' && stripos( $raw, 'credit balance' ) !== false ) {
		$message = __( 'Your Anthropic account is out of credit. Top it up in the Claude Console (Settings → Billing).', 'wp-ai-editor' );
	} else {
		$message = match ( true ) {
			$status === 401 || $type === 'authentication_error' => __( 'Invalid API key. Check it in AI Editor → Settings.', 'wp-ai-editor' ),
			$status === 402 || $type === 'billing_error'        => __( 'Billing problem on your Anthropic account. Check your payment details in the Claude Console.', 'wp-ai-editor' ),
			$status === 403 || $type === 'permission_error'     => __( 'The API key is not allowed to perform this action or to use the selected model.', 'wp-ai-editor' ),
			$status === 404 || $type === 'not_found_error'      => __( 'The selected model was not found or your account has no access to it. Choose another model in Settings.', 'wp-ai-editor' ),
			$status === 413 || $type === 'request_too_large'    => __( 'The conversation is too large for the API. Start a new chat (trash icon, top right).', 'wp-ai-editor' ),
			$status === 429 || $type === 'rate_limit_error'     => __( 'Anthropic API rate limit exceeded. Wait a moment and try again.', 'wp-ai-editor' ),
			$status === 529 || $type === 'overloaded_error'     => __( 'The Anthropic API is currently overloaded. Please try again in a moment.', 'wp-ai-editor' ),
			$status >= 500                                       => __( 'Temporary error on the Anthropic API side. Please try again.', 'wp-ai-editor' ),
			default                                              => __( 'The Anthropic API rejected the request.', 'wp-ai-editor' ),
		};
	}

	$detail = 'HTTP ' . $status . ( $raw !== '' ? ': ' . $raw : '' );
	if ( $request_id !== '' ) {
		$detail .= ' · request-id: ' . $request_id;
	}

	return new WP_Error(
		'wpaie_api' . ( $type !== '' ? '_' . $type : '' ),
		$message . ' (' . $detail . ')',
		[
			'status'     => $status,
			'type'       => $type,
			'raw'        => $raw,
			'request_id' => $request_id,
		]
	);
}

/**
 * Check that the key works and the model is available (used by the "Test connection" button).
 *
 * @return string|WP_Error Human readable success message.
 */
function wpaie_test_connection( string $api_key, string $model ) {
	if ( $api_key === '' ) {
		return new WP_Error( 'wpaie_no_key', __( 'No API key is set.', 'wp-ai-editor' ) );
	}
	$data = wpaie_api_request( 'GET', '/models/' . rawurlencode( $model ), $api_key, null, [], 20 );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$name = (string) wpaie_prop( $data, 'display_name', $model );
	return sprintf(
		/* translators: %s: model name */
		__( 'Connection works. The key is valid and the model "%s" is available.', 'wp-ai-editor' ),
		$name
	);
}
