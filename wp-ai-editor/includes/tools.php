<?php
/**
 * Tools the model can call: definitions, labels, dispatch and implementations.
 *
 * Every implementation returns a string (sent back to the model) or a WP_Error, whose message
 * is sent back as an error tool_result so the model can react to it.
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
			'description'  => 'Vypíše stránky nebo příspěvky (ID, název, typ, stav, datum úpravy), seřazené od naposledy upravených.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_type' => [ 'type' => 'string', 'enum' => [ 'any', 'post', 'page' ], 'description' => 'Typ obsahu, výchozí any.' ],
					'search'    => [ 'type' => 'string', 'description' => 'Hledaný text v názvu/obsahu (nepovinné).' ],
					'limit'     => [ 'type' => 'integer', 'description' => 'Max. počet výsledků (1–50), výchozí 20.' ],
				],
			],
		],
		[
			'name'         => 'get_post_content',
			'description'  => 'Vrátí titulek, stav a obsah (HTML s bloky) konkrétního příspěvku nebo stránky.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'ID příspěvku nebo stránky.' ],
				],
			],
		],
		[
			'name'         => 'update_post_content',
			'description'  => 'Aktualizuje titulek a/nebo obsah příspěvku či stránky. Ve výchozím stavu ukládá jako koncept (draft) k ruční kontrole; publikovaný příspěvek se tím stáhne z webu. Pro zachování/zveřejnění předej status="publish" (jen na výslovné přání uživatele).',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer' ],
					'title'   => [ 'type' => 'string', 'description' => 'Nový titulek (nepovinné).' ],
					'content' => [ 'type' => 'string', 'description' => 'Nový kompletní obsah v HTML/blocích (nepovinné). Nahrazuje celý stávající obsah.' ],
					'status'  => [ 'type' => 'string', 'enum' => WPAIE_POST_STATUSES, 'description' => 'Výchozí draft.' ],
				],
			],
		],
		[
			'name'         => 'create_post',
			'description'  => 'Vytvoří nový příspěvek nebo stránku. Výchozí stav je koncept (draft).',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'title' ],
				'properties' => [
					'post_type' => [ 'type' => 'string', 'enum' => [ 'post', 'page' ], 'description' => 'Výchozí post.' ],
					'title'     => [ 'type' => 'string' ],
					'content'   => [ 'type' => 'string', 'description' => 'Obsah v HTML/blocích (nepovinné).' ],
					'status'    => [ 'type' => 'string', 'enum' => WPAIE_POST_STATUSES, 'description' => 'Výchozí draft.' ],
				],
			],
		],
		[
			'name'         => 'get_theme_json',
			'description'  => 'Přečte styly tématu (theme.json: barvy, fonty, typografie) a uživatelské úpravy Globálních stylů, do kterých zapisuje update_theme_json.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'update_theme_json',
			'description'  => 'Sloučí zadaný objekt do sekce settings nebo styles v uživatelských Globálních stylech (nepřepisuje soubory tématu). Objekty se slučují do hloubky, seznamy (např. settings.color.palette) se nahrazují celé – pošli vždy celý seznam. Funguje jen u témat s theme.json. Změna je živá ihned.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'section', 'data' ],
				'properties' => [
					'section' => [ 'type' => 'string', 'enum' => [ 'settings', 'styles' ] ],
					'data'    => [ 'type' => 'object', 'description' => 'Objekt ke sloučení do dané sekce.' ],
				],
			],
		],
		[
			'name'         => 'list_templates',
			'description'  => 'Vypíše block templates a template parts (FSE) aktivního tématu se slugy.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'get_template_content',
			'description'  => 'Vrátí obsah (bloky) block template nebo template part.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'template_slug' ],
				'properties' => [
					'template_slug' => [ 'type' => 'string', 'description' => 'Slug, např. "index", "single", "header", "footer".' ],
					'template_type' => [ 'type' => 'string', 'enum' => [ 'wp_template', 'wp_template_part' ], 'description' => 'wp_template (výchozí) nebo wp_template_part pro části jako header/footer.' ],
				],
			],
		],
		[
			'name'         => 'update_template_content',
			'description'  => 'Uloží nový obsah (bloky) block template nebo template part jako uživatelskou úpravu (lze vrátit v editoru webu). Změna je živá ihned.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'template_slug', 'content' ],
				'properties' => [
					'template_slug' => [ 'type' => 'string' ],
					'template_type' => [ 'type' => 'string', 'enum' => [ 'wp_template', 'wp_template_part' ], 'description' => 'Výchozí wp_template.' ],
					'content'       => [ 'type' => 'string', 'description' => 'Kompletní nový obsah jako HTML/bloky.' ],
				],
			],
		],
		[
			'name'         => 'list_menus',
			'description'  => 'Vypíše klasická navigační menu s ID, názvem a položkami.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'update_menu',
			'description'  => 'Přidá položku do navigačního menu nebo přejmenuje existující položku. Změna je živá ihned.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'menu_id', 'action' ],
				'properties' => [
					'menu_id'    => [ 'type' => 'integer' ],
					'action'     => [ 'type' => 'string', 'enum' => [ 'add_item', 'rename_item' ] ],
					'item_title' => [ 'type' => 'string', 'description' => 'Název položky.' ],
					'item_url'   => [ 'type' => 'string', 'description' => 'URL (jen pro add_item).' ],
					'item_id'    => [ 'type' => 'integer', 'description' => 'ID položky (jen pro rename_item).' ],
				],
			],
		],
		[
			'name'         => 'get_site_identity',
			'description'  => 'Přečte identitu webu: název, podtitulek, logo (custom_logo), obrázek záhlaví, ikonu webu a co z toho téma podporuje.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'update_site_identity',
			'description'  => 'Upraví identitu webu (Vzhled → Přizpůsobit → Identita webu): název, podtitulek, logo, obrázek záhlaví. Změna je živá ihned.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'title'               => [ 'type' => 'string', 'description' => 'Nový název webu.' ],
					'tagline'             => [ 'type' => 'string', 'description' => 'Nový podtitulek.' ],
					'remove_logo'         => [ 'type' => 'boolean', 'description' => 'true = odebrat logo.' ],
					'logo_attachment_id'  => [ 'type' => 'integer', 'description' => 'ID obrázku v knihovně médií pro nové logo.' ],
					'remove_header_image' => [ 'type' => 'boolean', 'description' => 'true = odebrat obrázek záhlaví.' ],
				],
			],
		],
		[
			'name'         => 'get_site_context',
			'description'  => 'Vrátí technický kontext webu (téma, klasické vs. block/FSE, theme.json, detekované buildery). Je už v systémové instrukci; použij jen pro ověření.',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'get_custom_css',
			'description'  => 'Přečte Doplňkový CSS (Vzhled → Přizpůsobit → Doplňkový CSS).',
			'input_schema' => $no_input,
		],
		[
			'name'         => 'update_custom_css',
			'description'  => 'Zapíše Doplňkový CSS. Použij pro vzhledové úpravy, které nejdou přes theme.json (např. u klasických témat). mode="append" (výchozí) přidá CSS na konec, mode="replace" přepíše celý. Změna je živá ihned.',
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
		'list_posts'              => 'Načítám seznam stránek a příspěvků…',
		'get_post_content'        => sprintf( 'Čtu obsah #%d…', $post_id ),
		'update_post_content'     => sprintf( 'Ukládám příspěvek #%d…', $post_id ),
		'create_post'             => sprintf(
			'Vytvářím %s „%s“…',
			( $input['post_type'] ?? 'post' ) === 'page' ? 'stránku' : 'příspěvek',
			(string) ( $input['title'] ?? '' )
		),
		'get_theme_json'          => 'Čtu globální styly…',
		'update_theme_json'       => sprintf( 'Upravuji globální styly (%s)…', (string) ( $input['section'] ?? '?' ) ),
		'list_templates'          => 'Načítám šablony…',
		'get_template_content'    => sprintf( 'Čtu šablonu „%s“…', $slug ),
		'update_template_content' => sprintf( 'Ukládám šablonu „%s“…', $slug ),
		'list_menus'              => 'Načítám menu…',
		'update_menu'             => sprintf( 'Upravuji menu #%d…', (int) ( $input['menu_id'] ?? 0 ) ),
		'get_site_identity'       => 'Čtu identitu webu…',
		'update_site_identity'    => 'Upravuji identitu webu…',
		'get_site_context'        => 'Zjišťuji kontext webu…',
		'get_custom_css'          => 'Čtu Doplňkový CSS…',
		'update_custom_css'       => 'Upravuji Doplňkový CSS…',
		default                   => sprintf( 'Spouštím %s…', $name ),
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
			default                   => new WP_Error( 'wpaie_tool', "Neznámý nástroj: $name" ),
		};
	} catch ( Throwable $e ) {
		wpaie_log( 'Tool crashed', [ 'tool' => $name, 'error' => $e->getMessage() ] );
		return new WP_Error( 'wpaie_tool', sprintf( 'Nástroj %s selhal: %s', $name, $e->getMessage() ) );
	}

	if ( is_string( $result ) && mb_strlen( $result ) > WPAIE_TOOL_RESULT_MAX_CHARS ) {
		$result = mb_substr( $result, 0, WPAIE_TOOL_RESULT_MAX_CHARS )
			. "\n\n[VÝSTUP ZKRÁCEN – je delší než " . WPAIE_TOOL_RESULT_MAX_CHARS . ' znaků. Nepoužívej ho k přepsání celého obsahu, chyběl by konec.]';
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
		return 'Žádné příspěvky ani stránky nenalezeny.';
	}

	return implode(
		"\n",
		array_map(
			fn( WP_Post $p ) => sprintf(
				'ID:%d | %s | typ:%s | stav:%s | upraveno:%s',
				$p->ID,
				$p->post_title !== '' ? $p->post_title : '(bez názvu)',
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
		return wpaie_tool_error( "Příspěvek ani stránka s ID $id neexistuje." );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return wpaie_tool_error( "Nemáš oprávnění upravovat #$id." );
	}
	return $post;
}

function wpaie_tool_get_post( array $in ) {
	$post = wpaie_get_editable_post( (int) ( $in['post_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	return "Titulek: {$post->post_title}\nTyp: {$post->post_type}\nStav: {$post->post_status}\n\nObsah:\n{$post->post_content}";
}

function wpaie_tool_update_post( array $in ) {
	$id   = (int) ( $in['post_id'] ?? 0 );
	$post = wpaie_get_editable_post( $id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$status = $in['status'] ?? 'draft';
	if ( ! in_array( $status, WPAIE_POST_STATUSES, true ) ) {
		return wpaie_tool_error( 'Neplatný stav. Použij draft | publish | pending | private.' );
	}
	if ( ! isset( $in['title'] ) && ! isset( $in['content'] ) && $status === $post->post_status ) {
		return wpaie_tool_error( 'Nebylo zadáno nic ke změně (title, content nebo status).' );
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

	$label = $post->post_type === 'page' ? 'Stránka' : 'Příspěvek';
	$msg   = sprintf( '%s #%d uložen(a) se stavem „%s“.', $label, $id, $status );
	if ( $post->post_status === 'publish' && $status !== 'publish' ) {
		$msg .= sprintf( ' UPOZORNĚNÍ: byl publikovaný a se stavem „%s“ už není veřejně dostupný, dokud ho uživatel znovu nezveřejní.', $status );
	} elseif ( $status !== 'publish' ) {
		$msg .= ' Změny čekají na kontrolu a ruční publikaci.';
	}

	$edit = get_edit_post_link( $id, 'raw' );
	if ( $edit ) {
		$msg .= ' Editace: ' . $edit;
	}
	$preview = get_preview_post_link( $id );
	if ( $preview ) {
		$msg .= ' Náhled: ' . $preview;
	}
	return $msg;
}

function wpaie_tool_create_post( array $in ) {
	$type    = $in['post_type'] ?? 'post';
	$title   = (string) ( $in['title'] ?? '' );
	$content = (string) ( $in['content'] ?? '' );
	$status  = $in['status'] ?? 'draft';

	if ( ! in_array( $type, [ 'post', 'page' ], true ) ) {
		return wpaie_tool_error( 'post_type musí být "post" nebo "page".' );
	}
	if ( ! in_array( $status, WPAIE_POST_STATUSES, true ) ) {
		return wpaie_tool_error( 'Neplatný stav. Použij draft | publish | pending | private.' );
	}
	if ( trim( $title ) === '' && trim( $content ) === '' ) {
		return wpaie_tool_error( 'Zadej alespoň titulek nebo obsah.' );
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
		'%s #%d „%s“ vytvořen(a) (stav: %s).%s',
		$type === 'page' ? 'Stránka' : 'Příspěvek',
		$id,
		$title !== '' ? $title : '(bez názvu)',
		$status,
		$edit ? " Editace: $edit" : ''
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
	$id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post ? $post : null;
}

function wpaie_tool_get_theme_json(): string {
	if ( ! wpaie_theme_has_theme_json() ) {
		return 'Aktivní téma nemá theme.json, globální styly se tu nepoužívají. Vzhled uprav přes Doplňkový CSS.';
	}

	$theme = WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
	$post  = wpaie_user_global_styles_post();
	$user  = $post ? ( json_decode( $post->post_content, true ) ?: [] ) : [];
	unset( $user['isGlobalStylesUserThemeJSON'], $user['version'] );

	$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
	return "Styly tématu (theme.json, jen pro čtení):\n" . wp_json_encode( $theme, $flags )
		. "\n\nUživatelské úpravy Globálních stylů (mají přednost, zapisuje do nich update_theme_json):\n"
		. ( $user ? wp_json_encode( $user, $flags ) : '(žádné)' );
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
		return wpaie_tool_error( 'section musí být "settings" nebo "styles".' );
	}
	if ( ! is_array( $data ) || ( $data !== [] && array_is_list( $data ) ) ) {
		return wpaie_tool_error( 'data musí být objekt (např. {"color": {"background": "#fff"}}).' );
	}
	if ( ! wpaie_theme_has_theme_json() ) {
		return wpaie_tool_error( 'Aktivní téma nemá theme.json, globální styly by se neprojevily. Použij update_custom_css.' );
	}

	$post = wpaie_user_global_styles_post();
	if ( ! $post ) {
		return wpaie_tool_error( 'Nepodařilo se najít ani vytvořit úložiště Globálních stylů.' );
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
	return "Globální styly aktualizovány (sekce $section). Změna je živá ihned; lze ji vrátit ve Vzhled → Editor → Styly → Revize.";
}

// ─── Block templates (FSE) ────────────────────────────────────────────────────

function wpaie_template_type( array $in ): string {
	return ( $in['template_type'] ?? '' ) === 'wp_template_part' ? 'wp_template_part' : 'wp_template';
}

function wpaie_tool_list_templates(): string {
	$rows = [];
	foreach ( [ 'wp_template', 'wp_template_part' ] as $type ) {
		foreach ( get_block_templates( [], $type ) as $t ) {
			$rows[] = sprintf( 'slug:%s | název:%s | typ:%s | zdroj:%s', $t->slug, $t->title, $type, $t->source );
		}
	}
	return $rows ? implode( "\n", $rows ) : 'Žádné block templates nenalezeny (téma nejspíš není block/FSE).';
}

function wpaie_tool_get_template( array $in ) {
	$slug = sanitize_title( (string) ( $in['template_slug'] ?? '' ) );
	$type = wpaie_template_type( $in );
	if ( $slug === '' ) {
		return wpaie_tool_error( 'Chybí template_slug.' );
	}

	$t = get_block_template( get_stylesheet() . '//' . $slug, $type );
	if ( ! $t ) {
		return wpaie_tool_error( sprintf( 'Šablona „%s“ (%s) nebyla nalezena. Seznam vrátí list_templates.', $slug, $type ) );
	}
	return "Slug: {$t->slug}\nNázev: {$t->title}\nTyp: $type\nZdroj: {$t->source}\n\nObsah:\n{$t->content}";
}

function wpaie_tool_update_template( array $in ) {
	$slug    = sanitize_title( (string) ( $in['template_slug'] ?? '' ) );
	$type    = wpaie_template_type( $in );
	$content = (string) ( $in['content'] ?? '' );

	if ( $slug === '' ) {
		return wpaie_tool_error( 'Chybí template_slug.' );
	}
	if ( trim( $content ) === '' ) {
		return wpaie_tool_error( 'Chybí content.' );
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
			return wpaie_tool_error( sprintf( 'Template part „%s“ neexistuje. Seznam vrátí list_templates.', $slug ) );
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
	return sprintf( 'Šablona „%s“ uložena. Změna je živá ihned; původní verzi lze obnovit v editoru webu.', $slug );
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
			'Název webu: ' . get_bloginfo( 'name' ),
			'Podtitulek: ' . get_bloginfo( 'description' ),
			'Logo (custom_logo): ' . ( $logo_id ? "#$logo_id ($logo_url)" : '(žádné)' ),
			'Obrázek záhlaví: ' . ( $has_hdr ? $header : '(žádný)' ),
			'Ikona webu: ' . ( $icon_id ? "#$icon_id" : '(žádná)' ),
			'Téma podporuje custom-logo: ' . ( current_theme_supports( 'custom-logo' ) ? 'ano' : 'ne' ),
			'Téma podporuje custom-header: ' . ( current_theme_supports( 'custom-header' ) ? 'ano' : 'ne' ),
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
			return wpaie_tool_error( "Příloha #$logo_id neexistuje nebo to není obrázek." );
		}
	}

	if ( isset( $in['title'] ) && is_string( $in['title'] ) ) {
		update_option( 'blogname', sanitize_text_field( $in['title'] ) );
		$done[] = 'název webu nastaven';
	}
	if ( isset( $in['tagline'] ) && is_string( $in['tagline'] ) ) {
		update_option( 'blogdescription', sanitize_text_field( $in['tagline'] ) );
		$done[] = 'podtitulek nastaven';
	}
	if ( ! empty( $in['remove_logo'] ) ) {
		remove_theme_mod( 'custom_logo' );
		$done[] = 'logo odebráno';
	} elseif ( $logo_id ) {
		set_theme_mod( 'custom_logo', $logo_id );
		$done[] = "logo nastaveno na přílohu #$logo_id";
	}
	if ( ! empty( $in['remove_header_image'] ) ) {
		remove_theme_mod( 'header_image' );
		remove_theme_mod( 'header_image_data' );
		$done[] = 'obrázek záhlaví odebrán';
	}

	if ( ! $done ) {
		return wpaie_tool_error( 'Nebyla zadána žádná změna (title, tagline, remove_logo, logo_attachment_id, remove_header_image).' );
	}
	return 'Identita webu aktualizována: ' . implode( ', ', $done ) . '. Změna je živá ihned.';
}

// ─── Additional CSS ───────────────────────────────────────────────────────────

function wpaie_tool_get_custom_css(): string {
	$css = (string) wp_get_custom_css();
	return trim( $css ) === ''
		? 'Doplňkový CSS je prázdný.'
		: "Aktuální Doplňkový CSS:\n\n" . $css;
}

function wpaie_tool_update_custom_css( array $in ) {
	$css  = $in['css'] ?? '';
	$mode = $in['mode'] ?? 'append';

	if ( ! is_string( $css ) || trim( $css ) === '' ) {
		return wpaie_tool_error( 'Chybí css (neprázdný řetězec).' );
	}
	if ( ! in_array( $mode, [ 'append', 'replace' ], true ) ) {
		return wpaie_tool_error( 'mode musí být "append" nebo "replace".' );
	}
	if ( preg_match( '#</?\w+#', $css ) ) {
		return wpaie_tool_error( 'CSS nesmí obsahovat HTML značky.' );
	}

	$existing = (string) wp_get_custom_css();
	$new_css  = ( $mode === 'append' && trim( $existing ) !== '' ) ? rtrim( $existing ) . "\n\n" . $css : $css;

	$result = wp_update_custom_css_post( $new_css );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return sprintf(
		'Doplňkový CSS %s. Změna je živá ihned; předchozí verze je v revizích Customizeru. Případně vyprázdni cache.',
		$mode === 'append' ? 'doplněn' : 'přepsán'
	);
}

// ─── Menus ────────────────────────────────────────────────────────────────────

function wpaie_tool_list_menus(): string {
	$menus = wp_get_nav_menus();
	if ( ! $menus ) {
		return 'Žádná klasická navigační menu nenalezena (block témata používají blok Navigace v šablonách).';
	}

	$out = [];
	foreach ( $menus as $menu ) {
		$items  = wp_get_nav_menu_items( $menu->term_id ) ?: [];
		$list   = $items
			? implode( ', ', array_map( fn( $i ) => "#{$i->ID}:{$i->title}", $items ) )
			: '(prázdné)';
		$out[] = "ID:{$menu->term_id} | {$menu->name} | položky: $list";
	}
	return implode( "\n", $out );
}

function wpaie_tool_update_menu( array $in ) {
	$menu_id = (int) ( $in['menu_id'] ?? 0 );
	$action  = $in['action'] ?? '';
	$title   = trim( (string) ( $in['item_title'] ?? '' ) );

	if ( ! wp_get_nav_menu_object( $menu_id ) ) {
		return wpaie_tool_error( "Menu #$menu_id neexistuje. Seznam vrátí list_menus." );
	}

	if ( $action === 'add_item' ) {
		if ( $title === '' ) {
			return wpaie_tool_error( 'Chybí item_title.' );
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
		return sprintf( 'Položka „%s“ přidána do menu #%d.', $title, $menu_id );
	}

	if ( $action === 'rename_item' ) {
		$item_id = (int) ( $in['item_id'] ?? 0 );
		if ( ! $item_id ) {
			return wpaie_tool_error( 'Chybí item_id.' );
		}
		if ( $title === '' ) {
			return wpaie_tool_error( 'Chybí item_title.' );
		}
		$item = get_post( $item_id );
		if ( ! $item || $item->post_type !== 'nav_menu_item' ) {
			return wpaie_tool_error( "Položka menu #$item_id neexistuje." );
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
		return sprintf( 'Položka #%d přejmenována na „%s“.', $item_id, $title );
	}

	return wpaie_tool_error( sprintf( 'Neznámá akce „%s“. Použij add_item nebo rename_item.', (string) $action ) );
}
