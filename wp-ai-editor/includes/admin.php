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
		add_submenu_page( 'wp-ai-editor', 'AI Editor', 'Chat', WPAIE_CAPABILITY, 'wp-ai-editor', 'wpaie_render_chat_page' ),
		add_submenu_page( 'wp-ai-editor', 'AI Editor — Nastavení', 'Nastavení', WPAIE_CAPABILITY, 'wp-ai-editor-settings', 'wpaie_render_settings_page' ),
	];
}

function wpaie_settings_url(): string {
	return admin_url( 'admin.php?page=wp-ai-editor-settings' );
}

function wpaie_plugin_action_links( array $links ): array {
	array_unshift( $links, sprintf( '<a href="%s">Nastavení</a>', esc_url( wpaie_settings_url() ) ) );
	return $links;
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
			<button type="button" id="wpaie-reset" class="wpaie-icon-btn" title="Nový chat (smaže historii)" aria-label="Nový chat">🗑</button>
			<a href="<?php echo esc_url( wpaie_settings_url() ); ?>" class="wpaie-icon-btn" title="Nastavení" aria-label="Nastavení">⚙</a>
		</div>

		<?php if ( $no_key ) : ?>
			<div class="wpaie-notice">
				⚠ Nejdřív nastav API klíč Anthropic v <a href="<?php echo esc_url( wpaie_settings_url() ); ?>">Nastavení</a>.
			</div>
		<?php endif; ?>

		<div id="wpaie-chat">
			<div id="wpaie-messages" aria-live="polite">
				<div class="wpaie-msg wpaie-msg--ai">
					<div class="wpaie-bubble">
						Ahoj! Jsem AI asistent pro správu tohoto webu. Umím upravovat:<br>
						• <strong>obsah</strong> — stránky a příspěvky (ukládám jako koncepty),<br>
						• <strong>styly</strong> — globální styly (theme.json) a Doplňkový CSS,<br>
						• <strong>šablony</strong> — block templates (FSE),<br>
						• <strong>menu a identitu webu</strong> — položky menu, logo, název.
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
				<label for="wpaie-input" class="screen-reader-text">Zpráva pro AI asistenta</label>
				<textarea id="wpaie-input" rows="2" placeholder="Co mám upravit? Např. „Změň barvu primárního tlačítka na #e63946“…" <?php disabled( $no_key ); ?>></textarea>
				<button type="submit" id="wpaie-send" aria-label="Odeslat" <?php disabled( $no_key ); ?>>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
				</button>
			</form>
		</div>
		<p class="wpaie-footnote">Enter odešle, Shift+Enter nový řádek. Změny identity webu, CSS, stylů, šablon a menu jsou na webu vidět ihned.</p>
	</div>
	<?php
}

// ─── Settings page ────────────────────────────────────────────────────────────

function wpaie_render_settings_page(): void {
	$settings     = wpaie_get_settings();
	$from_const   = wpaie_api_key_from_constant();
	$stored_key   = (string) $settings['api_key'];
	$name         = WPAIE_OPTION_KEY;
	?>
	<div class="wrap" id="wpaie-settings">
		<h1>AI Editor — Nastavení</h1>
		<?php settings_errors(); // Not a Settings submenu, so WordPress does not print these on its own. ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'wpaie_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpaie-api-key">API klíč Anthropic</label></th>
					<td>
						<?php if ( $from_const ) : ?>
							<p><strong>Klíč je nastaven konstantou <code>WPAIE_API_KEY</code> v wp-config.php</strong> (<?php echo esc_html( wpaie_mask_key( wpaie_get_api_key() ) ); ?>).</p>
						<?php else : ?>
							<input type="password" id="wpaie-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value=""
								class="regular-text" autocomplete="new-password" spellcheck="false"
								placeholder="<?php echo esc_attr( $stored_key !== '' ? 'Uložen: ' . wpaie_mask_key( $stored_key ) . ' — vyplň jen pro změnu' : 'sk-ant-…' ); ?>" />
							<?php if ( $stored_key !== '' ) : ?>
								<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remove_api_key]" value="1" /> Odebrat uložený klíč</label></p>
							<?php endif; ?>
							<p class="description">
								Klíč vytvoříš v <a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener noreferrer">Claude Console</a>.
								Bezpečnější alternativa: do wp-config.php přidej <code>define( 'WPAIE_API_KEY', 'sk-ant-…' );</code>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpaie-model">Model</label></th>
					<td>
						<select id="wpaie-model" name="<?php echo esc_attr( $name ); ?>[model]">
							<?php foreach ( wpaie_models() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpaie-effort">Úroveň přemýšlení</label></th>
					<td>
						<select id="wpaie-effort" name="<?php echo esc_attr( $name ); ?>[effort]">
							<?php foreach ( wpaie_effort_levels() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['effort'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Vyšší úroveň = pečlivější práce, ale delší čekání a vyšší cena.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Uložit' ); ?>
		</form>

		<h2>Test připojení</h2>
		<p>Ověří uložený klíč a dostupnost zvoleného modelu (nejdřív ulož změny).</p>
		<p>
			<button type="button" class="button" id="wpaie-test" <?php disabled( wpaie_get_api_key() === '' ); ?>>Otestovat připojení</button>
			<span id="wpaie-test-result" role="status"></span>
		</p>
	</div>
	<?php
}
