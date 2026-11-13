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
	return [ 'model' => 'claude-opus-5-5', 'effort' => 'medium', 'approval_mode' => 'none' ];
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
	$loaded = wpaie_conversation_load( $state['id'] );
	check( wp_json_encode( $loaded['messages'] ) === wp_json_encode( $state['messages'] ), 'messages identical after save/load' );
	check( str_contains( wp_json_encode( $loaded['messages'] ), '"input":{}' ), 'empty tool input stays an object' );
	check( $loaded['system'] === 'Systém', 'system prompt stored' );
} );

test( 'legacy single history (1.x list, 2.0 object) is migrated to a conversation', function () {
	update_user_meta( 1, 'wpaie_history', wp_slash( '[{"role":"user","content":"Změň barvu"},{"role":"assistant","content":[{"type":"text","text":"y"}]}]' ) );
	$list = wpaie_conversations_list();
	check( count( $list ) === 1 && $list[0]['title'] === 'Změň barvu', 'migrated into the index with a title' );
	check( wpaie_active_conversation_id() === $list[0]['id'], 'migrated conversation is active' );
	$state = wpaie_conversation_load( $list[0]['id'] );
	check( count( $state['messages'] ) === 2 && $state['tools'], 'messages loaded, tool set fixed' );
	check( get_user_meta( 1, 'wpaie_history', true ) === '', 'legacy key removed' );

	update_user_meta( 1, 'wpaie_history', wp_slash( '{"v":2,"system":"S","messages":[{"role":"user","content":"druhá"}],"steps":0}' ) );
	$list = wpaie_conversations_list();
	check( count( $list ) === 2, '2.0 history migrated too' );
} );

test( 'several conversations: list, switch, delete, limit', function () {
	$a = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'První' );
	$b = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'Druhá' );
	wpaie_conversation_save( $a );
	wpaie_conversation_save( $b );
	$list = wpaie_conversations_list();
	check( count( $list ) === 2 && $list[0]['id'] === $b['id'], 'newest first' );
	wpaie_set_active_conversation( $a['id'] );
	check( wpaie_active_conversation_id() === $a['id'], 'switch' );
	wpaie_conversation_delete( $a['id'] );
	check( count( wpaie_conversations_list() ) === 1 && wpaie_active_conversation_id() === '', 'deleted, active cleared' );
	check( wpaie_conversation_load( $a['id'] )['messages'] === [], 'deleted conversation is gone' );
	check( wpaie_conversation_load( '../etc' )['messages'] === [], 'invalid id gives a new conversation' );
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
	check( $req['url'] === WPAIE_API_BASE . '/messages', 'messages endpoint' );
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

test( 'approval: live change waits, approve runs it and records an undoable change', function () {
	$GLOBALS['wp_test']['custom_css'] = 'a{}';
	wpaie_test_queue_response( 200, api_message( [
		[ 'type' => 'text', 'text' => 'Přidám CSS.' ],
		[ 'type' => 'tool_use', 'id' => 'r1', 'name' => 'get_custom_css', 'input' => new stdClass() ],
		[ 'type' => 'tool_use', 'id' => 'w1', 'name' => 'update_custom_css', 'input' => [ 'css' => 'b{color:red}' ] ],
	], 'tool_use' ) );
	$state = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'x' );
	$live  = [ 'approval_mode' => 'live' ] + settings();

	$step = wpaie_agent_step( $state, $live, 'k' );
	check( $step['awaiting'] === true && $step['done'] === false, 'step waits for approval' );
	check( count( $step['approvals'] ) === 1 && $step['approvals'][0]['live'] === true, 'one live card (the read runs without approval)' );
	check( str_contains( $step['approvals'][0]['preview'], 'b{color:red}' ), 'preview shows the CSS' );
	check( $GLOBALS['wp_test']['custom_css'] === 'a{}', 'nothing written yet' );
	check( is_wp_error( wpaie_agent_step( $state, $live, 'k' ) ), 'cannot continue while awaiting' );

	// Survives a save/load (page reload).
	wpaie_conversation_save( $state );
	$state = wpaie_conversation_load( $state['id'] );
	check( count( wpaie_approval_cards( $state ) ) === 1, 'pending approval restored after reload' );

	$res = wpaie_agent_decide( $state, true, 'k' );
	check( $res['done'] === false && $state['pending'] === null, 'decided, loop continues' );
	check( $GLOBALS['wp_test']['custom_css'] === "a{}\n\nb{color:red}", 'CSS written after approval' );
	$results = end( $state['messages'] )->content;
	check( count( $results ) === 2 && $results[0]->tool_use_id === 'r1' && $results[1]->tool_use_id === 'w1', 'both results in one message, in order' );
	check( count( $res['changes'] ) === 1 && isset( $state['change_map']['w1'] ), 'change recorded and mapped to the tool call' );

	$undo = wpaie_undo_change( $res['changes'][0]['id'] );
	check( $undo === true && $GLOBALS['wp_test']['custom_css'] === 'a{}', 'undo restores the previous CSS' );
	check( is_wp_error( wpaie_undo_change( $res['changes'][0]['id'] ) ), 'cannot undo twice' );
} );

test( 'approval: reject records the refusal and changes nothing', function () {
	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'tool_use', 'id' => 'w1', 'name' => 'update_custom_css', 'input' => [ 'css' => 'x{}' ] ] ], 'tool_use' ) );
	$state = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'x' );
	wpaie_agent_step( $state, [ 'approval_mode' => 'live' ] + settings(), 'k' );
	wpaie_agent_decide( $state, false, 'k' );
	check( $GLOBALS['wp_test']['custom_css'] === '', 'CSS unchanged' );
	check( end( $state['messages'] )->content[0]->content === WPAIE_REJECTED_RESULT, 'model is told the change was rejected' );
	check( $GLOBALS['wpdb']->rows === [], 'no change recorded' );
} );

test( 'approval: a new message instead of a decision skips the change', function () {
	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'tool_use', 'id' => 'w1', 'name' => 'update_custom_css', 'input' => [ 'css' => 'x{}' ] ] ], 'tool_use' ) );
	$state = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'x' );
	wpaie_agent_step( $state, [ 'approval_mode' => 'all' ] + settings(), 'k' );
	wpaie_agent_skip_pending( $state, 'k' );
	$state = wpaie_conversation_add_user_text( $state, 'ne, jinak' );
	$last  = end( $state['messages'] )->content;
	check( $last[0]->content === WPAIE_SKIPPED_RESULT && $last[1]->text === 'ne, jinak', 'skip result + new text in one message' );
	check( $GLOBALS['wp_test']['custom_css'] === '', 'CSS unchanged' );
} );

test( 'approval modes', function () {
	check( ! wpaie_tool_needs_approval( 'get_custom_css', [], 'all' ), 'reads never need approval' );
	check( wpaie_tool_needs_approval( 'update_custom_css', [ 'css' => 'a' ], 'live' ), 'live change needs approval in live mode' );
	check( ! wpaie_tool_needs_approval( 'update_custom_css', [ 'css' => 'a' ], 'none' ), 'none mode never asks' );
	check( ! wpaie_tool_needs_approval( 'create_post', [ 'title' => 'a' ], 'live' ), 'draft does not need approval in live mode' );
	check( wpaie_tool_needs_approval( 'create_post', [ 'title' => 'a', 'status' => 'publish' ], 'live' ), 'publishing needs approval' );
	check( wpaie_tool_needs_approval( 'create_post', [ 'title' => 'a' ], 'all' ), 'drafts need approval in all mode' );
} );

test( 'tools the user may not use are not offered or run', function () {
	$GLOBALS['wp_test']['denied_caps'] = [ 'edit_theme_options', 'manage_options' ];
	$names = wpaie_available_tool_names();
	check( in_array( 'update_post_content', $names, true ) && ! in_array( 'update_custom_css', $names, true ), 'design tools hidden' );
	check( is_wp_error( wpaie_run_tool( 'update_custom_css', [ 'css' => 'a' ] ) ), 'and refused when called' );
} );

function sse( string $type, array $data ): string {
	return "event: $type\ndata: " . json_encode( [ 'type' => $type ] + $data ) . "\n\n";
}

test( 'stream: SSE events are assembled into the same message as a non-streaming call', function () {
	$stream = sse( 'message_start', [ 'message' => [ 'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => [], 'stop_reason' => null, 'usage' => [ 'input_tokens' => 10, 'output_tokens' => 1 ] ] ] )
		. sse( 'content_block_start', [ 'index' => 0, 'content_block' => [ 'type' => 'thinking', 'thinking' => '', 'signature' => '' ] ] )
		. sse( 'content_block_delta', [ 'index' => 0, 'delta' => [ 'type' => 'signature_delta', 'signature' => 'sig-' ] ] )
		. sse( 'content_block_delta', [ 'index' => 0, 'delta' => [ 'type' => 'signature_delta', 'signature' => 'xyz' ] ] )
		. sse( 'content_block_stop', [ 'index' => 0 ] )
		. sse( 'ping', [] )
		. sse( 'content_block_start', [ 'index' => 1, 'content_block' => [ 'type' => 'text', 'text' => '' ] ] )
		. sse( 'content_block_delta', [ 'index' => 1, 'delta' => [ 'type' => 'text_delta', 'text' => 'Ahoj ' ] ] )
		. sse( 'content_block_delta', [ 'index' => 1, 'delta' => [ 'type' => 'text_delta', 'text' => 'světe' ] ] )
		. sse( 'content_block_stop', [ 'index' => 1 ] )
		. sse( 'content_block_start', [ 'index' => 2, 'content_block' => [ 'type' => 'tool_use', 'id' => 't1', 'name' => 'get_custom_css', 'input' => new stdClass() ] ] )
		. sse( 'content_block_stop', [ 'index' => 2 ] )
		. sse( 'content_block_start', [ 'index' => 3, 'content_block' => [ 'type' => 'tool_use', 'id' => 't2', 'name' => 'update_custom_css', 'input' => new stdClass() ] ] )
		. sse( 'content_block_delta', [ 'index' => 3, 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"css": "a{co' ] ] )
		. sse( 'content_block_delta', [ 'index' => 3, 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => 'lor:red}"}' ] ] )
		. sse( 'content_block_stop', [ 'index' => 3 ] )
		. sse( 'message_delta', [ 'delta' => [ 'stop_reason' => 'tool_use' ], 'usage' => [ 'output_tokens' => 42 ] ] )
		. sse( 'message_stop', [] );

	$texts  = [];
	$pings  = 0;
	$parser = new WPAIE_SSE_Message( function ( $d ) use ( &$texts ) { $texts[] = $d; }, function () use ( &$pings ) { $pings++; } );
	// Feed in awkward chunks (split inside events and CRLF line endings).
	foreach ( str_split( str_replace( "\n", "\r\n", $stream ), 7 ) as $chunk ) {
		$parser->feed( $chunk );
	}
	$msg = $parser->result();
	check( is_object( $msg ) && $msg->stop_reason === 'tool_use', 'complete message with stop_reason' );
	check( implode( '', $texts ) === 'Ahoj světe' && $pings === 1, 'text deltas and pings forwarded' );
	check( wp_json_encode( $msg->content[0] ) === '{"type":"thinking","thinking":"","signature":"sig-xyz"}', 'thinking block rebuilt verbatim' );
	check( $msg->content[1]->text === 'Ahoj světe', 'text block' );
	check( wp_json_encode( $msg->content[2]->input ) === '{}', 'empty tool input is an object' );
	check( $msg->content[3]->input->css === 'a{color:red}', 'tool input assembled from JSON deltas' );
	check( $msg->usage->input_tokens === 10 && $msg->usage->output_tokens === 42, 'usage merged' );
	check( empty( $msg->wpaie_invalid_tool_input ), 'no invalid input flag' );
} );

test( 'stream: cut-off tool input and error events', function () {
	$parser = new WPAIE_SSE_Message();
	$parser->feed(
		sse( 'message_start', [ 'message' => [ 'content' => [] ] ] )
		. sse( 'content_block_start', [ 'index' => 0, 'content_block' => [ 'type' => 'tool_use', 'id' => 't', 'name' => 'x', 'input' => new stdClass() ] ] )
		. sse( 'content_block_delta', [ 'index' => 0, 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"css": "a{' ] ] )
		. sse( 'content_block_stop', [ 'index' => 0 ] )
		. sse( 'message_delta', [ 'delta' => [ 'stop_reason' => 'max_tokens' ] ] )
		. sse( 'message_stop', [] )
	);
	check( ! empty( $parser->result()->wpaie_invalid_tool_input ), 'cut-off JSON flagged, never guessed' );

	$parser = new WPAIE_SSE_Message();
	$parser->feed( sse( 'message_start', [ 'message' => [ 'content' => [] ] ] ) . sse( 'error', [ 'error' => [ 'type' => 'overloaded_error', 'message' => 'Overloaded' ] ] ) );
	$err = $parser->result();
	check( is_wp_error( $err ) && str_contains( $err->get_error_message(), 'overloaded' ), 'mid-stream error mapped' );

	$parser = new WPAIE_SSE_Message();
	$parser->feed( sse( 'message_start', [ 'message' => [ 'content' => [] ] ] ) );
	check( is_wp_error( $parser->result() ), 'stream without message_stop is an error' );
} );

test( 'pricing: cost estimate, iterations, long-context tier', function () {
	$usage = [ 'input_tokens' => 1000000, 'output_tokens' => 100000, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0 ];
	check( abs( wpaie_usage_cost( 'claude-opus-5-5', $usage ) - 6.0 ) < 1e-9, 'Opus 5.5: $4 in + $2 out' );
	check( abs( wpaie_usage_cost( 'claude-opus-5-5', [ 'cache_creation_input_tokens' => 1000000, 'cache_read_input_tokens' => 1000000 ] ) - 5.2 ) < 1e-9, 'cache write 1.25x, cache read price' );
	check( abs( wpaie_usage_cost( 'claude-haiku-5-5', [ 'input_tokens' => 200000 ] ) - 0.1 ) < 1e-9, 'Haiku long-context input price' );
	check( wpaie_usage_cost( 'unknown-model', $usage ) === 0.0, 'unknown model costs 0' );

	$resp = json_decode( json_encode( [ 'model' => 'claude-opus-5', 'usage' => [ 'input_tokens' => 5, 'output_tokens' => 5, 'iterations' => [
		[ 'type' => 'message', 'model' => 'claude-opus-5-5', 'input_tokens' => 1000000, 'output_tokens' => 0 ],
		[ 'type' => 'fallback_message', 'model' => 'claude-opus-5', 'input_tokens' => 0, 'output_tokens' => 0 ],
	] ] ] ) );
	$u = wpaie_response_usage( $resp, 'claude-opus-5-5' );
	check( abs( $u['cost'] - 4.0 ) < 1e-9 && $u['input'] === 1000000, 'iterations summed per model' );
} );

test( 'pricing: monthly totals and budget', function () {
	$state = wpaie_empty_conversation();
	$resp  = json_decode( json_encode( [ 'model' => 'claude-opus-5-5', 'usage' => [ 'input_tokens' => 1000000, 'output_tokens' => 0 ] ] ) );
	$cost  = wpaie_track_response_usage( $state, $resp, 'claude-opus-5-5' );
	check( abs( $cost - 4.0 ) < 1e-9 && abs( $state['usage']['cost'] - 4.0 ) < 1e-9, 'conversation cost' );
	check( abs( wpaie_month_cost() - 4.0 ) < 1e-9 && wpaie_usage_by_month()[ gmdate( 'Y-m' ) ]['requests'] === 1, 'monthly total' );

	check( wpaie_budget_error() === null, 'no budget = no limit' );
	update_option( WPAIE_OPTION_KEY, [ 'monthly_budget' => 5 ] );
	check( wpaie_budget_error() === null, 'under budget' );
	update_option( WPAIE_OPTION_KEY, [ 'monthly_budget' => 3 ] );
	check( wpaie_budget_error() instanceof WP_Error, 'over budget blocks' );

	wpaie_test_queue_response( 200, api_message( [ [ 'type' => 'text', 'text' => 'x' ] ] ) );
	$s2   = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'x' );
	$step = wpaie_agent_step( $s2, settings(), 'k' );
	check( is_wp_error( $step ) && $step->get_error_code() === 'wpaie_budget' && $GLOBALS['wp_test']['requests'] === [], 'agent stops before calling the API' );
} );

test( 'settings: budget and checkboxes', function () {
	$out = wpaie_sanitize_settings( [ 'monthly_budget' => '12,5' ] );
	check( $out['monthly_budget'] === 12.5, 'decimal comma accepted' );
	check( $out['streaming'] === false && $out['allow_editors'] === false, 'unchecked boxes are false' );
	check( wpaie_get_settings()['streaming'] === true, 'streaming on by default' );
} );

test( 'stream: real cURL request against a local fake API', function () {
	if ( ! function_exists( 'curl_init' ) ) {
		echo "  (skipped: no cURL)\n";
		return;
	}
	$server = proc_open(
		[ PHP_BINARY, '-S', '127.0.0.1:' . WPAIE_TEST_API_PORT, __DIR__ . '/fixtures/fake-api.php' ],
		[ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ],
		$pipes
	);
	for ( $i = 0; $i < 50; $i++ ) {
		$sock = @fsockopen( '127.0.0.1', WPAIE_TEST_API_PORT );
		if ( $sock ) {
			fclose( $sock );
			break;
		}
		usleep( 100000 );
	}

	$texts = [];
	$pings = 0;
	$msg   = wpaie_api_stream_request(
		'sk-ant-test',
		[ 'model' => 'claude-opus-5-5', 'max_tokens' => 10, 'messages' => [] ],
		[],
		20,
		function ( $d ) use ( &$texts ) { $texts[] = $d; },
		function () use ( &$pings ) { $pings++; }
	);
	check( is_object( $msg ) && $msg->content[0]->text === 'Hotovo, změněno.', 'streamed answer assembled' );
	check( count( $texts ) === 2 && $pings === 1, 'deltas arrive one by one' );
	check( is_object( $msg ) && $msg->usage->output_tokens === 7, 'usage from message_delta' );

	$start = microtime( true );
	$err   = wpaie_api_stream_request( 'sk-ant-test', [ 'model' => 'x', 'metadata' => [ 'fail' => true ] ], [], 20, null, null );
	check( is_wp_error( $err ) && str_contains( $err->get_error_message(), 'overloaded' ) && str_contains( $err->get_error_message(), 'req_fake' ), 'HTTP error mapped with request-id' );
	check( microtime( true ) - $start >= 2, 'retried with retry-after before giving up' );

	proc_terminate( $server );
	proc_close( $server );
} );

// Stubs for the image tests.
function wp_attachment_is_image( $id ) { return in_array( (int) $id, [ 11, 12 ], true ); }
function get_attached_file( $id ) { return __DIR__ . '/fixtures/pixel.png'; }
function get_post_mime_type( $id ) { return 'image/png'; }
function image_get_intermediate_size( $id, $size ) { return false; }
function wp_basename( $path ) { return basename( $path ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

test( 'images: uploaded once per conversation to the Files API, sent as file blocks', function () {
	wpaie_test_queue_response( 200, [ 'id' => 'file_abc', 'type' => 'file' ] );
	$state             = wpaie_empty_conversation();
	$_POST['images']   = '11';
	$blocks            = wpaie_request_extra_blocks( $state, 'k' );
	unset( $_POST['images'] );

	$req = $GLOBALS['wp_test']['requests'][0];
	check( $req['url'] === WPAIE_API_BASE . '/files' && str_starts_with( $req['args']['headers']['content-type'], 'multipart/form-data; boundary=' ), 'multipart upload to /files' );
	check( str_contains( $req['args']['body'], 'filename="pixel.png"' ) && str_contains( $req['args']['body'], "Content-Type: image/png\r\n\r\n" ), 'file part with name and type' );
	check( $state['files'] === [ 11 => 'file_abc' ], 'file id remembered in the conversation' );
	check( count( $blocks ) === 2 && $blocks[1]['source'] === [ 'type' => 'file', 'file_id' => 'file_abc' ], 'image block references the file' );
	check( str_starts_with( $blocks[0]['text'], WPAIE_IMAGE_PREFIX . '11]' ), 'note with the attachment ID' );

	$again = wpaie_attachment_file_id( 11, $state['files'], 'k' );
	check( $again === 'file_abc' && count( $GLOBALS['wp_test']['requests'] ) === 1, 'not uploaded twice' );
	check( is_wp_error( wpaie_attachment_file_id( 99, $state['files'], 'k' ) ), 'non-image attachment refused' );

	$_POST['images'] = '11,12,11,12,11,12';
	check( is_wp_error( wpaie_request_extra_blocks( $state, 'k' ) ), 'too many images refused' );
	unset( $_POST['images'] );

	// Transcript shows the image and hides the note.
	$state = wpaie_conversation_add_user_text( $state, 'Použij jako logo', $blocks );
	$items = wpaie_conversation_transcript( $state );
	check( count( $items ) === 1 && $items[0]['images'] === [ 11 ] && $items[0]['text'] === 'Použij jako logo', 'transcript: image + text only' );
	check( wpaie_conversation_title( $state ) === 'Použij jako logo', 'title ignores the note' );

	// Deleting the conversation deletes its files.
	wpaie_conversation_save( $state );
	update_option( WPAIE_OPTION_KEY, [ 'api_key' => 'sk-ant-x' ] );
	wpaie_test_queue_response( 200, [ 'id' => 'file_abc', 'type' => 'file_deleted' ] );
	wpaie_conversation_delete( $state['id'] );
	$last = end( $GLOBALS['wp_test']['requests'] );
	check( $last['args']['method'] === 'DELETE' && $last['url'] === WPAIE_API_BASE . '/files/file_abc', 'file deleted with the conversation' );
} );

test( 'context note: only same-site URLs and editable posts', function () {
	check( wpaie_context_note( 0, 'https://example.test/o-nas/' ) === WPAIE_CONTEXT_PREFIX . 'The user was viewing https://example.test/o-nas/ on the site.]', 'own URL accepted' );
	check( wpaie_context_note( 0, 'https://evil.test/' ) === '', 'foreign URL ignored' );
	$state = wpaie_conversation_add_user_text( wpaie_empty_conversation(), 'tuhle stránku', [ [ 'type' => 'text', 'text' => wpaie_context_note( 0, 'https://example.test/' ) ] ] );
	check( wpaie_conversation_transcript( $state )[0]['text'] === 'tuhle stránku', 'context hidden in the transcript' );
} );

echo "\n" . ( $failures ? "$failures of $count checks FAILED\n" : "All $count checks passed\n" );
exit( $failures ? 1 : 0 );
