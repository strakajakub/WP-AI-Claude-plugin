<?php
/**
 * Shared helpers for the translation tools: .po parsing and .mo writing.
 */

/**
 * @return array<string,string> key (msgid, or msgid\0plural) => translation (plural forms joined by \0)
 */
function po_parse( string $file ): array {
	$entries = [];
	$entry   = [];
	$last    = null;

	$flush = function () use ( &$entry, &$entries ) {
		// Fuzzy entries are unreviewed guesses (e.g. after "Update from POT"); like msgfmt, leave
		// them out so users see the original text instead of a possibly wrong translation.
		$fuzzy = ! empty( $entry['fuzzy'] ) && ( $entry['msgid'] ?? '' ) !== '';
		if ( isset( $entry['msgid'] ) && ! $fuzzy ) {
			$key = $entry['msgid'] . ( isset( $entry['msgid_plural'] ) ? "\0" . $entry['msgid_plural'] : '' );
			if ( isset( $entry['msgstr'] ) ) {
				$forms = [ $entry['msgstr'] ];
			} else {
				$forms = $entry['plural'] ?? [];
				ksort( $forms );
			}
			// Skip untranslated entries, including plurals with a missing form.
			if ( $forms && ! in_array( '', $forms, true ) ) {
				$entries[ $key ] = implode( "\0", $forms );
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
			if ( str_starts_with( $line, '#,' ) && str_contains( $line, 'fuzzy' ) ) {
				if ( isset( $entry['msgid'] ) ) {
					$flush();
				}
				$entry['fuzzy'] = true;
			}
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
