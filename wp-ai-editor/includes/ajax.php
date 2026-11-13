<?php
/**
 * AJAX endpoints used by the admin chat, the history and the settings page.
 *
 * Responses are JSON { success, data } – or, for chat steps requested with stream=1, a
 * text/event-stream with "text"/"status" events followed by one "result" or "error" event.
 */

defined( 'ABSPATH' ) || exit;

/** Longest accepted user message (characters). */
define( 'WPAIE_MAX_MESSAGE_CHARS', 20000 );

add_action( 'wp_ajax_wpaie_chat', 'wpaie_ajax_chat' );
add_action( 'wp_ajax_wpaie_continue', 'wpaie_ajax_continue' );
add_action( 'wp_ajax_wpaie_decide', 'wpaie_ajax_decide' );
add_action( 'wp_ajax_wpaie_undo', 'wpaie_ajax_undo' );
add_action( 'wp_ajax_wpaie_new_conversation', 'wpaie_ajax_new_conversation' );
add_action( 'wp_ajax_wpaie_switch_conversation', 'wpaie_ajax_switch_conversation' );
add_action( 'wp_ajax_wpaie_delete_conversation', 'wpaie_ajax_delete_conversation' );
add_action( 'wp_ajax_wpaie_test_connection', 'wpaie_ajax_test_connection' );

// ─── Responses ────────────────────────────────────────────────────────────────

/**
 * Send an error (JSON, or an "error" event once a stream has started) and stop.
 */
function wpaie_ajax_fail( string $message, string $code = 'error', int $status = 200 ): void {
	$data = [
		'message' => $message,
		'code'    => $code,
	];
	if ( function_exists( 'wpaie_stream_started' ) && wpaie_stream_started() ) {
		wpaie_stream_emit( 'error', $data );
		exit;
	}
	wp_send_json_error( $data, $status );
}

function wpaie_ajax_success( array $data ): void {
	if ( function_exists( 'wpaie_stream_started' ) && wpaie_stream_started() ) {
		wpaie_stream_emit( 'result', $data );
		exit;
	}
	wp_send_json_success( $data );
}

function wpaie_ajax_guard( bool $admin_only = false ): void {
	if ( ! check_ajax_referer( 'wpaie_nonce', 'nonce', false ) ) {
		wpaie_ajax_fail( __( 'This page has expired. Please reload it (F5) and try again.', 'wp-ai-editor' ), 'nonce', 403 );
	}
	if ( $admin_only ? ! current_user_can( WPAIE_CAPABILITY ) : ! wpaie_user_can_chat() ) {
		wpaie_ajax_fail( __( 'You are not allowed to use AI Editor.', 'wp-ai-editor' ), 'forbidden', 403 );
	}
}

function wpaie_require_api_key(): string {
	$key = wpaie_get_api_key();
	if ( $key === '' ) {
		wpaie_ajax_fail( __( 'The Anthropic API key is missing. Set it in AI Editor → Settings.', 'wp-ai-editor' ), 'no_key' );
	}
	return $key;
}

/**
 * Per-user lock so two tabs (or a double click) cannot run the agent on the same history at once.
 */
function wpaie_acquire_lock(): void {
	$key = 'wpaie_lock_' . get_current_user_id();
	if ( get_transient( $key ) ) {
		wpaie_ajax_fail( __( 'A previous request is still running (maybe in another tab). Please wait for it to finish.', 'wp-ai-editor' ), 'locked' );
	}
	set_transient( $key, time(), wpaie_http_timeout( wpaie_get_settings()['model'] ) + 120 );
	register_shutdown_function( 'wpaie_release_lock' );
}

function wpaie_release_lock(): void {
	delete_transient( 'wpaie_lock_' . get_current_user_id() );
}

function wpaie_request_conversation_id(): string {
	$id = isset( $_POST['conversation'] ) ? sanitize_key( wp_unslash( $_POST['conversation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in wpaie_ajax_guard().
	return wpaie_valid_conversation_id( $id ) ? $id : '';
}

/**
 * Callback for live progress, when the browser asked for a stream (null otherwise).
 */
function wpaie_progress_emitter(): ?callable {
	if ( ! function_exists( 'wpaie_stream_requested' ) || ! wpaie_stream_requested() ) {
		return null;
	}
	wpaie_stream_begin();
	return 'wpaie_stream_emit';
}

/**
 * Persist the state and send the step result to the browser.
 */
function wpaie_send_step_result( array $state, array $result ): void {
	if ( ! wpaie_conversation_save( $state ) ) {
		wpaie_log( 'Saving the conversation failed.' );
	}
	wpaie_set_active_conversation( $state['id'] );

	wpaie_ajax_success(
		[
			'done'              => (bool) $result['done'],
			'awaiting'          => (bool) $result['awaiting'],
			'reply_html'        => $result['reply'] !== '' ? wpaie_format_ai_html( $result['reply'] ) : '',
			'tool_calls'        => array_values( $result['tool_calls'] ),
			'changes'           => array_values( $result['changes'] ),
			'approvals'         => array_values( $result['approvals'] ),
			'cost'              => (float) $result['cost'],
			'conversation'      => $state['id'],
			'conversation_cost' => (float) $state['usage']['cost'],
		]
	);
}

// ─── Chat ─────────────────────────────────────────────────────────────────────

/**
 * Start a new turn with the user's message and run the first agent step.
 */
function wpaie_ajax_chat(): void {
	wpaie_ajax_guard();
	$api_key = wpaie_require_api_key();

	$message = isset( $_POST['message'] ) && is_string( $_POST['message'] ) ? trim( wp_unslash( $_POST['message'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- free text for the model, never output unescaped; nonce verified in wpaie_ajax_guard().
	if ( $message === '' ) {
		wpaie_ajax_fail( __( 'The message is empty.', 'wp-ai-editor' ), 'empty' );
	}
	if ( mb_strlen( $message ) > WPAIE_MAX_MESSAGE_CHARS ) {
		wpaie_ajax_fail(
			sprintf(
				/* translators: %d: maximum number of characters */
				__( 'The message is too long (max. %d characters). Please split it into smaller parts.', 'wp-ai-editor' ),
				WPAIE_MAX_MESSAGE_CHARS
			),
			'too_long'
		);
	}

	wpaie_acquire_lock();

	$state = wpaie_conversation_load( wpaie_request_conversation_id() );
	if ( ! $state['messages'] ) {
		// Frozen for the whole conversation (see conversation.php).
		$state['system'] = wpaie_system_prompt();
		$state['tools']  = wpaie_available_tool_names();
	} elseif ( $state['system'] === '' ) {
		$state['system'] = wpaie_system_prompt();
	}

	$extra = function_exists( 'wpaie_request_extra_blocks' ) ? wpaie_request_extra_blocks( $state, $api_key ) : [];
	if ( is_wp_error( $extra ) ) {
		wpaie_ajax_fail( $extra->get_error_message(), $extra->get_error_code() );
	}

	$skipped = (bool) $state['pending'];
	wpaie_agent_skip_pending( $state, $api_key );
	$state = wpaie_conversation_compact( $state );
	$state = wpaie_conversation_add_user_text( $state, $message, $extra );
	if ( $skipped ) {
		// Tools of the skipped turn may have run already – keep that in the history.
		wpaie_conversation_save( $state );
	}

	$emit   = wpaie_progress_emitter();
	$result = wpaie_agent_step( $state, wpaie_get_settings(), $api_key, $emit );
	if ( is_wp_error( $result ) ) {
		// Nothing ran yet: keep the last good state, the user can simply resend the message.
		wpaie_ajax_fail( $result->get_error_message(), $result->get_error_code() );
	}
	wpaie_send_step_result( $state, $result );
}

/**
 * Run the next agent step of an unfinished turn (history ends with tool results).
 */
function wpaie_ajax_continue(): void {
	wpaie_ajax_guard();
	$api_key = wpaie_require_api_key();
	wpaie_acquire_lock();

	$state = wpaie_conversation_load( wpaie_request_conversation_id() );
	if ( $state['pending'] ) {
		wpaie_ajax_fail( __( 'Please approve or reject the proposed change first.', 'wp-ai-editor' ), 'awaiting' );
	}
	if ( ! wpaie_conversation_is_pending( $state ) ) {
		wpaie_ajax_fail( __( 'There is nothing to continue – send a new message.', 'wp-ai-editor' ), 'not_pending' );
	}

	$emit   = wpaie_progress_emitter();
	$result = wpaie_agent_step( $state, wpaie_get_settings(), $api_key, $emit );
	if ( is_wp_error( $result ) ) {
		// Changes made by earlier steps stay saved; the next message continues from them.
		wpaie_ajax_fail(
			$result->get_error_message() . ' ' . __( 'Changes made in the previous steps are saved – write "continue" to finish the task.', 'wp-ai-editor' ),
			$result->get_error_code()
		);
	}
	wpaie_send_step_result( $state, $result );
}

/**
 * Approve or reject the change(s) waiting for approval.
 */
function wpaie_ajax_decide(): void {
	wpaie_ajax_guard();
	$api_key = wpaie_require_api_key();
	wpaie_acquire_lock();

	$approve = isset( $_POST['approve'] ) && $_POST['approve'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in wpaie_ajax_guard().
	$state   = wpaie_conversation_load( wpaie_request_conversation_id() );

	$emit   = wpaie_progress_emitter();
	$result = wpaie_agent_decide( $state, $approve, $api_key, $emit );
	if ( is_wp_error( $result ) ) {
		wpaie_ajax_fail( $result->get_error_message(), $result->get_error_code() );
	}
	wpaie_send_step_result( $state, $result );
}

// ─── Undo ─────────────────────────────────────────────────────────────────────

function wpaie_ajax_undo(): void {
	wpaie_ajax_guard();
	$id     = isset( $_POST['change'] ) ? absint( $_POST['change'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in wpaie_ajax_guard().
	$result = wpaie_undo_change( $id );
	if ( is_wp_error( $result ) ) {
		wpaie_ajax_fail( $result->get_error_message(), $result->get_error_code() );
	}
	wpaie_ajax_success( [ 'message' => __( 'The change was undone.', 'wp-ai-editor' ) ] );
}

// ─── Conversations ────────────────────────────────────────────────────────────

function wpaie_ajax_new_conversation(): void {
	wpaie_ajax_guard();
	wpaie_set_active_conversation( '' );
	wpaie_ajax_success( [] );
}

function wpaie_ajax_switch_conversation(): void {
	wpaie_ajax_guard();
	$id = wpaie_request_conversation_id();
	if ( ! in_array( $id, array_column( wpaie_conversations_list(), 'id' ), true ) ) {
		wpaie_ajax_fail( __( 'This conversation no longer exists.', 'wp-ai-editor' ), 'not_found' );
	}
	wpaie_set_active_conversation( $id );
	wpaie_ajax_success( [] );
}

function wpaie_ajax_delete_conversation(): void {
	wpaie_ajax_guard();
	wpaie_conversation_delete( wpaie_request_conversation_id() );
	wpaie_ajax_success( [] );
}

// ─── Settings ─────────────────────────────────────────────────────────────────

function wpaie_ajax_test_connection(): void {
	wpaie_ajax_guard( true );
	$result = wpaie_test_connection( wpaie_get_api_key(), wpaie_get_settings()['model'] );
	if ( is_wp_error( $result ) ) {
		wpaie_ajax_fail( $result->get_error_message(), $result->get_error_code() );
	}
	wpaie_ajax_success( [ 'message' => $result ] );
}
