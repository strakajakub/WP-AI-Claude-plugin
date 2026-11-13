<?php
/**
 * The agent: one model call (+ tool execution) per step.
 *
 * Each browser request runs exactly one step, so no single PHP request has to survive a whole
 * multi-call agent loop (hosting proxies often cut admin-ajax requests after 60–120 s). The
 * browser keeps calling "continue" while the step reports done=false.
 *
 * Tool calls that need the user's approval (see wpaie_tool_needs_approval) are not run right
 * away: the step stores them in $state['pending'] and returns awaiting=true with an approval card.
 * wpaie_agent_decide() then runs them (or records the rejection) and the loop continues.
 */

defined( 'ABSPATH' ) || exit;

/** Maximum agent steps (model calls) per user message. */
define( 'WPAIE_MAX_STEPS', 15 );

const WPAIE_REJECTED_RESULT = 'The user rejected this change, so it was NOT made. Do not retry it; ask the user what they want instead.';
const WPAIE_SKIPPED_RESULT  = 'The user did not answer the approval request and sent a new message instead, so this change was NOT made.';

/**
 * Run one agent step on $state (modified in place on success).
 *
 * @param callable|null $emit fn( string $event, array $data ) – live progress (streaming).
 * @return array|WP_Error {
 *     @type bool     $done       Whether the turn is finished.
 *     @type bool     $awaiting   Waiting for the user's approval.
 *     @type string   $reply      Assistant text from this step (may be empty).
 *     @type string[] $tool_calls Labels of tools executed in this step.
 *     @type array    $changes    [ {id, label} ] recorded changes (for undo buttons).
 *     @type array    $approvals  [ {label, live, preview} ] calls waiting for approval.
 *     @type float    $cost       Estimated cost of this step in USD.
 * }
 */
function wpaie_agent_step( array &$state, array $settings, string $api_key, ?callable $emit = null ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( wpaie_http_timeout( $settings['model'] ) + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	if ( $state['pending'] ) {
		return new WP_Error( 'wpaie_awaiting', __( 'Please approve or reject the proposed change first.', 'wp-ai-editor' ) );
	}

	if ( $state['steps'] >= WPAIE_MAX_STEPS ) {
		return wpaie_step_result(
			true,
			sprintf(
				/* translators: %d: maximum number of agent steps */
				__( 'I reached the limit of %d steps for one message. Write "continue" if I should carry on with the task.', 'wp-ai-editor' ),
				WPAIE_MAX_STEPS
			)
		);
	}

	if ( function_exists( 'wpaie_budget_error' ) ) {
		$budget = wpaie_budget_error();
		if ( $budget ) {
			return $budget;
		}
	}

	$tools    = wpaie_tool_definitions( $state['tools'] ?: null );
	$response = wpaie_messages_create( $api_key, $settings['model'], $settings['effort'], $state['system'], $state['messages'], $tools, $emit );

	// A thinking block bound to a different history (should not happen with append-only storage,
	// but e.g. a manually edited user meta would trigger it): drop all thinking once and retry.
	if ( is_wp_error( $response ) && wpaie_is_thinking_binding_error( $response ) ) {
		wpaie_log( 'Thinking blocks rejected, stripping them and retrying.' );
		$state['messages'] = wpaie_strip_thinking( $state['messages'] );
		$response          = wpaie_messages_create( $api_key, $settings['model'], $settings['effort'], $state['system'], $state['messages'], $tools, $emit );
	}
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cost = function_exists( 'wpaie_track_response_usage' ) ? wpaie_track_response_usage( $state, $response, $settings['model'] ) : 0.0;

	$content = is_array( $response->content ?? null ) ? $response->content : [];
	$stop    = (string) ( $response->stop_reason ?? '' );

	if ( $stop === 'refusal' ) {
		// Content is empty or a partial answer that must not be treated as complete; not stored.
		$category = (string) wpaie_prop( $response->stop_details ?? null, 'category', '' );
		return new WP_Error(
			'wpaie_refusal',
			( $category !== ''
				/* translators: %s: refusal category reported by the API, e.g. "cyber" */
				? sprintf( __( 'The model declined this request (safety filter: %s).', 'wp-ai-editor' ), $category )
				: __( 'The model declined this request (safety filter).', 'wp-ai-editor' ) )
			. ' ' . __( 'Try rephrasing it or choose another model in Settings.', 'wp-ai-editor' )
		);
	}

	$uses = array_values( array_filter( $content, fn( $b ) => wpaie_prop( $b, 'type' ) === 'tool_use' ) );
	$text = trim(
		implode(
			'',
			array_map( fn( $b ) => wpaie_prop( $b, 'type' ) === 'text' ? (string) wpaie_prop( $b, 'text', '' ) : '', $content )
		)
	);

	if ( ( $stop === 'max_tokens' && $uses ) || ! empty( $response->wpaie_invalid_tool_input ) ) {
		// The tool call's JSON input was cut off; running it (especially a write) could corrupt data.
		return new WP_Error(
			'wpaie_truncated',
			__( 'The model response was cut off in the middle of a tool call (response length limit). Please split the task into smaller steps, e.g. edit the content in parts.', 'wp-ai-editor' )
		);
	}

	if ( ! $content ) {
		return new WP_Error( 'wpaie_empty', __( 'The model returned no answer. Please try again or rephrase your message.', 'wp-ai-editor' ) );
	}

	// Store the assistant turn exactly as received (thinking blocks included; they must go back unchanged).
	$state['messages'][] = (object) [
		'role'    => 'assistant',
		'content' => $content,
	];
	$state['steps']++;

	if ( $stop === 'max_tokens' ) {
		return wpaie_step_result( true, $text . "\n\n⚠ " . __( 'The answer was cut short (length limit reached). Write "continue" for the rest.', 'wp-ai-editor' ), [], [], $cost );
	}
	if ( ! $uses ) {
		return wpaie_step_result( true, $text, [], [], $cost );
	}

	// Calls that need approval: stop here and ask. All calls of this turn wait together, because
	// their results must go back in a single message.
	$mode  = (string) ( $settings['approval_mode'] ?? 'live' );
	$needs = [];
	foreach ( $uses as $use ) {
		if ( wpaie_tool_needs_approval( (string) wpaie_prop( $use, 'name', '' ), wpaie_to_array( wpaie_prop( $use, 'input' ) ), $mode ) ) {
			$needs[] = (string) wpaie_prop( $use, 'id', '' );
		}
	}
	if ( $needs ) {
		$state['pending'] = (object) [
			'uses'  => $uses,
			'needs' => $needs,
		];
		$result              = wpaie_step_result( false, $text, [], [], $cost );
		$result['awaiting']  = true;
		$result['approvals'] = wpaie_approval_cards( $state );
		return $result;
	}

	[ $labels, $changes ] = wpaie_execute_tool_uses( $state, $uses, [], '', $api_key, $emit );
	return wpaie_step_result( false, $text, $labels, $changes, $cost );
}

/**
 * Apply the user's decision on the pending tool calls.
 *
 * @return array|WP_Error Step result (done=false: the browser continues with the next step).
 */
function wpaie_agent_decide( array &$state, bool $approve, string $api_key, ?callable $emit = null ) {
	if ( ! $state['pending'] ) {
		return new WP_Error( 'wpaie_not_awaiting', __( 'There is no change waiting for approval.', 'wp-ai-editor' ) );
	}
	$pending              = $state['pending'];
	[ $labels, $changes ] = wpaie_execute_tool_uses(
		$state,
		(array) $pending->uses,
		$approve ? [] : array_map( 'strval', (array) $pending->needs ),
		WPAIE_REJECTED_RESULT,
		$api_key,
		$emit
	);
	return wpaie_step_result( false, '', $labels, $changes );
}

/**
 * The user wrote a new message instead of answering the approval request: the calls waiting for
 * approval are skipped, the others run, so the history stays valid.
 */
function wpaie_agent_skip_pending( array &$state, string $api_key ): void {
	if ( ! $state['pending'] ) {
		return;
	}
	wpaie_execute_tool_uses( $state, (array) $state['pending']->uses, array_map( 'strval', (array) $state['pending']->needs ), WPAIE_SKIPPED_RESULT, $api_key );
}

/**
 * Run tool calls (except the rejected ones) and append all results as one user message.
 *
 * @return array{0:string[],1:array} [ labels of executed tools, recorded changes ]
 */
function wpaie_execute_tool_uses( array &$state, array $uses, array $rejected_ids, string $rejected_text, string $api_key, ?callable $emit = null ): array {
	WPAIE_Run_Context::$api_key = $api_key;
	WPAIE_Run_Context::$files   = $state['files'];

	$labels  = [];
	$changes = [];
	$results = [];
	foreach ( $uses as $use ) {
		$id    = (string) wpaie_prop( $use, 'id', '' );
		$name  = (string) wpaie_prop( $use, 'name', '' );
		$input = wpaie_to_array( wpaie_prop( $use, 'input' ) );

		if ( in_array( $id, $rejected_ids, true ) ) {
			$results[] = [
				'type'        => 'tool_result',
				'tool_use_id' => $id,
				'content'     => $rejected_text,
			];
			continue;
		}

		$label    = wpaie_tool_label( $name, $input );
		$labels[] = $label;
		if ( $emit ) {
			$emit( 'status', [ 'label' => $label ] );
		}

		WPAIE_Run_Context::$changes = [];
		$output                     = wpaie_run_tool( $name, $input );
		if ( WPAIE_Run_Context::$changes ) {
			$state['change_map'][ $id ] = WPAIE_Run_Context::$changes;
			$changes                    = array_merge( $changes, WPAIE_Run_Context::$changes );
		}

		$result = [
			'type'        => 'tool_result',
			'tool_use_id' => $id,
			'content'     => is_wp_error( $output ) ? 'ERROR: ' . $output->get_error_message() : ( is_array( $output ) ? $output : (string) $output ),
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
	$state['pending'] = null;
	$state['files']   = WPAIE_Run_Context::$files;

	return [ $labels, $changes ];
}

/**
 * Approval cards for the pending calls: label + HTML preview.
 *
 * @return array<int,array{label:string,live:bool,preview:string}>
 */
function wpaie_approval_cards( array $state ): array {
	if ( ! $state['pending'] ) {
		return [];
	}
	$needs = array_map( 'strval', (array) $state['pending']->needs );
	$cards = [];
	foreach ( (array) $state['pending']->uses as $use ) {
		if ( ! in_array( (string) wpaie_prop( $use, 'id', '' ), $needs, true ) ) {
			continue;
		}
		$name    = (string) wpaie_prop( $use, 'name', '' );
		$input   = wpaie_to_array( wpaie_prop( $use, 'input' ) );
		$cards[] = [
			'label'   => wpaie_tool_label( $name, $input ),
			'live'    => wpaie_tool_is_live( $name, $input ),
			'preview' => wpaie_tool_preview( $name, $input ),
		];
	}
	return $cards;
}

function wpaie_step_result( bool $done, string $reply, array $labels = [], array $changes = [], float $cost = 0.0 ): array {
	return [
		'done'       => $done,
		'awaiting'   => false,
		'reply'      => $reply,
		'tool_calls' => $labels,
		'changes'    => $changes,
		'approvals'  => [],
		'cost'       => $cost,
	];
}

function wpaie_is_thinking_binding_error( WP_Error $error ): bool {
	$data = (array) $error->get_error_data();
	return (int) ( $data['status'] ?? 0 ) === 400
		&& stripos( (string) ( $data['raw'] ?? '' ), 'signature' ) !== false;
}
