<?php
/**
 * Update .po files from the .pot (dependency-free replacement for msgmerge).
 *
 *   php bin/update-po.php                 # every wp-ai-editor/languages/*.po
 *   php bin/update-po.php path/to/file.po
 *
 * Every entry of the .pot is written in .pot order with the existing translation for the same
 * msgid (and plural); new strings get an empty translation, strings no longer in the code are
 * dropped. Fuzzy translations are not kept – translate them again.
 */

require __DIR__ . '/po-lib.php';

$dir   = dirname( __DIR__ ) . '/wp-ai-editor/languages';
$pot   = (string) file_get_contents( $dir . '/wp-ai-editor.pot' );
$files = array_slice( $argv, 1 ) ?: glob( $dir . '/*.po' );

function po_quote( string $s ): string {
	return '"' . str_replace( [ '\\', '"', "\n", "\t" ], [ '\\\\', '\\"', '\\n', '\\t' ], $s ) . '"';
}

foreach ( $files as $file ) {
	$old     = po_parse( $file );
	$header  = substr( (string) file_get_contents( $file ), 0, (int) strpos( (string) file_get_contents( $file ), "\n\n" ) );
	$nplural = preg_match( '/nplurals=(\d+)/', $header, $m ) ? (int) $m[1] : 2;
	$blocks  = explode( "\n\n", trim( $pot ) );
	$out     = [ $header ];
	$missing = 0;

	foreach ( array_slice( $blocks, 1 ) as $block ) {
		$lines  = explode( "\n", $block );
		$keep   = [];
		$msgid  = null;
		$plural = null;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^msgid (".*")$/', $line, $m ) ) {
				$msgid = stripcslashes( substr( $m[1], 1, -1 ) );
			} elseif ( preg_match( '/^msgid_plural (".*")$/', $line, $m ) ) {
				$plural = stripcslashes( substr( $m[1], 1, -1 ) );
			}
			if ( ! str_starts_with( $line, 'msgstr' ) ) {
				$keep[] = $line;
			}
		}
		$key         = $msgid . ( $plural !== null ? "\0" . $plural : '' );
		$translation = $old[ $key ] ?? null;
		if ( $translation === null ) {
			$missing++;
		}
		if ( $plural === null ) {
			$keep[] = 'msgstr ' . po_quote( (string) $translation );
		} else {
			$forms = $translation !== null ? explode( "\0", $translation ) : [];
			for ( $i = 0; $i < $nplural; $i++ ) {
				$keep[] = "msgstr[$i] " . po_quote( $forms[ $i ] ?? '' );
			}
		}
		$out[] = implode( "\n", $keep );
	}

	file_put_contents( $file, implode( "\n\n", $out ) . "\n" );
	echo basename( $file ) . ': ' . ( count( $blocks ) - 1 ) . " entries, $missing untranslated\n";
}
