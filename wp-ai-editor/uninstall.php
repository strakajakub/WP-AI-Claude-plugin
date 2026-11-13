<?php
/**
 * Removes all plugin data when the plugin is deleted from the Plugins screen.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpaie_settings' );
delete_transient( 'wpaie_disable_beta_features' );
delete_metadata( 'user', 0, 'wpaie_history', '', true );
