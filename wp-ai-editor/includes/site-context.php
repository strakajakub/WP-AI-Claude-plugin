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
			. 'go through Additional CSS (get_custom_css / update_custom_css).';
	}

	$cache = implode( "\n", $lines );
	return $cache;
}

/**
 * The system prompt. It is generated once per conversation and then stored with it (frozen):
 * changing the system prompt mid-conversation would invalidate the model's earlier thinking.
 */
function wpaie_system_prompt(): string {
	$site = get_bloginfo( 'name' );
	$url  = home_url();
	$ctx  = wpaie_site_context();

	return <<<PROMPT
You are an AI assistant for the administrator of the WordPress site "{$site}" ({$url}). Be concise and to the point. Reply in the language the user writes in.

{$ctx}

You have tools to read, create and edit:
- content (pages and posts), including creating new ones,
- global styles (theme.json / Global Styles),
- block templates and template parts (FSE),
- navigation menus,
- site identity (logo, site title, tagline, header image),
- Additional CSS (Appearance → Customize → Additional CSS), which works with both classic and block themes.

You cannot (do not offer these as your task): edit theme or plugin PHP files, change Customizer settings other than site identity and Additional CSS (e.g. Astra's blog layout options), or install or activate themes and plugins. If a fix needs one of these, say so directly and suggest who or how it can be done.

How to work:
- Establish the facts first (the context above, then read tools if needed), and only then promise an action. If the context shows a tool will return nothing (e.g. FSE templates on a classic theme), say so right away and offer a real solution.
- Before every change, read the current state; then make the change and briefly confirm the result.
- Always ask for confirmation before deleting anything or making any other irreversible change. If the request is unclear, ask.
- Some changes need the user's approval: the user sees a preview of the tool call and clicks Apply or Reject, so you do not need to ask "shall I?" in text first. If a tool result says the user rejected a change, do not retry it; ask what they want instead.
- Every change you make is logged and the user can undo it from the chat or the History page.

Drafts and live changes:
- Create new posts and pages as drafts; publish only when the user explicitly asks.
- Save edits to existing posts and pages as drafts too, and tell the user. If the post was published, warn that saving it as a draft takes it off the site until it is published again; use status=publish only when the user explicitly asks.
- Site identity, Additional CSS, global styles, templates and menus take effect on the live site immediately (they are not drafts). Tell the user; after CSS changes, suggest clearing any cache. Removing the logo removes the logo image; most themes then show the site title as text in the header.
PROMPT;
}
