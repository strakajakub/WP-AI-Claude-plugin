<?php
/**
 * Chat extras and integrations:
 * - conversation switcher in the chat header,
 * - image attachments (button, preview strip),
 * - page context (?context_post / ?context_url) and the embedded mode (?embed=1),
 * - "AI Editor" sidebar in the block editor and a link in the admin bar.
 */

defined( 'ABSPATH' ) || exit;

// ─── Conversation switcher ────────────────────────────────────────────────────

add_action(
	'wpaie_chat_header',
	function ( array $state ) {
		$list = wpaie_conversations_list();
		?>
		<label for="wpaie-conversations" class="screen-reader-text"><?php esc_html_e( 'Conversation', 'wp-ai-editor' ); ?></label>
		<select id="wpaie-conversations">
			<?php if ( ! $state['messages'] ) : ?>
				<option value="" selected><?php esc_html_e( 'New chat', 'wp-ai-editor' ); ?></option>
			<?php endif; ?>
			<?php foreach ( $list as $c ) : ?>
				<option value="<?php echo esc_attr( $c['id'] ); ?>" <?php selected( $c['id'], $state['id'] ); ?>>
					<?php echo esc_html( ( $c['title'] !== '' ? $c['title'] : __( '(untitled)', 'wp-ai-editor' ) ) . ' · ' . wp_date( 'j. n. H:i', (int) $c['updated'] ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( $state['messages'] ) : ?>
			<button type="button" id="wpaie-delete" class="wpaie-icon-btn" title="<?php esc_attr_e( 'Delete this conversation', 'wp-ai-editor' ); ?>" aria-label="<?php esc_attr_e( 'Delete this conversation', 'wp-ai-editor' ); ?>">🗑</button>
		<?php endif; ?>
		<?php
	}
);

// ─── Image attachments ────────────────────────────────────────────────────────

add_action(
	'wpaie_chat_input_buttons',
	function () {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		?>
		<button type="button" id="wpaie-attach" class="wpaie-icon-btn" title="<?php esc_attr_e( 'Attach an image (or paste / drop it)', 'wp-ai-editor' ); ?>" aria-label="<?php esc_attr_e( 'Attach an image', 'wp-ai-editor' ); ?>">📎</button>
		<input type="file" id="wpaie-file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden />
		<?php
	}
);

add_action(
	'wpaie_chat_before_input',
	function () {
		echo '<div id="wpaie-attachments" hidden></div>';
	}
);

// ─── Context, embedded mode, JS config ────────────────────────────────────────

/**
 * Page context passed in the chat URL by the block editor sidebar or the admin bar link.
 */
function wpaie_request_context(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display context.
	$post_id = isset( $_GET['context_post'] ) ? absint( $_GET['context_post'] ) : 0;
	$url     = isset( $_GET['context_url'] ) ? esc_url_raw( wp_unslash( $_GET['context_url'] ) ) : '';
	// phpcs:enable
	if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
		$post_id = 0;
	}
	return [
		'post'  => $post_id,
		'url'   => $url,
		'title' => $post_id ? get_the_title( $post_id ) : '',
	];
}

function wpaie_is_embedded(): bool {
	return ! empty( $_GET['embed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display mode only.
}

add_filter(
	'wpaie_js_config',
	function ( array $config ) {
		$context                       = wpaie_request_context();
		$config['context']             = $context;
		$config['maxImages']           = WPAIE_MAX_IMAGES;
		$config['i18n']['tooManyImgs'] = sprintf(
			/* translators: %d: maximum number of images */
			__( 'You can attach at most %d images to one message.', 'wp-ai-editor' ),
			WPAIE_MAX_IMAGES
		);
		$config['i18n']['uploading']   = __( 'Uploading image…', 'wp-ai-editor' );
		$config['i18n']['remove']      = __( 'Remove', 'wp-ai-editor' );
		/* translators: %s: post title */
		$config['i18n']['contextPost'] = __( 'Context: %s', 'wp-ai-editor' );
		return $config;
	}
);

// Context hint above the input.
add_action(
	'wpaie_chat_before_input',
	function () {
		$context = wpaie_request_context();
		if ( ! $context['post'] ) {
			return;
		}
		printf(
			'<div id="wpaie-context">%s</div>',
			esc_html(
				sprintf(
					/* translators: %s: post title */
					__( 'Context: %s', 'wp-ai-editor' ),
					$context['title'] !== '' ? $context['title'] : '#' . $context['post']
				)
			)
		);
	},
	5
);

// Embedded mode (inside the block editor sidebar): no admin menu, bar or footer.
add_filter(
	'admin_body_class',
	function ( string $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( wpaie_is_embedded() && $screen && in_array( $screen->id, (array) ( $GLOBALS['wpaie_hooks'] ?? [] ), true ) ) {
			$classes .= ' wpaie-embed';
		}
		return $classes;
	}
);

// ─── Block editor sidebar ─────────────────────────────────────────────────────

add_action(
	'enqueue_block_editor_assets',
	function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! wpaie_user_can_chat() || ! $screen || $screen->base !== 'post' ) {
			return;
		}
		wp_enqueue_script(
			'wpaie-editor',
			WPAIE_URL . 'assets/editor.js',
			[ 'wp-plugins', 'wp-element', 'wp-data', 'wp-edit-post' ],
			WPAIE_VERSION,
			true
		);
		wp_add_inline_script(
			'wpaie-editor',
			'window.wpaieEditor = ' . wp_json_encode(
				[
					'chatUrl' => wpaie_chat_url( [ 'embed' => 1 ] ),
					'title'   => 'AI Editor',
					'notice'  => __( 'After the assistant changes this post, reload the editor to see the change. Save your own edits first.', 'wp-ai-editor' ),
				]
			) . ';',
			'before'
		);
	}
);

// ─── Admin bar ────────────────────────────────────────────────────────────────

add_action(
	'admin_bar_menu',
	function ( WP_Admin_Bar $bar ) {
		if ( ! is_user_logged_in() || ! wpaie_user_can_chat() ) {
			return;
		}
		$args = [];
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && $screen->base === 'post' && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- link only.
				$args['context_post'] = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		} else {
			if ( is_singular() ) {
				$args['context_post'] = get_queried_object_id();
			}
			$args['context_url'] = rawurlencode( home_url( add_query_arg( [] ) ) );
		}
		$bar->add_node(
			[
				'id'    => 'wpaie',
				'title' => '<span class="ab-icon dashicons dashicons-superhero" style="margin-top:2px"></span><span class="ab-label">AI Editor</span>',
				'href'  => wpaie_chat_url( $args ),
				'meta'  => [ 'title' => __( 'Ask the AI assistant about this page', 'wp-ai-editor' ) ],
			]
		);
	},
	100
);
