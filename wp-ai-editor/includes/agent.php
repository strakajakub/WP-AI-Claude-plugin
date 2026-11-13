<?php
/**
 * The agent: one model call (+ tool execution) per step.
 *
 * Each browser AJAX request runs exactly one step, so no single PHP request has to survive a
 * whole multi-call agent loop (hosting proxies often cut admin-ajax requests after 60–120 s).
 * The browser keeps calling "continue" while the step reports done=false.
 */

defined( 'ABSPATH' ) || exit;

/** Maximum agent steps (model calls) per user message. */
define( 'WPAIE_MAX_STEPS', 15 );

/**
 * Run one agent step on $state (modified in place on success).
 *
 * @return array|WP_Error {
 *     @type bool     $done       Whether the turn is finished.
 *     @type string   $reply      Assistant text from this step (may be empty).
 *     @type string[] $tool_calls Labels of tools executed in this step.
 * }
 */
function wpaie_agent_step( array &$state, array $settings, string $api_key ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( WPAIE_HTTP_TIMEOUT + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	if ( $state['steps'] >= WPAIE_MAX_STEPS ) {
		return [
			'done'       => true,
			'reply'      => sprintf( 'Dosáhl jsem limitu %d kroků pro jednu zprávu. Napiš „pokračuj“, pokud mám v úkolu pokračovat.', WPAIE_MAX_STEPS ),
			'tool_calls' => [],
		];
	}

	$response = wpaie_messages_create(
		$api_key,
		$settings['model'],
		$settings['effort'],
		$state['system'],
		$state['messages'],
		wpaie_tool_definitions()
	);

	// A thinking block bound to a different history (should not happen with append-only storage,
	// but e.g. a manually edited user meta would trigger it): drop all thinking once and retry.
	if ( is_wp_error( $response ) && wpaie_is_thinking_binding_error( $response ) ) {
		wpaie_log( 'Thinking blocks rejected, stripping them and retrying.' );
		$state['messages'] = wpaie_strip_thinking( $state['messages'] );
		$response          = wpaie_messages_create( $api_key, $settings['model'], $settings['effort'], $state['system'], $state['messages'], wpaie_tool_definitions() );
	}
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$content = is_array( $response->content ?? null ) ? $response->content : [];
	$stop    = (string) ( $response->stop_reason ?? '' );

	if ( $stop === 'refusal' ) {
		// Content is empty or a partial answer that must not be treated as complete; not stored.
		$category = (string) wpaie_prop( $response->stop_details ?? null, 'category', '' );
		return new WP_Error(
			'wpaie_refusal',
			'Model tuto žádost odmítl zpracovat (zasáhl bezpečnostní filtr' . ( $category !== '' ? ": $category" : '' ) . '). '
			. 'Zkus ji přeformulovat nebo v Nastavení vybrat jiný model.'
		);
	}

	$uses = array_values( array_filter( $content, fn( $b ) => wpaie_prop( $b, 'type' ) === 'tool_use' ) );
	$text = trim(
		implode(
			'',
			array_map( fn( $b ) => wpaie_prop( $b, 'type' ) === 'text' ? (string) wpaie_prop( $b, 'text', '' ) : '', $content )
		)
	);

	if ( $stop === 'max_tokens' && $uses ) {
		// The tool call's JSON input was cut off; running it (especially a write) could corrupt data.
		return new WP_Error(
			'wpaie_truncated',
			'Odpověď modelu byla přerušena uprostřed volání nástroje (limit délky odpovědi). Rozděl prosím úkol na menší kroky, např. upravuj obsah po částech.'
		);
	}

	if ( ! $content ) {
		return new WP_Error( 'wpaie_empty', 'Model nevrátil žádnou odpověď. Zkus to prosím znovu nebo zprávu přeformuluj.' );
	}

	// Store the assistant turn exactly as received (thinking blocks included; they must go back unchanged).
	$state['messages'][] = (object) [
		'role'    => 'assistant',
		'content' => $content,
	];
	$state['steps']++;

	if ( $stop === 'max_tokens' ) {
		return [
			'done'       => true,
			'reply'      => $text . "\n\n⚠ Odpověď byla zkrácena (dosažen limit délky). Napiš „pokračuj“ pro zbytek.",
			'tool_calls' => [],
		];
	}

	if ( ! $uses ) {
		return [
			'done'       => true,
			'reply'      => $text,
			'tool_calls' => [],
		];
	}

	$labels  = [];
	$results = [];
	foreach ( $uses as $use ) {
		$name     = (string) wpaie_prop( $use, 'name', '' );
		$input    = wpaie_to_array( wpaie_prop( $use, 'input' ) );
		$labels[] = wpaie_tool_label( $name, $input );
		$output   = wpaie_run_tool( $name, $input );

		$result = [
			'type'        => 'tool_result',
			'tool_use_id' => (string) wpaie_prop( $use, 'id', '' ),
			'content'     => is_wp_error( $output ) ? 'CHYBA: ' . $output->get_error_message() : (string) $output,
		];
		if ( is_wp_error( $output ) ) {
			$result['is_error'] = true;
			wpaie_log( 'Tool returned an error', [ 'tool' => $name, 'error' => $output->get_error_message() ] );
		}
		$results[] = $result;
	}

	// All results of one assistant turn go back in a single user message.
	$state['messages'][] = wpaie_to_object(
		[
			'role'    => 'user',
			'content' => $results,
		]
	);

	return [
		'done'       => false,
		'reply'      => $text,
		'tool_calls' => $labels,
	];
}

function wpaie_is_thinking_binding_error( WP_Error $error ): bool {
	$data = (array) $error->get_error_data();
	return (int) ( $data['status'] ?? 0 ) === 400
		&& stripos( (string) ( $data['raw'] ?? '' ), 'signature' ) !== false;
}
