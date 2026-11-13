<?php
/**
 * Removes all plugin data when the plugin is deleted from the Plugins screen.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wpaie_settings' );
delete_option( 'wpaie_db_version' );
delete_option( 'wpaie_usage' );
delete_transient( 'wpaie_disable_beta_features' );

// Conversations, the conversation index, the active conversation and the 2.0 history.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wpaie\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// History of changes.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpaie_changes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
