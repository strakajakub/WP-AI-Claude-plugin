<?php
/**
 * Images in the chat and the page context of a message.
 *
 * Images the user attaches are saved to the Media Library (so the assistant can use them, e.g. as
 * a logo) and sent to the model through the Files API: the conversation stores only the file ID,
 * which keeps the stored history small and byte-identical between requests.
 */

defined( 'ABSPATH' ) || exit;

/** Images per message. */
define( 'WPAIE_MAX_IMAGES', 5 );

/** Largest image sent to the model (bytes). */
define( 'WPAIE_MAX_IMAGE_BYTES', 5 * 1024 * 1024 );

const WPAIE_IMAGE_MIMES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

add_action( 'wp_ajax_wpaie_upload_image', 'wpaie_ajax_upload_image' );

/**
 * Upload one image from the chat to the Media Library.
 */
function wpaie_ajax_upload_image(): void {
	wpaie_ajax_guard();
	if ( ! current_user_can( 'upload_files' ) ) {
		wpaie_ajax_fail( __( 'You are not allowed to upload files.', 'wp-ai-editor' ), 'forbidden', 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- nonce verified in wpaie_ajax_guard(); the file is validated below and by media_handle_upload().
	$files = $_FILES['files'] ?? null;
	if ( ! is_array( $files ) || empty( $files['name'][0] ) ) {
		wpaie_ajax_fail( __( 'No image was received.', 'wp-ai-editor' ), 'no_file' );
	}
	$upload_error = (int) ( $files['error'][0] ?? UPLOAD_ERR_NO_FILE );
	if ( in_array( $upload_error, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ) {
		wpaie_ajax_fail(
			sprintf(
				/* translators: %s: maximum upload size, e.g. "2 MB" */
				__( 'The image is larger than this server allows (max. %s). Use a smaller image or ask your host to raise upload_max_filesize.', 'wp-ai-editor' ),
				size_format( wp_max_upload_size() )
			),
			'size'
		);
	}
	if ( $upload_error !== UPLOAD_ERR_OK ) {
		wpaie_ajax_fail(
			/* translators: %d: PHP upload error code */
			sprintf( __( 'The image upload failed on the server (PHP upload error %d).', 'wp-ai-editor' ), $upload_error ),
			'upload'
		);
	}
	$_FILES['wpaie_image'] = [
		'name'     => $files['name'][0],
		'type'     => $files['type'][0],
		'tmp_name' => $files['tmp_name'][0],
		'error'    => $files['error'][0],
		'size'     => $files['size'][0],
	];
	// phpcs:enable

	$check = wp_check_filetype_and_ext( $_FILES['wpaie_image']['tmp_name'], $_FILES['wpaie_image']['name'] );
	if ( ! in_array( $check['type'] ?? '', WPAIE_IMAGE_MIMES, true ) ) {
		wpaie_ajax_fail( __( 'Only JPEG, PNG, GIF and WebP images can be attached.', 'wp-ai-editor' ), 'type' );
	}
	if ( (int) $_FILES['wpaie_image']['size'] > WPAIE_MAX_IMAGE_BYTES ) {
		wpaie_ajax_fail( __( 'The image is too large (max. 5 MB).', 'wp-ai-editor' ), 'size' );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$id = media_handle_upload( 'wpaie_image', 0 );
	if ( is_wp_error( $id ) ) {
		wpaie_ajax_fail(
			/* translators: %s: error message */
			sprintf( __( 'The image could not be saved: %s', 'wp-ai-editor' ), $id->get_error_message() ),
			'upload'
		);
	}
	update_post_meta( $id, '_wpaie_uploaded', 1 );

	wpaie_ajax_success(
		[
			'id'    => (int) $id,
			'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
		]
	);
}

/**
 * Path and MIME type of the best version of an attachment to send to the model: the "large"
 * size when it exists (enough detail, fewer tokens), otherwise the original.
 *
 * @return array{path:string,mime:string}|WP_Error
 */
function wpaie_attachment_image_file( int $attachment_id ) {
	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return new WP_Error( 'wpaie_image', "Attachment #$attachment_id does not exist or is not an image." );
	}
	$path = (string) get_attached_file( $attachment_id );
	$mime = (string) get_post_mime_type( $attachment_id );

	$large = image_get_intermediate_size( $attachment_id, 'large' );
	if ( $large && ! empty( $large['path'] ) ) {
		$uploads = wp_get_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . $large['path'];
		$mime    = (string) ( $large['mime-type'] ?? $mime );
	}

	if ( ! in_array( $mime, WPAIE_IMAGE_MIMES, true ) ) {
		return new WP_Error( 'wpaie_image', "Attachment #$attachment_id is a $mime image; only JPEG, PNG, GIF and WebP can be sent to the model." );
	}
	if ( ! is_readable( $path ) || filesize( $path ) > WPAIE_MAX_IMAGE_BYTES ) {
		return new WP_Error( 'wpaie_image', "The image file of attachment #$attachment_id is missing or larger than 5 MB." );
	}
	return [
		'path' => $path,
		'mime' => $mime,
	];
}

/**
 * Files API ID of an attachment for the current conversation (uploaded once per conversation).
 *
 * @param array $files attachment id => file id (the conversation's map, updated in place)
 * @return string|WP_Error
 */
function wpaie_attachment_file_id( int $attachment_id, array &$files, string $api_key ) {
	if ( isset( $files[ $attachment_id ] ) ) {
		return (string) $files[ $attachment_id ];
	}
	$file = wpaie_attachment_image_file( $attachment_id );
	if ( is_wp_error( $file ) ) {
		return $file;
	}
	$file_id = wpaie_files_upload( $api_key, $file['path'], $file['mime'], wp_basename( $file['path'] ) );
	if ( is_wp_error( $file_id ) ) {
		return $file_id;
	}
	$files[ $attachment_id ] = $file_id;
	return $file_id;
}

/**
 * Content blocks to put before the user's text: page context and attached images.
 *
 * @return array|WP_Error
 */
function wpaie_request_extra_blocks( array &$state, string $api_key ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in wpaie_ajax_guard().
	$blocks = [];

	$context = wpaie_context_note(
		isset( $_POST['context_post'] ) ? absint( $_POST['context_post'] ) : 0,
		isset( $_POST['context_url'] ) ? esc_url_raw( wp_unslash( $_POST['context_url'] ) ) : ''
	);
	if ( $context !== '' ) {
		$blocks[] = [
			'type' => 'text',
			'text' => $context,
		];
	}

	$ids = isset( $_POST['images'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['images'] ) ) ) ) ) : [];
	// phpcs:enable
	if ( count( $ids ) > WPAIE_MAX_IMAGES ) {
		return new WP_Error(
			'wpaie_images',
			/* translators: %d: maximum number of images */
			sprintf( __( 'You can attach at most %d images to one message.', 'wp-ai-editor' ), WPAIE_MAX_IMAGES )
		);
	}

	foreach ( $ids as $id ) {
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaie_images', __( 'You are not allowed to use one of the attached images.', 'wp-ai-editor' ) );
		}
		$file_id = wpaie_attachment_file_id( $id, $state['files'], $api_key );
		if ( is_wp_error( $file_id ) ) {
			return new WP_Error(
				'wpaie_images',
				/* translators: %s: error message */
				sprintf( __( 'The image could not be sent to the AI: %s', 'wp-ai-editor' ), $file_id->get_error_message() )
			);
		}
		$blocks[] = [
			'type' => 'text',
			'text' => WPAIE_IMAGE_PREFIX . $id . '] The user attached this image; it is saved in the Media Library with this attachment ID.',
		];
		$blocks[] = [
			'type'   => 'image',
			'source' => [
				'type'    => 'file',
				'file_id' => $file_id,
			],
		];
	}
	return $blocks;
}

/**
 * Note telling the model where the user opened the chat from (block editor / admin bar).
 */
function wpaie_context_note( int $post_id, string $url ): string {
	$parts = [];
	if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
		$post = get_post( $post_id );
		if ( $post ) {
			$parts[] = sprintf(
				'The user opened the AI Editor for %s #%d "%s" (status: %s). Requests like "this page" refer to it. If you change it while it is open in the block editor, tell the user to reload the editor to see the change.',
				$post->post_type,
				$post->ID,
				$post->post_title,
				$post->post_status
			);
		}
	}
	if ( $url !== '' && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		$parts[] = 'The user was viewing ' . $url . ' on the site.';
	}
	return $parts ? WPAIE_CONTEXT_PREFIX . implode( ' ', $parts ) . ']' : '';
}
