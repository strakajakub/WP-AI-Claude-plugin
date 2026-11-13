<?php
/**
 * Per-user conversation storage (several conversations per user).
 *
 * User meta:
 *   wpaie_conversations        JSON index: [ { id, title, updated, cost } ]
 *   wpaie_conv_<id>            JSON state of one conversation (below)
 *   wpaie_active_conversation  ID of the conversation shown in the chat
 *
 * State:
 *   {
 *     "v": 3, "id": "…", "title": "…", "created": 0, "updated": 0,
 *     "system": "…",        // system prompt frozen at the start of the conversation
 *     "tools": [ … ],       // tool names frozen at the start of the conversation
 *     "messages": [ … ],    // Messages API history, assistant turns stored verbatim
 *     "steps": 0,           // agent steps used in the current turn
 *     "pending": null,      // tool calls waiting for the user's approval { uses: [...], needs: [ids] }
 *     "change_map": {},     // tool_use id => change id (for undo buttons in the transcript)
 *     "files": {},          // attachment id => Files API file id (images sent to the model)
 *     "usage": { "cost": 0, "input": 0, "output": 0 }
 *   }
 *
 * The history is append-only between compactions. Claude binds each thinking block to the exact
 * conversation prefix (system prompt, tools and earlier messages) that produced it; editing an
 * earlier turn invalidates every later thinking block (HTTP 400 on enforced accounts). Old tool
 * results are therefore cleared server-side (context_management), and the stored history is only
 * shrunk at a turn boundary, after first removing all thinking blocks.
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAIE_CONVERSATIONS_KEY', 'wpaie_conversations' );
define( 'WPAIE_CONVERSATION_PREFIX', 'wpaie_conv_' );
define( 'WPAIE_ACTIVE_CONVERSATION_KEY', 'wpaie_active_conversation' );

/** Maximum number of stored conversations per user (oldest are deleted). */
define( 'WPAIE_MAX_CONVERSATIONS', 30 );

/** Shrink the stored history when its JSON grows beyond this many bytes. */
define( 'WPAIE_HISTORY_MAX_BYTES', 400 * 1024 );

/** How many most recent tool results stay intact when the history is shrunk. */
define( 'WPAIE_HISTORY_KEEP_TOOL_RESULTS', 4 );

const WPAIE_TRIMMED_TOOL_RESULT = '[Tool result removed to keep the conversation short.]';

/** Prefixes of text blocks the plugin adds to user messages (hidden or rendered specially in the transcript). */
const WPAIE_CONTEXT_PREFIX = '[Context: ';
const WPAIE_IMAGE_PREFIX   = '[Attached image: Media Library attachment #';

function wpaie_new_conversation_id(): string {
	return bin2hex( random_bytes( 6 ) );
}

function wpaie_empty_conversation( string $id = '' ): array {
	return [
		'v'          => 3,
		'id'         => $id !== '' ? $id : wpaie_new_conversation_id(),
		'title'      => '',
		'created'    => time(),
		'updated'    => time(),
		'system'     => '',
		'tools'      => [],
		'messages'   => [],
		'steps'      => 0,
		'pending'    => null,
		'change_map' => [],
		'files'      => [],
		'usage'      => [
			'cost'   => 0.0,
			'input'  => 0,
			'output' => 0,
		],
	];
}

function wpaie_valid_conversation_id( string $id ): bool {
	return (bool) preg_match( '/^[a-f0-9]{12}$/', $id );
}

// ─── Index ────────────────────────────────────────────────────────────────────

/**
 * @return array<int,array{id:string,title:string,updated:int,cost:float}> newest first
 */
function wpaie_conversations_list( int $user_id = 0 ): array {
	$user_id = $user_id ?: get_current_user_id();
	wpaie_migrate_legacy_history( $user_id );

	$index = json_decode( (string) get_user_meta( $user_id, WPAIE_CONVERSATIONS_KEY, true ), true );
	$index = is_array( $index ) ? array_values( array_filter( $index, fn( $c ) => is_array( $c ) && wpaie_valid_conversation_id( (string) ( $c['id'] ?? '' ) ) ) ) : [];
	usort( $index, fn( $a, $b ) => ( $b['updated'] ?? 0 ) <=> ( $a['updated'] ?? 0 ) );
	return $index;
}

function wpaie_conversations_save_index( array $index, int $user_id ): void {
	update_user_meta( $user_id, WPAIE_CONVERSATIONS_KEY, wp_slash( (string) wp_json_encode( array_values( $index ), JSON_UNESCAPED_UNICODE ) ) );
}

function wpaie_active_conversation_id( int $user_id = 0 ): string {
	$user_id = $user_id ?: get_current_user_id();
	$id      = (string) get_user_meta( $user_id, WPAIE_ACTIVE_CONVERSATION_KEY, true );
	foreach ( wpaie_conversations_list( $user_id ) as $c ) {
		if ( $c['id'] === $id ) {
			return $id;
		}
	}
	return '';
}

function wpaie_set_active_conversation( string $id, int $user_id = 0 ): void {
	update_user_meta( $user_id ?: get_current_user_id(), WPAIE_ACTIVE_CONVERSATION_KEY, $id );
}

/**
 * Plugin 2.0 stored one conversation in wpaie_history (1.x: a bare message list). Move it.
 */
function wpaie_migrate_legacy_history( int $user_id ): void {
	$raw = get_user_meta( $user_id, 'wpaie_history', true );
	if ( ! is_string( $raw ) || $raw === '' ) {
		return;
	}
	delete_user_meta( $user_id, 'wpaie_history' );

	$data  = json_decode( $raw );
	$state = wpaie_empty_conversation();
	if ( is_array( $data ) ) {
		$state['messages'] = $data;
	} elseif ( is_object( $data ) && isset( $data->messages ) && is_array( $data->messages ) ) {
		$state['messages'] = $data->messages;
		$state['system']   = is_string( $data->system ?? null ) ? $data->system : '';
	}
	if ( ! $state['messages'] ) {
		return;
	}
	// 2.0 conversations used the full tool set of that version; they keep working with the
	// current one, because the tools are only fixed for conversations started from now on.
	$state['tools'] = wpaie_available_tool_names();
	wpaie_conversation_save( $state, $user_id );
	wpaie_set_active_conversation( $state['id'], $user_id );
}

// ─── Load / save / delete ─────────────────────────────────────────────────────

/**
 * Load a conversation; an empty or unknown ID gives a new, unsaved conversation.
 */
function wpaie_conversation_load( string $id = '', int $user_id = 0 ): array {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! wpaie_valid_conversation_id( $id ) ) {
		return wpaie_empty_conversation();
	}

	$raw = get_user_meta( $user_id, WPAIE_CONVERSATION_PREFIX . $id, true );
	if ( ! is_string( $raw ) || $raw === '' ) {
		return wpaie_empty_conversation();
	}

	// Decode to objects (not arrays) so empty JSON objects survive and history is resent verbatim.
	$data = json_decode( $raw );
	if ( ! is_object( $data ) || ! isset( $data->messages ) || ! is_array( $data->messages ) ) {
		wpaie_log( 'Stored conversation is corrupted, starting a new one.', [ 'user' => $user_id, 'id' => $id ] );
		return wpaie_empty_conversation();
	}

	$state = wpaie_empty_conversation( $id );
	foreach ( [ 'title', 'system' ] as $key ) {
		$state[ $key ] = is_string( $data->$key ?? null ) ? $data->$key : '';
	}
	foreach ( [ 'created', 'updated', 'steps' ] as $key ) {
		$state[ $key ] = (int) ( $data->$key ?? $state[ $key ] );
	}
	$state['tools']      = is_array( $data->tools ?? null ) ? array_map( 'strval', $data->tools ) : [];
	$state['messages']   = $data->messages;
	$state['pending']    = is_object( $data->pending ?? null ) ? $data->pending : null;
	$state['change_map'] = wpaie_to_array( $data->change_map ?? [] );
	$state['files']      = wpaie_to_array( $data->files ?? [] );
	$usage               = wpaie_to_array( $data->usage ?? [] );
	$state['usage']      = [
		'cost'   => (float) ( $usage['cost'] ?? 0 ),
		'input'  => (int) ( $usage['input'] ?? 0 ),
		'output' => (int) ( $usage['output'] ?? 0 ),
	];
	return $state;
}

function wpaie_conversation_save( array $state, int $user_id = 0 ): bool {
	$user_id          = $user_id ?: get_current_user_id();
	$state['updated'] = time();
	if ( $state['title'] === '' ) {
		$state['title'] = wpaie_conversation_title( $state );
	}

	$json = wp_json_encode(
		[
			'change_map' => (object) $state['change_map'],
			'files'      => (object) $state['files'],
		] + $state,
		JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
	);
	if ( $json === false ) {
		wpaie_log( 'Could not encode conversation.', [ 'user' => $user_id ] );
		return false;
	}
	// wp_slash compensates for the wp_unslash that update_metadata applies.
	update_user_meta( $user_id, WPAIE_CONVERSATION_PREFIX . $state['id'], wp_slash( $json ) );

	$index = array_values( array_filter( wpaie_conversations_list( $user_id ), fn( $c ) => $c['id'] !== $state['id'] ) );
	array_unshift(
		$index,
		[
			'id'      => $state['id'],
			'title'   => $state['title'],
			'updated' => $state['updated'],
			'cost'    => round( (float) $state['usage']['cost'], 4 ),
		]
	);
	foreach ( array_slice( $index, WPAIE_MAX_CONVERSATIONS ) as $old ) {
		wpaie_conversation_delete_data( $old['id'], $user_id );
	}
	wpaie_conversations_save_index( array_slice( $index, 0, WPAIE_MAX_CONVERSATIONS ), $user_id );
	return true;
}

function wpaie_conversation_delete( string $id, int $user_id = 0 ): void {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! wpaie_valid_conversation_id( $id ) ) {
		return;
	}
	wpaie_conversation_delete_data( $id, $user_id );
	wpaie_conversations_save_index( array_filter( wpaie_conversations_list( $user_id ), fn( $c ) => $c['id'] !== $id ), $user_id );
	if ( (string) get_user_meta( $user_id, WPAIE_ACTIVE_CONVERSATION_KEY, true ) === $id ) {
		delete_user_meta( $user_id, WPAIE_ACTIVE_CONVERSATION_KEY );
	}
}

/**
 * Delete the stored state, including files uploaded to the Files API for it (best effort).
 */
function wpaie_conversation_delete_data( string $id, int $user_id ): void {
	$state = wpaie_conversation_load( $id, $user_id );
	if ( $state['files'] && function_exists( 'wpaie_files_delete' ) ) {
		$api_key = wpaie_get_api_key();
		foreach ( $state['files'] as $file_id ) {
			wpaie_files_delete( $api_key, (string) $file_id );
		}
	}
	delete_user_meta( $user_id, WPAIE_CONVERSATION_PREFIX . $id );
}

function wpaie_conversation_title( array $state ): string {
	foreach ( $state['messages'] as $message ) {
		if ( wpaie_prop( $message, 'role' ) !== 'user' ) {
			continue;
		}
		foreach ( wpaie_user_texts( $message ) as $text ) {
			$text = trim( preg_replace( '/\s+/', ' ', $text ) );
			if ( $text !== '' ) {
				return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '…' : $text;
			}
		}
	}
	return '';
}

// ─── Messages ─────────────────────────────────────────────────────────────────

/**
 * Text typed by the user in a user message (without plugin-added context/image notes).
 *
 * @return string[]
 */
function wpaie_user_texts( $message ): array {
	$content = wpaie_prop( $message, 'content' );
	if ( is_string( $content ) ) {
		return [ $content ];
	}
	$texts = [];
	foreach ( (array) $content as $block ) {
		if ( wpaie_prop( $block, 'type' ) !== 'text' ) {
			continue;
		}
		$text = (string) wpaie_prop( $block, 'text', '' );
		if ( str_starts_with( $text, WPAIE_CONTEXT_PREFIX ) || str_starts_with( $text, WPAIE_IMAGE_PREFIX ) ) {
			continue;
		}
		$texts[] = $text;
	}
	return $texts;
}

/**
 * Append the user's input as a new turn.
 *
 * @param array $extra_blocks Content blocks placed before the text (images, context notes).
 *
 * If the last message is already a user message (a turn that ended on tool results – e.g. after
 * an error or the step limit), the blocks are appended to it after the tool results, which keeps
 * roles alternating without editing anything earlier.
 */
function wpaie_conversation_add_user_text( array $state, string $text, array $extra_blocks = [] ): array {
	$messages = $state['messages'];
	$last     = $messages ? $messages[ count( $messages ) - 1 ] : null;
	$blocks   = array_merge( $extra_blocks, [ [ 'type' => 'text', 'text' => $text ] ] );
	$blocks   = array_map( 'wpaie_to_object', $blocks );

	if ( $last && wpaie_prop( $last, 'role' ) === 'user' ) {
		$content = wpaie_prop( $last, 'content' );
		if ( is_string( $content ) ) {
			$content = [ wpaie_to_object( [ 'type' => 'text', 'text' => $content ] ) ];
		}
		$updated                            = clone $last;
		$updated->content                   = array_merge( $content, $blocks );
		$messages[ count( $messages ) - 1 ] = $updated;
	} elseif ( ! $extra_blocks ) {
		$messages[] = wpaie_to_object( [ 'role' => 'user', 'content' => $text ] );
	} else {
		$messages[] = (object) [
			'role'    => 'user',
			'content' => $blocks,
		];
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
		$message                 = clone $messages[ $mi ];
		$block                   = clone $message->content[ $bi ];
		$block->content          = WPAIE_TRIMMED_TOOL_RESULT;
		unset( $block->is_error );
		$message->content[ $bi ] = $block;
		$messages[ $mi ]         = $message;
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
 * @return array<int,array> { role: user, text, images[] } | { role: ai, html, tools[], changes[] }
 */
function wpaie_conversation_transcript( array $state ): array {
	$items = [];
	foreach ( $state['messages'] as $message ) {
		$role    = wpaie_prop( $message, 'role' );
		$content = wpaie_prop( $message, 'content' );

		if ( $role === 'user' ) {
			$images = [];
			foreach ( is_array( $content ) ? $content : [] as $block ) {
				$text = (string) wpaie_prop( $block, 'text', '' );
				if ( wpaie_prop( $block, 'type' ) === 'text' && str_starts_with( $text, WPAIE_IMAGE_PREFIX ) ) {
					$images[] = (int) substr( $text, strlen( WPAIE_IMAGE_PREFIX ) );
				}
			}
			$texts = array_values( array_filter( wpaie_user_texts( $message ), fn( $t ) => trim( $t ) !== '' ) );
			foreach ( $texts as $i => $text ) {
				$items[] = [
					'role'   => 'user',
					'text'   => $text,
					'images' => $i === 0 ? $images : [],
				];
			}
			continue;
		}

		if ( $role === 'assistant' && is_array( $content ) ) {
			$tools   = [];
			$changes = [];
			$text    = '';
			foreach ( $content as $block ) {
				$type = wpaie_prop( $block, 'type' );
				if ( $type === 'text' ) {
					$text .= (string) wpaie_prop( $block, 'text', '' );
				} elseif ( $type === 'tool_use' ) {
					$tools[] = wpaie_tool_label( (string) wpaie_prop( $block, 'name', '' ), wpaie_to_array( wpaie_prop( $block, 'input' ) ) );
					foreach ( (array) ( $state['change_map'][ (string) wpaie_prop( $block, 'id', '' ) ] ?? [] ) as $change ) {
						$changes[] = $change;
					}
				}
			}
			if ( trim( $text ) !== '' || $tools ) {
				$items[] = [
					'role'    => 'ai',
					'html'    => trim( $text ) !== '' ? wpaie_format_ai_html( trim( $text ) ) : '',
					'tools'   => $tools,
					'changes' => $changes,
				];
			}
		}
	}
	return $items;
}
