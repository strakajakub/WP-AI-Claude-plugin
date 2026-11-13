<?php
/**
 * Admin screens: chat page and settings page.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'wpaie_register_menu' );
add_action( 'admin_enqueue_scripts', 'wpaie_enqueue_assets' );
add_filter( 'plugin_action_links_' . plugin_basename( WPAIE_FILE ), 'wpaie_plugin_action_links' );

function wpaie_register_menu(): void {
	$GLOBALS['wpaie_hooks'] = [
		add_menu_page( 'AI Editor', 'AI Editor', WPAIE_CAPABILITY, 'wp-ai-editor', 'wpaie_render_chat_page', 'dashicons-superhero', 30 ),
		add_submenu_page( 'wp-ai-editor', 'AI Editor', __( 'Chat', 'wp-ai-editor' ), WPAIE_CAPABILITY, 'wp-ai-editor', 'wpaie_render_chat_page' ),
		add_submenu_page( 'wp-ai-editor', __( 'AI Editor — Settings', 'wp-ai-editor' ), __( 'Settings', 'wp-ai-editor' ), WPAIE_CAPABILITY, 'wp-ai-editor-settings', 'wpaie_render_settings_page' ),
	];
}

function wpaie_settings_url(): string {
	return admin_url( 'admin.php?page=wp-ai-editor-settings' );
}

function wpaie_plugin_action_links( array $links ): array {
	array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( wpaie_settings_url() ), esc_html__( 'Settings', 'wp-ai-editor' ) ) );
	return $links;
}

/**
 * Strings used by admin.js (kept here so they are translatable).
 */
function wpaie_js_strings(): array {
	return [
		'networkError'   => __( 'Could not reach the website server. Check your internet connection and try again.', 'wp-ai-editor' ),
		'expired'        => __( 'This page has expired. Please reload it (F5) and try again.', 'wp-ai-editor' ),
		'loggedOut'      => __( 'You are not logged in or the plugin is not active. Reload the page and log in.', 'wp-ai-editor' ),
		/* translators: %d: HTTP status code */
		'gatewayTimeout' => __( 'The website server stopped waiting for the AI (hosting time limit, HTTP %d). Try a simpler request or a lower "Thinking effort" in Settings. Changes already made are saved – write "continue".', 'wp-ai-editor' ),
		/* translators: %d: HTTP status code */
		'serverError'    => __( 'Error on the website server (HTTP %d). See the PHP error log for details (enable WP_DEBUG_LOG).', 'wp-ai-editor' ),
		/* translators: %d: HTTP status code */
		'unexpected'     => __( 'The server returned an unexpected response (HTTP %d).', 'wp-ai-editor' ),
		'unknownError'   => __( 'Unknown error.', 'wp-ai-editor' ),
		'confirmReset'   => __( 'Delete the whole conversation history and start a new chat?', 'wp-ai-editor' ),
		'testing'        => __( 'Testing…', 'wp-ai-editor' ),
	];
}

function wpaie_enqueue_assets( string $hook ): void {
	if ( ! in_array( $hook, (array) ( $GLOBALS['wpaie_hooks'] ?? [] ), true ) ) {
		return;
	}
	wp_enqueue_style( 'wpaie-admin', WPAIE_URL . 'assets/admin.css', [], WPAIE_VERSION );
	wp_enqueue_script( 'wpaie-admin', WPAIE_URL . 'assets/admin.js', [], WPAIE_VERSION, true );
	wp_add_inline_script(
		'wpaie-admin',
		'window.wpaieConfig = ' . wp_json_encode(
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpaie_nonce' ),
				'i18n'    => wpaie_js_strings(),
			]
		) . ';',
		'before'
	);
}

// ─── Chat page ────────────────────────────────────────────────────────────────

function wpaie_render_chat_page(): void {
	$settings = wpaie_get_settings();
	$no_key   = wpaie_get_api_key() === '';
	$items    = wpaie_conversation_transcript( wpaie_conversation_load() );
	?>
	<div class="wrap" id="wpaie-wrap">
		<div id="wpaie-header">
			<span id="wpaie-logo" aria-hidden="true">✦</span>
			<h1>AI Editor</h1>
			<span id="wpaie-model-badge"><?php echo esc_html( $settings['model'] ); ?></span>
			<button type="button" id="wpaie-reset" class="wpaie-icon-btn" title="<?php esc_attr_e( 'New chat (deletes the history)', 'wp-ai-editor' ); ?>" aria-label="<?php esc_attr_e( 'New chat', 'wp-ai-editor' ); ?>">🗑</button>
			<a href="<?php echo esc_url( wpaie_settings_url() ); ?>" class="wpaie-icon-btn" title="<?php esc_attr_e( 'Settings', 'wp-ai-editor' ); ?>" aria-label="<?php esc_attr_e( 'Settings', 'wp-ai-editor' ); ?>">⚙</a>
		</div>

		<?php if ( $no_key ) : ?>
			<div class="wpaie-notice">
				⚠ <?php
				printf(
					/* translators: %s: link to the settings page */
					esc_html__( 'Set your Anthropic API key in %s first.', 'wp-ai-editor' ),
					'<a href="' . esc_url( wpaie_settings_url() ) . '">' . esc_html__( 'Settings', 'wp-ai-editor' ) . '</a>'
				);
				?>
			</div>
		<?php endif; ?>

		<div id="wpaie-chat">
			<div id="wpaie-messages" aria-live="polite">
				<div class="wpaie-msg wpaie-msg--ai">
					<div class="wpaie-bubble">
						<?php esc_html_e( 'Hi! I am the AI assistant for managing this site. I can edit:', 'wp-ai-editor' ); ?><br>
						• <strong><?php esc_html_e( 'content', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'pages and posts (saved as drafts),', 'wp-ai-editor' ); ?><br>
						• <strong><?php esc_html_e( 'styles', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'global styles (theme.json) and Additional CSS,', 'wp-ai-editor' ); ?><br>
						• <strong><?php esc_html_e( 'templates', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'block templates (FSE),', 'wp-ai-editor' ); ?><br>
						• <strong><?php esc_html_e( 'menus and site identity', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'menu items, logo, site title.', 'wp-ai-editor' ); ?><br>
						<?php esc_html_e( 'Write to me in any language.', 'wp-ai-editor' ); ?>
					</div>
				</div>
				<?php foreach ( $items as $item ) : ?>
					<?php if ( $item['role'] === 'user' ) : ?>
						<div class="wpaie-msg wpaie-msg--user"><div class="wpaie-bubble"><?php echo esc_html( $item['text'] ); ?></div></div>
					<?php else : ?>
						<div class="wpaie-msg wpaie-msg--ai"><div class="wpaie-bubble">
							<?php foreach ( $item['tools'] as $label ) : ?>
								<div class="wpaie-tool-call"><?php echo esc_html( $label ); ?></div>
							<?php endforeach; ?>
							<?php echo $item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wpaie_format_ai_html(). ?>
						</div></div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<form id="wpaie-input-row">
				<label for="wpaie-input" class="screen-reader-text"><?php esc_html_e( 'Message for the AI assistant', 'wp-ai-editor' ); ?></label>
				<textarea id="wpaie-input" rows="2" placeholder="<?php esc_attr_e( 'What should I change? E.g. "Change the primary button color to #e63946"…', 'wp-ai-editor' ); ?>" <?php disabled( $no_key ); ?>></textarea>
				<button type="submit" id="wpaie-send" aria-label="<?php esc_attr_e( 'Send', 'wp-ai-editor' ); ?>" <?php disabled( $no_key ); ?>>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
				</button>
			</form>
		</div>
		<p class="wpaie-footnote"><?php esc_html_e( 'Enter sends, Shift+Enter adds a new line. Changes to site identity, CSS, styles, templates and menus are live on the site immediately.', 'wp-ai-editor' ); ?></p>
	</div>
	<?php
}

// ─── Settings page ────────────────────────────────────────────────────────────

function wpaie_render_settings_page(): void {
	$settings   = wpaie_get_settings();
	$from_const = wpaie_api_key_from_constant();
	$stored_key = (string) $settings['api_key'];
	$name       = WPAIE_OPTION_KEY;
	?>
	<div class="wrap" id="wpaie-settings">
		<h1><?php esc_html_e( 'AI Editor — Settings', 'wp-ai-editor' ); ?></h1>
		<?php settings_errors(); // Not a Settings submenu, so WordPress does not print these on its own. ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'wpaie_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpaie-api-key"><?php esc_html_e( 'Anthropic API key', 'wp-ai-editor' ); ?></label></th>
					<td>
						<?php if ( $from_const ) : ?>
							<p>
								<strong>
								<?php
								printf(
									/* translators: %s: constant name */
									esc_html__( 'The key is set by the %s constant in wp-config.php', 'wp-ai-editor' ),
									'<code>WPAIE_API_KEY</code>'
								);
								?>
								</strong>
								(<?php echo esc_html( wpaie_mask_key( wpaie_get_api_key() ) ); ?>).
							</p>
						<?php else : ?>
							<input type="password" id="wpaie-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value=""
								class="regular-text" autocomplete="new-password" spellcheck="false"
								placeholder="<?php echo esc_attr( $stored_key !== '' ? sprintf( /* translators: %s: masked API key */ __( 'Saved: %s — fill in only to change it', 'wp-ai-editor' ), wpaie_mask_key( $stored_key ) ) : 'sk-ant-…' ); ?>" />
							<?php if ( $stored_key !== '' ) : ?>
								<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remove_api_key]" value="1" /> <?php esc_html_e( 'Remove the saved key', 'wp-ai-editor' ); ?></label></p>
							<?php endif; ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the Claude Console */
									esc_html__( 'Create a key in the %s.', 'wp-ai-editor' ),
									'<a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener noreferrer">Claude Console</a>'
								);
								?>
								<?php esc_html_e( 'More secure alternative: add this to wp-config.php:', 'wp-ai-editor' ); ?>
								<code>define( 'WPAIE_API_KEY', 'sk-ant-…' );</code>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpaie-model"><?php esc_html_e( 'Model', 'wp-ai-editor' ); ?></label></th>
					<td>
						<select id="wpaie-model" name="<?php echo esc_attr( $name ); ?>[model]">
							<?php foreach ( wpaie_models() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpaie-effort"><?php esc_html_e( 'Thinking effort', 'wp-ai-editor' ); ?></label></th>
					<td>
						<select id="wpaie-effort" name="<?php echo esc_attr( $name ); ?>[effort]">
							<?php foreach ( wpaie_effort_levels() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['effort'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Higher effort means more careful work, but longer waits and higher cost.', 'wp-ai-editor' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'wp-ai-editor' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Connection test', 'wp-ai-editor' ); ?></h2>
		<p><?php esc_html_e( 'Checks the saved key and that the selected model is available (save your changes first).', 'wp-ai-editor' ); ?></p>
		<p>
			<button type="button" class="button" id="wpaie-test" <?php disabled( wpaie_get_api_key() === '' ); ?>><?php esc_html_e( 'Test connection', 'wp-ai-editor' ); ?></button>
			<span id="wpaie-test-result" role="status"></span>
		</p>
	</div>
	<?php
}
