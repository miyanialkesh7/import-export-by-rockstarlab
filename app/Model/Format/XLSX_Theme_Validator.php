<?php
/**
 * Validate required theme structure without retaining palettes or font lists.
 *
 * @package RockStarLab\ImportExport\Model\Format
 */

namespace RockStarLab\ImportExport\Model\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the theme parts of an XLSX workbook.
 */
class XLSX_Theme_Validator {
	/**
	 * Check that every theme part of the workbook is well-formed XML.
	 *
	 * @param Spreadsheet_XML_Parts $parts Opened parts of the XLSX archive.
	 * @return null|void Nothing; stops at the first invalid theme part.
	 * @throws \RuntimeException When a theme part is invalid.
	 */
	public static function validate( Spreadsheet_XML_Parts $parts ) {
		foreach ( XLSX_Part_Map::themes( $parts ) as $path ) {
			try {
				$namespace    = null;
				$has_elements = false;
				foreach ( $parts->nodes( $path ) as $node ) {
					if ( \XMLReader::ELEMENT !== $node->nodeType ) {
						continue; }
					if ( 0 === $node->depth ) {
						$namespace = $node->namespaceURI;
						if ( 'theme' !== $node->localName || ! in_array( $namespace, array( 'http://schemas.openxmlformats.org/drawingml/2006/main', 'http://purl.oclc.org/ooxml/drawingml/main' ), true ) ) {
							throw new \RuntimeException( __( 'Invalid spreadsheet theme.', 'import-export-by-rockstarlab' ) ); }
					}
					if ( 1 === $node->depth && 'themeElements' === $node->localName && $namespace === $node->namespaceURI ) {
						$has_elements = true; }
				}
				if ( ! $has_elements ) {
					throw new \RuntimeException( __( 'Incomplete spreadsheet theme.', 'import-export-by-rockstarlab' ) ); }
			} finally {
				$parts->release( $path ); }
		}
		return true;
	}
}
