<?php
/**
 * Categories/tags and bulk search & replace in content.
 */

defined( 'ABSPATH' ) || exit;

/** Posts changed by one replace_in_content call. */
define( 'WPAIE_MAX_REPLACE_POSTS', 100 );

const WPAIE_TAXONOMIES = [ 'category', 'post_tag' ];

function wpaie_taxonomy_search_tools(): array {
	return [
		[
			'name'         => 'list_terms',
			'description'  => 'List categories or tags (ID, name, slug, number of posts).',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'taxonomy' ],
				'properties' => [
					'taxonomy' => [ 'type' => 'string', 'enum' => WPAIE_TAXONOMIES ],
					'search'   => [ 'type' => 'string', 'description' => 'Part of the name (optional).' ],
				],
			],
			'label'        => fn( $in ) => ( $in['taxonomy'] ?? '' ) === 'post_tag' ? __( 'Loading tags…', 'wp-ai-editor' ) : __( 'Loading categories…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_list_terms',
		],
		[
			'name'         => 'set_post_terms',
			'description'  => 'Set the categories or tags of a post by name. mode="replace" (default) replaces the current ones, mode="append" adds to them. Missing tags are created; missing categories are created only if the user may manage categories.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id', 'taxonomy', 'terms' ],
				'properties' => [
					'post_id'  => [ 'type' => 'integer' ],
					'taxonomy' => [ 'type' => 'string', 'enum' => WPAIE_TAXONOMIES ],
					'terms'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Names.' ],
					'mode'     => [ 'type' => 'string', 'enum' => [ 'replace', 'append' ] ],
				],
			],
			'write'        => true,
			'live'         => fn( $in ) => get_post_status( (int) ( $in['post_id'] ?? 0 ) ) === 'publish',
			/* translators: %d: post ID */
			'label'        => fn( $in ) => sprintf( __( 'Setting categories/tags of #%d…', 'wp-ai-editor' ), (int) ( $in['post_id'] ?? 0 ) ),
			'preview'      => 'wpaie_preview_set_post_terms',
			'run'          => 'wpaie_tool_set_post_terms',
		],
		[
			'name'         => 'search_content',
			'description'  => 'Find posts and pages whose title or content contains an exact text (case-insensitive). Returns ID, title, status, number of occurrences and a snippet. Use before replace_in_content.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'query' ],
				'properties' => [
					'query' => [ 'type' => 'string' ],
					'limit' => [ 'type' => 'integer', 'description' => 'Maximum number of results (1–100), default 30.' ],
				],
			],
			/* translators: %s: searched text */
			'label'        => fn( $in ) => sprintf( __( 'Searching for "%s"…', 'wp-ai-editor' ), (string) ( $in['query'] ?? '' ) ),
			'run'          => 'wpaie_tool_search_content',
		],
		[
			'name'         => 'replace_in_content',
			'description'  => 'Replace an exact text with another in the content (and optionally titles) of posts and pages. Applies to the given post_ids, or to every post containing the text (max 100). Keeps each post\'s status, so published posts change immediately. Use search_content first and tell the user how many posts will change.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'search', 'replace' ],
				'properties' => [
					'search'         => [ 'type' => 'string' ],
					'replace'        => [ 'type' => 'string' ],
					'post_ids'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Limit to these posts (optional).' ],
					'case_sensitive' => [ 'type' => 'boolean', 'description' => 'Default true.' ],
					'include_titles' => [ 'type' => 'boolean', 'description' => 'Also replace in titles. Default false.' ],
				],
			],
			'cap'          => 'edit_others_posts',
			'write'        => true,
			'live'         => true,
			/* translators: 1: searched text, 2: replacement */
			'label'        => fn( $in ) => sprintf( __( 'Replacing "%1$s" with "%2$s"…', 'wp-ai-editor' ), (string) ( $in['search'] ?? '' ), (string) ( $in['replace'] ?? '' ) ),
			'preview'      => 'wpaie_preview_replace_in_content',
			'run'          => 'wpaie_tool_replace_in_content',
		],
	];
}

// ─── Terms ────────────────────────────────────────────────────────────────────

function wpaie_tool_list_terms( array $in ) {
	$taxonomy = (string) ( $in['taxonomy'] ?? '' );
	if ( ! in_array( $taxonomy, WPAIE_TAXONOMIES, true ) ) {
		return wpaie_tool_error( 'taxonomy must be "category" or "post_tag".' );
	}
	$args = [
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'number'     => 200,
	];
	if ( ! empty( $in['search'] ) ) {
		$args['search'] = (string) $in['search'];
	}
	$terms = get_terms( $args );
	if ( is_wp_error( $terms ) ) {
		return $terms;
	}
	if ( ! $terms ) {
		return 'None found.';
	}
	return implode( "\n", array_map( fn( $t ) => sprintf( 'ID:%d | %s | slug:%s | posts:%d', $t->term_id, $t->name, $t->slug, $t->count ), $terms ) );
}

function wpaie_preview_set_post_terms( array $in ): string {
	$post_id  = (int) ( $in['post_id'] ?? 0 );
	$taxonomy = (string) ( $in['taxonomy'] ?? '' );
	$current  = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );
	$current  = is_wp_error( $current ) ? [] : $current;
	$new      = array_map( 'strval', (array) ( $in['terms'] ?? [] ) );
	if ( ( $in['mode'] ?? 'replace' ) === 'append' ) {
		$new = array_unique( array_merge( $current, $new ) );
	}
	return wpaie_preview_table( [ get_the_title( $post_id ) => [ implode( ', ', $current ), implode( ', ', $new ) ] ] );
}

function wpaie_tool_set_post_terms( array $in ) {
	$post_id  = (int) ( $in['post_id'] ?? 0 );
	$taxonomy = (string) ( $in['taxonomy'] ?? '' );
	$names    = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $in['terms'] ?? [] ) ) ) ) );
	$append   = ( $in['mode'] ?? 'replace' ) === 'append';

	if ( ! in_array( $taxonomy, WPAIE_TAXONOMIES, true ) ) {
		return wpaie_tool_error( 'taxonomy must be "category" or "post_tag".' );
	}
	$post = wpaie_get_editable_post( $post_id, [ 'post' ] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$ids = [];
	foreach ( $names as $name ) {
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( ! $term ) {
			if ( $taxonomy === 'category' && ! current_user_can( 'manage_categories' ) ) {
				return wpaie_tool_error( "Category \"$name\" does not exist and the user may not create categories." );
			}
			$created = wp_insert_term( $name, $taxonomy );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$ids[] = (int) $created['term_id'];
		} else {
			$ids[] = (int) $term->term_id;
		}
	}

	$previous = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
	$result   = wp_set_object_terms( $post_id, $ids, $taxonomy, $append );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	wpaie_record_change(
		'set_post_terms',
		sprintf(
			/* translators: %s: post title */
			$taxonomy === 'category' ? __( 'Categories of "%s" changed', 'wp-ai-editor' ) : __( 'Tags of "%s" changed', 'wp-ai-editor' ),
			$post->post_title
		),
		[
			[
				'type'     => 'terms',
				'post_id'  => $post_id,
				'taxonomy' => $taxonomy,
				'term_ids' => is_wp_error( $previous ) ? [] : array_map( 'intval', $previous ),
			],
		]
	);
	$now = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );
	return sprintf( '%s of #%d are now: %s.', $taxonomy === 'category' ? 'Categories' : 'Tags', $post_id, is_wp_error( $now ) ? '?' : implode( ', ', $now ) );
}

// ─── Search & replace ─────────────────────────────────────────────────────────

/**
 * Posts and pages whose title or content contains $query (case-insensitive, any status but trash).
 *
 * @return WP_Post[]
 */
function wpaie_find_posts_containing( string $query, int $limit ): array {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( $query ) . '%';
	$ids  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN ('post','page')
			   AND post_status IN ('publish','draft','pending','private','future')
			   AND (post_content LIKE %s OR post_title LIKE %s)
			 ORDER BY post_modified DESC
			 LIMIT %d",
			$like,
			$like,
			$limit
		)
	);
	return array_values( array_filter( array_map( 'get_post', array_map( 'intval', (array) $ids ) ) ) );
}

function wpaie_snippet( string $text, string $query, int $radius = 60 ): string {
	$plain = wp_strip_all_tags( $text );
	$pos   = mb_stripos( $plain, $query );
	if ( $pos === false ) {
		return '';
	}
	$start = max( 0, $pos - $radius );
	return ( $start > 0 ? '…' : '' ) . trim( mb_substr( $plain, $start, mb_strlen( $query ) + 2 * $radius ) ) . '…';
}

function wpaie_tool_search_content( array $in ) {
	$query = (string) ( $in['query'] ?? '' );
	if ( mb_strlen( $query ) < 2 ) {
		return wpaie_tool_error( 'query must have at least 2 characters.' );
	}
	$posts = wpaie_find_posts_containing( $query, max( 1, min( (int) ( $in['limit'] ?? 30 ), 100 ) ) );
	if ( ! $posts ) {
		return "No post or page contains \"$query\".";
	}
	$rows = [];
	foreach ( $posts as $p ) {
		$count  = mb_substr_count( mb_strtolower( $p->post_content ), mb_strtolower( $query ) ) + mb_substr_count( mb_strtolower( $p->post_title ), mb_strtolower( $query ) );
		$rows[] = sprintf( 'ID:%d | %s | %s | status:%s | occurrences:%d | %s', $p->ID, $p->post_type, $p->post_title, $p->post_status, $count, wpaie_snippet( $p->post_content, $query ) );
	}
	return implode( "\n", $rows );
}

/**
 * Posts the replacement would change, with their new title/content.
 *
 * @return array<int,array{post:WP_Post,title:string,content:string,count:int}>|WP_Error
 */
function wpaie_plan_replacement( array $in ) {
	$search  = (string) ( $in['search'] ?? '' );
	$replace = (string) ( $in['replace'] ?? '' );
	$case    = (bool) ( $in['case_sensitive'] ?? true );
	$titles  = (bool) ( $in['include_titles'] ?? false );
	if ( $search === '' ) {
		return wpaie_tool_error( 'search is empty.' );
	}

	if ( ! empty( $in['post_ids'] ) ) {
		$posts = array_values( array_filter( array_map( 'get_post', array_map( 'intval', (array) $in['post_ids'] ) ) ) );
		$posts = array_filter( $posts, fn( $p ) => in_array( $p->post_type, [ 'post', 'page' ], true ) );
	} else {
		$posts = wpaie_find_posts_containing( $search, WPAIE_MAX_REPLACE_POSTS + 1 );
	}
	if ( count( $posts ) > WPAIE_MAX_REPLACE_POSTS ) {
		return wpaie_tool_error( 'More than ' . WPAIE_MAX_REPLACE_POSTS . ' posts match; pass post_ids to do it in batches.' );
	}

	$plan = [];
	foreach ( $posts as $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		$count   = 0;
		$content = $case ? str_replace( $search, $replace, $post->post_content, $count ) : str_ireplace( $search, $replace, $post->post_content, $count );
		$title   = $post->post_title;
		if ( $titles ) {
			$n     = 0;
			$title = $case ? str_replace( $search, $replace, $title, $n ) : str_ireplace( $search, $replace, $title, $n );
			$count += $n;
		}
		if ( $count > 0 ) {
			$plan[] = [
				'post'    => $post,
				'title'   => $title,
				'content' => $content,
				'count'   => $count,
			];
		}
	}
	return $plan;
}

function wpaie_preview_replace_in_content( array $in ): string {
	$plan = wpaie_plan_replacement( $in );
	if ( is_wp_error( $plan ) ) {
		return '<p class="wpaie-warning">' . esc_html( $plan->get_error_message() ) . '</p>';
	}
	if ( ! $plan ) {
		return '<p>' . esc_html__( 'No post contains the text, nothing would change.', 'wp-ai-editor' ) . '</p>';
	}
	$html = '<p>' . esc_html(
		sprintf(
			/* translators: %d: number of posts */
			_n( '%d post will change:', '%d posts will change:', count( $plan ), 'wp-ai-editor' ),
			count( $plan )
		)
	) . '</p><ul class="wpaie-preview-list">';
	foreach ( array_slice( $plan, 0, 20 ) as $item ) {
		$html .= '<li>' . esc_html( sprintf( '#%d %s (%s) × %d', $item['post']->ID, $item['post']->post_title, $item['post']->post_status, $item['count'] ) ) . '</li>';
	}
	$html .= '</ul>';
	return $html . wpaie_preview_diff( $plan[0]['post']->post_content, $plan[0]['content'], $plan[0]['post']->post_title );
}

function wpaie_tool_replace_in_content( array $in ) {
	$plan = wpaie_plan_replacement( $in );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	if ( ! $plan ) {
		return 'No post contains the text; nothing changed.';
	}

	$ops     = [];
	$changed = [];
	$total   = 0;
	foreach ( $plan as $item ) {
		$post   = $item['post'];
		$result = wp_update_post(
			wp_slash(
				[
					'ID'           => $post->ID,
					'post_title'   => $item['title'],
					'post_content' => $item['content'],
				]
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			continue;
		}
		$ops[]     = [
			'type'    => 'post_fields',
			'post_id' => $post->ID,
			'fields'  => [
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
			],
		];
		$changed[] = '#' . $post->ID;
		$total    += $item['count'];
	}

	if ( $ops ) {
		wpaie_record_change(
			'replace_in_content',
			sprintf(
				/* translators: 1: searched text, 2: replacement, 3: number of posts */
				__( '"%1$s" → "%2$s" in %3$d posts', 'wp-ai-editor' ),
				(string) $in['search'],
				(string) $in['replace'],
				count( $ops )
			),
			$ops
		);
	}
	return sprintf( 'Replaced %d occurrences in %d posts: %s. Statuses were kept (published posts changed immediately).', $total, count( $changed ), implode( ', ', $changed ) );
}
