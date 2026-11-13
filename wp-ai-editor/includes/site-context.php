<?php
/**
 * Site context detection and the system prompt.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cheap, factual snapshot of the environment, injected into the system prompt so the model knows
 * from turn 1 whether the site uses a block (FSE) or a classic theme – and therefore never
 * suggests the Site Editor on a classic theme. Also exposed via the get_site_context tool.
 */
function wpaie_site_context(): string {
	static $cache = null;
	if ( $cache !== null ) {
		return $cache;
	}

	$theme    = wp_get_theme();
	$name     = (string) $theme->get( 'Name' );
	$version  = (string) $theme->get( 'Version' );
	$is_block = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	$fse      = function_exists( 'get_block_templates' ) ? count( get_block_templates() ) : 0;
	$has_tj   = function_exists( 'wp_theme_has_theme_json' )
		? wp_theme_has_theme_json()
		: file_exists( get_stylesheet_directory() . '/theme.json' );

	$detected = [];
	$known    = [
		'ASTRA_THEME_VERSION' => 'Astra',
		'GENERATE_VERSION'    => 'GeneratePress',
		'ELEMENTOR_VERSION'   => 'Elementor',
		'ET_BUILDER_VERSION'  => 'Divi',
		'FL_BUILDER_VERSION'  => 'Beaver Builder',
	];
	foreach ( $known as $constant => $label ) {
		if ( defined( $constant ) ) {
			$detected[] = $label;
		}
	}

	$lines = [
		'SITE CONTEXT (detected automatically when the conversation started; treat as facts):',
		'- Theme: ' . ( $name !== '' ? $name : get_stylesheet() ) . ( $version !== '' ? " $version" : '' )
			. ' — ' . ( $is_block ? 'block (FSE) theme' : 'classic (non-FSE) theme' ),
		'- FSE block templates: ' . $fse . ( $fse === 0 ? ' (this site does not use them)' : '' ),
		'- theme.json in the theme: ' . ( $has_tj ? 'yes' : 'no' ),
		'- WordPress ' . get_bloginfo( 'version' ) . ', site language ' . get_locale(),
	];
	if ( $detected ) {
		$lines[] = '- Detected: ' . implode( ', ', $detected );
	}
	if ( ! $is_block ) {
		$lines[] = 'The site runs a classic theme, so the Site Editor (Appearance → Editor → Templates) does not exist here; do not suggest it. '
			. 'Blog and archive layouts are controlled by the theme (PHP and its Customizer settings). Visual fixes that theme.json cannot do '
			. 'go through Additional CSS (update_custom_css, if that tool is available to the user; otherwise an administrator has to do it).';
	}

	$cache = implode( "\n", $lines );
	return $cache;
}

/**
 * The system prompt. It is generated once per conversation and then stored with it (frozen):
 * changing the system prompt mid-conversation would invalidate the model's earlier thinking.
 *
 * It describes only what the conversation's tools can do, so an editor is not promised design
 * changes or the admin-only History page.
 *
 * @param string[] $tools Tool names of the conversation (wpaie_available_tool_names()).
 */
function wpaie_system_prompt( array $tools ): string {
	$site     = get_bloginfo( 'name' );
	$url      = home_url();
	$ctx      = wpaie_site_context();
	$has      = fn( string $name ): bool => in_array( $name, $tools, true );
	$is_admin = $has( 'update_custom_css' ) || $has( 'update_theme_json' );

	$abilities = [];
	if ( $has( 'update_post_content' ) ) {
		$content = 'pages and posts (including new ones), excerpts';
		if ( $has( 'set_post_terms' ) ) {
			$content .= ', categories and tags';
		}
		if ( $has( 'set_featured_image' ) ) {
			$content .= ', featured images';
		}
		if ( $has( 'replace_in_content' ) ) {
			$content .= ', search & replace across all content';
		}
		$abilities[] = "- content: $content,";
	}
	if ( $has( 'update_media_alt' ) ) {
		$abilities[] = '- images in the Media Library: you can look at them and write alt texts,';
	}
	$design = [
		'update_theme_json'       => 'global styles (theme.json / Global Styles)',
		'update_template_content' => 'block templates and template parts (FSE)',
		'update_menu'             => 'navigation menus',
		'update_site_identity'    => 'site identity (logo, site title, tagline, header image)',
		'update_custom_css'       => 'Additional CSS (Appearance → Customize → Additional CSS), which works with both classic and block themes',
	];
	foreach ( $design as $tool => $text ) {
		if ( $has( $tool ) ) {
			$abilities[] = "- $text,";
		}
	}
	if ( $has( 'update_seo' ) && function_exists( 'wpaie_seo_plugin' ) && wpaie_seo_plugin() ) {
		$abilities[] = '- SEO titles and meta descriptions (' . wpaie_seo_plugin()['name'] . '),';
	}
	if ( $has( 'update_product' ) ) {
		$abilities[] = '- WooCommerce products: names, descriptions, prices, sale prices, stock (also of variations),';
	}
	$abilities = $abilities ? implode( "\n", $abilities ) : '- (no editing tools are available to this user)';

	$limits = 'edit theme or plugin PHP files, change Customizer settings other than site identity and Additional CSS (e.g. Astra\'s blog layout options), or install or activate themes and plugins.';
	if ( ! $is_admin ) {
		$limits .= ' This user is not an administrator: site-wide design changes (styles, CSS, templates, menus, site identity) are not available to them – suggest asking an administrator.';
	}
	$undo = $is_admin
		? 'Every change you make is logged and the user can undo it from the chat or the History page.'
		: 'Every change you make is logged and the user can undo it from the chat.';
	$live = $is_admin
		? "\n- Site identity, Additional CSS, global styles, templates and menus take effect on the live site immediately (they are not drafts). Tell the user; after CSS changes, suggest clearing any cache. Removing the logo removes the logo image; most themes then show the site title as text in the header."
		: '';

	return <<<PROMPT
You are an AI assistant for a user who manages the WordPress site "{$site}" ({$url}). Be concise and to the point. Reply in the language the user writes in.

{$ctx}

You have tools to read, create and edit:
{$abilities}

You cannot (do not offer these as your task): {$limits} If a fix needs one of these, say so directly and suggest who or how it can be done.

How to work:
- Establish the facts first (the context above, then read tools if needed), and only then promise an action. If the context shows a tool will return nothing (e.g. FSE templates on a classic theme), say so right away and offer a real solution.
- Before every change, read the current state; then make the change and briefly confirm the result.
- Always ask for confirmation before deleting anything or making any other irreversible change. If the request is unclear, ask.
- Some changes need the user's approval: the user sees a preview of the tool call and clicks Apply or Reject, so you do not need to ask "shall I?" in text first. If a tool result says the user rejected a change, do not retry it; ask what they want instead.
- {$undo}
- The user can attach images (screenshots, photos, a new logo). Each one comes with a note giving its Media Library attachment ID, so you can use it directly, e.g. as a featured image.

Drafts and live changes:
- Create new posts and pages as drafts; publish only when the user explicitly asks.
- Save edits to existing posts and pages as drafts too, and tell the user. If the post was published, warn that saving it as a draft takes it off the site until it is published again; use status=publish only when the user explicitly asks.
- Categories, tags, featured images, alt texts, SEO and search & replace change published posts immediately.{$live}
PROMPT;
}
