<?php
/**
 * SEO tools for Yoast SEO and Rank Math (offered only when one of them is active).
 */

defined( 'ABSPATH' ) || exit;

/**
 * The active SEO plugin and its meta keys, or null.
 *
 * @return array{name:string,title:string,description:string,keyword:string}|null
 */
function wpaie_seo_plugin(): ?array {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return [
			'name'        => 'Yoast SEO',
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'keyword'     => '_yoast_wpseo_focuskw',
		];
	}
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		return [
			'name'        => 'Rank Math',
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'keyword'     => 'rank_math_focus_keyword',
		];
	}
	return null;
}

function wpaie_seo_tools(): array {
	$available = fn() => wpaie_seo_plugin() !== null;
	return [
		[
			'name'         => 'get_seo',
			'description'  => 'Read the SEO title, meta description and focus keyword of a post or page (Yoast SEO or Rank Math). An empty value means the plugin uses its default template.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer' ],
				],
			],
			'available'    => $available,
			/* translators: %d: post ID */
			'label'        => fn( $in ) => sprintf( __( 'Reading SEO of #%d…', 'wp-ai-editor' ), (int) ( $in['post_id'] ?? 0 ) ),
			'run'          => 'wpaie_tool_get_seo',
		],
		[
			'name'         => 'update_seo',
			'description'  => 'Set the SEO title and/or meta description of a post or page (Yoast SEO or Rank Math). Keep titles under ~60 and descriptions under ~155 characters. An empty string returns to the plugin default. Takes effect immediately.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id'     => [ 'type' => 'integer' ],
					'title'       => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
				],
			],
			'available'    => $available,
			'write'        => true,
			'live'         => fn( $in ) => get_post_status( (int) ( $in['post_id'] ?? 0 ) ) === 'publish',
			/* translators: %d: post ID */
			'label'        => fn( $in ) => sprintf( __( 'Updating SEO of #%d…', 'wp-ai-editor' ), (int) ( $in['post_id'] ?? 0 ) ),
			'preview'      => 'wpaie_preview_update_seo',
			'run'          => 'wpaie_tool_update_seo',
		],
	];
}

function wpaie_tool_get_seo( array $in ) {
	$seo  = wpaie_seo_plugin();
	$post = wpaie_get_editable_post( (int) ( $in['post_id'] ?? 0 ) );
	if ( ! $seo ) {
		return wpaie_tool_error( 'No supported SEO plugin is active.' );
	}
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$value = fn( string $key ) => (string) get_post_meta( $post->ID, $seo[ $key ], true );
	return implode(
		"\n",
		[
			'SEO plugin: ' . $seo['name'],
			"Post: #{$post->ID} {$post->post_title}",
			'SEO title: ' . ( $value( 'title' ) !== '' ? $value( 'title' ) : '(default template)' ),
			'Meta description: ' . ( $value( 'description' ) !== '' ? $value( 'description' ) : '(default)' ),
			'Focus keyword: ' . ( $value( 'keyword' ) !== '' ? $value( 'keyword' ) : '(none)' ),
			'Excerpt: ' . ( $post->post_excerpt !== '' ? $post->post_excerpt : '(none)' ),
		]
	);
}

function wpaie_preview_update_seo( array $in ): string {
	$seo     = wpaie_seo_plugin();
	$post_id = (int) ( $in['post_id'] ?? 0 );
	if ( ! $seo ) {
		return '';
	}
	$rows = [];
	if ( isset( $in['title'] ) ) {
		$rows[ __( 'SEO title', 'wp-ai-editor' ) ] = [ (string) get_post_meta( $post_id, $seo['title'], true ), (string) $in['title'] ];
	}
	if ( isset( $in['description'] ) ) {
		$rows[ __( 'Meta description', 'wp-ai-editor' ) ] = [ (string) get_post_meta( $post_id, $seo['description'], true ), (string) $in['description'] ];
	}
	return '<p><strong>' . esc_html( get_the_title( $post_id ) ) . '</strong> (' . esc_html( $seo['name'] ) . ')</p>' . wpaie_preview_table( $rows );
}

function wpaie_tool_update_seo( array $in ) {
	$seo  = wpaie_seo_plugin();
	$post = wpaie_get_editable_post( (int) ( $in['post_id'] ?? 0 ) );
	if ( ! $seo ) {
		return wpaie_tool_error( 'No supported SEO plugin is active.' );
	}
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( ! isset( $in['title'] ) && ! isset( $in['description'] ) ) {
		return wpaie_tool_error( 'Give title and/or description.' );
	}

	$previous = [];
	foreach ( [ 'title', 'description' ] as $field ) {
		if ( ! isset( $in[ $field ] ) ) {
			continue;
		}
		$key              = $seo[ $field ];
		$old              = get_post_meta( $post->ID, $key, true );
		$previous[ $key ] = $old === '' ? null : $old;
		$value            = sanitize_text_field( (string) $in[ $field ] );
		if ( $value === '' ) {
			delete_post_meta( $post->ID, $key );
		} else {
			update_post_meta( $post->ID, $key, wp_slash( $value ) );
		}
	}

	wpaie_record_change(
		'update_seo',
		/* translators: %s: post title */
		sprintf( __( 'SEO of "%s" changed', 'wp-ai-editor' ), $post->post_title ),
		[
			[
				'type'    => 'post_meta',
				'post_id' => $post->ID,
				'values'  => $previous,
			],
		]
	);
	return sprintf( 'SEO of #%d updated in %s.', $post->ID, $seo['name'] );
}
