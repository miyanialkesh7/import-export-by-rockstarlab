<?php
/**
 * Disk-backed shared-string lookup with constant-size index entries.
 *
 * @package RockStarLab\ImportExport\Model\Format
 */

namespace RockStarLab\ImportExport\Model\Format;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streamed, chunked file handling for large imports, exports and media transfers: PHP stream handles, locks, private 0700 directories and atomic renames that WP_Filesystem does not provide (it also needs credentials, which cron and background requests lack).

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the shared strings of a spreadsheet on disk and reads them by ID.
 */
class Spreadsheet_String_Store {
	/**
	 * Handle of the file with the fixed-width index entries.
	 *
	 * @var resource|null
	 */
	private $index;
	/**
	 * Handle of the file with the string values.
	 *
	 * @var resource|null
	 */
	private $values;
	/**
	 * Number of stored strings.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Create the store.
	 *
	 * @param mixed $path  Base path for the index and values files, or null for temporary files.
	 * @param int   $count Number of strings already stored at the path.
	 * @throws \RuntimeException When the storage cannot be opened.
	 */
	public function __construct( $path = null, $count = 0 ) {
		$this->index  = null === $path ? tmpfile() : fopen( $path . '.index', 'c+b' );
		$this->values = null === $path ? tmpfile() : fopen( $path . '.values', 'c+b' );
		if ( ! $this->index || ! $this->values ) {
			$this->close();
			throw new \RuntimeException( esc_html__( 'Cannot create spreadsheet string index.', 'import-export-by-rockstarlab' ) ); }
		if ( null !== $path ) {
			$length = 0;
			if ( $count ) {
				if ( 0 !== fseek( $this->index, ( $count - 1 ) * 40 ) ) {
					throw new \RuntimeException( esc_html__( 'Invalid string checkpoint.', 'import-export-by-rockstarlab' ) ); }
				$last = fread( $this->index, 40 );
				if ( 40 !== strlen( $last ) || ! ctype_digit( $last ) ) {
					throw new \RuntimeException( esc_html__( 'Invalid string checkpoint.', 'import-export-by-rockstarlab' ) ); }
				$length = (int) substr( $last, 0, 20 ) + (int) substr( $last, 20 );
			}
			if ( fstat( $this->values )['size'] < $length || ! ftruncate( $this->index, $count * 40 ) || ! ftruncate( $this->values, $length ) ) {
				throw new \RuntimeException( esc_html__( 'Invalid string checkpoint.', 'import-export-by-rockstarlab' ) ); }
			$this->count = $count;
		}
	}

	/**
	 * Load one shared string at a time; formatting and phonetic hints are not values.
	 *
	 * @param Spreadsheet_XML_Parts $parts      Opened parts of the spreadsheet archive.
	 * @param mixed                 $name       Name of the shared-strings part.
	 * @param self|null             $store      Store to fill, or null to create one.
	 * @param array|null            $checkpoint Checkpoint to resume from; updated while reading.
	 * @throws \RuntimeException When the part is not valid shared-strings XML.
	 * @throws \Throwable Any other error while reading; the store is closed first and the error is rethrown.
	 */
	public static function from_xml( Spreadsheet_XML_Parts $parts, $name, $store = null, &$checkpoint = null ) {
		$resume = func_num_args() > 3;
		$store  = $store ?? new self();
		try {
			$root      = $checkpoint['root'] ?? false;
			$namespace = $checkpoint['namespace'] ?? null;
			foreach ( ( $resume ? $parts->nodes( $name, $checkpoint['nodes'] ) : $parts->nodes( $name ) ) as $node ) {
				if ( \XMLReader::ELEMENT !== $node->nodeType ) {
					continue; }
				if ( 0 === $node->depth ) {
					if ( 'sst' !== $node->localName || ! in_array( $node->namespaceURI, array( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'http://purl.oclc.org/ooxml/spreadsheetml/main' ), true ) ) {
						throw new \RuntimeException( __( 'Invalid shared-string root.', 'import-export-by-rockstarlab' ) ); }
					$root      = true;
					$namespace = $node->namespaceURI;
				}
				if ( 1 !== $node->depth || 'si' !== $node->localName ) {
					continue; }
				if ( $node->namespaceURI !== $namespace ) {
					throw new \RuntimeException( __( 'Invalid shared-string namespace.', 'import-export-by-rockstarlab' ) ); }
				$xml = simplexml_load_string( $node->readOuterXml(), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
				if ( false === $xml ) {
					throw new \RuntimeException( __( 'Invalid shared string.', 'import-export-by-rockstarlab' ) ); }
				$item  = $xml->children( $namespace );
				$value = '';
				if ( isset( $item->t ) ) {
					$value = \PhpOffice\PhpSpreadsheet\Shared\StringHelper::controlCharacterOOXML2PHP( (string) $item->t );
				} else {
					foreach ( $item->r as $run ) {
						$value .= \PhpOffice\PhpSpreadsheet\Shared\StringHelper::controlCharacterOOXML2PHP( (string) $run->t );
					}
				}
				$store->append( $value );
			}
			if ( $resume ) {
				$checkpoint['root']      = $root;
				$checkpoint['namespace'] = $namespace; }
			if ( ! $root && ( ! $resume || ! empty( $checkpoint['nodes']['done'] ) ) ) {
				throw new \RuntimeException( __( 'Missing shared-string root.', 'import-export-by-rockstarlab' ) ); }
			return $store;
		} catch ( \Throwable $error ) {
			$store->close();
			throw $error; }
	}

	/**
	 * Get the number of stored strings.
	 *
	 * @return int
	 */
	public function count() {
		return $this->count; }

	/**
	 * Add a string and get its ID.
	 *
	 * @param mixed $value String to store.
	 * @return int ID of the string.
	 * @throws \RuntimeException When the value is not a string or cannot be written.
	 */
	public function append( $value ) {
		if ( ! is_string( $value ) || 0 !== fseek( $this->values, 0, SEEK_END ) || 0 !== fseek( $this->index, 0, SEEK_END ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid spreadsheet string.', 'import-export-by-rockstarlab' ) ); }
		$offset = ftell( $this->values );
		$length = strlen( $value );
		$entry  = sprintf( '%020d%020d', $offset, $length );
		if ( 40 !== strlen( $entry ) || $length !== fwrite( $this->values, $value ) || 40 !== fwrite( $this->index, $entry ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot store spreadsheet string.', 'import-export-by-rockstarlab' ) ); }
		return $this->count++;
	}

	/**
	 * Read a stored string.
	 *
	 * @param mixed $id ID returned by append().
	 * @return string
	 * @throws \RuntimeException When the ID is invalid or the string cannot be read.
	 */
	public function get( $id ) {
		if ( ! is_int( $id ) || $id < 0 || $id >= $this->count || 0 !== fseek( $this->index, $id * 40 ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid shared-string reference.', 'import-export-by-rockstarlab' ) ); }
		$entry = fread( $this->index, 40 );
		if ( 40 !== strlen( $entry ) || ! ctype_digit( $entry ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid spreadsheet string index.', 'import-export-by-rockstarlab' ) ); }
		$offset    = (int) substr( $entry, 0, 20 );
		$remaining = (int) substr( $entry, 20 );
		if ( 0 !== fseek( $this->values, $offset ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot seek spreadsheet string.', 'import-export-by-rockstarlab' ) ); }
		$value = '';
		while ( $remaining ) {
			$chunk = fread( $this->values, min( 65536, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new \RuntimeException( esc_html__( 'Incomplete spreadsheet string.', 'import-export-by-rockstarlab' ) ); }
			$value     .= $chunk;
			$remaining -= strlen( $chunk );
		}
		return $value;
	}

	/**
	 * Close the storage.
	 *
	 * @return void
	 */
	public function close() {
		if ( is_resource( $this->index ) ) {
			fclose( $this->index ); }
		if ( is_resource( $this->values ) ) {
			fclose( $this->values ); }
		$this->index  = null;
		$this->values = null;
		$this->count  = 0;
	}
	/**
	 * Close the storage when the store is released.
	 */
	public function __destruct() {
		$this->close(); }
}
