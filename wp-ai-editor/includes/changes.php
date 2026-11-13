<?php
/**
 * Change log: every change made by the assistant is recorded with what is needed to undo it.
 *
 * An undo record is a list of operations that restore the previous state; they are applied in
 * reverse order. Operation types:
 *   post_fields  { post_id, fields: { post_title|post_content|post_status|post_excerpt: value } }
 *   trash_post   { post_id }
 *   delete_post  { post_id }                       – permanently (template copies, menu items)
 *   options      { values: { option: value } }
 *   theme_mods   { values: { mod: value|null } }    – null = remove
 *   custom_css   { css }
 *   post_meta    { post_id, values: { key: value|null } } – null = delete
 *   terms        { post_id, taxonomy, term_ids: [] }
 *   menu_item    { menu_id, item_id, title }
 *   wc_product   { product_id, props: { prop: value } }
 *   theme_json   { post_id, content }               – user Global Styles post content
 *   customizer   { values: { setting_id: value }, unset: [ setting_id ] } – Customizer settings (published
 *                like the Customizer does); unset = was not stored before, remove so the default applies
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAIE_DB_VERSION', '1' );

/** Changes older than this many days are pruned. */
define( 'WPAIE_CHANGES_KEEP_DAYS', 180 );

function wpaie_changes_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'wpaie_changes';
}

function wpaie_install(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = wpaie_changes_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tool varchar(64) NOT NULL DEFAULT '',
			label text NOT NULL,
			undo longtext NOT NULL,
			reverted_at datetime DEFAULT NULL,
			reverted_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $charset;"
	);
	update_option( 'wpaie_db_version', WPAIE_DB_VERSION, false );
}

register_activation_hook( WPAIE_FILE, 'wpaie_install' );

// Upgrades replace the files without running the activation hook.
add_action(
	'admin_init',
	function () {
		if ( get_option( 'wpaie_db_version' ) !== WPAIE_DB_VERSION ) {
			wpaie_install();
		}
	}
);

/**
 * Record a change. Returns the change ID (0 if it could not be stored).
 *
 * @param array $undo List of undo operations (see the file header).
 */
function wpaie_record_change( string $tool, string $label, array $undo ): int {
	global $wpdb;

	$ok = $wpdb->insert(
		wpaie_changes_table(),
		[
			'created_at' => current_time( 'mysql', true ),
			'user_id'    => get_current_user_id(),
			'tool'       => $tool,
			'label'      => $label,
			'undo'       => (string) wp_json_encode( $undo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
		],
		[ '%s', '%d', '%s', '%s', '%s' ]
	);
	if ( ! $ok ) {
		wpaie_log( 'Could not record a change', [ 'tool' => $tool, 'db_error' => $wpdb->last_error ] );
		return 0;
	}
	$id = (int) $wpdb->insert_id;

	WPAIE_Run_Context::$changes[] = [
		'id'    => $id,
		'label' => $label,
	];

	if ( wp_rand( 1, 50 ) === 1 ) {
		wpaie_prune_changes();
	}
	return $id;
}

function wpaie_prune_changes(): void {
	global $wpdb;
	$table = wpaie_changes_table();
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - WPAIE_CHANGES_KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

function wpaie_get_change( int $id ): ?object {
	global $wpdb;
	$table = wpaie_changes_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $row ?: null;
}

/**
 * @return array{rows:object[],total:int}
 */
function wpaie_list_changes( int $page = 1, int $per_page = 50 ): array {
	global $wpdb;
	$table = wpaie_changes_table();
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, $page - 1 ) * $per_page ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return [
		'rows'  => $rows ?: [],
		'total' => $total,
	];
}

/**
 * Can the current user undo this change? Administrators can undo anything; other users only
 * their own changes.
 */
function wpaie_user_can_undo( object $change ): bool {
	return current_user_can( WPAIE_CAPABILITY ) || ( (int) $change->user_id === get_current_user_id() && wpaie_user_can_chat() );
}

/**
 * Undo a recorded change.
 *
 * @return true|WP_Error
 */
function wpaie_undo_change( int $id ) {
	global $wpdb;

	$change = wpaie_get_change( $id );
	if ( ! $change ) {
		return new WP_Error( 'wpaie_undo', __( 'This change was not found in the history.', 'wp-ai-editor' ) );
	}
	if ( ! wpaie_user_can_undo( $change ) ) {
		return new WP_Error( 'wpaie_undo', __( 'You are not allowed to undo this change.', 'wp-ai-editor' ) );
	}
	if ( $change->reverted_at ) {
		return new WP_Error( 'wpaie_undo', __( 'This change has already been undone.', 'wp-ai-editor' ) );
	}

	$ops = json_decode( (string) $change->undo, true );
	if ( ! is_array( $ops ) ) {
		return new WP_Error( 'wpaie_undo', __( 'The undo data for this change is damaged.', 'wp-ai-editor' ) );
	}

	// Undo restores a snapshot. If a newer change touched the same thing, restoring this snapshot
	// would silently wipe it (and undoing that one later would bring this change back), so the
	// newer changes have to be undone first.
	$newer = wpaie_newer_changes_to_same_targets( $change, $ops );
	if ( $newer ) {
		return new WP_Error(
			'wpaie_undo_order',
			sprintf(
				/* translators: %s: list of change descriptions */
				__( 'A newer change touched the same thing. Undo it first: %s', 'wp-ai-editor' ),
				implode( ', ', array_map( fn( $c ) => '"' . $c->label . '"', $newer ) )
			)
		);
	}

	foreach ( array_reverse( $ops ) as $op ) {
		$result = wpaie_apply_undo_op( (array) $op );
		if ( is_wp_error( $result ) ) {
			wpaie_log( 'Undo failed', [ 'change' => $id, 'error' => $result->get_error_message() ] );
			return new WP_Error(
				'wpaie_undo',
				/* translators: %s: error message */
				sprintf( __( 'Undo failed: %s', 'wp-ai-editor' ), $result->get_error_message() )
			);
		}
	}

	$wpdb->update(
		wpaie_changes_table(),
		[
			'reverted_at' => current_time( 'mysql', true ),
			'reverted_by' => get_current_user_id(),
		],
		[ 'id' => $id ],
		[ '%s', '%d' ],
		[ '%d' ]
	);
	return true;
}

/**
 * What an undo operation restores, as comparable keys (e.g. "post:12", "css", "meta:5:_thumbnail_id").
 *
 * @return string[]
 */
function wpaie_undo_op_targets( array $op ): array {
	$id = (int) ( $op['post_id'] ?? 0 );
	switch ( (string) ( $op['type'] ?? '' ) ) {
		case 'post_fields':
		case 'trash_post':
		case 'delete_post':
			return [ "post:$id" ];
		case 'theme_json':
			return [ 'theme_json' ];
		case 'custom_css':
			return [ 'css' ];
		case 'options':
			return array_map( fn( $name ) => "option:$name", array_keys( (array) ( $op['values'] ?? [] ) ) );
		case 'theme_mods':
			return array_map( fn( $name ) => "mod:$name", array_keys( (array) ( $op['values'] ?? [] ) ) );
		case 'post_meta':
			return array_map( fn( $key ) => "meta:$id:$key", array_keys( (array) ( $op['values'] ?? [] ) ) );
		case 'terms':
			return [ "terms:$id:" . (string) ( $op['taxonomy'] ?? '' ) ];
		case 'menu_item':
			return [ 'post:' . (int) ( $op['item_id'] ?? 0 ) ];
		case 'wc_product':
			return [ 'wc:' . (int) ( $op['product_id'] ?? 0 ) ];
		case 'customizer':
			return array_map( fn( $id ) => "customizer:$id", array_merge( array_keys( (array) ( $op['values'] ?? [] ) ), (array) ( $op['unset'] ?? [] ) ) );
	}
	return [];
}

/**
 * Newer changes that are not undone yet and touch something this change would restore.
 *
 * @return object[]
 */
function wpaie_newer_changes_to_same_targets( object $change, array $ops ): array {
	global $wpdb;

	$targets = [];
	foreach ( $ops as $op ) {
		$targets = array_merge( $targets, wpaie_undo_op_targets( (array) $op ) );
	}
	if ( ! $targets ) {
		return [];
	}

	$table = wpaie_changes_table();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE id > %d AND reverted_at IS NULL ORDER BY id DESC LIMIT 500", (int) $change->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$newer = [];
	foreach ( (array) $rows as $row ) {
		foreach ( (array) json_decode( (string) $row->undo, true ) as $op ) {
			if ( array_intersect( $targets, wpaie_undo_op_targets( (array) $op ) ) ) {
				$newer[] = $row;
				break;
			}
		}
	}
	return $newer;
}

/**
 * @return true|WP_Error
 */
function wpaie_apply_undo_op( array $op ) {
	$type = (string) ( $op['type'] ?? '' );

	switch ( $type ) {
		case 'post_fields':
			$args = [ 'ID' => (int) $op['post_id'] ] + (array) $op['fields'];
			$res  = wp_update_post( wp_slash( $args ), true );
			return is_wp_error( $res ) ? $res : true;

		case 'theme_json':
			$res = wp_update_post(
				wp_slash(
					[
						'ID'           => (int) $op['post_id'],
						'post_content' => (string) $op['content'],
					]
				),
				true
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			wpaie_clean_theme_json_cache();
			return true;

		case 'trash_post':
			$post = get_post( (int) $op['post_id'] );
			if ( ! $post || $post->post_status === 'trash' ) {
				return true; // Already trashed or deleted by hand – nothing left to undo.
			}
			return wp_trash_post( $post->ID ) ? true : new WP_Error( 'wpaie_undo', 'Could not move the post to the trash.' );

		case 'delete_post':
			$post = get_post( (int) $op['post_id'] );
			if ( ! $post ) {
				return true; // Already gone.
			}
			return wp_delete_post( $post->ID, true ) ? true : new WP_Error( 'wpaie_undo', 'Could not delete the item.' );

		case 'options':
			foreach ( (array) $op['values'] as $name => $value ) {
				update_option( (string) $name, $value );
			}
			return true;

		case 'theme_mods':
			foreach ( (array) $op['values'] as $name => $value ) {
				if ( $value === null ) {
					remove_theme_mod( (string) $name );
				} else {
					set_theme_mod( (string) $name, $value );
				}
			}
			return true;

		case 'custom_css':
			$res = wp_update_custom_css_post( (string) $op['css'] );
			return is_wp_error( $res ) ? $res : true;

		case 'post_meta':
			foreach ( (array) $op['values'] as $key => $value ) {
				if ( $value === null ) {
					delete_post_meta( (int) $op['post_id'], (string) $key );
				} else {
					update_post_meta( (int) $op['post_id'], (string) $key, wp_slash( $value ) );
				}
			}
			return true;

		case 'terms':
			$res = wp_set_object_terms( (int) $op['post_id'], array_map( 'intval', (array) $op['term_ids'] ), (string) $op['taxonomy'] );
			return is_wp_error( $res ) ? $res : true;

		case 'menu_item':
			$res = wpaie_rename_menu_item( (int) $op['menu_id'], (int) $op['item_id'], (string) $op['title'] );
			return is_wp_error( $res ) ? $res : true;

		case 'customizer':
			return wpaie_customizer_restore( (array) ( $op['values'] ?? [] ), array_map( 'strval', (array) ( $op['unset'] ?? [] ) ) );

		case 'wc_product':
			return function_exists( 'wpaie_wc_apply_props' )
				? wpaie_wc_apply_props( (int) $op['product_id'], (array) $op['props'] )
				: new WP_Error( 'wpaie_undo', 'WooCommerce is not active.' );
	}

	return new WP_Error( 'wpaie_undo', "Unknown undo operation: $type" );
}
