<?php
/**
 * Customizer tools: read and change any setting the active theme registers in the Customizer
 * (Appearance → Customize) – e.g. Astra's layout, typography, colors, header and footer, but also
 * GeneratePress, Kadence and other themes.
 *
 * Loading: a WP_Customize_Manager is created in the background (without the widgets and menus
 * components, which no tool here uses) and customize_register is fired, so every setting comes
 * with the theme's own label, section, choices, validation and sanitization.
 *
 * Settings without a server-side control: some themes create settings only on demand through the
 * core customize_dynamic_setting_args filter and draw their controls in JavaScript – Astra does this
 * for everything inside its setting groups (typography, many colors). Those settings are created
 * with the core add_dynamic_settings() API; themes can describe them for the list through the
 * wpaie_customizer_extra_settings filter, and an adapter for Astra is built in.
 *
 * Saving (changes and undo) always goes through core's save_changeset_post() with status
 * "publish": core validates and sanitizes all values together (nothing is saved if one is
 * invalid), checks capabilities, fires customize_save / customize_save_after in the right order
 * (themes rebuild their CSS cache there) and keeps a changeset revision – exactly like the
 * Customizer's own Publish button.
 */

defined( 'ABSPATH' ) || exit;

/** Settings per update_customizer_settings call. */
define( 'WPAIE_MAX_CUSTOMIZER_CHANGES', 20 );

/**
 * Settings other tools own (site identity, Additional CSS) or that are not appearance settings
 * (menus, widgets). Matched as exact IDs or ID prefixes.
 */
const WPAIE_CUSTOMIZER_EXCLUDED = [
	'blogname',
	'blogdescription',
	'custom_logo',
	'site_icon',
	'header_image',
	'header_image_data',
	'custom_css',
	'nav_menu',
	'nav_menus_created_posts',
	'sidebars_widgets',
	'widget_',
];

/** Control types that only structure the panel (headings, dividers, group toggles), not settings. */
const WPAIE_CUSTOMIZER_LAYOUT_CONTROLS = '/(heading|divider|description|settings-group|section-toggle|hidden|notice|upgrade)$/';

function wpaie_customizer_tools(): array {
	$cap = WPAIE_CAPABILITY;
	return [
		[
			'name'         => 'list_customizer_settings',
			'description'  => 'List the theme\'s Customizer settings (Appearance → Customize) with their section, label, type, allowed choices and current value – e.g. Astra\'s blog layout, container width, typography, colors, header and footer, including settings inside setting groups. Filter by search text (matched against ID, label, group, section and panel) and/or section ID. Site identity and Additional CSS have their own tools.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'search'  => [ 'type' => 'string', 'description' => 'Text to look for, e.g. "blog", "font", "container", "button" (optional).' ],
					'section' => [ 'type' => 'string', 'description' => 'Only this section ID (optional; IDs are shown in the results).' ],
					'limit'   => [ 'type' => 'integer', 'description' => 'Maximum number of settings (1–80), default 40.' ],
					'offset'  => [ 'type' => 'integer', 'description' => 'Skip this many results (paging).' ],
				],
			],
			'cap'          => $cap,
			'label'        => fn( $in ) => ! empty( $in['search'] )
				/* translators: %s: searched text */
				? sprintf( __( 'Searching Customizer settings for "%s"…', 'wp-ai-editor' ), (string) $in['search'] )
				: __( 'Loading Customizer settings…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_list_customizer_settings',
		],
		[
			'name'         => 'get_customizer_setting',
			'description'  => 'Return one Customizer setting in full: label, description, section, control type, choices, input attributes, default and the complete current value. Read it before changing a setting so the new value has exactly the same structure.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'id' ],
				'properties' => [
					'id' => [ 'type' => 'string', 'description' => 'Setting ID as listed, e.g. "astra-settings[blog-layout]".' ],
				],
			],
			'cap'          => $cap,
			/* translators: %s: setting ID */
			'label'        => fn( $in ) => sprintf( __( 'Reading Customizer setting %s…', 'wp-ai-editor' ), (string) ( $in['id'] ?? '' ) ),
			'run'          => 'wpaie_tool_get_customizer_setting',
		],
		[
			'name'         => 'update_customizer_settings',
			'description'  => 'Change one or more Customizer settings (up to 20 at once). The value must have the same structure as the current value from get_customizer_setting – e.g. responsive settings are objects with desktop/tablet/mobile keys, and a select accepts only one of its choices. The theme validates every value; if one is invalid, nothing is saved. Published like the Customizer\'s Publish button; takes effect on the live site immediately.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'changes' ],
				'properties' => [
					'changes' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'required'   => [ 'id', 'value' ],
							'properties' => [
								'id'    => [ 'type' => 'string' ],
								'value' => [ 'description' => 'New value (string, number, boolean or object, as the setting expects).' ],
							],
						],
					],
				],
			],
			'cap'          => $cap,
			'write'        => true,
			'live'         => true,
			'label'        => fn( $in ) => sprintf(
				/* translators: %d: number of settings */
				_n( 'Changing %d Customizer setting…', 'Changing %d Customizer settings…', count( (array) ( $in['changes'] ?? [] ) ), 'wp-ai-editor' ),
				count( (array) ( $in['changes'] ?? [] ) )
			),
			'preview'      => 'wpaie_preview_update_customizer_settings',
			'run'          => 'wpaie_tool_update_customizer_settings',
		],
	];
}

// ─── Loading the Customizer ───────────────────────────────────────────────────

/**
 * Holds the Customizer manager of this request and data captured while it registered.
 */
final class WPAIE_Customizer_State {
	/** @var WP_Customize_Manager|null */
	public static $manager = null;
	/** @var array|null Astra's final customizer configurations (captured from its filter). */
	public static $astra_configs = null;
}

/**
 * The Customizer manager with every setting the theme and plugins register.
 *
 * A manager can publish only one changeset; after a publish the next call builds a fresh one.
 *
 * @return WP_Customize_Manager|WP_Error
 */
function wpaie_customizer() {
	if ( WPAIE_Customizer_State::$manager ) {
		return WPAIE_Customizer_State::$manager;
	}
	if ( ! current_user_can( 'customize' ) ) {
		return new WP_Error( 'wpaie_customizer', 'The current user is not allowed to use the Customizer.' );
	}

	$previous_global = $GLOBALS['wp_customize'] ?? null;
	$no_components   = fn() => [];
	try {
		if ( ! class_exists( 'WP_Customize_Manager' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		}
		// Widgets and menus are not edited here; skipping them avoids building a setting per
		// widget and menu item on every call.
		add_filter( 'customize_loaded_components', $no_components, PHP_INT_MAX );
		$manager = new WP_Customize_Manager( [ 'settings_previewed' => false ] );
		remove_filter( 'customize_loaded_components', $no_components, PHP_INT_MAX );

		// Themes read the global, and core's publish handler uses it.
		$GLOBALS['wp_customize'] = $manager;
		// customize_register normally fires on wp_loaded, which already ran for this request.
		$manager->wp_loaded();

		// Create settings that exist only on demand (e.g. inside Astra's setting groups).
		$extra = array_keys( wpaie_customizer_extra_settings() );
		if ( $extra ) {
			$manager->add_dynamic_settings( $extra );
		}
	} catch ( Throwable $e ) {
		remove_filter( 'customize_loaded_components', $no_components, PHP_INT_MAX );
		$GLOBALS['wp_customize'] = $previous_global;
		wpaie_log( 'Customizer could not be loaded', [ 'error' => $e->getMessage() ] );
		return new WP_Error( 'wpaie_customizer', 'The Customizer could not be loaded in the background (' . $e->getMessage() . '). The user can make this change in Appearance → Customize.' );
	}

	WPAIE_Customizer_State::$manager = $manager;
	return $manager;
}

/**
 * Describe settings that have no server-side control (created on demand, drawn in JS).
 *
 * @return array<string,array> setting id => [ label, description, type, choices, input_attrs, section, group ]
 */
function wpaie_customizer_extra_settings(): array {
	return (array) apply_filters( 'wpaie_customizer_extra_settings', [] );
}

// Astra: capture its final configurations (the filter runs once per request, during customize_register).
add_filter(
	'astra_customizer_configurations',
	function ( $configs ) {
		WPAIE_Customizer_State::$astra_configs = is_array( $configs ) ? $configs : [];
		return $configs;
	},
	PHP_INT_MAX
);

// Astra: settings inside setting groups ("sub-controls") – created on demand by Astra's
// customize_dynamic_setting_args filter, rendered by its JavaScript.
add_filter(
	'wpaie_customizer_extra_settings',
	function ( array $extra ) {
		$configs = WPAIE_Customizer_State::$astra_configs;
		if ( ! $configs || ! defined( 'ASTRA_THEME_SETTINGS' ) ) {
			return $extra;
		}
		$groups = [];
		foreach ( $configs as $config ) {
			if ( ( $config['control'] ?? '' ) === 'ast-settings-group' && ! empty( $config['name'] ) ) {
				$groups[ $config['name'] ] = (string) ( $config['title'] ?? '' );
			}
		}
		foreach ( $configs as $config ) {
			if ( empty( $config['parent'] ) || empty( $config['name'] ) || preg_match( WPAIE_CUSTOMIZER_LAYOUT_CONTROLS, (string) ( $config['control'] ?? '' ) ) ) {
				continue;
			}
			$id           = ASTRA_THEME_SETTINGS . '[' . $config['name'] . ']';
			$extra[ $id ] = [
				'label'       => wp_strip_all_tags( (string) ( $config['title'] ?? '' ) ) ?: (string) $config['name'],
				'description' => wp_strip_all_tags( (string) ( $config['description'] ?? '' ) ),
				'type'        => (string) ( $config['control'] ?? '' ),
				'choices'     => is_array( $config['choices'] ?? null ) ? $config['choices'] : [],
				'input_attrs' => is_array( $config['input_attrs'] ?? null ) ? $config['input_attrs'] : [],
				'section'     => (string) ( $config['section'] ?? '' ),
				'group'       => wp_strip_all_tags( $groups[ $config['parent'] ] ?? '' ),
			];
		}
		return $extra;
	}
);

function wpaie_customizer_setting_allowed( string $id ): bool {
	foreach ( WPAIE_CUSTOMIZER_EXCLUDED as $excluded ) {
		if ( $id === $excluded || str_starts_with( $id, $excluded . '[' ) || str_starts_with( $id, $excluded . '_' ) || ( str_ends_with( $excluded, '_' ) && str_starts_with( $id, $excluded ) ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Label, type, choices, section, group and panel for every editable setting: from the
 * server-side controls, plus the settings described by wpaie_customizer_extra_settings.
 */
function wpaie_customizer_index( WP_Customize_Manager $manager ): array {
	$sections = $manager->sections();
	$panels   = $manager->panels();
	$titles   = function ( string $section_id ) use ( $sections, $panels ): array {
		$section = $sections[ $section_id ] ?? null;
		$panel   = $section && ! empty( $section->panel ) ? ( $panels[ $section->panel ] ?? null ) : null;
		return [
			'section_title' => $section ? wp_strip_all_tags( (string) $section->title ) : '',
			'panel_title'   => $panel ? wp_strip_all_tags( (string) $panel->title ) : '',
		];
	};

	$index = [];
	foreach ( $manager->controls() as $control ) {
		if ( preg_match( WPAIE_CUSTOMIZER_LAYOUT_CONTROLS, (string) $control->type ) ) {
			continue;
		}
		$settings = is_array( $control->settings ) ? $control->settings : [];
		if ( is_object( $control->setting ) ) {
			$settings[] = $control->setting;
		}
		foreach ( $settings as $setting ) {
			if ( ! is_object( $setting ) || isset( $index[ $setting->id ] ) ) {
				continue;
			}
			$index[ $setting->id ] = [
				'label'       => wp_strip_all_tags( (string) $control->label ),
				'description' => wp_strip_all_tags( (string) $control->description ),
				'type'        => (string) $control->type,
				'choices'     => is_array( $control->choices ?? null ) ? $control->choices : [],
				'input_attrs' => is_array( $control->input_attrs ?? null ) ? $control->input_attrs : [],
				'section'     => (string) $control->section,
				'group'       => '',
			] + $titles( (string) $control->section );
		}
	}
	foreach ( wpaie_customizer_extra_settings() as $id => $info ) {
		if ( ! isset( $index[ $id ] ) && $manager->get_setting( $id ) ) {
			$index[ $id ] = $info + $titles( (string) ( $info['section'] ?? '' ) );
		}
	}
	return $index;
}

/**
 * Choices of a control as "value=Label" text (labels may be HTML/arrays in some themes).
 */
function wpaie_customizer_choices_text( array $choices, int $max = 30 ): string {
	$out = [];
	foreach ( array_slice( $choices, 0, $max, true ) as $value => $label ) {
		$label = is_scalar( $label ) ? wp_strip_all_tags( (string) $label ) : '';
		$out[] = $label !== '' && $label !== (string) $value ? "$value=$label" : (string) $value;
	}
	return implode( ', ', $out ) . ( count( $choices ) > $max ? ', …' : '' );
}

function wpaie_customizer_value_text( $value, int $max = 160 ): string {
	$text = is_scalar( $value ) || $value === null ? var_export( $value, true ) : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
}

/**
 * @return WP_Customize_Setting|WP_Error
 */
function wpaie_customizer_get_setting( WP_Customize_Manager $manager, string $id ) {
	if ( ! wpaie_customizer_setting_allowed( $id ) ) {
		return wpaie_tool_error( "Customizer setting \"$id\" is handled by another tool (site identity, Additional CSS, menus or widgets)." );
	}
	$setting = $manager->get_setting( $id );
	if ( ! $setting ) {
		// The theme may create it on demand (customize_dynamic_setting_args).
		$manager->add_dynamic_settings( [ $id ] );
		$setting = $manager->get_setting( $id );
	}
	if ( ! $setting ) {
		return wpaie_tool_error( "Customizer setting \"$id\" does not exist. Use list_customizer_settings to find the ID." );
	}
	if ( ! $setting->check_capabilities() ) {
		return wpaie_tool_error( "The current user may not change \"$id\"." );
	}
	return $setting;
}

// ─── Reading ──────────────────────────────────────────────────────────────────

function wpaie_tool_list_customizer_settings( array $in ) {
	$manager = wpaie_customizer();
	if ( is_wp_error( $manager ) ) {
		return $manager;
	}
	$index   = wpaie_customizer_index( $manager );
	$search  = mb_strtolower( trim( (string) ( $in['search'] ?? '' ) ) );
	$section = (string) ( $in['section'] ?? '' );
	$limit   = max( 1, min( (int) ( $in['limit'] ?? 40 ), 80 ) );
	$offset  = max( 0, (int) ( $in['offset'] ?? 0 ) );

	$rows = [];
	foreach ( $index as $id => $info ) {
		$setting = $manager->get_setting( $id );
		if ( ! $setting || ! wpaie_customizer_setting_allowed( $id ) || ! $setting->check_capabilities() ) {
			continue;
		}
		if ( $section !== '' && $info['section'] !== $section ) {
			continue;
		}
		$haystack = mb_strtolower( implode( ' ', [ $id, $info['label'], $info['group'], $info['section'], $info['section_title'], $info['panel_title'] ] ) );
		if ( $search !== '' && ! str_contains( $haystack, $search ) ) {
			continue;
		}
		$where  = implode( ' › ', array_filter( [ $info['panel_title'], $info['section_title'] !== '' ? $info['section_title'] . ' (' . $info['section'] . ')' : $info['section'], $info['group'] ] ) );
		$rows[] = sprintf(
			'%s | %s | "%s" | type:%s%s | value:%s',
			$id,
			$where !== '' ? $where : '-',
			$info['label'],
			$info['type'],
			$info['choices'] ? ' | choices:' . wpaie_customizer_choices_text( $info['choices'], 12 ) : '',
			wpaie_customizer_value_text( $setting->value() )
		);
	}

	if ( ! $rows ) {
		return $search !== '' || $section !== ''
			? 'No Customizer setting matches. Try a broader search (e.g. one English word like "blog", "header", "font", "color").'
			: 'The theme registers no editable Customizer settings.';
	}
	$total = count( $rows );
	$page  = array_slice( $rows, $offset, $limit );
	return 'Theme: ' . wp_get_theme()->get( 'Name' ) . " | $total settings match" . ( $total > $offset + $limit ? ' (showing ' . ( $offset + 1 ) . '–' . ( $offset + count( $page ) ) . '; use offset for more)' : '' )
		. "\nid | panel › section › group | label | type | choices | current value\n" . implode( "\n", $page );
}

function wpaie_tool_get_customizer_setting( array $in ) {
	$manager = wpaie_customizer();
	if ( is_wp_error( $manager ) ) {
		return $manager;
	}
	$id      = (string) ( $in['id'] ?? '' );
	$setting = wpaie_customizer_get_setting( $manager, $id );
	if ( is_wp_error( $setting ) ) {
		return $setting;
	}
	$info  = wpaie_customizer_index( $manager )[ $id ] ?? [];
	$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
	return implode(
		"\n",
		array_filter(
			[
				"ID: $id",
				'Label: ' . ( $info['label'] ?? '' ),
				! empty( $info['description'] ) ? 'Description: ' . $info['description'] : '',
				'Section: ' . implode( ' › ', array_filter( [ $info['panel_title'] ?? '', $info['section_title'] ?? '', $info['group'] ?? '' ] ) ) . ' (' . ( $info['section'] ?? '' ) . ')',
				'Control type: ' . ( $info['type'] ?? '?' ),
				! empty( $info['choices'] ) ? 'Choices: ' . wpaie_customizer_choices_text( $info['choices'], 100 ) : '',
				! empty( $info['input_attrs'] ) ? 'Input attributes: ' . wp_json_encode( $info['input_attrs'] ) : '',
				'Stored as: ' . $setting->type . ( wpaie_customizer_is_stored( $setting ) ? '' : ' (not set yet – the default applies)' ),
				'Default: ' . wp_json_encode( $setting->default, $flags ),
				'Current value: ' . wp_json_encode( $setting->value(), $flags ),
			]
		)
	);
}

// ─── Saving ───────────────────────────────────────────────────────────────────

/**
 * Publish values through core's changeset API (validation, capabilities, save hooks, revision).
 *
 * @param array $values setting id => value
 * @return true|WP_Error
 */
function wpaie_customizer_publish( WP_Customize_Manager $manager, array $values ) {
	$data = [];
	foreach ( $values as $id => $value ) {
		$data[ $id ] = [ 'value' => $value ];
	}
	$result = $manager->save_changeset_post(
		[
			'status' => 'publish',
			'title'  => 'AI Editor',
			'data'   => $data,
		]
	);
	// A manager publishes only one changeset; the next save in this request needs a fresh one.
	WPAIE_Customizer_State::$manager = null;

	if ( is_wp_error( $result ) ) {
		$validities = (array) wpaie_prop( $result->get_error_data(), 'setting_validities', [] );
		$messages   = [];
		foreach ( $validities as $id => $validity ) {
			if ( is_wp_error( $validity ) ) {
				$messages[] = "\"$id\": " . implode( ' ', $validity->get_error_messages() );
			}
		}
		return new WP_Error(
			'wpaie_customizer_save',
			$messages
				? 'Nothing was saved, the theme rejected: ' . implode( ' ', $messages )
				: 'Nothing was saved (' . $result->get_error_code() . ( $result->get_error_message() !== '' ? ': ' . $result->get_error_message() : '' ) . ').'
		);
	}
	return true;
}

/**
 * Is a value stored for the setting, or does it fall back to its default?
 */
function wpaie_customizer_is_stored( $setting ): bool {
	$id_data = $setting->id_data();
	if ( $setting->type === 'theme_mod' ) {
		$root = get_theme_mods();
	} elseif ( $setting->type === 'option' ) {
		$root = get_option( $id_data['base'], null );
		if ( ! $id_data['keys'] ) {
			return $root !== null;
		}
		$root = [ $id_data['base'] => $root ];
	} else {
		return true; // Custom storage: treat as stored, undo writes the value back.
	}
	foreach ( array_merge( [ $id_data['base'] ], $id_data['keys'] ) as $key ) {
		if ( ! is_array( $root ) || ! array_key_exists( $key, $root ) ) {
			return false;
		}
		$root = $root[ $key ];
	}
	return true;
}

/**
 * Remove a stored value, so the setting falls back to its default again (undo of a first change).
 */
function wpaie_customizer_unset( $setting ): void {
	$id_data = $setting->id_data();
	$base    = $id_data['base'];
	$keys    = $id_data['keys'];

	$remove = function ( $root, array $keys ) use ( &$remove ) {
		$key = array_shift( $keys );
		if ( ! is_array( $root ) || ! array_key_exists( $key, $root ) ) {
			return $root;
		}
		if ( $keys ) {
			$root[ $key ] = $remove( $root[ $key ], $keys );
		} else {
			unset( $root[ $key ] );
		}
		return $root;
	};

	if ( $setting->type === 'theme_mod' ) {
		if ( ! $keys ) {
			remove_theme_mod( $base );
		} else {
			set_theme_mod( $base, $remove( get_theme_mod( $base ), $keys ) );
		}
	} elseif ( $setting->type === 'option' ) {
		if ( ! $keys ) {
			delete_option( $base );
		} else {
			update_option( $base, $remove( get_option( $base ), $keys ) );
		}
	}
}

function wpaie_preview_update_customizer_settings( array $in ): string {
	$manager = wpaie_customizer();
	if ( is_wp_error( $manager ) ) {
		return '<p class="wpaie-warning">' . esc_html( $manager->get_error_message() ) . '</p>';
	}
	$rows = [];
	foreach ( (array) ( $in['changes'] ?? [] ) as $change ) {
		$id      = (string) ( $change['id'] ?? '' );
		$setting = wpaie_customizer_get_setting( $manager, $id );
		$info    = wpaie_customizer_index( $manager )[ $id ] ?? [];
		$label   = implode( ' › ', array_filter( [ $info['section_title'] ?? '', $info['group'] ?? '', $info['label'] ?? '' ] ) );
		$rows[ $label !== '' ? $label : $id ] = [
			is_wp_error( $setting ) ? '?' : wpaie_customizer_value_text( $setting->value(), 300 ),
			wpaie_customizer_value_text( $change['value'] ?? null, 300 ),
		];
	}
	return wpaie_preview_table( $rows );
}

function wpaie_tool_update_customizer_settings( array $in ) {
	$changes = array_values( (array) ( $in['changes'] ?? [] ) );
	if ( ! $changes ) {
		return wpaie_tool_error( 'changes is empty.' );
	}
	if ( count( $changes ) > WPAIE_MAX_CUSTOMIZER_CHANGES ) {
		return wpaie_tool_error( 'At most ' . WPAIE_MAX_CUSTOMIZER_CHANGES . ' settings per call; split the changes.' );
	}
	$manager = wpaie_customizer();
	if ( is_wp_error( $manager ) ) {
		return $manager;
	}

	$values   = [];
	$previous = [];
	$unset    = [];
	$errors   = [];
	foreach ( $changes as $change ) {
		$id      = (string) ( $change['id'] ?? '' );
		$setting = wpaie_customizer_get_setting( $manager, $id );
		if ( is_wp_error( $setting ) ) {
			$errors[] = $setting->get_error_message();
			continue;
		}
		$values[ $id ] = $change['value'] ?? null;
		if ( wpaie_customizer_is_stored( $setting ) ) {
			$previous[ $id ] = $setting->value();
		} else {
			$unset[] = $id;
		}
	}
	if ( $errors ) {
		return wpaie_tool_error( 'Nothing was changed. ' . implode( ' ', $errors ) );
	}

	// Core validates and sanitizes all values together: if one is invalid, nothing is saved.
	$result = wpaie_customizer_publish( $manager, $values );
	if ( is_wp_error( $result ) ) {
		return wpaie_tool_error( $result->get_error_message() . ' Read the setting with get_customizer_setting and use the same value structure and one of its choices.' );
	}

	wpaie_record_change(
		'update_customizer_settings',
		sprintf(
			/* translators: %s: list of setting IDs */
			__( 'Customizer changed: %s', 'wp-ai-editor' ),
			implode( ', ', array_keys( $values ) )
		),
		[
			[
				'type'   => 'customizer',
				'values' => $previous,
				'unset'  => $unset,
			],
		]
	);
	return 'Saved and published: ' . implode( ', ', array_keys( $values ) ) . '. Live immediately; suggest clearing any page cache.';
}

/**
 * Undo: put the previous values back, and remove values that were not set before (so the theme
 * defaults apply again). Published through the changeset API like any change, so the theme's save
 * hooks (CSS rebuild) run also when only values were removed.
 *
 * @return true|WP_Error
 */
function wpaie_customizer_restore( array $values, array $unset = [] ) {
	$manager = wpaie_customizer();
	if ( is_wp_error( $manager ) ) {
		return $manager;
	}
	// Check everything first, so an undo is never applied halfway.
	foreach ( array_merge( array_keys( $values ), $unset ) as $id ) {
		$setting = wpaie_customizer_get_setting( $manager, (string) $id );
		if ( is_wp_error( $setting ) ) {
			return new WP_Error( 'wpaie_undo', "Customizer setting \"$id\" can no longer be restored (" . $setting->get_error_message() . ')' );
		}
	}
	foreach ( $manager->validate_setting_values( $values, [ 'validate_capability' => true, 'validate_existence' => true ] ) as $id => $validity ) {
		if ( is_wp_error( $validity ) ) {
			return new WP_Error( 'wpaie_undo', "The previous value of \"$id\" is no longer accepted by the theme: " . implode( ' ', $validity->get_error_messages() ) );
		}
	}
	foreach ( $unset as $id ) {
		wpaie_customizer_unset( $manager->get_setting( (string) $id ) );
	}
	$result = wpaie_customizer_publish( $manager, $values );
	return is_wp_error( $result ) ? new WP_Error( 'wpaie_undo', $result->get_error_message() ) : true;
}
