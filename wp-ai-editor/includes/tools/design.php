<?php
/**
 * Design tools: global styles, templates, Additional CSS, site identity, menus.
 * All of them change the live site immediately and need the edit_theme_options capability.
 */

defined( 'ABSPATH' ) || exit;

function wpaie_design_tools(): array {
	$cap = 'edit_theme_options';
	return [
		[
			'name'         => 'get_site_context',
			'description'  => 'Return the technical site context (theme, classic vs. block/FSE, theme.json, detected builders). It is already in the system prompt; use only to double-check.',
			'input_schema' => wpaie_schema_no_input(),
			'cap'          => 'edit_posts',
			'label'        => fn() => __( 'Checking site context…', 'wp-ai-editor' ),
			'run'          => fn() => wpaie_site_context(),
		],
		[
			'name'         => 'get_theme_json',
			'description'  => 'Read the theme styles (theme.json: colors, fonts, typography) and the user Global Styles customizations, which is where update_theme_json writes.',
			'input_schema' => wpaie_schema_no_input(),
			'cap'          => $cap,
			'label'        => fn() => __( 'Reading global styles…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_get_theme_json',
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
			'cap'          => $cap,
			'write'        => true,
			'live'         => true,
			/* translators: %s: theme.json section (settings or styles) */
			'label'        => fn( $in ) => sprintf( __( 'Updating global styles (%s)…', 'wp-ai-editor' ), (string) ( $in['section'] ?? '?' ) ),
			'preview'      => fn( $in ) => wpaie_preview_code( (string) wp_json_encode( [ (string) ( $in['section'] ?? '' ) => $in['data'] ?? null ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
			'run'          => 'wpaie_tool_update_theme_json',
		],
		[
			'name'         => 'list_templates',
			'description'  => 'List the block templates and template parts (FSE) of the active theme with their slugs.',
			'input_schema' => wpaie_schema_no_input(),
			'cap'          => $cap,
			'label'        => fn() => __( 'Loading templates…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_list_templates',
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
			'cap'          => $cap,
			/* translators: %s: template slug */
			'label'        => fn( $in ) => sprintf( __( 'Reading template "%s"…', 'wp-ai-editor' ), (string) ( $in['template_slug'] ?? '?' ) ),
			'run'          => 'wpaie_tool_get_template',
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
			'cap'          => $cap,
			'write'        => true,
			'live'         => true,
			/* translators: %s: template slug */
			'label'        => fn( $in ) => sprintf( __( 'Saving template "%s"…', 'wp-ai-editor' ), (string) ( $in['template_slug'] ?? '?' ) ),
			'preview'      => 'wpaie_preview_update_template',
			'run'          => 'wpaie_tool_update_template',
		],
		[
			'name'         => 'get_custom_css',
			'description'  => 'Read the Additional CSS (Appearance → Customize → Additional CSS).',
			'input_schema' => wpaie_schema_no_input(),
			'cap'          => $cap,
			'label'        => fn() => __( 'Reading Additional CSS…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_get_custom_css',
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
			'cap'          => $cap,
			'write'        => true,
			'live'         => true,
			'label'        => fn() => __( 'Updating Additional CSS…', 'wp-ai-editor' ),
			'preview'      => 'wpaie_preview_update_custom_css',
			'run'          => 'wpaie_tool_update_custom_css',
		],
		[
			'name'         => 'get_site_identity',
			'description'  => 'Read the site identity: title, tagline, logo (custom_logo), header image, site icon, and which of these the theme supports.',
			'input_schema' => wpaie_schema_no_input(),
			'cap'          => $cap,
			'label'        => fn() => __( 'Reading site identity…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_get_site_identity',
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
			'cap'          => 'manage_options',
			'write'        => true,
			'live'         => true,
			'label'        => fn() => __( 'Updating site identity…', 'wp-ai-editor' ),
			'preview'      => 'wpaie_preview_update_site_identity',
			'run'          => 'wpaie_tool_update_site_identity',
		],
		[
			'name'         => 'list_menus',
			'description'  => 'List classic navigation menus with their ID, name and items.',
			'input_schema' => wpaie_schema_no_input(),
			'cap'          => $cap,
			'label'        => fn() => __( 'Loading menus…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_list_menus',
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
			'cap'          => $cap,
			'write'        => true,
			'live'         => true,
			/* translators: %d: menu ID */
			'label'        => fn( $in ) => sprintf( __( 'Updating menu #%d…', 'wp-ai-editor' ), (int) ( $in['menu_id'] ?? 0 ) ),
			'preview'      => 'wpaie_preview_update_menu',
			'run'          => 'wpaie_tool_update_menu',
		],
	];
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

function wpaie_clean_theme_json_cache(): void {
	if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
		wp_clean_theme_json_cache();
	} elseif ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		WP_Theme_JSON_Resolver::clean_cached_data();
	}
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

	$previous = $post->post_content;
	$existing = json_decode( $previous, true );
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
	wpaie_clean_theme_json_cache();

	wpaie_record_change(
		'update_theme_json',
		/* translators: %s: theme.json section */
		sprintf( __( 'Global styles changed (%s)', 'wp-ai-editor' ), $section ),
		[
			[
				'type'    => 'theme_json',
				'post_id' => $post->ID,
				'content' => $previous,
			],
		]
	);
	return "Global styles updated (section $section). Live immediately; can be reverted under Appearance → Editor → Styles → Revisions or in the AI Editor history.";
}

// ─── Block templates (FSE) ────────────────────────────────────────────────────

function wpaie_template_type( array $in ): string {
	return ( $in['template_type'] ?? '' ) === 'wp_template_part' ? 'wp_template_part' : 'wp_template';
}

function wpaie_find_template( array $in ): ?WP_Block_Template {
	$slug = sanitize_title( (string) ( $in['template_slug'] ?? '' ) );
	if ( $slug === '' ) {
		return null;
	}
	$template = get_block_template( get_stylesheet() . '//' . $slug, wpaie_template_type( $in ) );
	return $template ?: null;
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
	if ( sanitize_title( (string) ( $in['template_slug'] ?? '' ) ) === '' ) {
		return wpaie_tool_error( 'template_slug is missing.' );
	}
	$t = wpaie_find_template( $in );
	if ( ! $t ) {
		return wpaie_tool_error( sprintf( 'Template "%s" (%s) not found. list_templates returns the available ones.', (string) $in['template_slug'], wpaie_template_type( $in ) ) );
	}
	return "Slug: {$t->slug}\nTitle: {$t->title}\nType: " . wpaie_template_type( $in ) . "\nSource: {$t->source}\n\nContent:\n{$t->content}";
}

function wpaie_preview_update_template( array $in ): string {
	$t = wpaie_find_template( $in );
	return wpaie_preview_diff( $t ? $t->content : '', (string) ( $in['content'] ?? '' ), $t ? $t->title : (string) ( $in['template_slug'] ?? '' ) );
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
	$template = wpaie_find_template( $in );

	if ( $template && ! empty( $template->wp_id ) ) {
		// Already customized: update the stored copy.
		$previous = (string) get_post_field( 'post_content', $template->wp_id, 'raw' );
		$result   = wp_update_post(
			wp_slash(
				[
					'ID'           => $template->wp_id,
					'post_content' => $content,
				]
			),
			true
		);
		$undo     = [
			[
				'type'    => 'post_fields',
				'post_id' => (int) $template->wp_id,
				'fields'  => [ 'post_content' => $previous ],
			],
		];
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
		// Undo = delete the user copy, which restores the theme's template.
		$undo = [
			[
				'type'    => 'delete_post',
				'post_id' => is_wp_error( $result ) ? 0 : (int) $result,
			],
		];
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	wpaie_record_change(
		'update_template_content',
		/* translators: %s: template slug */
		sprintf( __( 'Template "%s" changed', 'wp-ai-editor' ), $slug ),
		$undo
	);
	return sprintf( 'Template "%s" saved. Live immediately; can be undone in the AI Editor history or the Site Editor.', $slug );
}

// ─── Additional CSS ───────────────────────────────────────────────────────────

function wpaie_tool_get_custom_css(): string {
	$css = (string) wp_get_custom_css();
	return trim( $css ) === ''
		? 'Additional CSS is empty.'
		: "Current Additional CSS:\n\n" . $css;
}

function wpaie_custom_css_after( array $in ): string {
	$css      = (string) ( $in['css'] ?? '' );
	$existing = (string) wp_get_custom_css();
	return ( ( $in['mode'] ?? 'append' ) !== 'replace' && trim( $existing ) !== '' ) ? rtrim( $existing ) . "\n\n" . $css : $css;
}

function wpaie_preview_update_custom_css( array $in ): string {
	if ( ( $in['mode'] ?? 'append' ) === 'replace' ) {
		return wpaie_preview_diff( (string) wp_get_custom_css(), (string) ( $in['css'] ?? '' ) );
	}
	return '<p>' . esc_html__( 'Appended to the end of Additional CSS:', 'wp-ai-editor' ) . '</p>' . wpaie_preview_code( (string) ( $in['css'] ?? '' ) );
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

	$previous = (string) wp_get_custom_css();
	$result   = wp_update_custom_css_post( wpaie_custom_css_after( $in ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	wpaie_record_change(
		'update_custom_css',
		$mode === 'append' ? __( 'Additional CSS appended', 'wp-ai-editor' ) : __( 'Additional CSS replaced', 'wp-ai-editor' ),
		[
			[
				'type' => 'custom_css',
				'css'  => $previous,
			],
		]
	);
	return sprintf(
		'Additional CSS %s. Live immediately; can be undone in the AI Editor history. Suggest clearing any cache.',
		$mode === 'append' ? 'appended' : 'replaced'
	);
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

function wpaie_preview_update_site_identity( array $in ): string {
	$rows = [];
	if ( isset( $in['title'] ) ) {
		$rows[ __( 'Site title', 'wp-ai-editor' ) ] = [ get_bloginfo( 'name' ), (string) $in['title'] ];
	}
	if ( isset( $in['tagline'] ) ) {
		$rows[ __( 'Tagline', 'wp-ai-editor' ) ] = [ get_bloginfo( 'description' ), (string) $in['tagline'] ];
	}
	$logo = (int) get_theme_mod( 'custom_logo' );
	if ( ! empty( $in['remove_logo'] ) ) {
		$rows[ __( 'Logo', 'wp-ai-editor' ) ] = [ $logo ? "#$logo" : '—', __( '(removed)', 'wp-ai-editor' ) ];
	} elseif ( ! empty( $in['logo_attachment_id'] ) ) {
		$rows[ __( 'Logo', 'wp-ai-editor' ) ] = [ $logo ? "#$logo" : '—', '#' . (int) $in['logo_attachment_id'] ];
	}
	if ( ! empty( $in['remove_header_image'] ) ) {
		$rows[ __( 'Header image', 'wp-ai-editor' ) ] = [ '', __( '(removed)', 'wp-ai-editor' ) ];
	}
	$html = wpaie_preview_table( $rows );
	if ( ! empty( $in['logo_attachment_id'] ) && empty( $in['remove_logo'] ) ) {
		$url = wp_get_attachment_image_url( (int) $in['logo_attachment_id'], 'medium' );
		if ( $url ) {
			$html .= '<p><img class="wpaie-preview-image" src="' . esc_url( $url ) . '" alt="" /></p>';
		}
	}
	return $html;
}

function wpaie_tool_update_site_identity( array $in ) {
	$done = [];
	$undo = [
		'options'    => [],
		'theme_mods' => [],
	];

	// Validate everything first so a bad logo ID does not leave a half-applied change.
	$logo_id = 0;
	if ( empty( $in['remove_logo'] ) && ! empty( $in['logo_attachment_id'] ) ) {
		$logo_id = (int) $in['logo_attachment_id'];
		if ( ! wp_attachment_is_image( $logo_id ) ) {
			return wpaie_tool_error( "Attachment #$logo_id does not exist or is not an image." );
		}
	}

	if ( isset( $in['title'] ) && is_string( $in['title'] ) ) {
		$undo['options']['blogname'] = get_option( 'blogname' );
		update_option( 'blogname', sanitize_text_field( $in['title'] ) );
		$done[] = 'site title set';
	}
	if ( isset( $in['tagline'] ) && is_string( $in['tagline'] ) ) {
		$undo['options']['blogdescription'] = get_option( 'blogdescription' );
		update_option( 'blogdescription', sanitize_text_field( $in['tagline'] ) );
		$done[] = 'tagline set';
	}
	if ( ! empty( $in['remove_logo'] ) || $logo_id ) {
		$undo['theme_mods']['custom_logo'] = get_theme_mod( 'custom_logo', null );
		if ( ! empty( $in['remove_logo'] ) ) {
			remove_theme_mod( 'custom_logo' );
			$done[] = 'logo removed';
		} else {
			set_theme_mod( 'custom_logo', $logo_id );
			$done[] = "logo set to attachment #$logo_id";
		}
	}
	if ( ! empty( $in['remove_header_image'] ) ) {
		$undo['theme_mods']['header_image']      = get_theme_mod( 'header_image', null );
		$undo['theme_mods']['header_image_data'] = get_theme_mod( 'header_image_data', null );
		remove_theme_mod( 'header_image' );
		remove_theme_mod( 'header_image_data' );
		$done[] = 'header image removed';
	}

	if ( ! $done ) {
		return wpaie_tool_error( 'No change given (title, tagline, remove_logo, logo_attachment_id, remove_header_image).' );
	}

	$ops = [];
	if ( $undo['options'] ) {
		$ops[] = [
			'type'   => 'options',
			'values' => $undo['options'],
		];
	}
	if ( $undo['theme_mods'] ) {
		$ops[] = [
			'type'   => 'theme_mods',
			'values' => $undo['theme_mods'],
		];
	}
	wpaie_record_change( 'update_site_identity', __( 'Site identity changed', 'wp-ai-editor' ), $ops );

	return 'Site identity updated: ' . implode( ', ', $done ) . '. Live immediately.';
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

function wpaie_preview_update_menu( array $in ): string {
	$menu = wp_get_nav_menu_object( (int) ( $in['menu_id'] ?? 0 ) );
	$name = $menu ? $menu->name : '#' . (int) ( $in['menu_id'] ?? 0 );
	if ( ( $in['action'] ?? '' ) === 'rename_item' ) {
		$item = get_post( (int) ( $in['item_id'] ?? 0 ) );
		$old  = $item ? wp_setup_nav_menu_item( $item )->title : '';
		return wpaie_preview_table( [ $name => [ $old, (string) ( $in['item_title'] ?? '' ) ] ] );
	}
	return wpaie_preview_table(
		[
			/* translators: %s: menu name */
			sprintf( __( 'New item in %s', 'wp-ai-editor' ), $name ) => [ '', (string) ( $in['item_title'] ?? '' ) . ' → ' . (string) ( $in['item_url'] ?? '#' ) ],
		]
	);
}

/**
 * Rename a menu item, keeping all its other data (wp_update_nav_menu_item resets omitted fields).
 *
 * @return int|WP_Error
 */
function wpaie_rename_menu_item( int $menu_id, int $item_id, string $title ) {
	$item = get_post( $item_id );
	if ( ! $item || $item->post_type !== 'nav_menu_item' ) {
		return wpaie_tool_error( "Menu item #$item_id does not exist." );
	}
	$nav = wp_setup_nav_menu_item( $item );
	return wp_update_nav_menu_item(
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
}

function wpaie_tool_update_menu( array $in ) {
	$menu_id = (int) ( $in['menu_id'] ?? 0 );
	$action  = $in['action'] ?? '';
	$title   = trim( (string) ( $in['item_title'] ?? '' ) );
	$menu    = wp_get_nav_menu_object( $menu_id );

	if ( ! $menu ) {
		return wpaie_tool_error( "Menu #$menu_id does not exist. list_menus returns the available ones." );
	}

	if ( $action === 'add_item' ) {
		if ( $title === '' ) {
			return wpaie_tool_error( 'item_title is missing.' );
		}
		$url     = esc_url_raw( (string) ( $in['item_url'] ?? '' ) );
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			[
				'menu-item-title'  => $title,
				'menu-item-url'    => $url !== '' ? $url : '#',
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			]
		);
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}
		wpaie_record_change(
			'update_menu',
			/* translators: 1: menu item label, 2: menu name */
			sprintf( __( 'Menu item "%1$s" added to %2$s', 'wp-ai-editor' ), $title, $menu->name ),
			[
				[
					'type'    => 'delete_post',
					'post_id' => (int) $item_id,
				],
			]
		);
		return sprintf( 'Item "%s" added to menu #%d (item ID %d).', $title, $menu_id, $item_id );
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
		$old    = wp_setup_nav_menu_item( $item )->title;
		$result = wpaie_rename_menu_item( $menu_id, $item_id, $title );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		wpaie_record_change(
			'update_menu',
			/* translators: 1: old label, 2: new label */
			sprintf( __( 'Menu item "%1$s" renamed to "%2$s"', 'wp-ai-editor' ), $old, $title ),
			[
				[
					'type'    => 'menu_item',
					'menu_id' => $menu_id,
					'item_id' => $item_id,
					'title'   => $old,
				],
			]
		);
		return sprintf( 'Item #%d renamed to "%s".', $item_id, $title );
	}

	return wpaie_tool_error( sprintf( 'Unknown action "%s". Use add_item or rename_item.', (string) $action ) );
}
