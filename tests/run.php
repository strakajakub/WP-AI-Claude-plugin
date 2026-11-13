<?php
/**
 * Dependency-free test runner: php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

$failures = 0;
$count    = 0;

function check( bool $condition, string $message ): void {
	global $failures, $count;
	$count++;
	if ( ! $condition ) {
		$failures++;
		echo "  ✖ $message\n";
	}
}

function test( string $name, callable $fn ): void {
	wpaie_test_reset();
	echo "• $name\n";
	$fn();
}

function last_payload( int $index = -1 ): array {
	$requests = $GLOBALS['wp_test']['requests'];
	$request  = $requests[ $index < 0 ? count( $requests ) + $index : $index ];
	return json_decode( $request['args']['body'], true );
}

function api_message( array $content, string $stop = 'end_turn' ): array {
	return [
		'id'          => 'msg_test',
		'type'        => 'message',
		'role'        => 'assistant',
		'model'       => 'claude-opus-5-5',
		'content'     => $content,
		'stop_reason' => $stop,
	];
}

function settings(): array {
	return [ 'model' => 'claude-opus-5-5', 'effort' => 'medium' ];
}

// ─────────────────────────────────────────────────────────────────────────────

test( 'markdown formatting escapes HTML', function () {
	$html = wpaie_format_ai_html( "**Ahoj** <script>x</script>\n`code`" );
	check( str_contains( $html, '<strong>Ahoj</strong>' ), 'bold rendered' );
	check( ! str_contains( $html, '<script>' ), 'script escaped' );
	check( str_contains( $html, '<br>' ) && str_contains( $html, '<code>code</code>' ), 'newline + code rendered' );
} );

test( 'settings: retired model falls back to default, key validated', function () {
	update_option( WPAIE_OPTION_KEY, [ 'api_key' => 'sk-ant-old', 'model' => 'claude-opus-4-8', 'effort' => 'bogus' ] );
	$s = wpaie_get_settings();
	check( $s['model'] === 'claude-opus-5-5', 'retired model migrated' );
	check( $s['effort'] === 'medium', 'invalid effort reset' );

	$out = wpaie_sanitize_settings( [ 'api_key' => '', 'model' => 'claude-haiku-5-5', 'effort' => 'low' ] );
	check( $out['api_key'] === 'sk-ant-old', 'empty key field keeps stored key' );
	check( $out['model'] === 'claude-haiku-5-5' && $out['effort'] === 'low', 'model + effort saved' );

	$out = wpaie_sanitize_settings( [ 'api_key' => 'not-a-key' ] );
	check( $out['api_key'] === 'sk-ant-old', 'malformed key rejected' );
	check( in_array( 'wpaie_key_format', $GLOBALS['wp_test']['settings_errors'], true ), 'error shown for malformed key' );

	$out = wpaie_sanitize_settings( [ 'api_key' => '', 'remove_api_key' => '1' ] );
	check( $out['api_key'] === '', 'key removed' );
} );

test( 'conversation storage round-trips verbatim (empty objects, backslashes, unicode)', function () {
	$state             = wpaie_empty_conversation();
	$state['system']   = 'Systém';
	$state['messages'] = json_decode( '[{"role":"user","content":"Ahoj \\\\ \"svět\""},{"role":"assistant","content":[{"type":"thinking","thinking":"","signature":"abc"},{"type":"tool_use","id":"t1","name":"get_custom_css","input":{}}]}]' );
	wpaie_conversation_save( $state );
	$loaded = wpaie_conversation_load();
	check( wp_json_encode( $loaded['messages'] ) === wp_json_encode( $state['messages'] ), 'messages identical after save/load' );
	check( str_contains( wp_json_encode( $loaded['messages'] ), '"input":{}' ), 'empty tool input stays an object' );
	check( $loaded['system'] === 'Systém', 'system prompt stored' );
} );

test( 'v1 history (plain list) is migrated', function () {
	update_user_meta( 1, WPAIE_HISTORY_KEY, wp_slash( '[{"role":"user","content":"x"},{"role":"assistant","content":[{"type":"text","text":"y"}]}]' ) );
	$state = wpaie_conversation_load();
	check( count( $state['messages'] ) === 2 && $state['system'] === '', 'v1 messages loaded, system regenerated later' );
} );

test( 'user text after pending tool results is appended to that message', function () {
	$state             = wpaie_empty_conversation();
	$state['messages'] = json_decode( '[{"role":"user","content":"a"},{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"x","input":{}}]},{"role":"user","content":[{"type":"tool_result","tool_use_id":"t1","content":"ok"}]}]' );
	check( wpaie_conversation_is_pending( $state ), 'pending detected' );
	$state = wpaie_conversation_add_user_text( $state, 'pokračuj' );
	check( count( $state['messages'] ) === 3, 'no new message added' );
	$last = end( $state['messages'] );
	check( $last->content[1]->type === 'text' && $last->content[1]->text === 'pokračuj', 'text block appended after tool_result' );
	check( ! wpaie_conversation_is_pending( $state ), 'no longer pending' );
} );

test( 'compaction strips thinking, trims old tool results and drops old turns', function () {
	$messages = [];
	for ( $i = 0; $i < 10; $i++ ) {
		$messages[] = [ 'role' => 'user', 'content' => "turn $i" ];
		$messages[] = [ 'role' => 'assistant', 'content' => [ [ 'type' => 'thinking', 'thinking' => '', 'signature' => 's' ], [ 'type' => 'tool_use', 'id' => "t$i", 'name' => 'x', 'input' => new stdClass() ] ] ];
		$messages[] = [ 'role' => 'user', 'content' => [ [ 'type' => 'tool_result', 'tool_use_id' => "t$i", 'content' => str_repeat( 'x', 2000 ) ] ] ];
		$messages[] = [ 'role' => 'assistant', 'content' => [ [ 'type' => 'text', 'text' => "done $i" ] ] ];
	}
	$state             = wpaie_empty_conversation();
	$state['messages'] = wpaie_to_object( $messages );
	$original_json     = wp_json_encode( $state['messages'] );

	$small = wpaie_conversation_compact( $state, 1000000 );
	check( wp_json_encode( $small['messages'] ) === $original_json, 'below limit: untouched' );

	$compacted = wpaie_conversation_compact( $state, 9000 );
	$json      = wp_json_encode( $compacted['messages'] );
	check( wp_json_encode( $state['messages'] ) === $original_json, 'input state not mutated' );
	check( ! str_contains( $json, '"thinking"' ), 'all thinking blocks removed' );
	check( strlen( $json ) <= 9000, 'fits the limit' );
	check( $compacted['messages'][0]->role === 'user' && is_string( $compacted['messages'][0]->content ), 'starts with a user turn' );
	check( str_contains( $json, '"input":{}' ), 'empty tool input preserved' );

	$trimmed = wpaie_conversation_compact( $state, strlen( $original_json ) - 1 );
	$count   = substr_count( wp_json_encode( $trimmed['messages'], JSON_UNESCAPED_UNICODE ), WPAIE_TRIMMED_TOOL_RESULT );
	check( count( $trimmed['messages'] ) === 40, 'trimming alone was enough, no turn dropped' );
	check( $count === 10 - WPAIE_HISTORY_KEEP_TOOL_RESULTS, 'old tool results stubbed, recent kept' );
} );

test( 'agent step: tool call → result, verbatim replay, request shape', function () {
	$GLOBALS['wp_test']['custom_css'] = 'body{color:red}';
	$thinking = [ 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1' ];
	wpaie_test_queue_response( 200, api_message( [ $thinking, [ 'type' => 'text', 'text' => 'Podívám se.' ], [ 'type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_custom_css', 'input' => new stdClass() ] ], 'tool_use' ) );

	$state           = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'Jaké mám CSS?' );
	$state['system'] = 'SYS';
	$result          = wpaie_agent_step( $state, settings(), 'sk-ant-test' );

	check( is_array( $result ) && $result['done'] === false, 'step not done after tool use' );
	check( $result['tool_calls'] === [ 'Reading Additional CSS…' ], 'tool label returned' );
	check( $result['reply'] === 'Podívám se.', 'interim text returned' );
	check( count( $state['messages'] ) === 3, 'assistant + tool_result appended' );
	check( str_contains( $state['messages'][2]->content[0]->content, 'body{color:red}' ), 'tool result content' );

	$req = $GLOBALS['wp_test']['requests'][0];
	$p   = last_payload();
	check( $req['url'] === 'https://api.anthropic.com/v1/messages', 'messages endpoint' );
	check( $req['args']['headers']['anthropic-version'] === '2026-10-21', 'API version 2026-10-21' );
	check( $p['model'] === 'claude-opus-5-5' && $p['thinking']['type'] === 'adaptive', 'model + adaptive thinking' );
	check( $p['output_config']['effort'] === 'medium', 'effort set explicitly' );
	check( ! isset( $p['temperature'] ) && ! isset( $p['top_p'] ) && ! isset( $p['tool_choice'] ), 'no sampling params / forced tool choice' );
	check( $p['fallbacks'] === 'default' && str_contains( $req['args']['headers']['anthropic-beta'], 'server-side-fallback-2026-07-01' ), 'refusal fallbacks enabled' );
	check( $p['thinking']['block_binding']['prefix_mismatch_behavior'] === 'drop_block', 'drop_block set' );
	check( isset( $p['context_management'] ) && $p['cache_control']['type'] === 'ephemeral', 'context mgmt + caching' );
	check( str_contains( $req['args']['body'], '"properties":{}' ), 'empty tool schema properties sent as object' );

	// Next step replays the assistant turn byte-for-byte.
	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'text', 'text' => 'Máš **červený** text.' ] ] ) );
	$result = wpaie_agent_step( $state, settings(), 'sk-ant-test' );
	$body   = $GLOBALS['wp_test']['requests'][1]['args']['body'];
	check( $result['done'] === true && $result['reply'] === 'Máš **červený** text.', 'final reply' );
	check( str_contains( $body, '{"type":"thinking","thinking":"","signature":"sig-1"}' ), 'thinking block replayed unchanged' );
	check( str_contains( $body, '"input":{}' ), 'tool_use input replayed as {}' );
} );

test( 'agent step: haiku has no server-side fallbacks', function () {
	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'text', 'text' => 'ok' ] ] ) );
	$state = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'hi' );
	wpaie_agent_step( $state, [ 'model' => 'claude-haiku-5-5', 'effort' => 'low' ], 'k' );
	check( ! isset( last_payload()['fallbacks'] ), 'no fallbacks for Haiku' );
} );

test( 'agent step: Fable 5.2 request shape', function () {
	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'text', 'text' => 'ok' ] ] ) );
	$state = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'hi' );
	wpaie_agent_step( $state, [ 'model' => 'claude-fable-5-2', 'effort' => 'high' ], 'k' );
	$p   = last_payload();
	$req = $GLOBALS['wp_test']['requests'][0]['args'];
	check( $p['model'] === 'claude-fable-5-2' && $p['thinking']['type'] === 'adaptive', 'adaptive thinking (no disabled/budget)' );
	check( $p['fallbacks'] === 'default', 'refusal fallbacks enabled' );
	check( $p['max_tokens'] === WPAIE_FABLE_MAX_TOKENS && $req['timeout'] === WPAIE_FABLE_HTTP_TIMEOUT, 'longer output room and timeout' );
	check( ! isset( $p['tool_choice'] ) && ! isset( $p['temperature'] ), 'no forced tool choice / sampling params' );
	check( isset( wpaie_models()['claude-fable-5-2'] ), 'offered in settings' );

	wpaie_test_queue_response( 400, [ 'type' => 'error', 'error' => [ 'type' => 'invalid_request_error', 'message' => 'This model requires a 30-day data retention configuration.' ] ] );
	$e = wpaie_api_request( 'POST', '/messages', 'k', [] );
	check( str_contains( $e->get_error_message(), '30-day data retention' ), 'data retention error explained' );
} );

test( 'agent step: tool errors are flagged is_error', function () {
	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'tool_use', 'id' => 't', 'name' => 'update_custom_css', 'input' => [ 'css' => '' ] ] ], 'tool_use' ) );
	$state  = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'x' );
	wpaie_agent_step( $state, settings(), 'k' );
	$result = $state['messages'][2]->content[0];
	check( ! empty( $result->is_error ) && str_starts_with( $result->content, 'ERROR:' ), 'error result flagged' );
} );

test( 'agent step: refusal and truncated tool call are errors and not stored', function () {
	wpaie_test_queue_response( 200, api_message( [], 'refusal' ) + [ 'stop_details' => [ 'type' => 'refusal', 'category' => 'cyber' ] ] );
	$state  = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'x' );
	$before = wp_json_encode( $state );
	$result = wpaie_agent_step( $state, settings(), 'k' );
	check( is_wp_error( $result ) && $result->get_error_code() === 'wpaie_refusal', 'refusal error' );
	check( str_contains( $result->get_error_message(), 'cyber' ), 'category in message' );
	check( wp_json_encode( $state ) === $before, 'state unchanged on refusal' );

	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'tool_use', 'id' => 't', 'name' => 'update_custom_css', 'input' => [ 'css' => 'a{' ] ] ], 'max_tokens' ) );
	$result = wpaie_agent_step( $state, settings(), 'k' );
	check( is_wp_error( $result ) && $result->get_error_code() === 'wpaie_truncated', 'truncated tool call aborted' );
	check( $GLOBALS['wp_test']['custom_css'] === '', 'truncated tool call not executed' );
} );

test( 'agent step limit', function () {
	$state          = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'x' );
	$state['steps'] = WPAIE_MAX_STEPS;
	$result         = wpaie_agent_step( $state, settings(), 'k' );
	check( $result['done'] === true && str_contains( $result['reply'], 'limit of' ), 'stops at the step limit' );
	check( $GLOBALS['wp_test']['requests'] === [], 'no API call' );
} );

test( 'API errors are mapped to friendly messages', function () {
	wpaie_test_queue_response( 401, [ 'type' => 'error', 'error' => [ 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ] ], [ 'request-id' => 'req_1' ] );
	$e = wpaie_api_request( 'POST', '/messages', 'k', [] );
	check( is_wp_error( $e ) && str_starts_with( $e->get_error_message(), 'Invalid API key' ), '401 message' );
	check( str_contains( $e->get_error_message(), 'req_1' ), 'request id included' );

	wpaie_test_queue_response( 400, [ 'type' => 'error', 'error' => [ 'type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.' ] ] );
	$e = wpaie_api_request( 'POST', '/messages', 'k', [] );
	check( str_contains( $e->get_error_message(), 'out of credit' ), 'credit balance message' );

	wpaie_test_queue_response( 200, 'not json' );
	$e = wpaie_api_request( 'POST', '/messages', 'k', [] );
	check( is_wp_error( $e ) && $e->get_error_code() === 'wpaie_bad_response', 'non-JSON body handled' );
} );

test( 'transient errors are retried', function () {
	wpaie_test_queue_response( 529, [ 'type' => 'error', 'error' => [ 'type' => 'overloaded_error', 'message' => 'Overloaded' ] ], [ 'retry-after' => '1' ] );
	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'text', 'text' => 'ok' ] ] ) );
	$r = wpaie_api_request( 'POST', '/messages', 'k', [] );
	check( is_object( $r ) && count( $GLOBALS['wp_test']['requests'] ) === 2, 'retried once after 529' );
} );

test( 'beta rejection falls back to a plain request and is remembered', function () {
	wpaie_test_queue_response( 400, [ 'type' => 'error', 'error' => [ 'type' => 'invalid_request_error', 'message' => 'Unexpected value(s) `foo` for the `anthropic-beta` header.' ] ] );
	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'text', 'text' => 'ok' ] ] ) );
	$r = wpaie_messages_create( 'k', 'claude-opus-5-5', 'medium', 'S', [ [ 'role' => 'user', 'content' => 'hi' ] ], [] );
	check( is_object( $r ), 'succeeded on retry' );
	$second = $GLOBALS['wp_test']['requests'][1]['args'];
	check( ! isset( $second['headers']['anthropic-beta'] ) && ! isset( json_decode( $second['body'], true )['context_management'] ), 'retry without beta features' );
	check( (bool) get_transient( WPAIE_NO_BETA_TRANSIENT ), 'remembered for later requests' );
} );

test( 'theme.json merge replaces lists instead of merging them by index', function () {
	$base   = [ 'color' => [ 'palette' => [ [ 'slug' => 'a' ], [ 'slug' => 'b' ] ], 'text' => '#000' ] ];
	$merged = wpaie_merge_theme_json( $base, [ 'color' => [ 'palette' => [ [ 'slug' => 'c' ] ] ] ] );
	check( $merged['color']['palette'] === [ [ 'slug' => 'c' ] ], 'palette replaced' );
	check( $merged['color']['text'] === '#000', 'sibling key kept' );
} );

test( 'tool definitions are valid for the API', function () {
	$names = [];
	foreach ( wpaie_tool_definitions() as $tool ) {
		$names[] = $tool['name'];
		check( preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $tool['name'] ) === 1, "valid name {$tool['name']}" );
		check( ( $tool['input_schema']['type'] ?? '' ) === 'object', "object schema {$tool['name']}" );
		check( wpaie_tool_label( $tool['name'], [] ) !== "Running {$tool['name']}…", "label for {$tool['name']}" );
	}
	check( count( $names ) === count( array_unique( $names ) ), 'unique tool names' );
} );

echo "\n" . ( $failures ? "$failures of $count checks FAILED\n" : "All $count checks passed\n" );
exit( $failures ? 1 : 0 );
