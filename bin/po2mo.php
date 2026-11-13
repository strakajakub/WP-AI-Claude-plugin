<?php
/**
 * Compile .po translations to .mo (dependency-free replacement for msgfmt).
 *
 *   php bin/po2mo.php                     # every wp-ai-editor/languages/*.po
 *   php bin/po2mo.php path/to/file.po
 */

/**
 * @return array<string,string> key (msgid, or msgid\0plural) => translation (plural forms joined by \0)
 */
function po_parse( string $file ): array {
	$entries = [];
	$entry   = [];
	$last    = null;

	$flush = function () use ( &$entry, &$entries ) {
		if ( isset( $entry['msgid'] ) ) {
			$key = $entry['msgid'] . ( isset( $entry['msgid_plural'] ) ? "\0" . $entry['msgid_plural'] : '' );
			if ( isset( $entry['msgstr'] ) ) {
				$value = $entry['msgstr'];
			} else {
				ksort( $entry['plural'] );
				$value = implode( "\0", $entry['plural'] ?? [] );
			}
			if ( $value !== '' && str_replace( "\0", '', $value ) !== '' ) {
				$entries[ $key ] = $value;
			}
		}
		$entry = [];
	};

	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );
		if ( $line === '' ) {
			$flush();
			$last = null;
			continue;
		}
		if ( $line[0] === '#' ) {
			continue;
		}
		if ( preg_match( '/^(msgid|msgid_plural|msgstr)(?:\[(\d+)\])?\s+(".*")$/', $line, $m ) ) {
			if ( $m[1] === 'msgid' && isset( $entry['msgid'] ) ) {
				$flush();
			}
			$value = stripcslashes( substr( $m[3], 1, -1 ) );
			if ( $m[2] !== '' ) {
				$entry['plural'][ (int) $m[2] ] = $value;
				$last                           = [ 'plural', (int) $m[2] ];
			} else {
				$entry[ $m[1] ] = $value;
				$last           = [ $m[1], null ];
			}
		} elseif ( $line[0] === '"' && $last ) {
			$value = stripcslashes( substr( $line, 1, -1 ) );
			if ( $last[0] === 'plural' ) {
				$entry['plural'][ $last[1] ] .= $value;
			} else {
				$entry[ $last[0] ] .= $value;
			}
		}
	}
	$flush();
	return $entries;
}

function mo_write( array $entries, string $file ): void {
	ksort( $entries, SORT_STRING );
	$count  = count( $entries );
	$ids    = '';
	$strs   = '';
	$id_tab = [];
	$st_tab = [];
	foreach ( $entries as $id => $str ) {
		$id_tab[] = [ strlen( (string) $id ), strlen( $ids ) ];
		$ids     .= $id . "\0";
		$st_tab[] = [ strlen( $str ), strlen( $strs ) ];
		$strs    .= $str . "\0";
	}
	$header   = 28;
	$id_start = $header + $count * 16;
	$st_start = $id_start + strlen( $ids );

	$out = pack( 'V7', 0x950412de, 0, $count, $header, $header + $count * 8, 0, 0 );
	foreach ( $id_tab as [ $len, $off ] ) {
		$out .= pack( 'V2', $len, $id_start + $off );
	}
	foreach ( $st_tab as [ $len, $off ] ) {
		$out .= pack( 'V2', $len, $st_start + $off );
	}
	file_put_contents( $file, $out . $ids . $strs );
}

$files = array_slice( $argv, 1 ) ?: glob( dirname( __DIR__ ) . '/wp-ai-editor/languages/*.po' );
foreach ( $files as $po ) {
	$entries = po_parse( $po );
	$mo      = preg_replace( '/\.po$/', '.mo', $po );
	mo_write( $entries, $mo );
	echo basename( $mo ) . ': ' . ( count( $entries ) - ( isset( $entries[''] ) ? 1 : 0 ) ) . " translations\n";
}
