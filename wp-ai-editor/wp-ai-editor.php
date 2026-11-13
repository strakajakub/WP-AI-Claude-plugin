<?php
/**
 * Plugin Name:       WP AI Editor
 * Plugin URI:        https://github.com/strakajakub/WP-AI-Claude-plugin
 * Description:       AI asistent (Claude) pro úpravu obsahu, stylů, šablon a menu přímo v administraci WordPressu.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            WP AI Editor
 * Text Domain:       wp-ai-editor
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAIE_VERSION', '2.0.0' );
define( 'WPAIE_FILE', __FILE__ );
define( 'WPAIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIE_URL', plugin_dir_url( __FILE__ ) );

define( 'WPAIE_OPTION_KEY', 'wpaie_settings' );
define( 'WPAIE_HISTORY_KEY', 'wpaie_history' );
define( 'WPAIE_CAPABILITY', 'manage_options' );

require_once WPAIE_DIR . 'includes/helpers.php';
require_once WPAIE_DIR . 'includes/settings.php';
require_once WPAIE_DIR . 'includes/api.php';
require_once WPAIE_DIR . 'includes/site-context.php';
require_once WPAIE_DIR . 'includes/tools.php';
require_once WPAIE_DIR . 'includes/conversation.php';
require_once WPAIE_DIR . 'includes/agent.php';
require_once WPAIE_DIR . 'includes/ajax.php';
require_once WPAIE_DIR . 'includes/admin.php';
