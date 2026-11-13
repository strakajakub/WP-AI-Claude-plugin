<?php
/**
 * Streaming.
 *
 * Anthropic → PHP: the Messages API is called with stream=true over cURL (the WordPress HTTP API
 * cannot stream). WPAIE_SSE_Message rebuilds the final message from the server-sent events, so
 * the result is the same object a non-streaming call returns (thinking blocks and signatures
 * included – they must be replayed verbatim).
 *
 * PHP → browser: the AJAX request answers with text/event-stream: "text" events with answer
 * deltas, "status" events with tool labels, and one final "result" or "error" event. Text keeps
 * flowing while the model works, which also keeps proxies from timing the request out.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a Messages API response from its server-sent events.
 */
final class WPAIE_SSE_Message {
	/** @var object|null */
	public $message = null;
	/** @var WP_Error|null */
	public $error = null;
	/** @var bool */
	public $complete = false;

	/** @var array<int,object> */
	private $blocks = [];
	/** @var array<int,string> */
	private $json = [];
	/** @var string */
	private $buffer = '';
	/** @var callable|null fn( string $delta ) */
	private $on_text;
	/** @var callable|null fn() */
	private $on_ping;

	public function __construct( ?callable $on_text = null, ?callable $on_ping = null ) {
		$this->on_text = $on_text;
		$this->on_ping = $on_ping;
	}

	/**
	 * Feed raw bytes as they arrive.
	 */
	public function feed( string $chunk ): void {
		$this->buffer .= str_replace( "\r\n", "\n", $chunk );
		while ( ( $pos = strpos( $this->buffer, "\n\n" ) ) !== false ) {
			$raw          = substr( $this->buffer, 0, $pos );
			$this->buffer = substr( $this->buffer, $pos + 2 );
			$this->handle_event( $raw );
		}
	}

	private function handle_event( string $raw ): void {
		$data = '';
		foreach ( explode( "\n", $raw ) as $line ) {
			if ( str_starts_with( $line, 'data:' ) ) {
				$data .= ltrim( substr( $line, 5 ) );
			}
		}
		if ( $data === '' ) {
			return;
		}
		$event = json_decode( $data );
		if ( ! is_object( $event ) ) {
			return;
		}

		switch ( $event->type ?? '' ) {
			case 'message_start':
				$this->message          = $event->message ?? (object) [];
				$this->message->content = [];
				break;

			case 'content_block_start':
				$index                  = (int) ( $event->index ?? 0 );
				$this->blocks[ $index ] = $event->content_block ?? (object) [];
				if ( ( $this->blocks[ $index ]->type ?? '' ) === 'tool_use' ) {
					$this->json[ $index ] = '';
				}
				break;

			case 'content_block_delta':
				$index = (int) ( $event->index ?? 0 );
				$delta = $event->delta ?? null;
				$block = $this->blocks[ $index ] ?? null;
				if ( ! $block || ! $delta ) {
					break;
				}
				switch ( $delta->type ?? '' ) {
					case 'text_delta':
						$block->text = ( $block->text ?? '' ) . ( $delta->text ?? '' );
						if ( $this->on_text ) {
							( $this->on_text )( (string) ( $delta->text ?? '' ) );
						}
						break;
					case 'input_json_delta':
						$this->json[ $index ] = ( $this->json[ $index ] ?? '' ) . ( $delta->partial_json ?? '' );
						break;
					case 'thinking_delta':
						$block->thinking = ( $block->thinking ?? '' ) . ( $delta->thinking ?? '' );
						break;
					case 'signature_delta':
						$block->signature = ( $block->signature ?? '' ) . ( $delta->signature ?? '' );
						break;
					case 'citations_delta':
						$block->citations   = $block->citations ?? [];
						$block->citations[] = $delta->citation ?? null;
						break;
				}
				break;

			case 'content_block_stop':
				$index = (int) ( $event->index ?? 0 );
				if ( isset( $this->json[ $index ], $this->blocks[ $index ] ) ) {
					$json  = trim( $this->json[ $index ] );
					$input = $json === '' ? new stdClass() : json_decode( $json );
					if ( ! is_object( $input ) ) {
						// Cut off or malformed: never run a tool with a guessed input.
						$input = new stdClass();
						if ( $this->message ) {
							$this->message->wpaie_invalid_tool_input = true;
						}
					}
					$this->blocks[ $index ]->input = $input;
				}
				break;

			case 'message_delta':
				if ( ! $this->message ) {
					break;
				}
				foreach ( (array) ( $event->delta ?? [] ) as $key => $value ) {
					$this->message->$key = $value;
				}
				if ( isset( $event->usage ) ) {
					$usage = isset( $this->message->usage ) ? (object) $this->message->usage : new stdClass();
					foreach ( (array) $event->usage as $key => $value ) {
						$usage->$key = $value;
					}
					$this->message->usage = $usage;
				}
				break;

			case 'message_stop':
				$this->complete = true;
				break;

			case 'ping':
				if ( $this->on_ping ) {
					( $this->on_ping )();
				}
				break;

			case 'error':
				$this->error = wpaie_api_error(
					(int) ( [ 'overloaded_error' => 529, 'rate_limit_error' => 429, 'api_error' => 500 ][ $event->error->type ?? '' ] ?? 500 ),
					$event
				);
				break;
		}
	}

	/**
	 * The assembled message (same shape as a non-streaming response).
	 *
	 * @return object|WP_Error
	 */
	public function result() {
		if ( $this->error ) {
			return $this->error;
		}
		if ( ! $this->message ) {
			return new WP_Error( 'wpaie_stream', __( 'The Anthropic API stream ended before any answer arrived. Please try again.', 'wp-ai-editor' ) );
		}
		if ( ! $this->complete ) {
			return new WP_Error( 'wpaie_stream', __( 'The connection to the Anthropic API dropped in the middle of the answer. Please try again.', 'wp-ai-editor' ) );
		}
		ksort( $this->blocks );
		$this->message->content = array_values( $this->blocks );
		return $this->message;
	}
}

/**
 * Streaming is possible when cURL is available and not disabled in Settings.
 */
function wpaie_can_stream(): bool {
	return function_exists( 'curl_init' ) && ! empty( wpaie_get_settings()['streaming'] );
}

/**
 * POST /v1/messages with stream=true over cURL.
 *
 * @return object|WP_Error The assembled message.
 */
function wpaie_api_stream_request( string $api_key, array $payload, array $betas, int $timeout, ?callable $on_text, ?callable $on_ping ) {
	$payload['stream'] = true;
	$body              = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( $body === false ) {
		return new WP_Error( 'wpaie_encode', __( 'The request could not be encoded as JSON (invalid characters in the content?).', 'wp-ai-editor' ) );
	}

	$headers = [
		'x-api-key: ' . $api_key,
		'anthropic-version: ' . WPAIE_API_VERSION,
		'content-type: application/json',
		'accept: text/event-stream',
	];
	if ( $betas ) {
		$headers[] = 'anthropic-beta: ' . implode( ',', array_unique( $betas ) );
	}

	for ( $attempt = 1; ; $attempt++ ) {
		$parser   = new WPAIE_SSE_Message( $on_text, $on_ping );
		$status   = 0;
		$raw_body = '';
		$resp_hdr = [];
		$streamed = false;

		$ch = curl_init( WPAIE_API_BASE . '/messages' );
		curl_setopt_array(
			$ch,
			[
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$resp_hdr ) {
					$parts = explode( ':', $line, 2 );
					if ( count( $parts ) === 2 ) {
						$resp_hdr[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( $parser, &$status, &$raw_body, &$streamed ) {
					if ( ! $status ) {
						$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
					}
					if ( $status === 200 ) {
						$streamed = true;
						$parser->feed( $chunk );
					} elseif ( strlen( $raw_body ) < 65536 ) {
						$raw_body .= $chunk;
					}
					return strlen( $chunk );
				},
			] + wpaie_curl_site_options()
		);
		curl_exec( $ch );
		$errno  = curl_errno( $ch );
		$errstr = curl_error( $ch );
		$status = $status ?: (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( $errno && ! $streamed ) {
			$error = wpaie_network_error( new WP_Error( 'http_request_failed', "cURL error $errno: $errstr" ), $timeout );
			if ( $error->get_error_code() !== 'wpaie_timeout' && $attempt < WPAIE_MAX_ATTEMPTS && wpaie_can_wait( 2 * $attempt ) ) {
				sleep( 2 * $attempt );
				continue;
			}
			return $error;
		}
		if ( $status === 200 ) {
			if ( $errno && ! $parser->complete ) {
				wpaie_log( 'Stream interrupted', [ 'error' => $errstr ] );
				return $errno === 28
					? wpaie_network_error( new WP_Error( 'http_request_failed', "cURL error 28: $errstr" ), $timeout )
					: new WP_Error( 'wpaie_stream', __( 'The connection to the Anthropic API dropped in the middle of the answer. Please try again.', 'wp-ai-editor' ) );
			}
			return $parser->result();
		}

		$data = json_decode( $raw_body );
		if ( in_array( $status, [ 408, 409, 429, 500, 502, 503, 504, 529 ], true ) && $attempt < WPAIE_MAX_ATTEMPTS ) {
			$wait = (int) ( $resp_hdr['retry-after'] ?? 0 );
			$wait = $wait > 0 ? min( $wait, 20 ) : 2 ** $attempt;
			if ( wpaie_can_wait( $wait ) ) {
				sleep( $wait );
				continue;
			}
		}
		$error = wpaie_api_error( $status, $data, (string) ( $resp_hdr['request-id'] ?? '' ) );
		wpaie_log( 'API error (stream)', (array) $error->get_error_data() );
		return $error;
	}
}

/**
 * cURL options that mirror the WordPress HTTP API: CA bundle and proxy settings from wp-config.php.
 */
function wpaie_curl_site_options(): array {
	$options = [ CURLOPT_SSL_VERIFYPEER => true ];
	$bundle  = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
	if ( file_exists( $bundle ) ) {
		$options[ CURLOPT_CAINFO ] = $bundle;
	}
	if ( defined( 'WP_PROXY_HOST' ) && defined( 'WP_PROXY_PORT' ) ) {
		$options[ CURLOPT_PROXY ]     = WP_PROXY_HOST;
		$options[ CURLOPT_PROXYPORT ] = (int) WP_PROXY_PORT;
		if ( defined( 'WP_PROXY_USERNAME' ) && defined( 'WP_PROXY_PASSWORD' ) ) {
			$options[ CURLOPT_PROXYUSERPWD ] = WP_PROXY_USERNAME . ':' . WP_PROXY_PASSWORD;
		}
	}
	return $options;
}

// ─── PHP → browser ────────────────────────────────────────────────────────────

function wpaie_stream_requested(): bool {
	return isset( $_POST['stream'] ) && $_POST['stream'] === '1' && wpaie_can_stream(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the calling AJAX handler.
}

function wpaie_stream_started(): bool {
	return ! empty( $GLOBALS['wpaie_streaming'] );
}

/**
 * Switch the response to text/event-stream and disable every output buffer.
 */
function wpaie_stream_begin(): void {
	if ( wpaie_stream_started() ) {
		return;
	}
	$GLOBALS['wpaie_streaming'] = true;

	@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet
	while ( ob_get_level() > 0 ) {
		ob_end_flush();
	}
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' ); // nginx
	}
	// Keep working (and save the conversation) even if the browser goes away.
	ignore_user_abort( true );
	// Some servers only start sending after a few KB.
	echo ':' . str_repeat( ' ', 2048 ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	flush();
}

function wpaie_stream_emit( string $event, array $data = [] ): void {
	if ( ! wpaie_stream_started() ) {
		return;
	}
	if ( $event === 'ping' ) {
		echo ": ping\n\n";
	} else {
		echo 'event: ' . $event . "\ndata: " . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for the event stream.
	}
	flush();
}
