<?php
/**
 * Fake Anthropic API for the streaming integration test (php -S router).
 *
 *   POST /v1/messages        → streams a short answer as server-sent events
 *   POST /v1/messages?fail=1 → 529 overloaded (JSON error body)
 */

$body = json_decode( (string) file_get_contents( 'php://input' ), true );

if ( isset( $_GET['fail'] ) || ( $body['metadata']['fail'] ?? false ) ) {
	http_response_code( 529 );
	header( 'Content-Type: application/json' );
	header( 'request-id: req_fake' );
	header( 'retry-after: 1' );
	echo json_encode( [ 'type' => 'error', 'error' => [ 'type' => 'overloaded_error', 'message' => 'Overloaded' ] ] );
	return true;
}

if ( empty( $body['stream'] ) || ( $_SERVER['HTTP_X_API_KEY'] ?? '' ) !== 'sk-ant-test' ) {
	http_response_code( 400 );
	echo json_encode( [ 'type' => 'error', 'error' => [ 'type' => 'invalid_request_error', 'message' => 'expected stream=true and the test key' ] ] );
	return true;
}

header( 'Content-Type: text/event-stream' );
$events = [
	[ 'message_start', [ 'message' => [ 'id' => 'msg_fake', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'content' => [], 'stop_reason' => null, 'usage' => [ 'input_tokens' => 100, 'output_tokens' => 1 ] ] ] ],
	[ 'content_block_start', [ 'index' => 0, 'content_block' => [ 'type' => 'text', 'text' => '' ] ] ],
	[ 'content_block_delta', [ 'index' => 0, 'delta' => [ 'type' => 'text_delta', 'text' => 'Hotovo, ' ] ] ],
	[ 'ping', [] ],
	[ 'content_block_delta', [ 'index' => 0, 'delta' => [ 'type' => 'text_delta', 'text' => 'změněno.' ] ] ],
	[ 'content_block_stop', [ 'index' => 0 ] ],
	[ 'message_delta', [ 'delta' => [ 'stop_reason' => 'end_turn' ], 'usage' => [ 'output_tokens' => 7 ] ] ],
	[ 'message_stop', [] ],
];
foreach ( $events as [ $type, $data ] ) {
	echo "event: $type\ndata: " . json_encode( [ 'type' => $type ] + $data ) . "\n\n";
	flush();
	usleep( 20000 );
}
return true;
