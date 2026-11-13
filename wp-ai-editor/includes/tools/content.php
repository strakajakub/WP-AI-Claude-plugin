<?php
/**
 * Content tools: posts and pages.
 */

defined( 'ABSPATH' ) || exit;

const WPAIE_POST_STATUSES = [ 'draft', 'publish', 'pending', 'private' ];

function wpaie_content_tools(): array {
	return [
		[
			'name'         => 'list_posts',
			'description'  => 'List pages or posts (ID, title, type, status, modified date), most recently modified first.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_type' => [ 'type' => 'string', 'enum' => [ 'any', 'post', 'page' ], 'description' => 'Content type, default any.' ],
					'search'    => [ 'type' => 'string', 'description' => 'Text to search for in title/content (optional).' ],
					'limit'     => [ 'type' => 'integer', 'description' => 'Maximum number of results (1–50), default 20.' ],
				],
			],
			'label'        => fn() => __( 'Loading pages and posts…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_list_posts',
		],
		[
			'name'         => 'get_post_content',
			'description'  => 'Return the title, status, excerpt, categories/tags, featured image and content (HTML with block markup) of a post or page.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'ID of the post or page.' ],
				],
			],
			/* translators: %d: post ID */
			'label'        => fn( $in ) => sprintf( __( 'Reading #%d…', 'wp-ai-editor' ), (int) ( $in['post_id'] ?? 0 ) ),
			'run'          => 'wpaie_tool_get_post',
		],
		[
			'name'         => 'update_post_content',
			'description'  => 'Update the title, content and/or excerpt of a post or page. By default it is saved as a draft for manual review; a published post is taken off the site by that. Pass status="publish" to keep it published (only when the user explicitly asks).',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer' ],
					'title'   => [ 'type' => 'string', 'description' => 'New title (optional).' ],
					'content' => [ 'type' => 'string', 'description' => 'New complete content as HTML/block markup (optional). Replaces the whole existing content.' ],
					'excerpt' => [ 'type' => 'string', 'description' => 'New excerpt (optional).' ],
					'status'  => [ 'type' => 'string', 'enum' => WPAIE_POST_STATUSES, 'description' => 'Default draft.' ],
				],
			],
			'write'        => true,
			'live'         => 'wpaie_update_post_is_live',
			/* translators: %d: post ID */
			'label'        => fn( $in ) => sprintf( __( 'Saving post #%d…', 'wp-ai-editor' ), (int) ( $in['post_id'] ?? 0 ) ),
			'preview'      => 'wpaie_preview_update_post',
			'run'          => 'wpaie_tool_update_post',
		],
		[
			'name'         => 'create_post',
			'description'  => 'Create a new post or page. Default status is draft.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'title' ],
				'properties' => [
					'post_type' => [ 'type' => 'string', 'enum' => [ 'post', 'page' ], 'description' => 'Default post.' ],
					'title'     => [ 'type' => 'string' ],
					'content'   => [ 'type' => 'string', 'description' => 'Content as HTML/block markup (optional).' ],
					'excerpt'   => [ 'type' => 'string', 'description' => 'Excerpt (optional).' ],
					'status'    => [ 'type' => 'string', 'enum' => WPAIE_POST_STATUSES, 'description' => 'Default draft.' ],
				],
			],
			'write'        => true,
			'live'         => fn( $in ) => in_array( $in['status'] ?? 'draft', [ 'publish', 'private' ], true ),
			'label'        => fn( $in ) => sprintf(
				( $in['post_type'] ?? 'post' ) === 'page'
					/* translators: %s: page title */
					? __( 'Creating page "%s"…', 'wp-ai-editor' )
					/* translators: %s: post title */
					: __( 'Creating post "%s"…', 'wp-ai-editor' ),
				(string) ( $in['title'] ?? '' )
			),
			'preview'      => 'wpaie_preview_create_post',
			'run'          => 'wpaie_tool_create_post',
		],
	];
}

function wpaie_tool_list_posts( array $in ): string {
	$type  = in_array( $in['post_type'] ?? 'any', [ 'post', 'page' ], true ) ? $in['post_type'] : [ 'post', 'page' ];
	$limit = max( 1, min( (int) ( $in['limit'] ?? 20 ), 50 ) );
	$args  = [
		'post_type'      => $type,
		'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
		'posts_per_page' => $limit,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	];
	if ( ! empty( $in['search'] ) && is_string( $in['search'] ) ) {
		$args['s'] = $in['search'];
	}

	$posts = get_posts( $args );
	if ( ! $posts ) {
		return 'No posts or pages found.';
	}

	return implode(
		"\n",
		array_map(
			fn( WP_Post $p ) => sprintf(
				'ID:%d | %s | type:%s | status:%s | modified:%s',
				$p->ID,
				$p->post_title !== '' ? $p->post_title : '(untitled)',
				$p->post_type,
				$p->post_status,
				$p->post_modified
			),
			$posts
		)
	);
}

/**
 * @return WP_Post|WP_Error
 */
function wpaie_get_editable_post( int $id, array $types = [ 'post', 'page' ] ) {
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || ! in_array( $post->post_type, $types, true ) ) {
		return wpaie_tool_error( "No post or page with ID $id exists." );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return wpaie_tool_error( "The current user is not allowed to edit #$id." );
	}
	return $post;
}

function wpaie_tool_get_post( array $in ) {
	$post = wpaie_get_editable_post( (int) ( $in['post_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$lines = [
		"Title: {$post->post_title}",
		"Type: {$post->post_type}",
		"Status: {$post->post_status}",
		'URL: ' . get_permalink( $post ),
		'Excerpt: ' . ( $post->post_excerpt !== '' ? $post->post_excerpt : '(none)' ),
		'Featured image: ' . ( has_post_thumbnail( $post ) ? '#' . get_post_thumbnail_id( $post ) : '(none)' ),
	];
	if ( $post->post_type === 'post' ) {
		foreach ( [ 'category' => 'Categories', 'post_tag' => 'Tags' ] as $taxonomy => $label ) {
			$terms   = wp_get_post_terms( $post->ID, $taxonomy, [ 'fields' => 'names' ] );
			$lines[] = $label . ': ' . ( ! is_wp_error( $terms ) && $terms ? implode( ', ', $terms ) : '(none)' );
		}
	}
	return implode( "\n", $lines ) . "\n\nContent:\n{$post->post_content}";
}

function wpaie_update_post_is_live( array $in ): bool {
	$status = $in['status'] ?? 'draft';
	if ( in_array( $status, [ 'publish', 'private' ], true ) ) {
		return true;
	}
	// Saving a published post as a draft takes it off the site – also a live change.
	$post = get_post( (int) ( $in['post_id'] ?? 0 ) );
	return $post && $post->post_status === 'publish';
}

function wpaie_preview_update_post( array $in ): string {
	$post = get_post( (int) ( $in['post_id'] ?? 0 ) );
	if ( ! $post ) {
		return '';
	}
	$status = $in['status'] ?? 'draft';
	$html   = '<p><strong>' . esc_html( $post->post_title ) . '</strong> (#' . (int) $post->ID . ')</p>';

	$rows = [];
	if ( $status !== $post->post_status ) {
		$rows[ __( 'Status', 'wp-ai-editor' ) ] = [ $post->post_status, $status ];
	}
	if ( isset( $in['title'] ) && $in['title'] !== $post->post_title ) {
		$rows[ __( 'Title', 'wp-ai-editor' ) ] = [ $post->post_title, (string) $in['title'] ];
	}
	if ( isset( $in['excerpt'] ) && $in['excerpt'] !== $post->post_excerpt ) {
		$rows[ __( 'Excerpt', 'wp-ai-editor' ) ] = [ $post->post_excerpt, (string) $in['excerpt'] ];
	}
	if ( $rows ) {
		$html .= wpaie_preview_table( $rows );
	}
	if ( $post->post_status === 'publish' && $status !== 'publish' ) {
		$html .= '<p class="wpaie-warning">' . esc_html__( 'The post is published; saving it with this status takes it off the site.', 'wp-ai-editor' ) . '</p>';
	}
	if ( isset( $in['content'] ) ) {
		$html .= wpaie_preview_diff( $post->post_content, (string) $in['content'] );
	}
	return $html;
}

function wpaie_tool_update_post( array $in ) {
	$id   = (int) ( $in['post_id'] ?? 0 );
	$post = wpaie_get_editable_post( $id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$status = $in['status'] ?? 'draft';
	if ( ! in_array( $status, WPAIE_POST_STATUSES, true ) ) {
		return wpaie_tool_error( 'Invalid status. Use draft | publish | pending | private.' );
	}
	if ( ! isset( $in['title'] ) && ! isset( $in['content'] ) && ! isset( $in['excerpt'] ) && $status === $post->post_status ) {
		return wpaie_tool_error( 'Nothing to change (no title, content, excerpt or new status given).' );
	}

	$args   = [
		'ID'          => $id,
		'post_status' => $status,
	];
	$before = [ 'post_status' => $post->post_status ];
	foreach ( [ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' ] as $key => $field ) {
		if ( isset( $in[ $key ] ) ) {
			$args[ $field ]   = (string) $in[ $key ];
			$before[ $field ] = $post->$field;
		}
	}

	$result = wp_update_post( wp_slash( $args ), true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	wpaie_record_change(
		'update_post_content',
		/* translators: 1: post title, 2: post ID */
		sprintf( __( 'Edited "%1$s" (#%2$d)', 'wp-ai-editor' ), $post->post_title, $id ),
		[
			[
				'type'    => 'post_fields',
				'post_id' => $id,
				'fields'  => $before,
			],
		]
	);

	$msg = sprintf( '%s #%d saved with status "%s".', $post->post_type === 'page' ? 'Page' : 'Post', $id, $status );
	if ( $post->post_status === 'publish' && $status !== 'publish' ) {
		$msg .= sprintf( ' WARNING: it was published; with status "%s" it is no longer publicly visible until the user publishes it again.', $status );
	} elseif ( $status !== 'publish' ) {
		$msg .= ' The changes await review and manual publishing.';
	}

	$edit = get_edit_post_link( $id, 'raw' );
	if ( $edit ) {
		$msg .= ' Edit: ' . $edit;
	}
	$preview = get_preview_post_link( $id );
	if ( $preview ) {
		$msg .= ' Preview: ' . $preview;
	}
	return $msg;
}

function wpaie_preview_create_post( array $in ): string {
	$rows = [
		__( 'Type', 'wp-ai-editor' )   => [ '', (string) ( $in['post_type'] ?? 'post' ) ],
		__( 'Title', 'wp-ai-editor' )  => [ '', (string) ( $in['title'] ?? '' ) ],
		__( 'Status', 'wp-ai-editor' ) => [ '', (string) ( $in['status'] ?? 'draft' ) ],
	];
	$html = wpaie_preview_table( $rows );
	if ( ! empty( $in['content'] ) ) {
		$html .= wpaie_preview_code( (string) $in['content'] );
	}
	return $html;
}

function wpaie_tool_create_post( array $in ) {
	$type    = $in['post_type'] ?? 'post';
	$title   = (string) ( $in['title'] ?? '' );
	$content = (string) ( $in['content'] ?? '' );
	$status  = $in['status'] ?? 'draft';

	if ( ! in_array( $type, [ 'post', 'page' ], true ) ) {
		return wpaie_tool_error( 'post_type must be "post" or "page".' );
	}
	if ( ! in_array( $status, WPAIE_POST_STATUSES, true ) ) {
		return wpaie_tool_error( 'Invalid status. Use draft | publish | pending | private.' );
	}
	if ( trim( $title ) === '' && trim( $content ) === '' ) {
		return wpaie_tool_error( 'Provide at least a title or content.' );
	}
	$type_object = get_post_type_object( $type );
	if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
		return wpaie_tool_error( "The current user is not allowed to create a $type." );
	}
	if ( $status === 'publish' && ! current_user_can( $type_object->cap->publish_posts ) ) {
		return wpaie_tool_error( "The current user is not allowed to publish a $type; create it as a draft." );
	}

	$id = wp_insert_post(
		wp_slash(
			[
				'post_type'    => $type,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => (string) ( $in['excerpt'] ?? '' ),
			]
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}

	wpaie_record_change(
		'create_post',
		/* translators: 1: post title, 2: post ID */
		sprintf( __( 'Created "%1$s" (#%2$d)', 'wp-ai-editor' ), $title, $id ),
		[
			[
				'type'    => 'trash_post',
				'post_id' => $id,
			],
		]
	);

	$edit = get_edit_post_link( $id, 'raw' );
	return sprintf(
		'%s #%d "%s" created (status: %s).%s',
		$type === 'page' ? 'Page' : 'Post',
		$id,
		$title !== '' ? $title : '(untitled)',
		$status,
		$edit ? " Edit: $edit" : ''
	);
}
