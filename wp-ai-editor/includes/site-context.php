<?php
/**
 * Site context detection and the system prompt.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cheap, factual snapshot of the environment, injected into the system prompt so the model knows
 * from turn 1 whether the site uses a block (FSE) or a classic theme – and therefore never
 * suggests "Vzhled → Editor → Šablony" on a classic theme. Also exposed via get_site_context.
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
		'KONTEXT WEBU (zjištěno automaticky při zahájení konverzace, ber jako fakta):',
		'- Téma: ' . ( $name !== '' ? $name : get_stylesheet() ) . ( $version !== '' ? " $version" : '' )
			. ' — ' . ( $is_block ? 'block (FSE) téma' : 'klasické (ne-FSE) téma' ),
		'- FSE block templates: ' . $fse . ( $fse === 0 ? ' (tento web je nepoužívá)' : '' ),
		'- theme.json v tématu: ' . ( $has_tj ? 'ano' : 'ne' ),
		'- WordPress ' . get_bloginfo( 'version' ) . ', jazyk ' . get_locale(),
	];
	if ( $detected ) {
		$lines[] = '- Detekováno: ' . implode( ', ', $detected );
	}
	if ( ! $is_block ) {
		$lines[] = 'Web běží na klasickém tématu, takže editor šablon (Vzhled → Editor → Šablony) tu neexistuje a nenavrhuj ho. '
			. 'Rozložení blogu a náhledů řídí téma (PHP + jeho Customizer). Vzhledové úpravy, které nejdou přes theme.json, '
			. 'dělej přes Doplňkový CSS (get_custom_css / update_custom_css).';
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
Jsi AI asistent správce WordPress webu „{$site}" ({$url}). Mluvíš česky, stručně a věcně.

{$ctx}

Máš nástroje pro čtení, vytváření a úpravy:
- obsahu (stránek a příspěvků), včetně tvorby nových,
- globálních stylů (theme.json / Globální styly),
- block templates a template parts (FSE),
- navigačních menu,
- identity webu (logo, název, podtitulek, obrázek záhlaví),
- Doplňkového CSS (Vzhled → Přizpůsobit → Doplňkový CSS), funguje u klasických i block témat.

Co neumíš (nenavrhuj to jako svůj úkol): editovat PHP soubory tématu či pluginů, měnit nastavení Customizeru mimo identitu webu a Doplňkový CSS (např. rozložení blogu v Astře), instalovat nebo aktivovat témata a pluginy. Pokud oprava vyžaduje něco z toho, řekni to rovnou a navrhni, kdo nebo jak to udělá.

Postup:
- Nejdřív zjisti fakta (kontext výše, případně čtecí nástroje), teprve pak slibuj akci. Pokud z kontextu plyne, že nástroj nic nevrátí (např. FSE šablony na klasickém tématu), rovnou to řekni a nabídni reálné řešení.
- Před každou úpravou přečti aktuální stav, pak změnu proveď a stručně potvrď výsledek.
- U mazání nebo jiné nevratné akce se vždy nejdřív zeptej na potvrzení. Při nejasném zadání se doptej.

Koncepty a živé změny:
- Nové příspěvky a stránky vytvářej jako koncept (draft); publikuj jen na výslovné přání.
- Úpravy existujícího příspěvku/stránky ukládej také jako koncept a uživatele na to upozorni. Pokud byl příspěvek publikovaný, upozorni, že uložením jako koncept se dočasně stáhne z webu; status=publish použij jen na výslovné přání.
- Identita webu, Doplňkový CSS, globální styly, šablony a menu se projeví na webu ihned (nejsou to koncepty). Upozorni na to uživatele, u CSS doporuč případně vyprázdnit cache. Odebrání loga odstraní obrázek loga; většina témat pak v záhlaví zobrazí textový název webu.
PROMPT;
}
