<?php
/**
 * Stage the selected ODS worksheet into the shared disk row store.
 *
 * @package RockStarLab\ImportExport\Model\Format
 */

namespace RockStarLab\ImportExport\Model\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the rows of an ODS worksheet.
 */
class ODS_Row_Stream {
	/**
	 * Check that an ODS file is a readable spreadsheet archive.
	 *
	 * @param mixed $path Path of the ODS file.
	 * @return true|\WP_Error
	 * @throws \RuntimeException When the spreadsheet data is invalid or cannot be read.
	 */
	public static function validate( $path ) {
		$parts = new Spreadsheet_XML_Parts( $path );
		try {
			foreach ( array( 'meta.xml', 'styles.xml' ) as $name ) {
				try {
					foreach ( $parts->nodes( $name ) as $node ) {
					}
				} catch ( \RuntimeException $error ) {
					if ( 'styles.xml' !== $name || __( 'Invalid spreadsheet XML.', 'import-export-by-rockstarlab' ) !== $error->getMessage() ) {
						throw $error; }
				} finally {
					$parts->release( $name ); }
			}
			ODS_Sheet_Selector::select( $parts );
			foreach ( ODS_Cell_Stream::cells( $parts, null ) as $cell ) {
			}
			return true;
		} finally {
			$parts->close(); }
	}

	/**
	 * Read the header row of the worksheet.
	 *
	 * @param mixed $path    Path of the ODS file.
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
	 * @param mixed $path    Path of the ODS file.
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
	 * @param mixed      $path        Path of the ODS file.
	 * @param array      $options     Reader options.
	 * @param array|null $raw_headers Receives the header row as it appears in the file.
	 * @return \Generator
	 */
	public static function rows( $path, array $options = array(), &$raw_headers = null ) {
		yield from self::scan( $path, $options, false, false, $raw_headers ); }

	/**
	 * Stage the worksheet cells on disk and yield headers, a preview or rows from them.
	 *
	 * @param mixed      $path         Path of the ODS file.
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
			$parts    = new Spreadsheet_XML_Parts( $path );
			$selected = ODS_Sheet_Selector::select( $parts );
			$store    = new Spreadsheet_Row_Store();
			foreach ( ODS_Cell_Stream::cells( $parts, $selected ) as $cell ) {
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
