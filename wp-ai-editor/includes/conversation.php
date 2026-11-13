<?php
/**
 * Per-user conversation storage.
 *
 * State shape (stored as JSON in user meta):
 *   {
 *     "v": 2,
 *     "system": "…",          // system prompt frozen at the start of the conversation
 *     "messages": [ … ],      // Messages API history, assistant turns stored verbatim
 *     "steps": 0              // agent steps used in the current turn
 *   }
 *
 * The history is append-only between compactions. Claude Opus 5.5 binds each thinking block to
 * the exact conversation prefix (system prompt, tools and earlier messages) that produced it;
 * editing an earlier turn invalidates every later thinking block (HTTP 400 on enforced accounts).
 * Old tool results are therefore cleared server-side (context_management), and the stored history
 * is only shrunk at a turn boundary, after first removing all thinking blocks.
 */

defined( 'ABSPATH' ) || exit;

/** Shrink the stored history when its JSON grows beyond this many bytes. */
define( 'WPAIE_HISTORY_MAX_BYTES', 400 * 1024 );

/** How many most recent tool results stay intact when the history is shrunk. */
define( 'WPAIE_HISTORY_KEEP_TOOL_RESULTS', 4 );

const WPAIE_TRIMMED_TOOL_RESULT = '[Výsledek nástroje byl odstraněn kvůli délce konverzace.]';

function wpaie_empty_conversation(): array {
	return [
		'v'        => 2,
		'system'   => '',
		'messages' => [],
		'steps'    => 0,
	];
}

function wpaie_conversation_load( int $user_id = 0 ): array {
	$user_id = $user_id ?: get_current_user_id();
	$raw     = get_user_meta( $user_id, WPAIE_HISTORY_KEY, true );
	if ( ! is_string( $raw ) || $raw === '' ) {
		return wpaie_empty_conversation();
	}

	// Decode to objects (not arrays) so empty JSON objects survive and history is resent verbatim.
	$data = json_decode( $raw );

	if ( is_array( $data ) ) {
		// v1 (plugin 1.x) stored only the message list.
		$state             = wpaie_empty_conversation();
		$state['messages'] = $data;
		return $state;
	}
	if ( ! is_object( $data ) || ! isset( $data->messages ) || ! is_array( $data->messages ) ) {
		wpaie_log( 'Stored conversation is corrupted, starting a new one.', [ 'user' => $user_id ] );
		return wpaie_empty_conversation();
	}

	return [
		'v'        => 2,
		'system'   => is_string( $data->system ?? null ) ? $data->system : '',
		'messages' => $data->messages,
		'steps'    => (int) ( $data->steps ?? 0 ),
	];
}

function wpaie_conversation_save( array $state, int $user_id = 0 ): bool {
	$user_id = $user_id ?: get_current_user_id();
	$json    = wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( $json === false ) {
		wpaie_log( 'Could not encode conversation.', [ 'user' => $user_id ] );
		return false;
	}
	// wp_slash compensates for the wp_unslash that update_metadata applies.
	return (bool) update_user_meta( $user_id, WPAIE_HISTORY_KEY, wp_slash( $json ) );
}

function wpaie_conversation_clear( int $user_id = 0 ): void {
	delete_user_meta( $user_id ?: get_current_user_id(), WPAIE_HISTORY_KEY );
}

/**
 * Append the user's text as a new turn.
 *
 * If the last message is already a user message (a turn that ended on tool results – e.g. after
 * an error or the step limit), the text is appended to it as a block after the tool results,
 * which keeps roles alternating without editing anything earlier.
 */
function wpaie_conversation_add_user_text( array $state, string $text ): array {
	$messages = $state['messages'];
	$last     = $messages ? $messages[ count( $messages ) - 1 ] : null;

	if ( $last && wpaie_prop( $last, 'role' ) === 'user' ) {
		$content = wpaie_prop( $last, 'content' );
		if ( is_string( $content ) ) {
			$content = [ wpaie_to_object( [ 'type' => 'text', 'text' => $content ] ) ];
		}
		$content[]                          = wpaie_to_object( [ 'type' => 'text', 'text' => $text ] );
		$updated                            = clone $last;
		$updated->content                   = $content;
		$messages[ count( $messages ) - 1 ] = $updated;
	} else {
		$messages[] = wpaie_to_object( [ 'role' => 'user', 'content' => $text ] );
	}

	$state['messages'] = $messages;
	$state['steps']    = 0;
	return $state;
}

/**
 * Whether the history ends with tool results waiting for the model's next step.
 */
function wpaie_conversation_is_pending( array $state ): bool {
	$messages = $state['messages'];
	if ( ! $messages ) {
		return false;
	}
	$last = $messages[ count( $messages ) - 1 ];
	if ( wpaie_prop( $last, 'role' ) !== 'user' || ! is_array( wpaie_prop( $last, 'content' ) ) ) {
		return false;
	}
	$blocks = wpaie_prop( $last, 'content' );
	$tail   = end( $blocks );
	return wpaie_prop( $tail, 'type' ) === 'tool_result';
}

/**
 * Remove every thinking block from the history.
 *
 * Removing all of them is always valid; it is used once at a compaction boundary and as the
 * recovery when the API reports a thinking block bound to a different conversation.
 */
function wpaie_strip_thinking( array $messages ): array {
	foreach ( $messages as $i => $message ) {
		if ( wpaie_prop( $message, 'role' ) !== 'assistant' || ! is_array( wpaie_prop( $message, 'content' ) ) ) {
			continue;
		}
		$kept = array_values(
			array_filter(
				$message->content,
				fn( $block ) => ! in_array( wpaie_prop( $block, 'type' ), [ 'thinking', 'redacted_thinking' ], true )
			)
		);
		if ( ! $kept ) {
			$kept = [ wpaie_to_object( [ 'type' => 'text', 'text' => '…' ] ) ];
		}
		$messages[ $i ]          = clone $message;
		$messages[ $i ]->content = $kept;
	}
	return $messages;
}

/**
 * Replace all but the most recent $keep tool results with a short placeholder.
 */
function wpaie_trim_tool_results( array $messages, int $keep ): array {
	$slots = [];
	foreach ( $messages as $mi => $message ) {
		if ( wpaie_prop( $message, 'role' ) !== 'user' || ! is_array( wpaie_prop( $message, 'content' ) ) ) {
			continue;
		}
		foreach ( $message->content as $bi => $block ) {
			if ( wpaie_prop( $block, 'type' ) === 'tool_result' ) {
				$slots[] = [ $mi, $bi ];
			}
		}
	}
	$cut = count( $slots ) - $keep;
	for ( $i = 0; $i < $cut; $i++ ) {
		[ $mi, $bi ] = $slots[ $i ];
		// Clone so the caller's objects are not modified in place.
		$message                  = clone $messages[ $mi ];
		$block                    = clone $message->content[ $bi ];
		$block->content           = WPAIE_TRIMMED_TOOL_RESULT;
		unset( $block->is_error );
		$message->content[ $bi ]  = $block;
		$messages[ $mi ]          = $message;
	}
	return $messages;
}

/**
 * Is this message the start of a user turn (typed text, not a tool-result message)?
 */
function wpaie_is_turn_start( $message ): bool {
	if ( wpaie_prop( $message, 'role' ) !== 'user' ) {
		return false;
	}
	$content = wpaie_prop( $message, 'content' );
	if ( is_string( $content ) ) {
		return true;
	}
	foreach ( (array) $content as $block ) {
		if ( wpaie_prop( $block, 'type' ) === 'tool_result' ) {
			return false;
		}
	}
	return true;
}

/**
 * Shrink an oversized history. Only call at a turn boundary (before adding a new user message).
 */
function wpaie_conversation_compact( array $state, int $max_bytes = WPAIE_HISTORY_MAX_BYTES ): array {
	$size = fn( array $m ): int => strlen( (string) wp_json_encode( $m, JSON_UNESCAPED_UNICODE ) );

	$messages = $state['messages'];
	if ( $size( $messages ) <= $max_bytes ) {
		return $state;
	}

	$messages = wpaie_strip_thinking( $messages );
	$messages = wpaie_trim_tool_results( $messages, WPAIE_HISTORY_KEEP_TOOL_RESULTS );

	// Still too big: drop the oldest whole turns (the history must start with a user turn).
	while ( $size( $messages ) > $max_bytes && count( $messages ) > 2 ) {
		$next = null;
		for ( $i = 1, $n = count( $messages ); $i < $n; $i++ ) {
			if ( wpaie_is_turn_start( $messages[ $i ] ) ) {
				$next = $i;
				break;
			}
		}
		if ( $next === null ) {
			break;
		}
		$messages = array_slice( $messages, $next );
	}

	$state['messages'] = array_values( $messages );
	return $state;
}

/**
 * Turn the stored history into display items for the chat transcript.
 *
 * @return array<int,array{role:string,html?:string,tools?:string[]}>
 */
function wpaie_conversation_transcript( array $state ): array {
	$items = [];
	foreach ( $state['messages'] as $message ) {
		$role    = wpaie_prop( $message, 'role' );
		$content = wpaie_prop( $message, 'content' );

		if ( $role === 'user' ) {
			$texts = is_string( $content ) ? [ $content ] : [];
			foreach ( (array) ( is_array( $content ) ? $content : [] ) as $block ) {
				if ( wpaie_prop( $block, 'type' ) === 'text' ) {
					$texts[] = (string) wpaie_prop( $block, 'text', '' );
				}
			}
			foreach ( $texts as $text ) {
				$items[] = [ 'role' => 'user', 'text' => $text ];
			}
			continue;
		}

		if ( $role === 'assistant' && is_array( $content ) ) {
			$tools = [];
			$text  = '';
			foreach ( $content as $block ) {
				$type = wpaie_prop( $block, 'type' );
				if ( $type === 'text' ) {
					$text .= (string) wpaie_prop( $block, 'text', '' );
				} elseif ( $type === 'tool_use' ) {
					$tools[] = wpaie_tool_label( (string) wpaie_prop( $block, 'name', '' ), wpaie_to_array( wpaie_prop( $block, 'input' ) ) );
				}
			}
			if ( trim( $text ) !== '' || $tools ) {
				$items[] = [
					'role'  => 'ai',
					'html'  => trim( $text ) !== '' ? wpaie_format_ai_html( trim( $text ) ) : '',
					'tools' => $tools,
				];
			}
		}
	}
	return $items;
}
