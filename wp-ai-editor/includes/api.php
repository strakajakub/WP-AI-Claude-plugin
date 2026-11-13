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
define( 'WPAIE_MAX_ATTEMPTS', 3 );       // 1 try + 2 retries on transient errors.

/** Transient that remembers the API rejected the optional beta features (graceful degradation). */
define( 'WPAIE_NO_BETA_TRANSIENT', 'wpaie_disable_beta_features' );

/**
 * Models that support server-side refusal fallbacks (fallbacks: "default").
 */
function wpaie_model_supports_fallbacks( string $model ): bool {
	return in_array( $model, [ 'claude-opus-5-5', 'claude-sonnet-5-5' ], true );
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
function wpaie_messages_create( string $api_key, string $model, string $effort, string $system, array $messages, array $tools ) {
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
		'max_tokens'    => WPAIE_MAX_TOKENS,
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

	return wpaie_api_request( 'POST', '/messages', $api_key, $payload, $betas, WPAIE_HTTP_TIMEOUT );
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
			return new WP_Error( 'wpaie_encode', 'Požadavek se nepodařilo převést do JSON (neplatné znaky v obsahu?).' );
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
				return new WP_Error( 'wpaie_bad_response', 'Anthropic API vrátilo neplatnou odpověď (nejde o JSON). Zkus to prosím znovu.' );
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
				'Anthropic API neodpovědělo do %d s. Zkus požadavek rozdělit na menší kroky, snížit „Úroveň přemýšlení“ v Nastavení, nebo zvolit rychlejší model.',
				$timeout
			),
			[ 'raw' => $raw ]
		);
	}
	if ( stripos( $raw, 'cURL error 6' ) !== false || stripos( $raw, 'resolve host' ) !== false ) {
		return new WP_Error( 'wpaie_network', 'Server nemůže přeložit adresu api.anthropic.com (DNS). Zkontroluj připojení serveru k internetu, případně firewall hostingu.', [ 'raw' => $raw ] );
	}
	if ( stripos( $raw, 'SSL' ) !== false || stripos( $raw, 'certificate' ) !== false ) {
		return new WP_Error( 'wpaie_network', 'Chyba zabezpečeného spojení (SSL) s api.anthropic.com. Hosting má nejspíš zastaralé certifikáty nebo OpenSSL. Detail: ' . $raw, [ 'raw' => $raw ] );
	}
	return new WP_Error( 'wpaie_network', 'Nepodařilo se spojit s Anthropic API: ' . $raw, [ 'raw' => $raw ] );
}

/**
 * Turn an HTTP error response into a user-friendly (Czech) WP_Error.
 */
function wpaie_api_error( int $status, $data, string $request_id = '' ): WP_Error {
	$type = (string) wpaie_prop( wpaie_prop( $data, 'error' ), 'type', '' );
	$raw  = (string) wpaie_prop( wpaie_prop( $data, 'error' ), 'message', '' );

	if ( $raw !== '' && stripos( $raw, 'credit balance' ) !== false ) {
		$message = 'Na účtu Anthropic došel kredit. Dobij ho v Claude Console (Settings → Billing).';
	} else {
		$message = match ( true ) {
			$status === 401 || $type === 'authentication_error' => 'Neplatný API klíč. Zkontroluj ho v AI Editor → Nastavení.',
			$status === 402 || $type === 'billing_error'        => 'Problém s fakturací účtu Anthropic. Zkontroluj platební údaje v Claude Console.',
			$status === 403 || $type === 'permission_error'     => 'API klíč nemá oprávnění k této akci nebo ke zvolenému modelu.',
			$status === 404 || $type === 'not_found_error'      => 'Zvolený model nebyl nalezen nebo k němu tvůj účet nemá přístup. Vyber jiný model v Nastavení.',
			$status === 413 || $type === 'request_too_large'    => 'Konverzace je pro API příliš velká. Začni nový chat (ikona koše vpravo nahoře).',
			$status === 429 || $type === 'rate_limit_error'     => 'Překročen limit požadavků na Anthropic API. Počkej chvíli a zkus to znovu.',
			$status === 529 || $type === 'overloaded_error'     => 'Anthropic API je momentálně přetížené. Zkus to prosím za chvíli.',
			$status >= 500                                       => 'Dočasná chyba na straně Anthropic API. Zkus to prosím znovu.',
			default                                              => 'Anthropic API odmítlo požadavek.',
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
		return new WP_Error( 'wpaie_no_key', 'Není nastaven API klíč.' );
	}
	$data = wpaie_api_request( 'GET', '/models/' . rawurlencode( $model ), $api_key, null, [], 20 );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$name = (string) wpaie_prop( $data, 'display_name', $model );
	return sprintf( 'Spojení funguje. Klíč je platný a model „%s“ je dostupný.', $name );
}
