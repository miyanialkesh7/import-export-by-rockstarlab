<?php
/**
 * Disk-backed shared formula masters, scoped to one worksheet scan.
 *
 * @package RockStarLab\ImportExport\Model\Format
 */

namespace RockStarLab\ImportExport\Model\Format;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Streamed, chunked file handling for large imports, exports and media transfers: PHP stream handles, locks, private 0700 directories and atomic renames that WP_Filesystem does not provide (it also needs credentials, which cron and background requests lack).

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\ReferenceHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps shared XLSX formulas on disk so dependent cells can be resolved.
 */
class XLSX_Formula_Store {
	/**
	 * Directory that holds the shared formulas.
	 *
	 * @var string
	 */
	private $directory;
	/**
	 * Whether the directory was supplied by the caller and must be kept.
	 *
	 * @var bool
	 */
	private $persistent = false;

	/**
	 * Create the store.
	 *
	 * @param mixed $directory Existing directory to keep the formulas in, or null for a temporary one.
	 * @throws \RuntimeException When the spreadsheet data is invalid or cannot be read.
	 */
	public function __construct( $directory = null ) {
		$this->persistent = null !== $directory;
		$this->directory  = $directory ?? rtrim( get_temp_dir(), '/\\' ) . '/rsl-formula-' . bin2hex( random_bytes( 16 ) );
		if ( is_link( $this->directory ) || ( ! is_dir( $this->directory ) && ! mkdir( $this->directory, 0700 ) ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot create shared-formula store.', 'import-export-by-rockstarlab' ) ); }
	}

	/**
	 * Match Xlsx's first-encounter master semantics, including empty masters.
	 *
	 * @param mixed $id         Shared formula ID.
	 * @param mixed $coordinate Cell coordinate, for example B7.
	 * @param mixed $formula    Formula text of the cell; empty for dependents.
	 * @return string Formula for the cell.
	 * @throws \RuntimeException When an argument is not a string.
	 */
	public function resolve( $id, $coordinate, $formula ) {
		if ( ! is_string( $id ) || ! is_string( $coordinate ) || ! is_string( $formula ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid shared formula.', 'import-export-by-rockstarlab' ) ); }
		$hash    = hash( 'sha256', $id );
		$shard   = $this->directory . '/' . substr( $hash, 0, 2 );
		$path    = $shard . '/' . $hash;
		$current = Coordinate::indexesFromString( $coordinate );
		if ( ! is_file( $path ) ) {
			if ( ! is_dir( $shard ) && ! mkdir( $shard, 0700 ) ) {
				throw new \RuntimeException( esc_html__( 'Cannot create formula shard.', 'import-export-by-rockstarlab' ) ); }
			$data = serialize( array( $id, $coordinate, $formula ) );
			if ( strlen( $data ) !== file_put_contents( $path, $data ) ) {
				throw new \RuntimeException( esc_html__( 'Cannot store shared formula.', 'import-export-by-rockstarlab' ) ); }
			return $formula;
		}
		$data   = file_get_contents( $path );
		$master = false === $data ? false : unserialize( $data, array( 'allowed_classes' => false ) );
		if ( ! is_array( $master ) || count( $master ) !== 3 || $master[0] !== $id || ! is_string( $master[1] ) || ! is_string( $master[2] ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid shared-formula record.', 'import-export-by-rockstarlab' ) ); }
		$origin = Coordinate::indexesFromString( $master[1] );
		return ReferenceHelper::getInstance()->updateFormulaReferences( $master[2], 'A1', $current[0] - $origin[0], $current[1] - $origin[1] );
	}

	/**
	 * Remove the temporary files, unless the store uses a directory it was given.
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->persistent ) {
			return; }
		if ( $this->directory && is_dir( $this->directory ) ) {
			foreach ( new \DirectoryIterator( $this->directory ) as $shard ) {
				if ( $shard->isDot() ) {
					continue; }
				foreach ( new \DirectoryIterator( $shard->getPathname() ) as $file ) {
					if ( ! $file->isDot() ) {
						wp_delete_file( $file->getPathname() ); }
				}
				rmdir( $shard->getPathname() );
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
