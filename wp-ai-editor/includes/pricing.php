<?php
/**
 * Usage and cost: estimated price of every API response, monthly totals and the monthly budget.
 *
 * Prices are list prices in USD per million tokens and only an estimate – the Claude Console
 * billing is authoritative. Cache writes (5-minute cache) cost 1.25× the input price.
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAIE_USAGE_OPTION', 'wpaie_usage' );

/** Months of usage history kept. */
define( 'WPAIE_USAGE_KEEP_MONTHS', 24 );

/**
 * @return array<string,array> model => [ input, output, cache_read, (long_threshold, long_input, long_output) ]
 */
function wpaie_model_prices(): array {
	return (array) apply_filters(
		'wpaie_model_prices',
		[
			'claude-fable-5-2'  => [
				'input'      => 8.0,
				'output'     => 40.0,
				'cache_read' => 0.20,
			],
			'claude-opus-5-5'   => [
				'input'      => 4.0,
				'output'     => 20.0,
				'cache_read' => 0.20,
			],
			'claude-sonnet-5-5' => [
				'input'      => 2.0,
				'output'     => 10.0,
				'cache_read' => 0.10,
			],
			'claude-haiku-5-5'  => [
				'input'          => 0.10,
				'output'         => 0.50,
				'cache_read'     => 0.01,
				// Prompts over 100K tokens are billed at a higher rate.
				'long_threshold' => 100000,
				'long_input'     => 0.50,
				'long_output'    => 2.50,
			],
		]
	);
}

/**
 * Estimated cost of one usage object (input/output/cache tokens) in USD.
 */
function wpaie_usage_cost( string $model, $usage ): float {
	$prices = wpaie_model_prices()[ $model ] ?? null;
	if ( ! $prices ) {
		return 0.0;
	}
	$in    = (int) wpaie_prop( $usage, 'input_tokens', 0 );
	$out   = (int) wpaie_prop( $usage, 'output_tokens', 0 );
	$write = (int) wpaie_prop( $usage, 'cache_creation_input_tokens', 0 );
	$read  = (int) wpaie_prop( $usage, 'cache_read_input_tokens', 0 );

	$price_in   = (float) $prices['input'];
	$price_out  = (float) $prices['output'];
	$price_read = (float) $prices['cache_read'];
	if ( ! empty( $prices['long_threshold'] ) && $in + $write + $read > $prices['long_threshold'] ) {
		$factor     = (float) $prices['long_input'] / $price_in;
		$price_in   = (float) $prices['long_input'];
		$price_out  = (float) $prices['long_output'];
		$price_read = $price_read * $factor;
	}

	return ( $in * $price_in + $write * $price_in * 1.25 + $read * $price_read + $out * $price_out ) / 1000000;
}

/**
 * Tokens and estimated cost of an API response. With server-side fallbacks the per-attempt
 * usage.iterations is the source of truth.
 *
 * @return array{input:int,output:int,cost:float}
 */
function wpaie_response_usage( $response, string $requested_model ): array {
	$usage      = $response->usage ?? null;
	$model      = (string) ( $response->model ?? $requested_model );
	$iterations = is_array( wpaie_prop( $usage, 'iterations' ) ) ? wpaie_prop( $usage, 'iterations' ) : [];
	$entries    = $iterations ?: ( $usage ? [ $usage ] : [] );

	$total = [
		'input'  => 0,
		'output' => 0,
		'cost'   => 0.0,
	];
	foreach ( $entries as $entry ) {
		$entry_model      = (string) wpaie_prop( $entry, 'model', $model );
		$total['input']  += (int) wpaie_prop( $entry, 'input_tokens', 0 ) + (int) wpaie_prop( $entry, 'cache_creation_input_tokens', 0 ) + (int) wpaie_prop( $entry, 'cache_read_input_tokens', 0 );
		$total['output'] += (int) wpaie_prop( $entry, 'output_tokens', 0 );
		$total['cost']   += wpaie_usage_cost( $entry_model, $entry );
	}
	return $total;
}

/**
 * Record a response's usage in the conversation and the monthly totals. Returns its cost.
 */
function wpaie_track_response_usage( array &$state, $response, string $model ): float {
	$usage = wpaie_response_usage( $response, $model );

	$state['usage']['cost']   += $usage['cost'];
	$state['usage']['input']  += $usage['input'];
	$state['usage']['output'] += $usage['output'];

	wpaie_usage_increment(
		gmdate( 'Y-m' ),
		[
			'cost'     => (int) round( $usage['cost'] * 1000000 ),
			'input'    => $usage['input'],
			'output'   => $usage['output'],
			'requests' => 1,
		]
	);

	return $usage['cost'];
}

/**
 * Atomically add to a month's counters.
 *
 * Every counter is its own row in wp_options (wpaie_usage_<Y-m>_<field>, cost in micro-USD) and is
 * incremented in SQL, so concurrent requests of several users never overwrite each other – a
 * read-modify-write of one shared option would lose costs and let the budget be exceeded.
 */
function wpaie_usage_increment( string $month, array $values ): void {
	global $wpdb;
	wpaie_migrate_usage_option();
	foreach ( $values as $field => $amount ) {
		$name = WPAIE_USAGE_OPTION . '_' . $month . '_' . $field;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')
				 ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS SIGNED) + %d",
				$name,
				(int) $amount,
				(int) $amount
			)
		);
		wp_cache_delete( $name, 'options' );
	}
	wp_cache_delete( 'notoptions', 'options' );

	if ( wp_rand( 1, 50 ) === 1 ) {
		wpaie_prune_usage();
	}
}

/**
 * Raw counters [ month => [ field => int ] ], read from the database (not the request cache).
 */
function wpaie_usage_rows(): array {
	global $wpdb;
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( WPAIE_USAGE_OPTION . '_' ) . '%'
		)
	);
	$out = [];
	foreach ( (array) $rows as $row ) {
		if ( preg_match( '/^wpaie_usage_(\d{4}-\d{2})_(cost|input|output|requests)$/', (string) $row->option_name, $m ) ) {
			$out[ $m[1] ][ $m[2] ] = (int) $row->option_value;
		}
	}
	return $out;
}

function wpaie_prune_usage(): void {
	$keep = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -' . ( WPAIE_USAGE_KEEP_MONTHS - 1 ) . ' months' ) );
	foreach ( array_keys( wpaie_usage_rows() ) as $month ) {
		if ( $month < $keep ) {
			foreach ( [ 'cost', 'input', 'output', 'requests' ] as $field ) {
				delete_option( WPAIE_USAGE_OPTION . '_' . $month . '_' . $field );
			}
		}
	}
}

/**
 * Plugin 3.0.0 kept all months in one wpaie_usage option; move it to the counters once.
 */
function wpaie_migrate_usage_option(): void {
	$old = get_option( WPAIE_USAGE_OPTION, null );
	if ( ! is_array( $old ) ) {
		return;
	}
	// Deleted first, so the increments below (which call this again) do not migrate twice.
	delete_option( WPAIE_USAGE_OPTION );
	foreach ( $old as $month => $row ) {
		if ( preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) {
			wpaie_usage_increment(
				(string) $month,
				[
					'cost'     => (int) round( (float) ( $row['cost'] ?? 0 ) * 1000000 ),
					'input'    => (int) ( $row['input'] ?? 0 ),
					'output'   => (int) ( $row['output'] ?? 0 ),
					'requests' => (int) ( $row['requests'] ?? 0 ),
				]
			);
		}
	}
}

/**
 * @return array<string,array{cost:float,input:int,output:int,requests:int}> month (Y-m) => totals, newest first
 */
function wpaie_usage_by_month(): array {
	wpaie_migrate_usage_option();
	$out = [];
	foreach ( wpaie_usage_rows() as $month => $row ) {
		$out[ $month ] = [
			'cost'     => ( $row['cost'] ?? 0 ) / 1000000,
			'input'    => $row['input'] ?? 0,
			'output'   => $row['output'] ?? 0,
			'requests' => $row['requests'] ?? 0,
		];
	}
	krsort( $out );
	return $out;
}

function wpaie_month_cost(): float {
	return (float) ( wpaie_usage_by_month()[ gmdate( 'Y-m' ) ]['cost'] ?? 0 );
}

/**
 * Error when the monthly budget is used up (checked before every model call), null otherwise.
 */
function wpaie_budget_error(): ?WP_Error {
	$budget = (float) ( wpaie_get_settings()['monthly_budget'] ?? 0 );
	if ( $budget <= 0 || wpaie_month_cost() < $budget ) {
		return null;
	}
	return new WP_Error(
		'wpaie_budget',
		sprintf(
			/* translators: 1: spent amount in USD, 2: monthly budget in USD */
			__( 'The monthly budget is used up ($%1$s of $%2$s). An administrator can raise it in AI Editor → Settings; it resets at the start of the month (UTC).', 'wp-ai-editor' ),
			number_format( wpaie_month_cost(), 2 ),
			number_format( $budget, 2 )
		)
	);
}

function wpaie_format_usd( float $amount ): string {
	return '$' . number_format( $amount, $amount < 1 ? 4 : 2 );
}

/**
 * Usage panel on the settings page.
 */
function wpaie_render_usage_panel( array $settings ): void {
	$months = array_slice( wpaie_usage_by_month(), 0, 6, true );
	$budget = (float) $settings['monthly_budget'];
	$spent  = wpaie_month_cost();
	?>
	<h2><?php esc_html_e( 'Usage', 'wp-ai-editor' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %s: amount in USD */
			esc_html__( 'Estimated spending this month: %s', 'wp-ai-editor' ),
			'<strong>' . esc_html( wpaie_format_usd( $spent ) ) . '</strong>'
		);
		if ( $budget > 0 ) {
			echo ' ';
			printf(
				/* translators: 1: budget in USD, 2: percentage used */
				esc_html__( 'of the %1$s budget (%2$s %%).', 'wp-ai-editor' ),
				esc_html( wpaie_format_usd( $budget ) ),
				esc_html( (string) min( 100, (int) round( $spent / $budget * 100 ) ) )
			);
		}
		?>
	</p>
	<?php if ( $budget > 0 ) : ?>
		<progress max="100" value="<?php echo esc_attr( (string) min( 100, (int) round( $spent / $budget * 100 ) ) ); ?>" style="width: 320px;"></progress>
	<?php endif; ?>
	<?php if ( $months ) : ?>
		<table class="widefat striped" style="max-width: 640px; margin-top: 12px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Month', 'wp-ai-editor' ); ?></th>
					<th><?php esc_html_e( 'Requests', 'wp-ai-editor' ); ?></th>
					<th><?php esc_html_e( 'Input tokens', 'wp-ai-editor' ); ?></th>
					<th><?php esc_html_e( 'Output tokens', 'wp-ai-editor' ); ?></th>
					<th><?php esc_html_e( 'Estimated cost', 'wp-ai-editor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $months as $month => $row ) : ?>
					<tr>
						<td><?php echo esc_html( $month ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['requests'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['input'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['output'] ) ); ?></td>
						<td><?php echo esc_html( wpaie_format_usd( (float) $row['cost'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="description"><?php esc_html_e( 'Estimate based on list prices; your bill in the Claude Console is authoritative.', 'wp-ai-editor' ); ?></p>
	<?php
}
add_action( 'wpaie_settings_after_form', 'wpaie_render_usage_panel', 5 );
