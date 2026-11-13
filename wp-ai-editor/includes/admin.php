<?php
/**
 * Admin screens: chat, history of changes, settings.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'wpaie_register_menu' );
add_action( 'admin_enqueue_scripts', 'wpaie_enqueue_assets' );
add_filter( 'plugin_action_links_' . plugin_basename( WPAIE_FILE ), 'wpaie_plugin_action_links' );

function wpaie_register_menu(): void {
	$cap = wpaie_chat_capability();

	$GLOBALS['wpaie_hooks'] = [
		'chat'     => add_menu_page( 'AI Editor', 'AI Editor', $cap, 'wp-ai-editor', 'wpaie_render_chat_page', 'dashicons-superhero', 30 ),
		'chat_sub' => add_submenu_page( 'wp-ai-editor', 'AI Editor', __( 'Chat', 'wp-ai-editor' ), $cap, 'wp-ai-editor', 'wpaie_render_chat_page' ),
		'history'  => add_submenu_page( 'wp-ai-editor', __( 'AI Editor — History', 'wp-ai-editor' ), __( 'History', 'wp-ai-editor' ), WPAIE_CAPABILITY, 'wp-ai-editor-history', 'wpaie_render_history_page' ),
		'settings' => add_submenu_page( 'wp-ai-editor', __( 'AI Editor — Settings', 'wp-ai-editor' ), __( 'Settings', 'wp-ai-editor' ), WPAIE_CAPABILITY, 'wp-ai-editor-settings', 'wpaie_render_settings_page' ),
	];
}

function wpaie_chat_url( array $args = [] ): string {
	return add_query_arg( $args, admin_url( 'admin.php?page=wp-ai-editor' ) );
}

function wpaie_settings_url(): string {
	return admin_url( 'admin.php?page=wp-ai-editor-settings' );
}

function wpaie_history_url(): string {
	return admin_url( 'admin.php?page=wp-ai-editor-history' );
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
		'interrupted'    => __( 'The connection was interrupted before the answer finished. Changes already made are saved – write "continue".', 'wp-ai-editor' ),
		'unknownError'   => __( 'Unknown error.', 'wp-ai-editor' ),
		'confirmNew'     => __( 'Start a new chat? The current one stays in the list.', 'wp-ai-editor' ),
		'confirmDelete'  => __( 'Delete this conversation?', 'wp-ai-editor' ),
		'confirmUndo'    => __( 'Undo this change? Anything changed in the same place since then will be overwritten.', 'wp-ai-editor' ),
		'testing'        => __( 'Testing…', 'wp-ai-editor' ),
		'approvalTitle'  => __( 'The assistant wants to make this change:', 'wp-ai-editor' ),
		'liveBadge'      => __( 'live on the site', 'wp-ai-editor' ),
		'approve'        => __( 'Apply', 'wp-ai-editor' ),
		'reject'         => __( 'Reject', 'wp-ai-editor' ),
		'approved'       => __( 'Approved', 'wp-ai-editor' ),
		'rejected'       => __( 'Rejected', 'wp-ai-editor' ),
		'undo'           => __( 'Undo', 'wp-ai-editor' ),
		'undone'         => __( 'Undone', 'wp-ai-editor' ),
		/* translators: %s: cost in USD */
		'turnCost'       => __( '≈ $%s', 'wp-ai-editor' ),
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
		'window.wpaieConfig = ' . wp_json_encode( wpaie_js_config() ) . ';',
		'before'
	);
}

function wpaie_js_config(): array {
	return (array) apply_filters(
		'wpaie_js_config',
		[
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'wpaie_nonce' ),
			'conversation' => wpaie_active_conversation_id(),
			'stream'       => wpaie_can_stream(),
			'i18n'         => wpaie_js_strings(),
		]
	);
}

// ─── Chat page ────────────────────────────────────────────────────────────────

function wpaie_render_chat_page(): void {
	$settings = wpaie_get_settings();
	$no_key   = wpaie_get_api_key() === '';
	$state    = wpaie_conversation_load( wpaie_active_conversation_id() );
	$items    = wpaie_conversation_transcript( $state );
	?>
	<div class="wrap" id="wpaie-wrap">
		<div id="wpaie-header">
			<span id="wpaie-logo" aria-hidden="true">✦</span>
			<h1>AI Editor</h1>
			<?php do_action( 'wpaie_chat_header', $state ); ?>
			<span id="wpaie-cost" title="<?php esc_attr_e( 'Estimated cost of this conversation', 'wp-ai-editor' ); ?>"><?php echo $state['usage']['cost'] > 0 ? esc_html( wpaie_format_usd( (float) $state['usage']['cost'] ) ) : ''; ?></span>
			<span id="wpaie-model-badge"><?php echo esc_html( $settings['model'] ); ?></span>
			<button type="button" id="wpaie-new" class="wpaie-icon-btn" title="<?php esc_attr_e( 'New chat', 'wp-ai-editor' ); ?>" aria-label="<?php esc_attr_e( 'New chat', 'wp-ai-editor' ); ?>">＋</button>
			<?php if ( current_user_can( WPAIE_CAPABILITY ) ) : ?>
				<a href="<?php echo esc_url( wpaie_history_url() ); ?>" class="wpaie-icon-btn" title="<?php esc_attr_e( 'History of changes', 'wp-ai-editor' ); ?>" aria-label="<?php esc_attr_e( 'History of changes', 'wp-ai-editor' ); ?>">↶</a>
				<a href="<?php echo esc_url( wpaie_settings_url() ); ?>" class="wpaie-icon-btn" title="<?php esc_attr_e( 'Settings', 'wp-ai-editor' ); ?>" aria-label="<?php esc_attr_e( 'Settings', 'wp-ai-editor' ); ?>">⚙</a>
			<?php endif; ?>
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
						• <strong><?php esc_html_e( 'content', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'pages and posts (saved as drafts), categories, tags, search & replace,', 'wp-ai-editor' ); ?><br>
						• <strong><?php esc_html_e( 'images', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'alt texts, featured images; you can also send me a screenshot,', 'wp-ai-editor' ); ?><br>
						<?php if ( function_exists( 'wpaie_seo_plugin' ) && wpaie_seo_plugin() ) : ?>
							• <strong><?php esc_html_e( 'SEO', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'SEO titles and meta descriptions,', 'wp-ai-editor' ); ?><br>
						<?php endif; ?>
						<?php if ( class_exists( 'WooCommerce' ) && current_user_can( 'edit_products' ) ) : ?>
							• <strong><?php esc_html_e( 'products', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'descriptions, prices, sales and stock,', 'wp-ai-editor' ); ?><br>
						<?php endif; ?>
						• <strong><?php esc_html_e( 'styles', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'global styles (theme.json) and Additional CSS,', 'wp-ai-editor' ); ?><br>
						<?php if ( current_user_can( WPAIE_CAPABILITY ) ) : ?>
							• <strong><?php esc_html_e( 'theme settings', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'everything in the Customizer: layout, fonts, colors, header, footer, blog (e.g. Astra),', 'wp-ai-editor' ); ?><br>
						<?php endif; ?>
						• <strong><?php esc_html_e( 'templates', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'block templates (FSE),', 'wp-ai-editor' ); ?><br>
						• <strong><?php esc_html_e( 'menus and site identity', 'wp-ai-editor' ); ?></strong> — <?php esc_html_e( 'menu items, logo, site title.', 'wp-ai-editor' ); ?><br>
						<?php esc_html_e( 'Write to me in any language.', 'wp-ai-editor' ); ?>
					</div>
				</div>
				<?php
				foreach ( $items as $item ) {
					wpaie_render_transcript_item( $item );
				}
				if ( $state['pending'] ) {
					wpaie_render_approval_card( wpaie_approval_cards( $state ), true );
				}
				?>
			</div>

			<?php do_action( 'wpaie_chat_before_input', $state ); ?>
			<form id="wpaie-input-row">
				<?php do_action( 'wpaie_chat_input_buttons' ); ?>
				<label for="wpaie-input" class="screen-reader-text"><?php esc_html_e( 'Message for the AI assistant', 'wp-ai-editor' ); ?></label>
				<textarea id="wpaie-input" rows="2" placeholder="<?php esc_attr_e( 'What should I change? E.g. "Change the primary button color to #e63946"…', 'wp-ai-editor' ); ?>" <?php disabled( $no_key ); ?>></textarea>
				<button type="submit" id="wpaie-send" aria-label="<?php esc_attr_e( 'Send', 'wp-ai-editor' ); ?>" <?php disabled( $no_key ); ?>>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
				</button>
			</form>
		</div>
		<p class="wpaie-footnote"><?php esc_html_e( 'Enter sends, Shift+Enter adds a new line. Every change can be undone in the chat or in the History.', 'wp-ai-editor' ); ?></p>
	</div>
	<?php
}

function wpaie_render_transcript_item( array $item ): void {
	if ( $item['role'] === 'user' ) {
		echo '<div class="wpaie-msg wpaie-msg--user"><div class="wpaie-bubble">';
		foreach ( $item['images'] ?? [] as $attachment_id ) {
			$url = wp_get_attachment_image_url( (int) $attachment_id, 'thumbnail' );
			if ( $url ) {
				echo '<img class="wpaie-msg-image" src="' . esc_url( $url ) . '" alt="" />';
			}
		}
		echo esc_html( $item['text'] ) . '</div></div>';
		return;
	}

	echo '<div class="wpaie-msg wpaie-msg--ai"><div class="wpaie-bubble">';
	foreach ( $item['tools'] as $label ) {
		echo '<div class="wpaie-tool-call">' . esc_html( $label ) . '</div>';
	}
	foreach ( $item['changes'] ?? [] as $change ) {
		wpaie_render_undo_button( $change );
	}
	echo $item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wpaie_format_ai_html().
	echo '</div></div>';
}

function wpaie_render_undo_button( array $change ): void {
	printf(
		'<div class="wpaie-change"><span>%s</span> <button type="button" class="button-link wpaie-undo" data-change="%d">%s</button></div>',
		esc_html( (string) ( $change['label'] ?? '' ) ),
		(int) ( $change['id'] ?? 0 ),
		esc_html__( 'Undo', 'wp-ai-editor' )
	);
}

/**
 * The approval card. Same markup as admin.js builds for live requests.
 */
function wpaie_render_approval_card( array $cards, bool $restored = false ): void {
	?>
	<div class="wpaie-msg wpaie-msg--ai">
		<div class="wpaie-bubble wpaie-approval" <?php echo $restored ? 'data-restored="1"' : ''; ?>>
			<p class="wpaie-approval-title"><?php esc_html_e( 'The assistant wants to make this change:', 'wp-ai-editor' ); ?></p>
			<?php foreach ( $cards as $card ) : ?>
				<div class="wpaie-approval-item">
					<div class="wpaie-tool-call"><?php echo esc_html( $card['label'] ); ?></div>
					<?php if ( $card['live'] ) : ?>
						<span class="wpaie-live-badge"><?php esc_html_e( 'live on the site', 'wp-ai-editor' ); ?></span>
					<?php endif; ?>
					<?php echo $card['preview']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in the tool preview callbacks. ?>
				</div>
			<?php endforeach; ?>
			<div class="wpaie-approval-actions">
				<button type="button" class="button button-primary wpaie-approve"><?php esc_html_e( 'Apply', 'wp-ai-editor' ); ?></button>
				<button type="button" class="button wpaie-reject"><?php esc_html_e( 'Reject', 'wp-ai-editor' ); ?></button>
			</div>
		</div>
	</div>
	<?php
}

// ─── History page ─────────────────────────────────────────────────────────────

function wpaie_render_history_page(): void {
	$page     = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
	$per_page = 50;
	$list     = wpaie_list_changes( $page, $per_page );
	$pages    = max( 1, (int) ceil( $list['total'] / $per_page ) );
	?>
	<div class="wrap" id="wpaie-history">
		<h1><?php esc_html_e( 'AI Editor — History of changes', 'wp-ai-editor' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: %d: number of days */
				esc_html__( 'Every change the assistant made, newest first. Changes are kept for %d days.', 'wp-ai-editor' ),
				(int) WPAIE_CHANGES_KEEP_DAYS
			);
			?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'wp-ai-editor' ); ?></th>
					<th><?php esc_html_e( 'User', 'wp-ai-editor' ); ?></th>
					<th><?php esc_html_e( 'Change', 'wp-ai-editor' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $list['rows'] ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No changes yet.', 'wp-ai-editor' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $list['rows'] as $row ) : ?>
					<?php $user = get_userdata( (int) $row->user_id ); ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '#' . (int) $row->user_id ); ?></td>
						<td><?php echo esc_html( $row->label ); ?></td>
						<td>
							<?php if ( $row->reverted_at ) : ?>
								<em><?php esc_html_e( 'Undone', 'wp-ai-editor' ); ?></em>
							<?php else : ?>
								<button type="button" class="button wpaie-undo" data-change="<?php echo (int) $row->id; ?>"><?php esc_html_e( 'Undo', 'wp-ai-editor' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						[
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => $pages,
						]
					)
				);
				?>
			</div></div>
		<?php endif; ?>
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
						<p class="description"><?php esc_html_e( 'Claude Fable 5.2 costs about twice as much as Opus 5.5 and one answer can take several minutes; it requires 30-day data retention on your Anthropic organization (not available with zero data retention).', 'wp-ai-editor' ); ?></p>
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
						<p class="description"><?php esc_html_e( 'With Claude Fable 5.2, start with High.', 'wp-ai-editor' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpaie-approval"><?php esc_html_e( 'Ask before changes', 'wp-ai-editor' ); ?></label></th>
					<td>
						<select id="wpaie-approval" name="<?php echo esc_attr( $name ); ?>[approval_mode]">
							<?php foreach ( wpaie_approval_modes() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['approval_mode'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The assistant shows a preview of the change and waits for Apply or Reject. Every change can be undone in the History either way.', 'wp-ai-editor' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpaie-budget"><?php esc_html_e( 'Monthly budget (USD)', 'wp-ai-editor' ); ?></label></th>
					<td>
						<input type="number" id="wpaie-budget" name="<?php echo esc_attr( $name ); ?>[monthly_budget]" value="<?php echo esc_attr( (string) $settings['monthly_budget'] ); ?>" min="0" step="0.01" class="small-text" />
						<p class="description"><?php esc_html_e( 'The chat stops when the estimated spending this month reaches this amount. 0 = no limit.', 'wp-ai-editor' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Live answers', 'wp-ai-editor' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[streaming]" value="1" <?php checked( $settings['streaming'] ); ?> <?php disabled( ! function_exists( 'curl_init' ) ); ?> />
							<?php esc_html_e( 'Show the answer as it is written (streaming)', 'wp-ai-editor' ); ?>
						</label>
						<p class="description">
							<?php
							echo function_exists( 'curl_init' )
								? esc_html__( 'Also keeps long answers from hitting hosting time limits. Turn it off if answers appear only at the end or never arrive (some proxies buffer the response).', 'wp-ai-editor' )
								: esc_html__( 'Not available: the PHP cURL extension is missing on this server.', 'wp-ai-editor' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Editors', 'wp-ai-editor' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_editors]" value="1" <?php checked( $settings['allow_editors'] ); ?> />
							<?php esc_html_e( 'Let editors use the chat', 'wp-ai-editor' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Editors get only the tools their role allows: content, categories and tags, media, SEO. Styles, CSS, templates, menus and site identity stay with administrators. Editors can undo their own changes; the History and Settings stay admin-only.', 'wp-ai-editor' ); ?></p>
					</td>
				</tr>
				<?php do_action( 'wpaie_settings_rows', $settings ); ?>
			</table>
			<?php submit_button( __( 'Save', 'wp-ai-editor' ) ); ?>
		</form>

		<?php do_action( 'wpaie_settings_after_form', $settings ); ?>

		<h2><?php esc_html_e( 'Connection test', 'wp-ai-editor' ); ?></h2>
		<p><?php esc_html_e( 'Checks the saved key and that the selected model is available (save your changes first).', 'wp-ai-editor' ); ?></p>
		<p>
			<button type="button" class="button" id="wpaie-test" <?php disabled( wpaie_get_api_key() === '' ); ?>><?php esc_html_e( 'Test connection', 'wp-ai-editor' ); ?></button>
			<span id="wpaie-test-result" role="status"></span>
		</p>
	</div>
	<?php
}
