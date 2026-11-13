<?php
/**
 * Plugin Name:       WP AI Editor
 * Plugin URI:        https://github.com/strakajakub/WP-AI-Claude-plugin
 * Description:       AI assistant (Claude) that edits content, styles, templates and menus right from the WordPress admin.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Jakub Straka
 * Author URI:        https://github.com/strakajakub
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       wp-ai-editor
 * Domain Path:       /languages
 *
 * Copyright (C) 2026 Jakub Straka
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAIE_VERSION', '2.0.0' );
define( 'WPAIE_FILE', __FILE__ );
define( 'WPAIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIE_URL', plugin_dir_url( __FILE__ ) );

define( 'WPAIE_OPTION_KEY', 'wpaie_settings' );
define( 'WPAIE_HISTORY_KEY', 'wpaie_history' );
define( 'WPAIE_CAPABILITY', 'manage_options' );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'wp-ai-editor', false, dirname( plugin_basename( WPAIE_FILE ) ) . '/languages' );
	}
);

require_once WPAIE_DIR . 'includes/helpers.php';
require_once WPAIE_DIR . 'includes/settings.php';
require_once WPAIE_DIR . 'includes/api.php';
require_once WPAIE_DIR . 'includes/site-context.php';
require_once WPAIE_DIR . 'includes/tools.php';
require_once WPAIE_DIR . 'includes/conversation.php';
require_once WPAIE_DIR . 'includes/agent.php';
require_once WPAIE_DIR . 'includes/ajax.php';
require_once WPAIE_DIR . 'includes/admin.php';
