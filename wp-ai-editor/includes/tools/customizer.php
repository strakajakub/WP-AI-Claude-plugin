<?php
/**
 * Customizer tools: read and change any setting the active theme registers in the Customizer
 * (Appearance → Customize) – e.g. Astra's layout, typography, colors, header and footer, but also
 * GeneratePress, Kadence and other themes.
 *
 * The Customizer is loaded in the background the same way its screen does it: a
 * WP_Customize_Manager is created and customize_register is fired, so every setting comes with
 * the theme's own label, section, choices, validation and sanitization. Saving goes through the
 * setting objects (validate → sanitize → save) between the customize_save / customize_save_after
 * actions, so themes that rebuild their CSS cache on save (e.g. Astra) do so as usual.
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

function wpaie_customizer_tools(): array {
	$cap = WPAIE_CAPABILITY;
	return [
		[
			'name'         => 'list_customizer_settings',
			'description'  => 'List the theme\'s Customizer settings (Appearance → Customize) with their section, label, type, allowed choices and current value – e.g. Astra\'s blog layout, container width, typography, colors, header and footer. Filter by search text (matched against ID, label, section and panel) and/or section ID. Site identity and Additional CSS have their own tools.',
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
			'description'  => 'Change one or more Customizer settings (up to 20 at once). The value must have the same structure as the current value from get_customizer_setting – e.g. responsive settings are objects with desktop/tablet/mobile keys, and a select accepts only one of its choices. The theme validates and sanitizes every value; invalid ones are rejected. Takes effect on the live site immediately.',
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
 * The Customizer manager with every setting the theme and plugins register.
 *
 * @return WP_Customize_Manager|WP_Error
 */
function wpaie_customizer() {
	static $manager = null;
	if ( $manager ) {
		return $manager;
	}
	if ( ! current_user_can( 'customize' ) ) {
		return new WP_Error( 'wpaie_customizer', 'The current user is not allowed to use the Customizer.' );
	}
	try {
		if ( ! class_exists( 'WP_Customize_Manager' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		}
		$manager = new WP_Customize_Manager( [ 'settings_previewed' => false ] );
		// Themes look up the global (and core does when publishing); keep it pointing at this manager.
		$GLOBALS['wp_customize'] = $manager;
		// customize_register normally fires on wp_loaded, which already ran for this request.
		$manager->wp_loaded();
	} catch ( Throwable $e ) {
		$manager = null;
		wpaie_log( 'Customizer could not be loaded', [ 'error' => $e->getMessage() ] );
		return new WP_Error( 'wpaie_customizer', 'The Customizer could not be loaded in the background (' . $e->getMessage() . '). The user can make this change in Appearance → Customize.' );
	}
	return $manager;
}

function wpaie_customizer_setting_allowed( string $id ): bool {
	foreach ( WPAIE_CUSTOMIZER_EXCLUDED as $excluded ) {
		if ( $id === $excluded || str_starts_with( $id, $excluded . '[' ) || ( str_ends_with( $excluded, '_' ) && str_starts_with( $id, $excluded ) ) || str_starts_with( $id, $excluded . '_' ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Control, section and panel information for every setting: id => [ label, description, type,
 * choices, input_attrs, section, section_title, panel_title ].
 */
function wpaie_customizer_index( WP_Customize_Manager $manager ): array {
	$sections = $manager->sections();
	$panels   = $manager->panels();
	$index    = [];
	foreach ( $manager->controls() as $control ) {
		$settings = is_array( $control->settings ) ? $control->settings : [];
		if ( is_object( $control->setting ) ) {
			$settings[] = $control->setting;
		}
		foreach ( $settings as $setting ) {
			if ( ! is_object( $setting ) || isset( $index[ $setting->id ] ) ) {
				continue;
			}
			$section = $sections[ $control->section ] ?? null;
			$panel   = $section && ! empty( $section->panel ) ? ( $panels[ $section->panel ] ?? null ) : null;

			$index[ $setting->id ] = [
				'label'         => wp_strip_all_tags( (string) $control->label ),
				'description'   => wp_strip_all_tags( (string) $control->description ),
				'type'          => (string) $control->type,
				'choices'       => is_array( $control->choices ?? null ) ? $control->choices : [],
				'input_attrs'   => is_array( $control->input_attrs ?? null ) ? $control->input_attrs : [],
				'section'       => (string) $control->section,
				'section_title' => $section ? wp_strip_all_tags( (string) $section->title ) : '',
				'panel_title'   => $panel ? wp_strip_all_tags( (string) $panel->title ) : '',
			];
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

// ─── Tools ────────────────────────────────────────────────────────────────────

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
	foreach ( $manager->settings() as $id => $setting ) {
		if ( ! wpaie_customizer_setting_allowed( $id ) || ! $setting->check_capabilities() ) {
			continue;
		}
		$info = $index[ $id ] ?? null;
		if ( ! $info ) {
			continue; // Without a control the setting is not editable in the Customizer either.
		}
		if ( $section !== '' && $info['section'] !== $section ) {
			continue;
		}
		$haystack = mb_strtolower( implode( ' ', [ $id, $info['label'], $info['section'], $info['section_title'], $info['panel_title'] ] ) );
		if ( $search !== '' && ! str_contains( $haystack, $search ) ) {
			continue;
		}
		$rows[] = sprintf(
			'%s | %s › %s | "%s" | type:%s%s | value:%s',
			$id,
			$info['panel_title'] !== '' ? $info['panel_title'] : '-',
			$info['section_title'] !== '' ? $info['section_title'] . ' (' . $info['section'] . ')' : $info['section'],
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
		. "\nid | panel › section | label | type | choices | current value\n" . implode( "\n", $page );
}

/**
 * @return WP_Customize_Setting|WP_Error
 */
function wpaie_customizer_get_setting( WP_Customize_Manager $manager, string $id ) {
	$setting = $manager->get_setting( $id );
	if ( ! $setting || ! wpaie_customizer_setting_allowed( $id ) ) {
		return wpaie_tool_error( "Customizer setting \"$id\" does not exist or is handled by another tool. Use list_customizer_settings to find the ID." );
	}
	if ( ! $setting->check_capabilities() ) {
		return wpaie_tool_error( "The current user may not change \"$id\"." );
	}
	return $setting;
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
				'Section: ' . trim( ( $info['panel_title'] ?? '' ) . ' › ' . ( $info['section_title'] ?? '' ), ' ›' ) . ' (' . ( $info['section'] ?? '' ) . ')',
				'Control type: ' . ( $info['type'] ?? '?' ),
				! empty( $info['choices'] ) ? 'Choices: ' . wpaie_customizer_choices_text( $info['choices'], 100 ) : '',
				! empty( $info['input_attrs'] ) ? 'Input attributes: ' . wp_json_encode( $info['input_attrs'] ) : '',
				'Stored as: ' . $setting->type,
				'Default: ' . wp_json_encode( $setting->default, $flags ),
				'Current value: ' . wp_json_encode( $setting->value(), $flags ),
			]
		)
	);
}

function wpaie_preview_update_customizer_settings( array $in ): string {
	$manager = wpaie_customizer();
	if ( is_wp_error( $manager ) ) {
		return '<p class="wpaie-warning">' . esc_html( $manager->get_error_message() ) . '</p>';
	}
	$index = wpaie_customizer_index( $manager );
	$rows  = [];
	foreach ( (array) ( $in['changes'] ?? [] ) as $change ) {
		$id      = (string) ( $change['id'] ?? '' );
		$setting = $manager->get_setting( $id );
		$label   = trim( ( $index[ $id ]['section_title'] ?? '' ) . ' › ' . ( $index[ $id ]['label'] ?? $id ), ' ›' );
		$rows[ $label !== '' ? $label : $id ] = [
			$setting ? wpaie_customizer_value_text( $setting->value(), 300 ) : '?',
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

	// Validate everything first, so an invalid value does not leave a half-applied change.
	$settings = [];
	$errors   = [];
	foreach ( $changes as $change ) {
		$id      = (string) ( $change['id'] ?? '' );
		$value   = $change['value'] ?? null;
		$setting = wpaie_customizer_get_setting( $manager, $id );
		if ( is_wp_error( $setting ) ) {
			$errors[] = $setting->get_error_message();
			continue;
		}
		$validity = $setting->validate( $value );
		if ( is_wp_error( $validity ) ) {
			$errors[] = "\"$id\": " . implode( ' ', $validity->get_error_messages() );
			continue;
		}
		if ( $setting->sanitize( $value ) === null ) {
			$errors[] = "\"$id\": the theme rejected this value.";
			continue;
		}
		$settings[ $id ] = [ $setting, $value, $setting->value() ];
	}
	if ( $errors ) {
		return wpaie_tool_error( 'Nothing was changed. ' . implode( ' ', $errors ) . ' Read the setting with get_customizer_setting and use the same value structure.' );
	}

	// Save like the Customizer does, so themes run their save hooks (e.g. CSS cache rebuilds).
	do_action( 'customize_save', $manager );
	$saved  = [];
	$failed = [];
	foreach ( $settings as $id => [ $setting, $value ] ) {
		$manager->set_post_value( $id, $value );
		if ( $setting->save() === false ) {
			$failed[] = $id;
		} else {
			$saved[] = $id;
		}
	}
	do_action( 'customize_save_after', $manager );

	if ( $saved ) {
		$previous = [];
		foreach ( $saved as $id ) {
			$previous[ $id ] = $settings[ $id ][2];
		}
		wpaie_record_change(
			'update_customizer_settings',
			sprintf(
				/* translators: %s: list of setting IDs */
				__( 'Customizer changed: %s', 'wp-ai-editor' ),
				implode( ', ', $saved )
			),
			[
				[
					'type'   => 'customizer',
					'values' => $previous,
				],
			]
		);
	}

	$msg = $saved ? 'Saved: ' . implode( ', ', $saved ) . '. Live immediately; suggest clearing any page cache.' : 'Nothing was saved.';
	return $msg . ( $failed ? ' Could not save: ' . implode( ', ', $failed ) . '.' : '' );
}

/**
 * Undo: put the previous values back (through the same validated save path).
 *
 * @return true|WP_Error
 */
function wpaie_customizer_restore( array $values ) {
	$manager = wpaie_customizer();
	if ( is_wp_error( $manager ) ) {
		return $manager;
	}
	do_action( 'customize_save', $manager );
	foreach ( $values as $id => $value ) {
		$setting = $manager->get_setting( (string) $id );
		if ( ! $setting ) {
			return new WP_Error( 'wpaie_undo', "Customizer setting \"$id\" no longer exists (theme changed?)." );
		}
		$manager->set_post_value( (string) $id, $value );
		$setting->save();
	}
	do_action( 'customize_save_after', $manager );
	return true;
}
