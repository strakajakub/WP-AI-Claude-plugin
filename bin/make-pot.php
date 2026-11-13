<?php
/**
 * Extract translatable strings from the plugin into languages/wp-ai-editor.pot.
 *
 *   php bin/make-pot.php
 *
 * A small, dependency-free replacement for `wp i18n make-pot` (which needs WP-CLI). It understands
 * __(), _e(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e() and _n() with literal strings
 * and the text domain "wp-ai-editor", plus the "translators:" comments before them.
 */

$root   = dirname( __DIR__ ) . '/wp-ai-editor';
$domain = 'wp-ai-editor';
$funcs  = [
	'__'         => 'single',
	'_e'         => 'single',
	'esc_html__' => 'single',
	'esc_html_e' => 'single',
	'esc_attr__' => 'single',
	'esc_attr_e' => 'single',
	'_n'         => 'plural',
];

/** @var array<string,array{msgid:string,plural:?string,refs:string[],comments:string[]}> */
$entries = [];

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$list  = [];
foreach ( $files as $file ) {
	if ( $file->getExtension() === 'php' ) {
		$list[] = $file->getPathname();
	}
}
sort( $list );

foreach ( $list as $path ) {
	$rel     = 'wp-ai-editor/' . str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
	$tokens  = token_get_all( (string) file_get_contents( $path ) );
	$comment = null;
	$count   = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$tok = $tokens[ $i ];
		if ( is_array( $tok ) && $tok[0] === T_COMMENT && stripos( $tok[1], 'translators:' ) !== false ) {
			$comment = trim( preg_replace( '#^/\*+|\*+/$|^//#', '', $tok[1] ) );
			continue;
		}
		if ( ! is_array( $tok ) || $tok[0] !== T_STRING || ! isset( $funcs[ $tok[1] ] ) ) {
			continue;
		}

		// Collect the literal arguments of the call.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
			$j++;
		}
		if ( ( $tokens[ $j ] ?? null ) !== '(' ) {
			continue;
		}
		// Split the top-level arguments; keep those that are a single string literal.
		$args  = [];
		$cur   = [];
		$depth = 0;
		for ( $j++; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( $t === '(' || $t === '[' || $t === '{' ) {
				$depth++;
			} elseif ( $t === ')' || $t === ']' || $t === '}' ) {
				if ( $depth === 0 ) {
					$args[] = $cur;
					break;
				}
				$depth--;
			} elseif ( $t === ',' && $depth === 0 ) {
				$args[] = $cur;
				$cur    = [];
				continue;
			}
			if ( is_array( $t ) && in_array( $t[0], [ T_WHITESPACE, T_COMMENT ], true ) ) {
				continue;
			}
			$cur[] = $t;
		}
		$args = array_map(
			fn( $a ) => count( $a ) === 1 && is_array( $a[0] ) && $a[0][0] === T_CONSTANT_ENCAPSED_STRING ? eval( 'return ' . $a[0][1] . ';' ) : null,
			$args
		);

		$plural = $funcs[ $tok[1] ] === 'plural';
		$msgid  = $args[0] ?? null;
		$domain_arg = $plural ? ( $args[3] ?? null ) : ( $args[1] ?? null );
		if ( $msgid === null || $domain_arg !== $domain ) {
			$comment = null;
			continue;
		}
		$key = $msgid . "\0" . ( $plural ? (string) $args[1] : '' );
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = [
				'msgid'    => $msgid,
				'plural'   => $plural ? (string) $args[1] : null,
				'refs'     => [],
				'comments' => [],
			];
		}
		$entries[ $key ]['refs'][] = $rel . ':' . $tok[2];
		if ( $comment !== null && ! in_array( $comment, $entries[ $key ]['comments'], true ) ) {
			$entries[ $key ]['comments'][] = $comment;
		}
		$comment = null;
	}
}

function pot_escape( string $s ): string {
	return '"' . str_replace( [ '\\', '"', "\n", "\t" ], [ '\\\\', '\\"', '\\n', '\\t' ], $s ) . '"';
}

$out  = "# Copyright (C) 2026 Jakub Straka\n# This file is distributed under the GPL-2.0-or-later.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: WP AI Editor\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"X-Domain: wp-ai-editor\\n\"\n";
foreach ( $entries as $e ) {
	$out .= "\n";
	foreach ( $e['comments'] as $c ) {
		$out .= '#. ' . $c . "\n";
	}
	$out .= '#: ' . implode( ' ', array_unique( $e['refs'] ) ) . "\n";
	if ( strpos( $e['msgid'], '%' ) !== false ) {
		$out .= "#, php-format\n";
	}
	$out .= 'msgid ' . pot_escape( $e['msgid'] ) . "\n";
	if ( $e['plural'] !== null ) {
		$out .= 'msgid_plural ' . pot_escape( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
	} else {
		$out .= "msgstr \"\"\n";
	}
}

@mkdir( $root . '/languages' );
file_put_contents( $root . '/languages/wp-ai-editor.pot', $out );
echo count( $entries ) . " strings written to wp-ai-editor/languages/wp-ai-editor.pot\n";
