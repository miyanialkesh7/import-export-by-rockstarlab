<?php
/**
 * Assemble unordered cells on disk; memory is bounded by one output row.
 *
 * @package RockStarLab\ImportExport\Model\Format
 */

namespace RockStarLab\ImportExport\Model\Format;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Streamed, chunked file handling for large imports, exports and media transfers: PHP stream handles, locks, private 0700 directories and atomic renames that WP_Filesystem does not provide (it also needs credentials, which cron and background requests lack).

defined( 'ABSPATH' ) || exit;

/**
 * Assembles unordered spreadsheet cells into rows on disk, so memory stays bounded by one row.
 */
class Spreadsheet_Row_Store {
	/**
	 * Directory holding one file per row.
	 *
	 * @var string
	 */
	private $directory;
	/**
	 * Number of columns per row.
	 *
	 * @var int
	 */
	private $width      = 1;
	/**
	 * Whether the directory was supplied by the caller and must be kept.
	 *
	 * @var bool
	 */
	private $persistent = false;

	/**
	 * Create the store.
	 *
	 * @param mixed $directory Existing directory to keep the rows in, or null for a temporary one.
	 * @param int   $width     Number of columns per row.
	 * @throws \RuntimeException When the spreadsheet data is invalid or cannot be read.
	 */
	public function __construct( $directory = null, $width = 1 ) {
		$this->persistent = null !== $directory;
		$this->width      = $width;
		$this->directory  = $directory ?? rtrim( get_temp_dir(), '/\\' ) . '/rsl-sheet-rows-' . bin2hex( random_bytes( 16 ) );
		if ( is_link( $this->directory ) || ( ! is_dir( $this->directory ) && ! mkdir( $this->directory, 0700 ) ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot create spreadsheet row store.', 'import-export-by-rockstarlab' ) ); }
	}

	/**
	 * Add one cell to the store.
	 *
	 * @param array $cell Cell with its row, column and string value.
	 * @return void
	 * @throws \RuntimeException When the cell is invalid or cannot be written.
	 */
	public function append( array $cell ) {
		$row    = $cell['row'];
		$column = $cell['column'];
		$value  = $cell['value'];
		if ( ! is_int( $row ) || ! is_int( $column ) || $row < 1 || $column < 1 || ! is_string( $value ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid spreadsheet cell record.', 'import-export-by-rockstarlab' ) ); }
		$path      = $this->path( $row );
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot create row shard.', 'import-export-by-rockstarlab' ) ); }
		$data   = serialize( array( $column, $value, ! empty( $cell['preserve_existing'] ) ) );
		$record = sprintf( '%020d', strlen( $data ) ) . $data;
		if ( strlen( $record ) !== file_put_contents( $path, $record, FILE_APPEND ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot store spreadsheet cell.', 'import-export-by-rockstarlab' ) ); }
		$this->width = max( $this->width, $column );
	}

	/**
	 * Get the file that holds a row.
	 *
	 * @param mixed $row Row number.
	 * @return string
	 */
	private function path( $row ) {
		// Fixed-width decimal paths sort numerically without collecting all row IDs.
		return $this->directory . '/' . implode( '/', str_split( sprintf( '%021d', $row ), 3 ) );
	}

	/**
	 * List the stored row numbers in ascending order.
	 *
	 * @param mixed  $directory Directory level to read.
	 * @param string $prefix    Row number prefix collected from the levels above.
	 * @param int    $after     Only rows after this row number.
	 * @return \Generator Row numbers.
	 */
	private function stored_rows( $directory, $prefix = '', $after = 0 ) {
		// Each level has at most 1,000 entries, even for widely separated coordinates.
		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue; }
			$path   = $directory . '/' . $entry;
			$digits = $prefix . $entry;
			if ( strcmp( str_pad( $digits, 21, '9' ), sprintf( '%021d', $after ) ) <= 0 ) {
				continue; }
			if ( is_dir( $path ) ) {
				yield from $this->stored_rows( $path, $digits, $after ); } else {
				yield (int) $digits; }
		}
	}

	/**
	 * Read the values of one row.
	 *
	 * @param mixed $row Row number.
	 * @return array Cell values, empty strings for missing cells.
	 * @throws \RuntimeException When the row file cannot be read.
	 */
	public function row( $row ) {
		$values = array_fill( 0, $this->width, '' );
		$path   = $this->path( $row );
		if ( ! is_file( $path ) ) {
			return $values; }
		$input = fopen( $path, 'rb' );
		if ( ! $input ) {
			throw new \RuntimeException( esc_html__( 'Cannot read spreadsheet row.', 'import-export-by-rockstarlab' ) ); }
		try {
			while ( ! feof( $input ) ) {
				$header = fread( $input, 20 );
				if ( '' === $header && feof( $input ) ) {
					break; }
				if ( false === $header || strlen( $header ) !== 20 || ! ctype_digit( $header ) ) {
					throw new \RuntimeException( __( 'Invalid spreadsheet row index.', 'import-export-by-rockstarlab' ) ); }
				$remaining = (int) $header;
				$data      = '';
				while ( $remaining > 0 ) {
					$chunk = fread( $input, min( 65536, $remaining ) );
					if ( false === $chunk || '' === $chunk ) {
						throw new \RuntimeException( __( 'Incomplete spreadsheet row.', 'import-export-by-rockstarlab' ) ); }
					$data      .= $chunk;
					$remaining -= strlen( $chunk );
				}
				$cell = unserialize( $data, array( 'allowed_classes' => false ) );
				if ( ! is_array( $cell ) || count( $cell ) !== 3 || ! is_int( $cell[0] ) || $cell[0] < 1 || $cell[0] > $this->width || ! is_string( $cell[1] ) ) {
					throw new \RuntimeException( __( 'Invalid stored spreadsheet cell.', 'import-export-by-rockstarlab' ) ); }
				if ( ! $cell[2] ) {
					$values[ $cell[0] - 1 ] = $cell[1]; }
			}
		} finally {
			fclose( $input ); }
		return $values;
	}

	/**
	 * Number of the header row: the first row with a value (blank rows above it are skipped).
	 *
	 * @var int|null
	 */
	private $header_row = null;

	/**
	 * Find the number of the header row: the first row with a value.
	 *
	 * @return int
	 */
	private function header_row() {
		if ( null === $this->header_row ) {
			$this->header_row = 1;
			foreach ( $this->stored_rows( $this->directory, '', 0 ) as $row ) {
				foreach ( $this->row( $row ) as $value ) {
					if ( '' !== trim( (string) $value ) ) {
						$this->header_row = $row;
						break 2; }
				}
			}
		}
		return $this->header_row;
	}

	/**
	 * Get the column names.
	 *
	 * @param bool $has_header Whether the first non-empty row holds the column names.
	 * @return array Values of the header row, or "Column N" names without a header.
	 */
	public function headers( $has_header = true ) {
		return $has_header ? $this->row( $this->header_row() ) : array_map(
			static function ( $column ) {
				return 'Column ' . $column;
			},
			range( 1, $this->width )
		);
	}

	/**
	 * Yield the data rows as arrays keyed by column name.
	 *
	 * @param bool $has_header    Whether to treat the first non-empty row as the header.
	 * @param int  $after         Only rows after this row number.
	 * @param bool $include_empty Whether to include rows without any value.
	 * @return \Generator
	 */
	public function rows( $has_header = true, $after = 0, $include_empty = false ) {
		$headers = $this->headers( $has_header );
		foreach ( $this->stored_rows( $this->directory, '', $after ) as $row ) {
			if ( $has_header && $row <= $this->header_row() ) {
				continue; }
			$values = $this->row( $row );
			$empty  = true;
			foreach ( $values as $value ) {
				if ( '' !== trim( $value ) ) {
					$empty = false;
					break; }
			}
			if ( ! $empty ) {
				yield $row => array_combine( $headers, $values );
			} elseif ( $include_empty ) {
				yield $row => null; }
		}
	}

	/**
	 * Derive preview and count from the same staged worksheet, retaining five rows.
	 *
	 * @param bool $has_header Whether the first non-empty row holds the column names.
	 */
	public function preview( $has_header = true ) {
		$result = array(
			'headers'    => $this->headers( $has_header ),
			'preview'    => array(),
			'total_rows' => 0,
		);
		foreach ( $this->rows( $has_header ) as $row ) {
			if ( $result['total_rows'] < 5 ) {
				$result['preview'][] = $row;
			}
			++$result['total_rows'];
		}
		return $result;
	}

	/**
	 * Get the number of columns.
	 *
	 * @return int
	 */
	public function width() {
		return $this->width; }

	/**
	 * Remove the temporary files, unless the store uses a directory it was given.
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->persistent ) {
			return; }
		if ( $this->directory && is_dir( $this->directory ) ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getPathname() ); } else {
					wp_delete_file( $file->getPathname() ); }
			}
			rmdir( $this->directory );
		}
	}
	/**
	 * Clean up when the store is released.
	 */
	public function __destruct() {
		$this->close(); }
}
