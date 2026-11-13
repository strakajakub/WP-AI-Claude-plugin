<?php
/**
 * WooCommerce tools (offered only when WooCommerce is active).
 */

defined( 'ABSPATH' ) || exit;

/** Product properties the assistant may change (WC_Product set_/get_ methods). */
const WPAIE_WC_PROPS = [ 'name', 'description', 'short_description', 'regular_price', 'sale_price', 'status', 'stock_quantity', 'stock_status', 'manage_stock' ];

function wpaie_woocommerce_tools(): array {
	$available = fn() => class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	return [
		[
			'name'         => 'list_products',
			'description'  => 'List WooCommerce products (ID, name, type, status, price, regular/sale price, stock, SKU).',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'search' => [ 'type' => 'string', 'description' => 'Text in the name or description (optional).' ],
					'limit'  => [ 'type' => 'integer', 'description' => 'Maximum number of results (1–100), default 30.' ],
				],
			],
			'cap'          => 'edit_products',
			'available'    => $available,
			'label'        => fn() => __( 'Loading products…', 'wp-ai-editor' ),
			'run'          => 'wpaie_tool_list_products',
		],
		[
			'name'         => 'get_product',
			'description'  => 'Return a WooCommerce product: prices, stock, descriptions, categories and, for variable products, the variations with their IDs, attributes, prices and stock.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'product_id' ],
				'properties' => [
					'product_id' => [ 'type' => 'integer' ],
				],
			],
			'cap'          => 'edit_products',
			'available'    => $available,
			/* translators: %d: product ID */
			'label'        => fn( $in ) => sprintf( __( 'Reading product #%d…', 'wp-ai-editor' ), (int) ( $in['product_id'] ?? 0 ) ),
			'run'          => 'wpaie_tool_get_product',
		],
		[
			'name'         => 'update_product',
			'description'  => 'Change a WooCommerce product or variation (use the variation ID for variation prices/stock). Prices are strings in the shop currency, e.g. "499" or "19.90"; sale_price "" removes the sale. Setting stock_quantity turns on stock management. Takes effect in the shop immediately.',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'product_id' ],
				'properties' => [
					'product_id'        => [ 'type' => 'integer' ],
					'name'              => [ 'type' => 'string' ],
					'description'       => [ 'type' => 'string', 'description' => 'Long description (HTML).' ],
					'short_description' => [ 'type' => 'string', 'description' => 'Short description (HTML).' ],
					'regular_price'     => [ 'type' => 'string' ],
					'sale_price'        => [ 'type' => 'string' ],
					'status'            => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ] ],
					'stock_quantity'    => [ 'type' => 'integer' ],
					'stock_status'      => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
				],
			],
			'cap'          => 'edit_products',
			'available'    => $available,
			'write'        => true,
			'live'         => true,
			/* translators: %d: product ID */
			'label'        => fn( $in ) => sprintf( __( 'Updating product #%d…', 'wp-ai-editor' ), (int) ( $in['product_id'] ?? 0 ) ),
			'preview'      => 'wpaie_preview_update_product',
			'run'          => 'wpaie_tool_update_product',
		],
	];
}

function wpaie_wc_product_line( WC_Product $p ): string {
	return sprintf(
		'ID:%d | %s | type:%s | status:%s | price:%s | regular:%s | sale:%s | stock:%s%s | sku:%s',
		$p->get_id(),
		$p->get_name(),
		$p->get_type(),
		$p->get_status(),
		$p->get_price(),
		$p->get_regular_price(),
		$p->get_sale_price() !== '' ? $p->get_sale_price() : '-',
		$p->get_stock_status(),
		$p->get_manage_stock() ? ' (' . (int) $p->get_stock_quantity() . ')' : '',
		$p->get_sku() !== '' ? $p->get_sku() : '-'
	);
}

function wpaie_tool_list_products( array $in ): string {
	$args = [
		'post_type'      => 'product',
		'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
		'posts_per_page' => max( 1, min( (int) ( $in['limit'] ?? 30 ), 100 ) ),
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'fields'         => 'ids',
	];
	if ( ! empty( $in['search'] ) && is_string( $in['search'] ) ) {
		$args['s'] = $in['search'];
	}
	$lines = [];
	foreach ( get_posts( $args ) as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			$lines[] = wpaie_wc_product_line( $product );
		}
	}
	return $lines ? 'Currency: ' . get_woocommerce_currency() . "\n" . implode( "\n", $lines ) : 'No products found.';
}

/**
 * @return WC_Product|WP_Error
 */
function wpaie_wc_get_editable_product( int $id ) {
	$product = $id ? wc_get_product( $id ) : null;
	if ( ! $product ) {
		return wpaie_tool_error( "Product #$id does not exist." );
	}
	$check_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $id;
	if ( ! current_user_can( 'edit_product', $check_id ) ) {
		return wpaie_tool_error( "The current user is not allowed to edit product #$id." );
	}
	return $product;
}

function wpaie_tool_get_product( array $in ) {
	$product = wpaie_wc_get_editable_product( (int) ( $in['product_id'] ?? 0 ) );
	if ( is_wp_error( $product ) ) {
		return $product;
	}
	$lines = [
		'Currency: ' . get_woocommerce_currency(),
		wpaie_wc_product_line( $product ),
		'Categories: ' . wp_strip_all_tags( wc_get_product_category_list( $product->get_id() ) ),
		'Short description: ' . $product->get_short_description(),
		'Description: ' . $product->get_description(),
	];
	if ( $product->is_type( 'variable' ) ) {
		$lines[] = 'Variations:';
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation ) {
				$lines[] = '  ' . wpaie_wc_product_line( $variation ) . ' | ' . wc_get_formatted_variation( $variation, true, false );
			}
		}
	}
	return implode( "\n", $lines );
}

/**
 * Current values of the given properties.
 */
function wpaie_wc_get_props( WC_Product $product, array $props ): array {
	$out = [];
	foreach ( $props as $prop ) {
		if ( in_array( $prop, WPAIE_WC_PROPS, true ) && is_callable( [ $product, 'get_' . $prop ] ) ) {
			$out[ $prop ] = $product->{'get_' . $prop}( 'edit' );
		}
	}
	return $out;
}

/**
 * Set product properties and save. Also used to undo changes.
 *
 * @return true|WP_Error
 */
function wpaie_wc_apply_props( int $product_id, array $props ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return new WP_Error( 'wpaie_wc', "Product #$product_id does not exist." );
	}
	try {
		if ( array_key_exists( 'stock_quantity', $props ) && $props['stock_quantity'] !== null && ! array_key_exists( 'manage_stock', $props ) ) {
			$product->set_manage_stock( true );
		}
		foreach ( $props as $prop => $value ) {
			if ( in_array( $prop, WPAIE_WC_PROPS, true ) && is_callable( [ $product, 'set_' . $prop ] ) ) {
				$product->{'set_' . $prop}( $value );
			}
		}
		$product->save();
	} catch ( Throwable $e ) {
		return new WP_Error( 'wpaie_wc', $e->getMessage() );
	}
	return true;
}

function wpaie_requested_product_props( array $in ): array {
	$props = [];
	foreach ( WPAIE_WC_PROPS as $prop ) {
		if ( $prop !== 'manage_stock' && array_key_exists( $prop, $in ) ) {
			$props[ $prop ] = $prop === 'stock_quantity' ? (int) $in[ $prop ] : (string) $in[ $prop ];
		}
	}
	return $props;
}

function wpaie_preview_update_product( array $in ): string {
	$product = wc_get_product( (int) ( $in['product_id'] ?? 0 ) );
	if ( ! $product ) {
		return '';
	}
	$new  = wpaie_requested_product_props( $in );
	$old  = wpaie_wc_get_props( $product, array_keys( $new ) );
	$rows = [];
	foreach ( $new as $prop => $value ) {
		if ( in_array( $prop, [ 'description', 'short_description' ], true ) ) {
			continue;
		}
		$rows[ $prop ] = [ (string) ( $old[ $prop ] ?? '' ), (string) $value ];
	}
	$html = '<p><strong>' . esc_html( $product->get_name() ) . '</strong> (#' . (int) $product->get_id() . ', ' . esc_html( get_woocommerce_currency() ) . ')</p>';
	$html .= $rows ? wpaie_preview_table( $rows ) : '';
	foreach ( [ 'short_description', 'description' ] as $prop ) {
		if ( isset( $new[ $prop ] ) ) {
			$html .= wpaie_preview_diff( (string) ( $old[ $prop ] ?? '' ), (string) $new[ $prop ], $prop );
		}
	}
	return $html;
}

function wpaie_tool_update_product( array $in ) {
	$product = wpaie_wc_get_editable_product( (int) ( $in['product_id'] ?? 0 ) );
	if ( is_wp_error( $product ) ) {
		return $product;
	}
	$props = wpaie_requested_product_props( $in );
	if ( ! $props ) {
		return wpaie_tool_error( 'Nothing to change.' );
	}
	if ( isset( $props['status'] ) && $product->is_type( 'variation' ) ) {
		return wpaie_tool_error( 'Variations have no status of their own; change the parent product.' );
	}
	foreach ( [ 'regular_price', 'sale_price' ] as $price ) {
		if ( isset( $props[ $price ] ) && $props[ $price ] !== '' && ! is_numeric( str_replace( ',', '.', $props[ $price ] ) ) ) {
			return wpaie_tool_error( "$price must be a number like \"499\" or \"19.90\"." );
		}
		if ( isset( $props[ $price ] ) ) {
			$props[ $price ] = wc_format_decimal( str_replace( ',', '.', $props[ $price ] ) );
		}
	}

	$undo_keys = array_keys( $props );
	if ( isset( $props['stock_quantity'] ) ) {
		$undo_keys[] = 'manage_stock';
	}
	$previous = wpaie_wc_get_props( $product, $undo_keys );

	$result = wpaie_wc_apply_props( $product->get_id(), $props );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	wpaie_record_change(
		'update_product',
		/* translators: %s: product name */
		sprintf( __( 'Product "%s" changed', 'wp-ai-editor' ), $product->get_name() ),
		[
			[
				'type'       => 'wc_product',
				'product_id' => $product->get_id(),
				'props'      => $previous,
			],
		]
	);
	$fresh = wc_get_product( $product->get_id() );
	return 'Product updated: ' . ( $fresh ? wpaie_wc_product_line( $fresh ) : '#' . $product->get_id() );
}
