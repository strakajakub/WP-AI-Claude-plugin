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
	check( wpaie_usage_cost( 'unknown-model', $usage ) > 0, 'unknown model is not free' );

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

function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }

test( 'content: editing a published post is live, saved as draft, undo restores it', function () {
	wpaie_test_add_post( [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'O nás', 'post_content' => 'Starý text' ] );
	check( wpaie_tool_needs_approval( 'update_post_content', [ 'post_id' => 5, 'content' => 'x' ], 'live' ), 'unpublishing a live page needs approval' );
	check( str_contains( wpaie_tool_preview( 'update_post_content', [ 'post_id' => 5, 'title' => 'Kdo jsme' ] ), 'Kdo jsme' ), 'preview shows the new title' );

	WPAIE_Run_Context::$changes = [];
	$out = wpaie_run_tool( 'update_post_content', [ 'post_id' => 5, 'content' => 'Nový \\ text' ] );
	check( is_string( $out ) && str_contains( $out, 'WARNING' ), 'model warned that the page went offline' );
	check( get_post( 5 )->post_status === 'draft' && get_post( 5 )->post_content === 'Nový \\ text', 'saved as draft, backslash kept' );

	check( wpaie_undo_change( WPAIE_Run_Context::$changes[0]['id'] ) === true, 'undo ok' );
	check( get_post( 5 )->post_status === 'publish' && get_post( 5 )->post_content === 'Starý text', 'status and content restored' );
} );

test( 'search & replace: finds, previews, replaces across posts, undo restores all', function () {
	wpaie_test_add_post( [ 'ID' => 1, 'post_status' => 'publish', 'post_title' => 'Akce ACME', 'post_content' => 'ACME s.r.o. a acme' ] );
	wpaie_test_add_post( [ 'ID' => 2, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Kontakt', 'post_content' => 'Napište ACME' ] );
	wpaie_test_add_post( [ 'ID' => 3, 'post_title' => 'Jiné', 'post_content' => 'nic' ] );

	$found = wpaie_tool_search_content( [ 'query' => 'acme' ] );
	check( str_contains( $found, 'ID:1' ) && str_contains( $found, 'occurrences:3' ) && ! str_contains( $found, 'ID:3' ), 'search counts occurrences (title + content, case-insensitive)' );

	$plan = wpaie_plan_replacement( [ 'search' => 'ACME', 'replace' => 'Acme Group' ] );
	check( count( $plan ) === 2 && $plan[0]['count'] === 1, 'case-sensitive by default, titles untouched' );
	check( str_contains( wpaie_tool_preview( 'replace_in_content', [ 'search' => 'ACME', 'replace' => 'X' ] ), '2 posts will change' ), 'preview lists affected posts' );
	check( wpaie_tool_needs_approval( 'replace_in_content', [ 'search' => 'a', 'replace' => 'b' ], 'live' ), 'bulk replace always asks in live mode' );

	WPAIE_Run_Context::$changes = [];
	$out = wpaie_run_tool( 'replace_in_content', [ 'search' => 'acme', 'replace' => 'Acme Group', 'case_sensitive' => false, 'include_titles' => true ] );
	check( str_contains( (string) $out, 'Replaced 4 occurrences in 2 posts' ), 'replaced in content and titles' );
	check( get_post( 1 )->post_title === 'Akce Acme Group' && get_post( 1 )->post_status === 'publish', 'title replaced, status kept' );
	check( count( WPAIE_Run_Context::$changes ) === 1, 'one change for the whole batch' );

	wpaie_undo_change( WPAIE_Run_Context::$changes[0]['id'] );
	check( get_post( 1 )->post_content === 'ACME s.r.o. a acme' && get_post( 2 )->post_content === 'Napište ACME' && get_post( 1 )->post_title === 'Akce ACME', 'undo restores every post' );
} );

test( 'media: alt texts in bulk, undo removes them again', function () {
	update_post_meta( 12, '_wp_attachment_image_alt', 'staré' );
	WPAIE_Run_Context::$changes = [];
	$out = wpaie_run_tool( 'update_media_alt', [ 'items' => [ [ 'attachment_id' => 11, 'alt' => 'Logo firmy' ], [ 'attachment_id' => 12, 'alt' => 'Tým' ], [ 'attachment_id' => 99, 'alt' => 'x' ] ] ] );
	check( str_contains( (string) $out, '#11, #12' ) && str_contains( (string) $out, 'Skipped: #99' ), 'non-images skipped' );
	check( get_post_meta( 11, '_wp_attachment_image_alt' ) === 'Logo firmy', 'alt set' );
	check( wpaie_tool_label( 'update_media_alt', [ 'items' => [ 1, 2 ] ] ) === 'Setting alt texts of %d images…' || str_contains( wpaie_tool_label( 'update_media_alt', [ 'items' => [ 1, 2 ] ] ), '2' ), 'plural label' );
	wpaie_undo_change( WPAIE_Run_Context::$changes[0]['id'] );
	check( get_post_meta( 11, '_wp_attachment_image_alt' ) === '' && get_post_meta( 12, '_wp_attachment_image_alt' ) === 'staré', 'undo: new alt removed, old alt restored' );
} );

test( 'media: get_image returns the image to the model via the Files API', function () {
	wpaie_test_queue_response( 200, [ 'id' => 'file_img' ] );
	WPAIE_Run_Context::$api_key = 'k';
	WPAIE_Run_Context::$files   = [];
	$out = wpaie_run_tool( 'get_image', [ 'attachment_id' => 11 ] );
	check( is_array( $out ) && $out[1]['source']['file_id'] === 'file_img', 'image block in the tool result' );
	check( WPAIE_Run_Context::$files === [ 11 => 'file_img' ], 'file id kept for the conversation' );
} );

test( 'optional tools: SEO and WooCommerce only when the plugin is active', function () {
	$names = wpaie_available_tool_names();
	check( ! in_array( 'update_seo', $names, true ) && ! in_array( 'update_product', $names, true ), 'hidden without the plugins' );

	define( 'WPSEO_VERSION', '99' );
	check( in_array( 'update_seo', wpaie_available_tool_names(), true ), 'SEO tools with Yoast' );
	wpaie_test_add_post( [ 'ID' => 7, 'post_status' => 'publish', 'post_title' => 'Ceník' ] );
	WPAIE_Run_Context::$changes = [];
	wpaie_run_tool( 'update_seo', [ 'post_id' => 7, 'title' => 'Ceník služeb | Firma', 'description' => 'Popis' ] );
	check( get_post_meta( 7, '_yoast_wpseo_title' ) === 'Ceník služeb | Firma', 'Yoast title meta written' );
	check( wpaie_tool_is_live( 'update_seo', [ 'post_id' => 7 ] ), 'SEO of a published post is live' );
	wpaie_undo_change( WPAIE_Run_Context::$changes[0]['id'] );
	check( get_post_meta( 7, '_yoast_wpseo_title' ) === '' && get_post_meta( 7, '_yoast_wpseo_metadesc' ) === '', 'undo removes the SEO meta' );
} );

test( 'editors: chat only when allowed, admin-only tools hidden', function () {
	$GLOBALS['wp_test']['denied_caps'] = [ 'manage_options', 'edit_theme_options', 'edit_products' ];
	check( ! wpaie_user_can_chat(), 'editor cannot chat by default' );
	update_option( WPAIE_OPTION_KEY, [ 'allow_editors' => true ] );
	check( wpaie_user_can_chat() && wpaie_chat_capability() === 'edit_others_posts', 'editor can chat when allowed' );
	$names = wpaie_available_tool_names();
	check( in_array( 'replace_in_content', $names, true ) && in_array( 'update_media_alt', $names, true ), 'content and media tools available' );
	foreach ( [ 'update_custom_css', 'update_theme_json', 'update_template_content', 'update_menu', 'update_site_identity' ] as $tool ) {
		check( ! in_array( $tool, $names, true ), "$tool hidden from editors" );
	}
} );

test( 'translations: .pot covers the code, Czech .mo is complete', function () {
	$pot = (string) file_get_contents( dirname( __DIR__ ) . '/wp-ai-editor/languages/wp-ai-editor.pot' );
	$po  = (string) file_get_contents( dirname( __DIR__ ) . '/wp-ai-editor/languages/wp-ai-editor-cs_CZ.po' );
	check( str_contains( $pot, 'msgid "The assistant wants to make this change:"' ), '.pot contains UI strings' );
	// Same msgids (and plurals), not just the same number of them.
	$ids = function ( string $text ): array {
		preg_match_all( '/^msgid (".*")\n(?:msgid_plural (".*")\n)?/m', $text, $m, PREG_SET_ORDER );
		$out = array_map( fn( $e ) => $e[1] . ( $e[2] ?? '' ), $m );
		sort( $out );
		return $out;
	};
	check( $ids( $pot ) === $ids( $po ), '.po has exactly the .pot entries' );
	check( ! preg_match( '/^msgstr(\[\d+\])? ""\n(?!")/m', preg_replace( '/\A.*?\n\n/s', '', $po ) . "\n" ), 'no empty translation or plural form' );
	check( ! str_contains( $po, '#, fuzzy' ), 'no fuzzy translation' );
	check( is_file( dirname( __DIR__ ) . '/wp-ai-editor/languages/wp-ai-editor-cs_CZ.mo' ), '.mo compiled' );
} );

test( 'translation tools: fuzzy, incomplete and broken entries are not compiled', function () {
	require_once dirname( __DIR__ ) . '/bin/po-lib.php';
	$file = tempnam( sys_get_temp_dir(), 'po' );
	file_put_contents(
		$file,
		"msgid \"\"\nmsgstr \"Plural-Forms: nplurals=3;\\n\"\n\n"
		. "msgid \"OK\"\nmsgstr \"Dobře\"\n\n"
		. "#, fuzzy, php-format\nmsgid \"Guess %s\"\nmsgstr \"Odhad %s\"\n\n"
		. "msgid \"%d file\"\nmsgid_plural \"%d files\"\nmsgstr[0] \"%d soubor\"\nmsgstr[1] \"\"\nmsgstr[2] \"%d souborů\"\n\n"
		. "msgid \"Multi\"\nmsgstr \"\"\n\"line\"\n\n"
		. "msgid \"No translation at all\"\n"
	);
	$entries = po_parse( $file );
	unlink( $file );
	check( ( $entries['OK'] ?? '' ) === 'Dobře' && ( $entries['Multi'] ?? '' ) === 'line', 'normal and multi-line entries kept' );
	check( ! isset( $entries['Guess %s'] ), 'fuzzy entry skipped' );
	check( ! isset( $entries["%d file\0%d files"] ), 'plural with a missing form skipped' );
	check( ! isset( $entries['No translation at all'] ) && isset( $entries[''] ), 'entry without msgstr does not crash; header kept' );
} );

test( 'review fix: roles with edit_theme_options (e.g. shop_manager) get no design tools', function () {
	// shop_manager: edit_others_posts + edit_theme_options, but not manage_options.
	$GLOBALS['wp_test']['denied_caps'] = [ 'manage_options' ];
	update_option( WPAIE_OPTION_KEY, [ 'allow_editors' => true ] );
	$names = wpaie_available_tool_names();
	foreach ( [ 'update_custom_css', 'update_theme_json', 'update_template_content', 'update_menu', 'update_site_identity', 'get_theme_json' ] as $tool ) {
		check( ! in_array( $tool, $names, true ), "$tool hidden" );
	}
	check( is_wp_error( wpaie_run_tool( 'update_custom_css', [ 'css' => 'a{}' ] ) ), 'and refused when called' );

	// An administrator without edit_css (e.g. DISALLOW_UNFILTERED_HTML on multisite).
	$GLOBALS['wp_test']['denied_caps'] = [ 'edit_css' ];
	$out = wpaie_run_tool( 'update_custom_css', [ 'css' => 'a{}' ] );
	check( is_wp_error( $out ) && $GLOBALS['wp_test']['custom_css'] === '', 'CSS needs edit_css' );
} );

test( 'review fix: usage counters are atomic increments (no lost updates)', function () {
	$a    = wpaie_empty_conversation();
	$b    = wpaie_empty_conversation();
	$resp = fn( $tokens ) => json_decode( json_encode( [ 'model' => 'claude-opus-5-5', 'usage' => [ 'input_tokens' => $tokens, 'output_tokens' => 0 ] ] ) );

	// Request A reads the total (budget check), then B records its cost, then A records its own.
	wpaie_month_cost();
	wpaie_track_response_usage( $b, $resp( 125000 ), 'claude-opus-5-5' ); // $0.50
	wpaie_track_response_usage( $a, $resp( 250000 ), 'claude-opus-5-5' ); // $1.00
	check( abs( wpaie_month_cost() - 1.5 ) < 1e-9, 'both costs counted' );
	check( wpaie_usage_by_month()[ gmdate( 'Y-m' ) ]['requests'] === 2, 'both requests counted' );

	// The single 3.0.0 option is migrated into the counters.
	wpaie_test_reset();
	update_option( 'wpaie_usage', [ '2026-01' => [ 'cost' => 2.5, 'input' => 10, 'output' => 5, 'requests' => 3 ] ] );
	$months = wpaie_usage_by_month();
	check( abs( $months['2026-01']['cost'] - 2.5 ) < 1e-9 && $months['2026-01']['requests'] === 3, 'old usage migrated' );
	check( get_option( 'wpaie_usage', null ) === null, 'old option removed' );
} );

// Stubs for the system prompt.
function get_bloginfo( $what = '' ) { return $what === 'version' ? '6.8' : 'Test site'; }
function wp_get_theme() { return new class() { public function get( $k ) { return $k === 'Name' ? 'Astra' : '4.0'; } }; }
function wp_is_block_theme() { return false; }
function get_block_templates() { return []; }
function wp_theme_has_theme_json() { return false; }
function get_locale() { return 'cs_CZ'; }
function get_stylesheet() { return 'astra'; }
require_once dirname( __DIR__ ) . '/wp-ai-editor/includes/site-context.php';

test( 'review fix: the system prompt describes only the tools of the conversation', function () {
	$admin = wpaie_system_prompt( wpaie_available_tool_names() );
	check( str_contains( $admin, 'global styles' ) && str_contains( $admin, 'History page' ), 'administrator: design tools and History mentioned' );

	$GLOBALS['wp_test']['denied_caps'] = [ 'manage_options', 'edit_theme_options' ];
	update_option( WPAIE_OPTION_KEY, [ 'allow_editors' => true ] );
	$editor = wpaie_system_prompt( wpaie_available_tool_names() );
	check( ! str_contains( $editor, 'global styles (theme.json' ) && ! str_contains( $editor, 'History page' ), 'editor: no design tools, no History page' );
	check( str_contains( $editor, 'not an administrator' ) && str_contains( $editor, 'search & replace' ), 'editor: told to ask an administrator; content abilities listed' );
} );

test( 'review 2 fix: undo order – an older change cannot wipe a newer one', function () {
	WPAIE_Run_Context::$changes = [];
	wpaie_run_tool( 'update_custom_css', [ 'css' => 'A{}' ] );
	wpaie_run_tool( 'update_custom_css', [ 'css' => 'B{}' ] );
	[ $first, $second ] = WPAIE_Run_Context::$changes;

	$blocked = wpaie_undo_change( $first['id'] );
	check( is_wp_error( $blocked ) && $blocked->get_error_code() === 'wpaie_undo_order', 'older undo blocked while a newer change to the CSS exists' );
	check( $GLOBALS['wp_test']['custom_css'] === "A{}\n\nB{}", 'CSS untouched by the blocked undo' );

	check( wpaie_undo_change( $second['id'] ) === true && $GLOBALS['wp_test']['custom_css'] === 'A{}', 'newest undone first' );
	check( wpaie_undo_change( $first['id'] ) === true && $GLOBALS['wp_test']['custom_css'] === '', 'then the older one' );

	// Changes to different things do not block each other.
	wpaie_test_add_post( [ 'ID' => 21, 'post_title' => 'A', 'post_content' => 'a' ] );
	wpaie_test_add_post( [ 'ID' => 22, 'post_title' => 'B', 'post_content' => 'b' ] );
	WPAIE_Run_Context::$changes = [];
	wpaie_run_tool( 'update_post_content', [ 'post_id' => 21, 'content' => 'a2' ] );
	wpaie_run_tool( 'update_post_content', [ 'post_id' => 22, 'content' => 'b2' ] );
	check( wpaie_undo_change( WPAIE_Run_Context::$changes[0]['id'] ) === true && get_post( 21 )->post_content === 'a', 'independent older change can be undone' );
} );

test( 'review 2 fix: undoing a created post that was already trashed by hand succeeds', function () {
	wpaie_test_add_post( [ 'ID' => 31, 'post_status' => 'trash', 'post_title' => 'X' ] );
	check( wpaie_apply_undo_op( [ 'type' => 'trash_post', 'post_id' => 31 ] ) === true, 'already in trash' );
	check( wpaie_apply_undo_op( [ 'type' => 'trash_post', 'post_id' => 999 ] ) === true, 'already deleted' );
} );

test( 'review 2 fix: no model is free – fallback and unknown models are priced', function () {
	$usage = [ 'input_tokens' => 1000000, 'output_tokens' => 100000 ];
	check( abs( wpaie_usage_cost( 'claude-opus-4-8', $usage ) - 7.5 ) < 1e-9, 'fallback model Opus 4.8 priced' );
	check( abs( wpaie_usage_cost( 'claude-sonnet-5', $usage ) - 3.0 ) < 1e-9, 'fallback model Sonnet 5 priced' );
	check( wpaie_usage_cost( 'claude-future-9', $usage ) >= wpaie_usage_cost( 'claude-fable-5-2', $usage ), 'unknown model costed at the most expensive known price' );
} );

function is_object_in_term( $object_id, $taxonomy, $term ) { return (int) $term === 5; }
function wp_get_object_terms( $object_id, $taxonomy, $args = [] ) { return [ 5 ]; }

test( 'review 2 fix: renaming a menu item with the wrong menu ID is refused', function () {
	wpaie_test_add_post( [ 'ID' => 57, 'post_type' => 'nav_menu_item', 'post_title' => 'Blog' ] );
	$err = wpaie_rename_menu_item( 3, 57, 'Články' );
	check( is_wp_error( $err ) && str_contains( $err->get_error_message(), 'is in menu #5' ), 'refused, model told the right menu' );
} );

test( 'review 2 fix: case-insensitive replace understands diacritics; limit counts real matches', function () {
	$n   = 0;
	$out = wpaie_replace_text( 'účet', 'konto', 'Účet, ÚČET a účet', false, $n );
	check( $n === 3 && $out === 'konto, konto a konto', 'Unicode case folding' );
	$out = wpaie_replace_text( 'a.b', '$1 \\0', 'A.B axb', false, $n );
	check( $n === 1 && $out === '$1 \\0 axb', 'search is literal, replacement inserted literally' );

	for ( $i = 100; $i < 230; $i++ ) {
		wpaie_test_add_post( [ 'ID' => $i, 'post_status' => 'publish', 'post_title' => "P$i", 'post_content' => $i < 103 ? 'Firma ACME' : 'firma acme' ] );
	}
	$plan = wpaie_plan_replacement( [ 'search' => 'ACME', 'replace' => 'Acme' ] );
	check( is_array( $plan ) && count( $plan ) === 3, '130 LIKE candidates, 3 exact matches – not refused' );
	$plan = wpaie_plan_replacement( [ 'search' => 'acme', 'replace' => 'Acme', 'case_sensitive' => false ] );
	check( is_wp_error( $plan ) && str_contains( $plan->get_error_message(), '130 posts' ), 'over 100 real matches refused' );
} );

test( 'review 2 fix: shared retry policy', function () {
	check( wpaie_retry_delay( 529, '1', 1 ) === 1 && wpaie_retry_delay( 429, '', 2 ) === 4, 'retryable statuses wait (retry-after or backoff)' );
	check( wpaie_retry_delay( 400, '', 1 ) === null && wpaie_retry_delay( 529, '1', WPAIE_MAX_ATTEMPTS ) === null, 'no retry for 400 or after the last attempt' );
	check( wpaie_retry_delay( 529, '999', 1 ) === 20, 'retry-after capped' );
	check( wpaie_network_retry_delay( new WP_Error( 'wpaie_timeout', '' ), 1 ) === null && wpaie_network_retry_delay( new WP_Error( 'wpaie_network', '' ), 1 ) === 2, 'timeouts are not retried, connection errors are' );
} );

test( 'review 2 fix: lock token and cleanup of images of an unsaved step', function () {
	require_once dirname( __DIR__ ) . '/wp-ai-editor/includes/ajax.php';
	wpaie_acquire_lock();
	check( wpaie_lock_still_held(), 'lock held after acquiring' );
	set_transient( 'wpaie_lock_1', 'someone-else' );
	check( ! wpaie_lock_still_held(), 'detects a lock taken over by another request' );
	wpaie_release_lock();
	check( get_transient( 'wpaie_lock_1' ) === 'someone-else', 'does not release a lock it no longer holds' );

	$state          = wpaie_empty_conversation();
	$state['files'] = [ 1 => 'file_old', 2 => 'file_new' ];
	wpaie_test_queue_response( 200, [ 'id' => 'file_new', 'type' => 'file_deleted' ] );
	wpaie_discard_new_files( $state, [ 1 => 'file_old' ], 'k' );
	$requests = $GLOBALS['wp_test']['requests'];
	check( count( $requests ) === 1 && str_ends_with( $requests[0]['url'], '/files/file_new' ) && $requests[0]['args']['method'] === 'DELETE', 'only the newly uploaded file is deleted' );
} );

// ─── A fake Customizer that follows core's behaviour (verified against wordpress-develop trunk) ───
// Values live in the "astra-settings" option like Astra's; settings inside Astra's groups exist only
// on demand (add_dynamic_settings); save_changeset_post() is transactional, fires customize_save
// after the values are set and customize_save_after at the end; a manager publishes only once.

if ( ! defined( 'ASTRA_THEME_SETTINGS' ) ) {
	define( 'ASTRA_THEME_SETTINGS', 'astra-settings' );
}

class WPAIE_Fake_Setting {
	public $id;
	public $type       = 'option';
	public $default    = '';
	public $capability = 'edit_theme_options';
	public $validator;
	public $sanitizer;
	public function __construct( $id, array $args = [] ) {
		$this->id = $id;
		foreach ( $args as $k => $v ) {
			$this->$k = $v;
		}
	}
	public function id_data() {
		$keys = preg_split( '/\[/', str_replace( ']', '', $this->id ) );
		return [ 'base' => array_shift( $keys ), 'keys' => $keys ];
	}
	public function value() {
		$d    = $this->id_data();
		$root = get_option( $d['base'], null );
		if ( ! $d['keys'] ) {
			return $root ?? $this->default;
		}
		return is_array( $root ) && array_key_exists( $d['keys'][0], $root ) ? $root[ $d['keys'][0] ] : $this->default;
	}
	public function validate( $value ) { return $this->validator ? ( $this->validator )( $value ) : true; }
	public function sanitize( $value ) { return $this->sanitizer ? ( $this->sanitizer )( $value ) : $value; }
	public function check_capabilities() { return current_user_can( $this->capability ); }
	public function save_value( $value ) {
		$d = $this->id_data();
		if ( ! $d['keys'] ) {
			update_option( $d['base'], $value );
			return;
		}
		$root                  = (array) get_option( $d['base'], [] );
		$root[ $d['keys'][0] ] = $value;
		update_option( $d['base'], $root );
	}
}

class WP_Customize_Manager {
	public $settings  = [];
	public $controls  = [];
	public $dynamic   = []; // what the theme's customize_dynamic_setting_args filter would return
	public $published = false;
	public function __construct( $args = [] ) {
		$GLOBALS['wp_test']['customizer_args'] = $args;
		$layouts = [ 'blog-layout-1' => 'Classic', 'blog-layout-4' => 'Grid', 'blog-layout-5' => 'List' ];
		$this->add( 'astra-settings[blog-layout]', 'Blog Layout', 'section-blog', 'ast-radio-image', $layouts, 'blog-layout-1', fn( $v ) => isset( $layouts[ $v ] ) ? true : new WP_Error( 'invalid', 'Invalid layout.' ) );
		$this->add( 'astra-settings[site-content-width]', 'Container Width', 'section-container-layout', 'ast-slider', [], 1200, fn( $v ) => is_numeric( $v ) && $v >= 768 ? true : new WP_Error( 'invalid', 'Must be at least 768.' ) );
		$this->add( 'astra-settings[ast-body-font-settings]', 'Body Font', 'section-body-typo', 'ast-settings-group', [], '' );
		$this->add( 'blogname', 'Site Title', 'title_tagline', 'text', [], 'Web' );
		$this->add( 'custom_css[astra]', 'CSS', 'custom_css', 'code_editor', [], '' );
		// Settings inside Astra's "Body Font" group: no control, created on demand.
		$this->dynamic['astra-settings[font-size-body]']   = [ 'default' => [ 'desktop' => 16, 'tablet' => '', 'mobile' => '' ] ];
		$this->dynamic['astra-settings[body-font-family]'] = [ 'default' => 'inherit', 'sanitizer' => fn( $v ) => is_string( $v ) ? $v : new WP_Error( 'bad_font', 'Font must be a string.' ) ];
	}
	private function add( $id, $label, $section, $type, $choices, $default, $validator = null ) {
		$this->settings[ $id ] = new WPAIE_Fake_Setting( $id, [ 'default' => $default, 'validator' => $validator ] );
		$this->controls[ $id ] = (object) [
			'settings'    => [ 'default' => $this->settings[ $id ] ],
			'setting'     => $this->settings[ $id ],
			'label'       => $label,
			'description' => '',
			'type'        => $type,
			'choices'     => $choices,
			'input_attrs' => [],
			'section'     => $section,
		];
	}
	public function wp_loaded() {
		$GLOBALS['wp_test']['customize_register'] = ( $GLOBALS['wp_test']['customize_register'] ?? 0 ) + 1;
		// What Astra does during customize_register: build its configurations through its filter.
		apply_filters(
			'astra_customizer_configurations',
			[
				[ 'name' => 'astra-settings[ast-body-font-settings]', 'control' => 'ast-settings-group', 'title' => 'Body Font', 'section' => 'section-body-typo' ],
				[ 'name' => 'font-size-body', 'parent' => 'astra-settings[ast-body-font-settings]', 'control' => 'ast-responsive-slider', 'title' => 'Font Size', 'section' => 'section-body-typo' ],
				[ 'name' => 'body-font-family', 'parent' => 'astra-settings[ast-body-font-settings]', 'control' => 'ast-font', 'title' => 'Font Family', 'section' => 'section-body-typo', 'choices' => [ 'inherit' => 'Default', 'Roboto' => 'Roboto' ] ],
				[ 'name' => 'body-divider', 'parent' => 'astra-settings[ast-body-font-settings]', 'control' => 'ast-divider', 'section' => 'section-body-typo' ],
			]
		);
	}
	public function add_dynamic_settings( $ids ) {
		foreach ( $ids as $id ) {
			if ( ! isset( $this->settings[ $id ] ) && isset( $this->dynamic[ $id ] ) ) {
				$this->settings[ $id ] = new WPAIE_Fake_Setting( $id, $this->dynamic[ $id ] );
			}
		}
	}
	public function settings() { return $this->settings; }
	public function controls() { return $this->controls; }
	public function sections() {
		return [
			'section-blog'             => (object) [ 'title' => 'Blog / Archive', 'panel' => 'panel-blog' ],
			'section-container-layout' => (object) [ 'title' => 'Container', 'panel' => 'panel-global' ],
			'section-body-typo'        => (object) [ 'title' => 'Body', 'panel' => 'panel-global' ],
		];
	}
	public function panels() {
		return [
			'panel-blog'   => (object) [ 'title' => 'Blog' ],
			'panel-global' => (object) [ 'title' => 'Global' ],
		];
	}
	public function get_setting( $id ) { return $this->settings[ $id ] ?? null; }
	public function validate_setting_values( $values, $options = [] ) {
		$out = [];
		foreach ( $values as $id => $value ) {
			$setting = $this->get_setting( $id );
			if ( ! $setting ) {
				$out[ $id ] = new WP_Error( 'unrecognized', 'Setting does not exist or is unrecognized.' );
				continue;
			}
			$validity = $setting->validate( $value );
			if ( ! is_wp_error( $validity ) ) {
				$sanitized = $setting->sanitize( $value );
				$validity  = is_wp_error( $sanitized ) ? $sanitized : ( $sanitized === null ? new WP_Error( 'invalid_value', 'Invalid value.' ) : true );
			}
			$out[ $id ] = $validity;
		}
		return $out;
	}
	public function save_changeset_post( $args ) {
		if ( $this->published ) {
			return new WP_Error( 'changeset_already_published', 'The previous set of changes has already been published.' );
		}
		$values = [];
		foreach ( $args['data'] as $id => $params ) {
			$values[ $id ] = $params['value'];
		}
		$this->add_dynamic_settings( array_keys( $values ) );
		$validities = $this->validate_setting_values( $values );
		if ( array_filter( $validities, 'is_wp_error' ) ) {
			return new WP_Error( 'transaction_fail', '', [ 'setting_validities' => $validities ] );
		}
		$this->published                          = true;
		$GLOBALS['wp_test']['customizer_publish'] = ( $GLOBALS['wp_test']['customizer_publish'] ?? 0 ) + 1;
		do_action( 'customize_save', $this );
		foreach ( $values as $id => $value ) {
			$this->settings[ $id ]->save_value( $value );
		}
		do_action( 'customize_save_after', $this );
		return [ 'setting_validities' => $validities ];
	}
}

function get_theme_mods() { return []; }

test( 'customizer: lists theme settings incl. Astra group settings; layout controls and other tools\' settings excluded', function () {
	WPAIE_Customizer_State::$manager = null;
	$out = wpaie_tool_list_customizer_settings( [] );
	check( str_contains( $out, 'astra-settings[blog-layout] | Blog › Blog / Archive (section-blog) | "Blog Layout"' ), 'setting with panel, section and label' );
	check( str_contains( $out, 'choices:blog-layout-1=Classic, blog-layout-4=Grid' ) && str_contains( $out, "value:'blog-layout-1'" ), 'choices and current (default) value' );
	check( str_contains( $out, 'astra-settings[font-size-body] | Global › Body (section-body-typo) › Body Font | "Font Size" | type:ast-responsive-slider' ), 'setting inside an Astra group listed (created on demand)' );
	check( str_contains( $out, 'choices:inherit=Default, Roboto' ), 'group setting choices from Astra\'s configuration' );
	check( ! str_contains( $out, 'ast-body-font-settings]' ) && ! str_contains( $out, 'body-divider' ), 'group toggle and divider are not settings' );
	check( ! str_contains( $out, 'blogname' ) && ! str_contains( $out, 'custom_css' ), 'identity and CSS left to their own tools' );
	check( ( $GLOBALS['wp_test']['customizer_args']['settings_previewed'] ?? null ) === false, 'loaded without previewing' );

	check( str_contains( wpaie_tool_list_customizer_settings( [ 'search' => 'body font' ] ), '2 settings match' ), 'search matches the group name' );
	$one = wpaie_tool_get_customizer_setting( [ 'id' => 'astra-settings[font-size-body]' ] );
	check( str_contains( $one, '"desktop": 16' ) && str_contains( $one, 'not set yet' ), 'structured value; reports that the default applies' );
	check( is_wp_error( wpaie_tool_get_customizer_setting( [ 'id' => 'blogname' ] ) ), 'excluded setting refused' );
	check( is_wp_error( wpaie_tool_get_customizer_setting( [ 'id' => 'astra-settings[nope]' ] ) ), 'unknown setting refused' );
} );

test( 'customizer: all-or-nothing publish through the changeset API (sanitize WP_Error too)', function () {
	WPAIE_Customizer_State::$manager = null;
	$GLOBALS['wp_test']['actions']   = [];

	$bad = wpaie_run_tool( 'update_customizer_settings', [ 'changes' => [
		[ 'id' => 'astra-settings[blog-layout]', 'value' => 'blog-layout-4' ],
		[ 'id' => 'astra-settings[body-font-family]', 'value' => [ 'not', 'a', 'string' ] ],
	] ] );
	check( is_wp_error( $bad ) && str_contains( $bad->get_error_message(), 'Font must be a string' ), 'sanitize WP_Error rejects the whole change' );
	check( get_option( 'astra-settings', null ) === null && ! in_array( 'customize_save', $GLOBALS['wp_test']['actions'], true ), 'nothing saved, no save hooks fired' );

	WPAIE_Run_Context::$changes = [];
	$out = wpaie_run_tool( 'update_customizer_settings', [ 'changes' => [
		[ 'id' => 'astra-settings[blog-layout]', 'value' => 'blog-layout-4' ],
		[ 'id' => 'astra-settings[font-size-body]', 'value' => [ 'desktop' => 18, 'tablet' => 16, 'mobile' => 15 ] ],
	] ] );
	check( str_starts_with( (string) $out, 'Saved and published' ), 'saved' );
	check( get_option( 'astra-settings' )['font-size-body']['desktop'] === 18, 'group setting saved' );
	check( $GLOBALS['wp_test']['actions'] === [ 'customize_save', 'customize_save_after' ], 'save hooks fired once, in core\'s order' );

	// Undo in the same request needs a fresh manager (a manager publishes once) – and restores the
	// "not set" state instead of writing the defaults.
	check( wpaie_undo_change( WPAIE_Run_Context::$changes[0]['id'] ) === true, 'undo ok' );
	check( get_option( 'astra-settings' ) === [], 'previously unset keys removed again (defaults apply), not saved as values' );
	check( ( $GLOBALS['wp_test']['customizer_publish'] ?? 0 ) === 2, 'undo published through the changeset API too (theme CSS rebuild)' );
} );

test( 'customizer: undo restores stored values and refuses values the theme no longer accepts', function () {
	WPAIE_Customizer_State::$manager = null;
	update_option( 'astra-settings', [ 'site-content-width' => 1100 ] );
	WPAIE_Run_Context::$changes = [];
	wpaie_run_tool( 'update_customizer_settings', [ 'changes' => [ [ 'id' => 'astra-settings[site-content-width]', 'value' => 1400 ] ] ] );
	check( get_option( 'astra-settings' )['site-content-width'] === 1400, 'changed' );
	check( wpaie_undo_change( WPAIE_Run_Context::$changes[0]['id'] ) === true && get_option( 'astra-settings' )['site-content-width'] === 1100, 'stored previous value restored' );

	// A recorded value that is no longer valid: undo fails without touching anything.
	WPAIE_Customizer_State::$manager = null;
	$res = wpaie_customizer_restore( [ 'astra-settings[site-content-width]' => 300 ], [ 'astra-settings[blog-layout]' ] );
	check( is_wp_error( $res ) && str_contains( $res->get_error_message(), 'no longer accepted' ), 'invalid previous value refused' );
	check( get_option( 'astra-settings' )['site-content-width'] === 1100, 'nothing changed by the refused undo' );
} );

test( 'customizer: only for administrators with the customize capability', function () {
	$GLOBALS['wp_test']['denied_caps'] = [ 'manage_options' ];
	update_option( WPAIE_OPTION_KEY, [ 'allow_editors' => true ] );
	check( ! in_array( 'update_customizer_settings', wpaie_available_tool_names(), true ), 'hidden from editors / shop managers' );
	$admin = wpaie_system_prompt( [ 'update_customizer_settings', 'update_custom_css' ] );
	check( str_contains( $admin, 'Customizer settings (Appearance' ) && ! str_contains( $admin, 'change Customizer settings other than' ), 'prompt no longer says Astra settings are out of reach' );
} );

echo "\n" . ( $failures ? "$failures of $count checks FAILED\n" : "All $count checks passed\n" );
exit( $failures ? 1 : 0 );
