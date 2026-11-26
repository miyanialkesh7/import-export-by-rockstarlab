<?php
/**
 * Complete XLSX staging before exposing normalized import rows.
 *
 * @package RockStarLab\ImportExport\Model\Format
 */

namespace RockStarLab\ImportExport\Model\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the rows of an XLSX worksheet.
 */
class XLSX_Row_Stream {
	/**
	 * Validate every worksheet's cells without retaining their values.
	 *
	 * @param mixed $path           Path of the XLSX file.
	 * @param bool  $validate_theme Whether to also validate the theme parts.
	 * @return true|\WP_Error
	 */
	public static function validate_cells( $path, $validate_theme = false ) {
		$parts   = new Spreadsheet_XML_Parts( $path );
		$strings = null;
		try {
			if ( $validate_theme ) {
				XLSX_Theme_Validator::validate( $parts ); }
			$map   = XLSX_Part_Map::resolve( $parts );
			$count = $map['sheet_count'];
			if ( null !== $map['strings'] ) {
				$strings = Spreadsheet_String_Store::from_xml( $parts, $map['strings'] );
				$parts->release( $map['strings'] ); }
			for ( $index = 0; $index < $count; ++$index ) {
				if ( $index ) {
					$map = XLSX_Part_Map::resolve( $parts, $index ); }
				try {
					foreach ( XLSX_Cell_Stream::cells( $parts, $map, $strings ) as $cell ) {
						/* Decode to validate, retaining no row collection. */ }
				} finally {
					$parts->release( $map['worksheet'] ); }
			}
			return true;
		} finally {
			if ( $strings ) {
				$strings->close();
			} $parts->close(); }
	}

	/**
	 * Read the header row of the worksheet.
	 *
	 * @param mixed $path    Path of the XLSX file.
	 * @param array $options Reader options.
	 * @return array Header names.
	 */
	public static function headers( $path, array $options = array() ) {
		foreach ( self::scan( $path, $options, true ) as $headers ) {
			return $headers; }
	}

	/**
	 * Return complete worksheet metadata and a bounded sample from one staging pass.
	 *
	 * @param mixed $path    Path of the XLSX file.
	 * @param array $options Reader options.
	 */
	public static function preview( $path, array $options = array() ) {
		foreach ( self::scan( $path, $options, false, true ) as $summary ) {
			return $summary;
		}
	}

	/**
	 * Stream the rows of the worksheet as associative arrays.
	 *
	 * @param mixed      $path        Path of the XLSX file.
	 * @param array      $options     Reader options.
	 * @param array|null $raw_headers Receives the header row as it appears in the file.
	 * @return \Generator
	 */
	public static function rows( $path, array $options = array(), &$raw_headers = null ) {
		yield from self::scan( $path, $options, false, false, $raw_headers ); }

	/**
	 * Stage the worksheet cells on disk and yield headers, a preview or rows from them.
	 *
	 * @param mixed      $path         Path of the XLSX file.
	 * @param array      $options      Reader options.
	 * @param mixed      $headers_only Whether to stop after the header row.
	 * @param bool       $preview      Whether to yield a bounded sample with metadata.
	 * @param array|null $raw_headers  Receives the header row as it appears in the file.
	 * @return \Generator
	 */
	private static function scan( $path, array $options, $headers_only, $preview = false, &$raw_headers = null ) {
		$parts = null;
		$store = null;
		try {
			$parts = new Spreadsheet_XML_Parts( $path );
			$map   = XLSX_Part_Map::resolve( $parts, XLSX_Part_Map::data_sheet_index( $parts ) );
			$store = new Spreadsheet_Row_Store();
			foreach ( XLSX_Cell_Stream::cells( $parts, $map ) as $cell ) {
				$store->append( $cell ); }
			$parts->close();
			$parts       = null;
			$raw_headers = $store->headers( (bool) ( $options['has_header'] ?? true ) );
			if ( $preview ) {
				yield $store->preview( (bool) ( $options['has_header'] ?? true ) );
			} elseif ( $headers_only ) {
				yield $store->headers( (bool) ( $options['has_header'] ?? true ) ); } else {
				yield from $store->rows( array_key_exists( 'has_header', $options ) ? (bool) $options['has_header'] : true ); }
		} finally {
			if ( $store ) {
				$store->close(); }
			if ( $parts ) {
				$parts->close(); }
		}
	}
}
