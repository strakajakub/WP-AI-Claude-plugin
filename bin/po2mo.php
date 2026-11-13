<?php
/**
 * Compile .po translations to .mo (dependency-free replacement for msgfmt).
 *
 *   php bin/po2mo.php                     # every wp-ai-editor/languages/*.po
 *   php bin/po2mo.php path/to/file.po
 */

require __DIR__ . '/po-lib.php';

$files = array_slice( $argv, 1 ) ?: glob( dirname( __DIR__ ) . '/wp-ai-editor/languages/*.po' );
foreach ( $files as $po ) {
	$entries = po_parse( $po );
	$mo      = preg_replace( '/\.po$/', '.mo', $po );
	mo_write( $entries, $mo );
	echo basename( $mo ) . ': ' . ( count( $entries ) - ( isset( $entries[''] ) ? 1 : 0 ) ) . " translations\n";
}
