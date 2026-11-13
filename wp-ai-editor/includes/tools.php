<?php
/**
 * Tools the model can call: definitions, labels, dispatch and implementations.
 *
 * Every implementation returns a string (sent back to the model) or a WP_Error, whose message
 * is sent back as an error tool_result so the model can react to it. Tool descriptions and
 * results are read by the model only, so they are plain English and not translated; the labels
 * shown in the chat are translatable.
 */

defined( 'ABSPATH' ) || exit;

/** Longest tool result sent to the model (characters). */
define( 'WPAIE_TOOL_RESULT_MAX_CHARS', 150000 );

const WPAIE_POST_STATUSES = [ 'draft', 'publish', 'pending', 'private' ];

// ─── Definitions ──────────────────────────────────────────────────────────────

function wpaie_tool_definitions(): array {
	$no_input = [
		'type'       => 'object',
		'properties' => new stdClass(),
	];

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
		],
		[
			'name'         => 'get_post_content',
			'description'  => 'Return the title, status and content (HTML with block markup) of a post or page.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'ID of the post or page.' ],
				],
			],
		],
		[
			'name'         => 'update_post_content',
			'description'  => 'Update the title and/or content of a post or page. By default it is saved as a draft for manual review; a published post is taken off the site by that. Pass status="publish" to keep it published (only when the user explicitly asks).',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer' ],
					'title'   => [ 'type' => 'string', 'description' => 'New title (optional).' ],
					'content' => [ 'type' => 'string', 'description' => 'New complete content as HTML/block markup (optional). Replaces the whole existing content.' ],
					'status'  => [ 'type' => 'string', 'enum' => WPAIE_POST_STATUSES, 'description' => 'Default draft.' ],
				],
			],
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
					'status'    => [ 'type' => 'string', 'enum' => WPAIE_POST_STATUSES, 'description' => 'Default draft.' ],
				],
			],
		],
		[
			'name'         => 'get_theme_json',
			'description'  => 'Read the theme styles (theme.json: colors, fonts, typography) and the user Global Styles customizations, which is where update_theme_json writes.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'update_theme_json',
			'description'  => 'Merge an object into the settings or styles section of the user Global Styles (theme files are not modified). Objects are merged deeply; lists (e.g. settings.color.palette) are replaced as a whole, so always send the complete list. Only works with themes that have theme.json. Takes effect immediately.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'section', 'data' ],
				'properties' => [
					'section' => [ 'type' => 'string', 'enum' => [ 'settings', 'styles' ] ],
					'data'    => [ 'type' => 'object', 'description' => 'Object to merge into the section.' ],
				],
			],
		],
		[
			'name'         => 'list_templates',
			'description'  => 'List the block templates and template parts (FSE) of the active theme with their slugs.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'get_template_content',
			'description'  => 'Return the content (block markup) of a block template or template part.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'template_slug' ],
				'properties' => [
					'template_slug' => [ 'type' => 'string', 'description' => 'Slug, e.g. "index", "single", "header", "footer".' ],
					'template_type' => [ 'type' => 'string', 'enum' => [ 'wp_template', 'wp_template_part' ], 'description' => 'wp_template (default) or wp_template_part for parts such as header/footer.' ],
				],
			],
		],
		[
			'name'         => 'update_template_content',
			'description'  => 'Save new content (block markup) for a block template or template part as a user customization (can be reverted in the Site Editor). Takes effect immediately.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'template_slug', 'content' ],
				'properties' => [
					'template_slug' => [ 'type' => 'string' ],
					'template_type' => [ 'type' => 'string', 'enum' => [ 'wp_template', 'wp_template_part' ], 'description' => 'Default wp_template.' ],
					'content'       => [ 'type' => 'string', 'description' => 'Complete new content as HTML/block markup.' ],
				],
			],
		],
		[
			'name'         => 'list_menus',
			'description'  => 'List classic navigation menus with their ID, name and items.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'update_menu',
			'description'  => 'Add an item to a navigation menu or rename an existing item. Takes effect immediately.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'menu_id', 'action' ],
				'properties' => [
					'menu_id'    => [ 'type' => 'integer' ],
					'action'     => [ 'type' => 'string', 'enum' => [ 'add_item', 'rename_item' ] ],
					'item_title' => [ 'type' => 'string', 'description' => 'Item label.' ],
					'item_url'   => [ 'type' => 'string', 'description' => 'URL (add_item only).' ],
					'item_id'    => [ 'type' => 'integer', 'description' => 'Item ID (rename_item only).' ],
				],
			],
		],
		[
			'name'         => 'get_site_identity',
			'description'  => 'Read the site identity: title, tagline, logo (custom_logo), header image, site icon, and which of these the theme supports.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'update_site_identity',
			'description'  => 'Change the site identity (Appearance → Customize → Site Identity): title, tagline, logo, header image. Takes effect immediately.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'title'               => [ 'type' => 'string', 'description' => 'New site title.' ],
					'tagline'             => [ 'type' => 'string', 'description' => 'New tagline.' ],
					'remove_logo'         => [ 'type' => 'boolean', 'description' => 'true = remove the logo.' ],
					'logo_attachment_id'  => [ 'type' => 'integer', 'description' => 'ID of an image in the Media Library to use as the new logo.' ],
					'remove_header_image' => [ 'type' => 'boolean', 'description' => 'true = remove the header image.' ],
				],
			],
		],
		[
			'name'         => 'get_site_context',
			'description'  => 'Return the technical site context (theme, classic vs. block/FSE, theme.json, detected builders). It is already in the system prompt; use only to double-check.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'get_custom_css',
			'description'  => 'Read the Additional CSS (Appearance → Customize → Additional CSS).',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'update_custom_css',
			'description'  => 'Write Additional CSS. Use for visual changes theme.json cannot do (e.g. on classic themes). mode="append" (default) adds the CSS at the end, mode="replace" overwrites everything. Takes effect immediately.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'css' ],
				'properties' => [
					'css'  => [ 'type' => 'string' ],
					'mode' => [ 'type' => 'string', 'enum' => [ 'append', 'replace' ] ],
				],
			],
		],
	];
}

/**
 * Short human-readable label for a tool call, shown in the chat.
 */
function wpaie_tool_label( string $name, array $input ): string {
	$post_id = (int) ( $input['post_id'] ?? 0 );
	$slug    = (string) ( $input['template_slug'] ?? '?' );

	return match ( $name ) {
		'list_posts'              => __( 'Loading pages and posts…', 'wp-ai-editor' ),
		/* translators: %d: post ID */
		'get_post_content'        => sprintf( __( 'Reading #%d…', 'wp-ai-editor' ), $post_id ),
		/* translators: %d: post ID */
		'update_post_content'     => sprintf( __( 'Saving post #%d…', 'wp-ai-editor' ), $post_id ),
		'create_post'             => sprintf(
			( $input['post_type'] ?? 'post' ) === 'page'
				/* translators: %s: page title */
				? __( 'Creating page "%s"…', 'wp-ai-editor' )
				/* translators: %s: post title */
				: __( 'Creating post "%s"…', 'wp-ai-editor' ),
			(string) ( $input['title'] ?? '' )
		),
		'get_theme_json'          => __( 'Reading global styles…', 'wp-ai-editor' ),
		/* translators: %s: theme.json section (settings or styles) */
		'update_theme_json'       => sprintf( __( 'Updating global styles (%s)…', 'wp-ai-editor' ), (string) ( $input['section'] ?? '?' ) ),
		'list_templates'          => __( 'Loading templates…', 'wp-ai-editor' ),
		/* translators: %s: template slug */
		'get_template_content'    => sprintf( __( 'Reading template "%s"…', 'wp-ai-editor' ), $slug ),
		/* translators: %s: template slug */
		'update_template_content' => sprintf( __( 'Saving template "%s"…', 'wp-ai-editor' ), $slug ),
		'list_menus'              => __( 'Loading menus…', 'wp-ai-editor' ),
		/* translators: %d: menu ID */
		'update_menu'             => sprintf( __( 'Updating menu #%d…', 'wp-ai-editor' ), (int) ( $input['menu_id'] ?? 0 ) ),
		'get_site_identity'       => __( 'Reading site identity…', 'wp-ai-editor' ),
		'update_site_identity'    => __( 'Updating site identity…', 'wp-ai-editor' ),
		'get_site_context'        => __( 'Checking site context…', 'wp-ai-editor' ),
		'get_custom_css'          => __( 'Reading Additional CSS…', 'wp-ai-editor' ),
		'update_custom_css'       => __( 'Updating Additional CSS…', 'wp-ai-editor' ),
		/* translators: %s: tool name */
		default                   => sprintf( __( 'Running %s…', 'wp-ai-editor' ), $name ),
	};
}

/**
 * Run a tool. Never throws: unexpected exceptions become a WP_Error for the model.
 *
 * @return string|WP_Error
 */
function wpaie_run_tool( string $name, array $input ) {
	try {
		$result = match ( $name ) {
			'list_posts'              => wpaie_tool_list_posts( $input ),
			'get_post_content'        => wpaie_tool_get_post( $input ),
			'update_post_content'     => wpaie_tool_update_post( $input ),
			'create_post'             => wpaie_tool_create_post( $input ),
			'get_theme_json'          => wpaie_tool_get_theme_json(),
			'update_theme_json'       => wpaie_tool_update_theme_json( $input ),
			'list_templates'          => wpaie_tool_list_templates(),
			'get_template_content'    => wpaie_tool_get_template( $input ),
			'update_template_content' => wpaie_tool_update_template( $input ),
			'list_menus'              => wpaie_tool_list_menus(),
			'update_menu'             => wpaie_tool_update_menu( $input ),
			'get_site_identity'       => wpaie_tool_get_site_identity(),
			'update_site_identity'    => wpaie_tool_update_site_identity( $input ),
			'get_site_context'        => wpaie_site_context(),
			'get_custom_css'          => wpaie_tool_get_custom_css(),
			'update_custom_css'       => wpaie_tool_update_custom_css( $input ),
			default                   => new WP_Error( 'wpaie_tool', "Unknown tool: $name" ),
		};
	} catch ( Throwable $e ) {
		wpaie_log( 'Tool crashed', [ 'tool' => $name, 'error' => $e->getMessage() ] );
		return new WP_Error( 'wpaie_tool', sprintf( 'Tool %s failed: %s', $name, $e->getMessage() ) );
	}

	if ( is_string( $result ) && mb_strlen( $result ) > WPAIE_TOOL_RESULT_MAX_CHARS ) {
		$result = mb_substr( $result, 0, WPAIE_TOOL_RESULT_MAX_CHARS )
			. "\n\n[OUTPUT TRUNCATED – longer than " . WPAIE_TOOL_RESULT_MAX_CHARS . ' characters. Do not use it to overwrite the whole content; the end is missing.]';
	}
	return $result;
}

function wpaie_tool_error( string $message ): WP_Error {
	return new WP_Error( 'wpaie_tool', $message );
}

// ─── Posts ────────────────────────────────────────────────────────────────────

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
function wpaie_get_editable_post( int $id ) {
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
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
	return "Title: {$post->post_title}\nType: {$post->post_type}\nStatus: {$post->post_status}\n\nContent:\n{$post->post_content}";
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
	if ( ! isset( $in['title'] ) && ! isset( $in['content'] ) && $status === $post->post_status ) {
		return wpaie_tool_error( 'Nothing to change (no title, content or new status given).' );
	}

	$args = [
		'ID'          => $id,
		'post_status' => $status,
	];
	if ( isset( $in['title'] ) ) {
		$args['post_title'] = (string) $in['title'];
	}
	if ( isset( $in['content'] ) ) {
		$args['post_content'] = (string) $in['content'];
	}

	$result = wp_update_post( wp_slash( $args ), true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

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

	$id = wp_insert_post(
		wp_slash(
			[
				'post_type'    => $type,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $content,
			]
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}

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

// ─── Global styles (theme.json) ───────────────────────────────────────────────

function wpaie_theme_has_theme_json(): bool {
	return function_exists( 'wp_theme_has_theme_json' )
		? wp_theme_has_theme_json()
		: file_exists( get_stylesheet_directory() . '/theme.json' ) || file_exists( get_template_directory() . '/theme.json' );
}

/**
 * The post that stores the user's Global Styles for the active theme (created on demand by core).
 */
function wpaie_user_global_styles_post(): ?WP_Post {
	$id   = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post ? $post : null;
}

function wpaie_tool_get_theme_json(): string {
	if ( ! wpaie_theme_has_theme_json() ) {
		return 'The active theme has no theme.json, so global styles are not used here. Use Additional CSS for visual changes.';
	}

	$theme = WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
	$post  = wpaie_user_global_styles_post();
	$user  = $post ? ( json_decode( $post->post_content, true ) ?: [] ) : [];
	unset( $user['isGlobalStylesUserThemeJSON'], $user['version'] );

	$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
	return "Theme styles (theme.json, read-only):\n" . wp_json_encode( $theme, $flags )
		. "\n\nUser Global Styles customizations (take precedence; update_theme_json writes here):\n"
		. ( $user ? wp_json_encode( $user, $flags ) : '(none)' );
}

/**
 * Deep-merge for theme.json: objects merge key by key, lists are replaced as a whole
 * (index-wise merging would corrupt e.g. a color palette).
 */
function wpaie_merge_theme_json( array $base, array $patch ): array {
	foreach ( $patch as $key => $value ) {
		if ( is_array( $value ) && ! array_is_list( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $base[ $key ] ) ) {
			$base[ $key ] = wpaie_merge_theme_json( $base[ $key ], $value );
		} else {
			$base[ $key ] = $value;
		}
	}
	return $base;
}

function wpaie_tool_update_theme_json( array $in ) {
	$section = $in['section'] ?? '';
	$data    = $in['data'] ?? null;

	if ( ! in_array( $section, [ 'settings', 'styles' ], true ) ) {
		return wpaie_tool_error( 'section must be "settings" or "styles".' );
	}
	if ( ! is_array( $data ) || ( $data !== [] && array_is_list( $data ) ) ) {
		return wpaie_tool_error( 'data must be an object (e.g. {"color": {"background": "#fff"}}).' );
	}
	if ( ! wpaie_theme_has_theme_json() ) {
		return wpaie_tool_error( 'The active theme has no theme.json, so global styles would have no effect. Use update_custom_css.' );
	}

	$post = wpaie_user_global_styles_post();
	if ( ! $post ) {
		return wpaie_tool_error( 'Could not find or create the Global Styles storage post.' );
	}

	$existing = json_decode( $post->post_content, true );
	if ( ! is_array( $existing ) ) {
		$existing = [];
	}
	$existing['version']                     = $existing['version'] ?? 2;
	$existing['isGlobalStylesUserThemeJSON'] = true;
	$existing[ $section ]                    = wpaie_merge_theme_json( (array) ( $existing[ $section ] ?? [] ), $data );

	$result = wp_update_post(
		wp_slash(
			[
				'ID'           => $post->ID,
				'post_content' => wp_json_encode( $existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			]
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
		wp_clean_theme_json_cache();
	} else {
		WP_Theme_JSON_Resolver::clean_cached_data();
	}
	return "Global styles updated (section $section). Live immediately; can be reverted under Appearance → Editor → Styles → Revisions.";
}

// ─── Block templates (FSE) ────────────────────────────────────────────────────

function wpaie_template_type( array $in ): string {
	return ( $in['template_type'] ?? '' ) === 'wp_template_part' ? 'wp_template_part' : 'wp_template';
}

function wpaie_tool_list_templates(): string {
	$rows = [];
	foreach ( [ 'wp_template', 'wp_template_part' ] as $type ) {
		foreach ( get_block_templates( [], $type ) as $t ) {
			$rows[] = sprintf( 'slug:%s | title:%s | type:%s | source:%s', $t->slug, $t->title, $type, $t->source );
		}
	}
	return $rows ? implode( "\n", $rows ) : 'No block templates found (the theme is probably not a block/FSE theme).';
}

function wpaie_tool_get_template( array $in ) {
	$slug = sanitize_title( (string) ( $in['template_slug'] ?? '' ) );
	$type = wpaie_template_type( $in );
	if ( $slug === '' ) {
		return wpaie_tool_error( 'template_slug is missing.' );
	}

	$t = get_block_template( get_stylesheet() . '//' . $slug, $type );
	if ( ! $t ) {
		return wpaie_tool_error( sprintf( 'Template "%s" (%s) not found. list_templates returns the available ones.', $slug, $type ) );
	}
	return "Slug: {$t->slug}\nTitle: {$t->title}\nType: $type\nSource: {$t->source}\n\nContent:\n{$t->content}";
}

function wpaie_tool_update_template( array $in ) {
	$slug    = sanitize_title( (string) ( $in['template_slug'] ?? '' ) );
	$type    = wpaie_template_type( $in );
	$content = (string) ( $in['content'] ?? '' );

	if ( $slug === '' ) {
		return wpaie_tool_error( 'template_slug is missing.' );
	}
	if ( trim( $content ) === '' ) {
		return wpaie_tool_error( 'content is missing.' );
	}

	$theme    = get_stylesheet();
	$template = get_block_template( $theme . '//' . $slug, $type );

	if ( $template && ! empty( $template->wp_id ) ) {
		// Already customized: update the stored copy.
		$result = wp_update_post(
			wp_slash(
				[
					'ID'           => $template->wp_id,
					'post_content' => $content,
				]
			),
			true
		);
	} else {
		if ( ! $template && $type === 'wp_template_part' ) {
			return wpaie_tool_error( sprintf( 'Template part "%s" does not exist. list_templates returns the available ones.', $slug ) );
		}
		// First customization (or a brand-new template): store a user copy, as the Site Editor does.
		$result = wp_insert_post(
			wp_slash(
				[
					'post_type'    => $type,
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => $template ? $template->title : $slug,
					'post_content' => $content,
				]
			),
			true
		);
		if ( ! is_wp_error( $result ) ) {
			// tax_input silently fails for these taxonomies; assign the terms explicitly.
			wp_set_object_terms( $result, $theme, 'wp_theme' );
			if ( $type === 'wp_template_part' ) {
				wp_set_object_terms( $result, $template->area ?? 'uncategorized', 'wp_template_part_area' );
			}
		}
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return sprintf( 'Template "%s" saved. Live immediately; the original can be restored in the Site Editor.', $slug );
}

// ─── Site identity ────────────────────────────────────────────────────────────

function wpaie_tool_get_site_identity(): string {
	$logo_id  = (int) get_theme_mod( 'custom_logo' );
	$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
	$header   = get_theme_mod( 'header_image' ); // URL | 'remove-header' | false
	$icon_id  = (int) get_option( 'site_icon' );
	$has_hdr  = $header && ! in_array( $header, [ 'remove', 'remove-header' ], true );

	return implode(
		"\n",
		[
			'Site title: ' . get_bloginfo( 'name' ),
			'Tagline: ' . get_bloginfo( 'description' ),
			'Logo (custom_logo): ' . ( $logo_id ? "#$logo_id ($logo_url)" : '(none)' ),
			'Header image: ' . ( $has_hdr ? $header : '(none)' ),
			'Site icon: ' . ( $icon_id ? "#$icon_id" : '(none)' ),
			'Theme supports custom-logo: ' . ( current_theme_supports( 'custom-logo' ) ? 'yes' : 'no' ),
			'Theme supports custom-header: ' . ( current_theme_supports( 'custom-header' ) ? 'yes' : 'no' ),
		]
	);
}

function wpaie_tool_update_site_identity( array $in ) {
	$done = [];

	// Validate everything first so a bad logo ID does not leave a half-applied change.
	$logo_id = 0;
	if ( empty( $in['remove_logo'] ) && ! empty( $in['logo_attachment_id'] ) ) {
		$logo_id = (int) $in['logo_attachment_id'];
		if ( ! wp_attachment_is_image( $logo_id ) ) {
			return wpaie_tool_error( "Attachment #$logo_id does not exist or is not an image." );
		}
	}

	if ( isset( $in['title'] ) && is_string( $in['title'] ) ) {
		update_option( 'blogname', sanitize_text_field( $in['title'] ) );
		$done[] = 'site title set';
	}
	if ( isset( $in['tagline'] ) && is_string( $in['tagline'] ) ) {
		update_option( 'blogdescription', sanitize_text_field( $in['tagline'] ) );
		$done[] = 'tagline set';
	}
	if ( ! empty( $in['remove_logo'] ) ) {
		remove_theme_mod( 'custom_logo' );
		$done[] = 'logo removed';
	} elseif ( $logo_id ) {
		set_theme_mod( 'custom_logo', $logo_id );
		$done[] = "logo set to attachment #$logo_id";
	}
	if ( ! empty( $in['remove_header_image'] ) ) {
		remove_theme_mod( 'header_image' );
		remove_theme_mod( 'header_image_data' );
		$done[] = 'header image removed';
	}

	if ( ! $done ) {
		return wpaie_tool_error( 'No change given (title, tagline, remove_logo, logo_attachment_id, remove_header_image).' );
	}
	return 'Site identity updated: ' . implode( ', ', $done ) . '. Live immediately.';
}

// ─── Additional CSS ───────────────────────────────────────────────────────────

function wpaie_tool_get_custom_css(): string {
	$css = (string) wp_get_custom_css();
	return trim( $css ) === ''
		? 'Additional CSS is empty.'
		: "Current Additional CSS:\n\n" . $css;
}

function wpaie_tool_update_custom_css( array $in ) {
	$css  = $in['css'] ?? '';
	$mode = $in['mode'] ?? 'append';

	if ( ! is_string( $css ) || trim( $css ) === '' ) {
		return wpaie_tool_error( 'css is missing (non-empty string required).' );
	}
	if ( ! in_array( $mode, [ 'append', 'replace' ], true ) ) {
		return wpaie_tool_error( 'mode must be "append" or "replace".' );
	}
	if ( preg_match( '#</?\w+#', $css ) ) {
		return wpaie_tool_error( 'CSS must not contain HTML tags.' );
	}

	$existing = (string) wp_get_custom_css();
	$new_css  = ( $mode === 'append' && trim( $existing ) !== '' ) ? rtrim( $existing ) . "\n\n" . $css : $css;

	$result = wp_update_custom_css_post( $new_css );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return sprintf(
		'Additional CSS %s. Live immediately; the previous version is in the Customizer revisions. Suggest clearing any cache.',
		$mode === 'append' ? 'appended' : 'replaced'
	);
}

// ─── Menus ────────────────────────────────────────────────────────────────────

function wpaie_tool_list_menus(): string {
	$menus = wp_get_nav_menus();
	if ( ! $menus ) {
		return 'No classic navigation menus found (block themes use the Navigation block in templates).';
	}

	$out = [];
	foreach ( $menus as $menu ) {
		$items = wp_get_nav_menu_items( $menu->term_id ) ?: [];
		$list  = $items
			? implode( ', ', array_map( fn( $i ) => "#{$i->ID}:{$i->title}", $items ) )
			: '(empty)';
		$out[] = "ID:{$menu->term_id} | {$menu->name} | items: $list";
	}
	return implode( "\n", $out );
}

function wpaie_tool_update_menu( array $in ) {
	$menu_id = (int) ( $in['menu_id'] ?? 0 );
	$action  = $in['action'] ?? '';
	$title   = trim( (string) ( $in['item_title'] ?? '' ) );

	if ( ! wp_get_nav_menu_object( $menu_id ) ) {
		return wpaie_tool_error( "Menu #$menu_id does not exist. list_menus returns the available ones." );
	}

	if ( $action === 'add_item' ) {
		if ( $title === '' ) {
			return wpaie_tool_error( 'item_title is missing.' );
		}
		$url    = esc_url_raw( (string) ( $in['item_url'] ?? '' ) );
		$result = wp_update_nav_menu_item(
			$menu_id,
			0,
			[
				'menu-item-title'  => $title,
				'menu-item-url'    => $url !== '' ? $url : '#',
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return sprintf( 'Item "%s" added to menu #%d.', $title, $menu_id );
	}

	if ( $action === 'rename_item' ) {
		$item_id = (int) ( $in['item_id'] ?? 0 );
		if ( ! $item_id ) {
			return wpaie_tool_error( 'item_id is missing.' );
		}
		if ( $title === '' ) {
			return wpaie_tool_error( 'item_title is missing.' );
		}
		$item = get_post( $item_id );
		if ( ! $item || $item->post_type !== 'nav_menu_item' ) {
			return wpaie_tool_error( "Menu item #$item_id does not exist." );
		}

		// wp_update_nav_menu_item resets omitted fields to defaults – pass the existing data through.
		$nav    = wp_setup_nav_menu_item( $item );
		$result = wp_update_nav_menu_item(
			$menu_id,
			$item_id,
			[
				'menu-item-title'       => $title,
				'menu-item-url'         => $nav->url,
				'menu-item-object'      => $nav->object,
				'menu-item-object-id'   => $nav->object_id,
				'menu-item-type'        => $nav->type,
				'menu-item-parent-id'   => $nav->menu_item_parent,
				'menu-item-position'    => $nav->menu_order,
				'menu-item-target'      => $nav->target,
				'menu-item-attr-title'  => $nav->attr_title,
				'menu-item-classes'     => implode( ' ', (array) $nav->classes ),
				'menu-item-xfn'         => $nav->xfn,
				'menu-item-description' => $nav->description,
				'menu-item-status'      => 'publish',
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return sprintf( 'Item #%d renamed to "%s".', $item_id, $title );
	}

	return wpaie_tool_error( sprintf( 'Unknown action "%s". Use add_item or rename_item.', (string) $action ) );
}
