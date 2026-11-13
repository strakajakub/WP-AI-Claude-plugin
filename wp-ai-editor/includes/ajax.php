<?php
/**
 * AJAX endpoints used by the admin chat and the settings page.
 *
 * All responses are JSON: { success: true, data: {...} } or { success: false, data: { message, code } }.
 */

defined( 'ABSPATH' ) || exit;

/** Longest accepted user message (characters). */
define( 'WPAIE_MAX_MESSAGE_CHARS', 20000 );

add_action( 'wp_ajax_wpaie_chat', 'wpaie_ajax_chat' );
add_action( 'wp_ajax_wpaie_continue', 'wpaie_ajax_continue' );
add_action( 'wp_ajax_wpaie_reset', 'wpaie_ajax_reset' );
add_action( 'wp_ajax_wpaie_test_connection', 'wpaie_ajax_test_connection' );

/**
 * @return never
 */
function wpaie_ajax_fail( string $message, string $code = 'error', int $status = 200 ): void {
	wp_send_json_error(
		[
			'message' => $message,
			'code'    => $code,
		],
		$status
	);
}

function wpaie_ajax_guard(): void {
	if ( ! check_ajax_referer( 'wpaie_nonce', 'nonce', false ) ) {
		wpaie_ajax_fail( 'Platnost stránky vypršela. Obnov ji prosím (F5) a zkus to znovu.', 'nonce', 403 );
	}
	if ( ! current_user_can( WPAIE_CAPABILITY ) ) {
		wpaie_ajax_fail( 'Na použití AI Editoru nemáš oprávnění (vyžaduje roli administrátora).', 'forbidden', 403 );
	}
}

function wpaie_require_api_key(): string {
	$key = wpaie_get_api_key();
	if ( $key === '' ) {
		wpaie_ajax_fail( 'Chybí API klíč Anthropic. Nastav ho v AI Editor → Nastavení.', 'no_key' );
	}
	return $key;
}

/**
 * Per-user lock so two tabs (or a double click) cannot run the agent on the same history at once.
 */
function wpaie_acquire_lock(): void {
	$key = 'wpaie_lock_' . get_current_user_id();
	if ( get_transient( $key ) ) {
		wpaie_ajax_fail( 'Předchozí požadavek ještě běží (možná v jiné záložce). Počkej prosím, než doběhne.', 'locked' );
	}
	set_transient( $key, time(), WPAIE_HTTP_TIMEOUT + 120 );
	register_shutdown_function( 'wpaie_release_lock' );
}

function wpaie_release_lock(): void {
	delete_transient( 'wpaie_lock_' . get_current_user_id() );
}

/**
 * Persist the state and send the step result to the browser.
 */
function wpaie_send_step_result( array $state, array $result ): void {
	if ( ! wpaie_conversation_save( $state ) ) {
		wpaie_log( 'Saving the conversation failed.' );
	}
	wp_send_json_success(
		[
			'done'       => (bool) $result['done'],
			'reply_html' => $result['reply'] !== '' ? wpaie_format_ai_html( $result['reply'] ) : '',
			'tool_calls' => array_values( $result['tool_calls'] ),
		]
	);
}

/**
 * Start a new turn with the user's message and run the first agent step.
 */
function wpaie_ajax_chat(): void {
	wpaie_ajax_guard();
	$api_key = wpaie_require_api_key();

	$message = isset( $_POST['message'] ) && is_string( $_POST['message'] ) ? trim( wp_unslash( $_POST['message'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- free text for the model, never output unescaped.
	if ( $message === '' ) {
		wpaie_ajax_fail( 'Zpráva je prázdná.', 'empty' );
	}
	if ( mb_strlen( $message ) > WPAIE_MAX_MESSAGE_CHARS ) {
		wpaie_ajax_fail( sprintf( 'Zpráva je příliš dlouhá (max. %d znaků). Rozděl ji prosím na menší části.', WPAIE_MAX_MESSAGE_CHARS ), 'too_long' );
	}

	wpaie_acquire_lock();

	$state = wpaie_conversation_load();
	if ( ! $state['messages'] || $state['system'] === '' ) {
		// Frozen for the whole conversation (see conversation.php).
		$state['system'] = wpaie_system_prompt();
	}
	$state = wpaie_conversation_compact( $state );
	$state = wpaie_conversation_add_user_text( $state, $message );

	$result = wpaie_agent_step( $state, wpaie_get_settings(), $api_key );
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

	$state = wpaie_conversation_load();
	if ( ! wpaie_conversation_is_pending( $state ) ) {
		wpaie_ajax_fail( 'Není co dokončovat – pošli novou zprávu.', 'not_pending' );
	}

	$result = wpaie_agent_step( $state, wpaie_get_settings(), $api_key );
	if ( is_wp_error( $result ) ) {
		// Changes made by earlier steps stay saved; the next message continues from them.
		wpaie_ajax_fail(
			$result->get_error_message() . ' Změny provedené v předchozích krocích zůstaly uložené – napiš „pokračuj“ pro dokončení úkolu.',
			$result->get_error_code()
		);
	}
	wpaie_send_step_result( $state, $result );
}

function wpaie_ajax_reset(): void {
	wpaie_ajax_guard();
	wpaie_conversation_clear();
	wp_send_json_success();
}

function wpaie_ajax_test_connection(): void {
	wpaie_ajax_guard();
	$result = wpaie_test_connection( wpaie_get_api_key(), wpaie_get_settings()['model'] );
	if ( is_wp_error( $result ) ) {
		wpaie_ajax_fail( $result->get_error_message(), $result->get_error_code() );
	}
	wp_send_json_success( [ 'message' => $result ] );
}
