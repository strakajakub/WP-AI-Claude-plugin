<?php
/**
 * Media tools: list images, look at an image, alt texts, featured images.
 */

defined( 'ABSPATH' ) || exit;

/** Alt texts per update_media_alt call. */
define( 'WPAIE_MAX_ALT_ITEMS', 50 );

function wpaie_media_tools(): array {
	return [
		[
			'name'         => 'list_media',
			'description'  => 'List images in the Media Library (ID, title, file name, alt text, size, the post it is attached to). Use missing_alt=true to find images without alt text.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'search'      => [ 'type' => 'string', 'description' => 'Text in the title or file name (optional).' ],
					'missing_alt' => [ 'type' => 'boolean', 'description' => 'Only images without alt text.' ],
					'limit'       => [ 'type' => 'integer', 'description' => 'Maximum number of results (1–100), default 30.' ],
					'offset'      => [ 'type' => 'integer', 'description' => 'Skip this many results (for paging).' ],
				],
			],
			'cap'          => 'upload_files',
			'label'        => fn() => __( 'Loading images…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_list_media',
		],
		[
			'name'         => 'get_image',
			'description'  => 'Look at an image from the Media Library (returns the image itself), e.g. to write an accurate alt text or check a logo.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'attachment_id' ],
				'properties' => [
					'attachment_id' => [ 'type' => 'integer' ],
				],
			],
			'cap'          => 'upload_files',
			/* translators: %d: attachment ID */
			'label'        => fn( $in ) => sprintf( __( 'Looking at image #%d…', 'wp-ai-editor' ), (int) ( $in['attachment_id'] ?? 0 ) ),
			'run'          => 'wpaie_tool_get_image',
		],
		[
			'name'         => 'update_media_alt',
			'description'  => 'Set the alt text of one or more images (up to 50 per call). Alt texts describe the image for screen readers and search engines; keep them short and specific, in the language of the site. Takes effect immediately.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'items' ],
				'properties' => [
					'items' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'required'   => [ 'attachment_id', 'alt' ],
							'properties' => [
								'attachment_id' => [ 'type' => 'integer' ],
								'alt'           => [ 'type' => 'string' ],
							],
						],
					],
				],
			],
			'cap'          => 'upload_files',
			'write'        => true,
			'live'         => true,
			'label'        => fn( $in ) => sprintf(
				/* translators: %d: number of images */
				_n( 'Setting alt text of %d image…', 'Setting alt texts of %d images…', count( (array) ( $in['items'] ?? [] ) ), 'wp-ai-editor' ),
				count( (array) ( $in['items'] ?? [] ) )
			),
			'preview'      => 'wpaie_preview_update_media_alt',
			'run'          => 'wpaie_tool_update_media_alt',
		],
		[
			'name'         => 'set_featured_image',
			'description'  => 'Set (or remove with attachment_id=0) the featured image of a post or page.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id', 'attachment_id' ],
				'properties' => [
					'post_id'       => [ 'type' => 'integer' ],
					'attachment_id' => [ 'type' => 'integer', 'description' => 'Image ID, or 0 to remove the featured image.' ],
				],
			],
			'cap'          => 'edit_posts',
			'write'        => true,
			'live'         => fn( $in ) => get_post_status( (int) ( $in['post_id'] ?? 0 ) ) === 'publish',
			/* translators: %d: post ID */
			'label'        => fn( $in ) => sprintf( __( 'Setting the featured image of #%d…', 'wp-ai-editor' ), (int) ( $in['post_id'] ?? 0 ) ),
			'preview'      => 'wpaie_preview_set_featured_image',
			'run'          => 'wpaie_tool_set_featured_image',
		],
	];
}

function wpaie_tool_list_media( array $in ): string {
	$args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image',
		'posts_per_page' => max( 1, min( (int) ( $in['limit'] ?? 30 ), 100 ) ),
		'offset'         => max( 0, (int) ( $in['offset'] ?? 0 ) ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	];
	if ( ! empty( $in['search'] ) && is_string( $in['search'] ) ) {
		$args['s'] = $in['search'];
	}
	if ( ! empty( $in['missing_alt'] ) ) {
		$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			[
				'key'     => '_wp_attachment_image_alt',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'   => '_wp_attachment_image_alt',
				'value' => '',
			],
		];
	}

	$images = get_posts( $args );
	if ( ! $images ) {
		return 'No images found.';
	}
	return implode(
		"\n",
		array_map(
			function ( WP_Post $img ) {
				$meta = wp_get_attachment_metadata( $img->ID );
				$alt  = (string) get_post_meta( $img->ID, '_wp_attachment_image_alt', true );
				return sprintf(
					'ID:%d | %s | file:%s | alt:%s | %sx%s | attached to:%s',
					$img->ID,
					$img->post_title,
					wp_basename( (string) get_attached_file( $img->ID ) ),
					$alt !== '' ? $alt : '(none)',
					$meta['width'] ?? '?',
					$meta['height'] ?? '?',
					$img->post_parent ? '#' . $img->post_parent : '-'
				);
			},
			$images
		)
	);
}

function wpaie_tool_get_image( array $in ) {
	$id = (int) ( $in['attachment_id'] ?? 0 );
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return wpaie_tool_error( "The current user is not allowed to use attachment #$id." );
	}
	$file_id = wpaie_attachment_file_id( $id, WPAIE_Run_Context::$files, WPAIE_Run_Context::$api_key );
	if ( is_wp_error( $file_id ) ) {
		return wpaie_tool_error( $file_id->get_error_message() );
	}
	$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	return [
		[
			'type' => 'text',
			'text' => sprintf( 'Attachment #%d "%s", current alt text: %s', $id, get_the_title( $id ), $alt !== '' ? $alt : '(none)' ),
		],
		[
			'type'   => 'image',
			'source' => [
				'type'    => 'file',
				'file_id' => $file_id,
			],
		],
	];
}

function wpaie_preview_update_media_alt( array $in ): string {
	$rows = [];
	foreach ( array_slice( (array) ( $in['items'] ?? [] ), 0, WPAIE_MAX_ALT_ITEMS ) as $item ) {
		$id                                       = (int) ( $item['attachment_id'] ?? 0 );
		$rows[ '#' . $id . ' ' . get_the_title( $id ) ] = [ (string) get_post_meta( $id, '_wp_attachment_image_alt', true ), (string) ( $item['alt'] ?? '' ) ];
	}
	return wpaie_preview_table( $rows );
}

function wpaie_tool_update_media_alt( array $in ) {
	$items = (array) ( $in['items'] ?? [] );
	if ( ! $items ) {
		return wpaie_tool_error( 'items is empty.' );
	}
	if ( count( $items ) > WPAIE_MAX_ALT_ITEMS ) {
		return wpaie_tool_error( 'At most ' . WPAIE_MAX_ALT_ITEMS . ' images per call; split the list.' );
	}

	$ops     = [];
	$done    = [];
	$errors  = [];
	foreach ( $items as $item ) {
		$id  = (int) ( $item['attachment_id'] ?? 0 );
		$alt = sanitize_text_field( (string) ( $item['alt'] ?? '' ) );
		if ( ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			$errors[] = "#$id (not an image or not editable)";
			continue;
		}
		$previous = get_post_meta( $id, '_wp_attachment_image_alt', true );
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		$ops[]  = [
			'type'    => 'post_meta',
			'post_id' => $id,
			'values'  => [ '_wp_attachment_image_alt' => $previous === '' ? null : $previous ],
		];
		$done[] = "#$id";
	}

	if ( $ops ) {
		wpaie_record_change(
			'update_media_alt',
			sprintf(
				/* translators: %d: number of images */
				_n( 'Alt text of %d image changed', 'Alt texts of %d images changed', count( $ops ), 'wp-ai-editor' ),
				count( $ops )
			),
			$ops
		);
	}
	return ( $done ? 'Alt text set for ' . implode( ', ', $done ) . '.' : 'No alt text was set.' )
		. ( $errors ? ' Skipped: ' . implode( ', ', $errors ) . '.' : '' );
}

function wpaie_preview_set_featured_image( array $in ): string {
	$post_id = (int) ( $in['post_id'] ?? 0 );
	$new     = (int) ( $in['attachment_id'] ?? 0 );
	$old     = (int) get_post_thumbnail_id( $post_id );
	$html    = wpaie_preview_table( [ get_the_title( $post_id ) => [ $old ? "#$old" : '—', $new ? "#$new" : __( '(removed)', 'wp-ai-editor' ) ] ] );
	$url     = $new ? wp_get_attachment_image_url( $new, 'medium' ) : '';
	return $url ? $html . '<p><img class="wpaie-preview-image" src="' . esc_url( $url ) . '" alt="" /></p>' : $html;
}

function wpaie_tool_set_featured_image( array $in ) {
	$post_id       = (int) ( $in['post_id'] ?? 0 );
	$attachment_id = (int) ( $in['attachment_id'] ?? 0 );
	$post          = wpaie_get_editable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( $attachment_id && ! wp_attachment_is_image( $attachment_id ) ) {
		return wpaie_tool_error( "Attachment #$attachment_id does not exist or is not an image." );
	}

	$previous = (int) get_post_thumbnail_id( $post_id );
	$ok       = $attachment_id ? set_post_thumbnail( $post_id, $attachment_id ) : delete_post_thumbnail( $post_id );
	if ( ! $ok && $previous !== $attachment_id ) {
		return wpaie_tool_error( 'The featured image could not be changed (does the theme support featured images?).' );
	}

	wpaie_record_change(
		'set_featured_image',
		/* translators: %s: post title */
		sprintf( __( 'Featured image of "%s" changed', 'wp-ai-editor' ), $post->post_title ),
		[
			[
				'type'    => 'post_meta',
				'post_id' => $post_id,
				'values'  => [ '_thumbnail_id' => $previous ? (string) $previous : null ],
			],
		]
	);
	return $attachment_id
		? "Featured image of #$post_id set to attachment #$attachment_id."
		: "Featured image of #$post_id removed.";
}
