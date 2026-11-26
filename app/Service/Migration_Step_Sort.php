<?php
/**
 * Bounded external sorting of validated archive steps.
 *
 * @package RockStarLab\ImportExport\Service
 */

namespace RockStarLab\ImportExport\Service;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streamed, chunked file handling for large imports, exports and media transfers: PHP stream handles, locks, private 0700 directories and atomic renames that WP_Filesystem does not provide (it also needs credentials, which cron and background requests lack).

defined( 'ABSPATH' ) || exit;

/**
 * External merge sort for migration steps, so large migrations stay within memory limits.
 */
class Migration_Step_Sort {
	/**
	 * Steps held in memory until the next run is written.
	 *
	 * @var array
	 */
	private $buffer = array();
	/**
	 * Sorted run files written so far.
	 *
	 * @var array
	 */
	private $runs   = array();
	/**
	 * Number of steps added.
	 *
	 * @var int
	 */
	private $count  = 0;
	/**
	 * Approximate serialized bytes retained by the current run.
	 *
	 * @var int
	 */
	private $buffer_bytes = 0;

	/**
	 * Compare two steps by their order and then their key.
	 *
	 * @param array $a First value.
	 * @param array $b Second value.
	 * @return int Negative, zero or positive, as for usort().
	 */
	public static function compare( array $a, array $b ) {
		$order = $a['scope']['order'] <=> $b['scope']['order'];
		return $order ?: strcmp( $a['scope']['key'], $b['scope']['key'] );
	}

	/**
	 * Add a step to the sort, spilling a sorted run to disk when the buffer is full.
	 *
	 * @param array $step Migration step.
	 * @return void
	 */
	public function append( array $step ) {
		$bytes = strlen( serialize( $step ) );
		if ( $this->buffer && $this->buffer_bytes + $bytes > 1048576 ) {
			$this->flush();
		}
		$this->buffer[]      = $step;
		$this->buffer_bytes += $bytes;
		++$this->count;
		if ( count( $this->buffer ) >= 100 || $this->buffer_bytes >= 1048576 ) {
			$this->flush(); }
	}

	/**
	 * Write one step to a run file.
	 *
	 * @param mixed $stream Open run file handle.
	 * @param array $step   Migration step.
	 * @return void
	 * @throws \RuntimeException When the write fails.
	 */
	private function write( $stream, array $step ) {
		$line = base64_encode( serialize( $step ) ) . "\n";
		if ( strlen( $line ) !== fwrite( $stream, $line ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot write step sort.', 'import-export-by-rockstarlab' ) ); }
	}

	/**
	 * Read the next step from a run file.
	 *
	 * @param mixed $stream Open run file handle.
	 * @return array|null The step, or null at the end of the run.
	 * @throws \RuntimeException When the read fails.
	 */
	private function read( $stream ) {
		$line = fgets( $stream );
		if ( false === $line ) {
			if ( ! feof( $stream ) ) {
				throw new \RuntimeException( esc_html__( 'Cannot read step sort.', 'import-export-by-rockstarlab' ) ); }
			return null;
		}
		$value = unserialize( base64_decode( rtrim( $line, "\n" ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid step sort.', 'import-export-by-rockstarlab' ) ); }
		return $value;
	}

	/**
	 * Open a temporary file for a sorted run.
	 *
	 * @return resource
	 * @throws \RuntimeException When the file cannot be created.
	 */
	private function stream() {
		$stream = tmpfile();
		if ( ! $stream ) {
			throw new \RuntimeException( esc_html__( 'Cannot create step sort.', 'import-export-by-rockstarlab' ) ); }
		return $stream;
	}

	/**
	 * Merge two sorted runs into one.
	 *
	 * @param mixed $left  First sorted run.
	 * @param mixed $right Second sorted run.
	 * @return resource The merged run.
	 * @throws \RuntimeException When a run cannot be rewound, read or written.
	 * @throws \Throwable Any other error; the output run is closed first and the error is rethrown.
	 */
	private function merge( $left, $right ) {
		$output = $this->stream();
		try {
			if ( ! rewind( $left ) || ! rewind( $right ) ) {
				throw new \RuntimeException( __( 'Cannot rewind step sort.', 'import-export-by-rockstarlab' ) ); }
			$a = $this->read( $left );
			$b = $this->read( $right );
			while ( null !== $a || null !== $b ) {
				if ( null === $b || ( null !== $a && self::compare( $a, $b ) <= 0 ) ) {
					$this->write( $output, $a );
					$a = $this->read( $left );
				} else {
					$this->write( $output, $b );
					$b = $this->read( $right ); }
			}
			return $output;
		} catch ( \Throwable $error ) {
			fclose( $output );
			throw $error; }
	}

	/**
	 * Sort the buffered steps and write them to disk as one run.
	 *
	 * @return void
	 * @throws \Throwable Any error while writing; the run is closed first and the error is rethrown.
	 */
	private function flush() {
		if ( ! $this->buffer ) {
			return; }
		usort( $this->buffer, array( self::class, 'compare' ) );
		$run = $this->stream();
		try {
			foreach ( $this->buffer as $step ) {
				$this->write( $run, $step ); }
			$this->buffer       = array();
			$this->buffer_bytes = 0;
			$level              = 0;
			while ( isset( $this->runs[ $level ] ) ) {
				$merged = $this->merge( $this->runs[ $level ], $run );
				fclose( $this->runs[ $level ] );
				unset( $this->runs[ $level ] );
				fclose( $run );
				$run = $merged;
				++$level;
			}
			$this->runs[ $level ] = $run;
		} catch ( \Throwable $error ) {
			fclose( $run );
			throw $error; }
	}

	/**
	 * Select one dependency-ordered step without retaining the full result.
	 *
	 * @param mixed $index Position.
	 * @throws \InvalidArgumentException When the position is outside the sorted steps.
	 * @throws \RuntimeException When the sorted runs cannot be copied or are missing.
	 */
	public function select( $index ) {
		if ( ! is_int( $index ) || $index < 0 || $index >= $this->count ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid step ordinal.', 'import-export-by-rockstarlab' ) );
		}
		$this->flush();
		$run = null;
		try {
			foreach ( $this->runs as $part ) {
				if ( null === $run ) {
					$run = $this->stream();
					if ( ! rewind( $part ) || false === stream_copy_to_stream( $part, $run ) ) {
						throw new \RuntimeException( __( 'Cannot copy step sort.', 'import-export-by-rockstarlab' ) ); }
				} else {
					$merged = $this->merge( $run, $part );
					fclose( $run );
					$run = $merged; }
			}
			if ( null === $run || ! rewind( $run ) ) {
				throw new \RuntimeException( __( 'Missing step sort.', 'import-export-by-rockstarlab' ) ); }
			for ( $i = 0; $i <= $index; ++$i ) {
				$step = $this->read( $run );
				if ( null === $step ) {
					throw new \RuntimeException( __( 'Truncated step sort.', 'import-export-by-rockstarlab' ) ); }
			}
			return array(
				'total_steps' => $this->count,
				'steps'       => array( $index => $step ),
			);
		} finally {
			if ( is_resource( $run ) ) {
				fclose( $run ); }
		}
	}

	/**
	 * Get the number of steps added.
	 *
	 * @return int
	 */
	public function count() {
		return $this->count; }
	/**
	 * Close and discard every run.
	 *
	 * @return void
	 */
	public function close() {
		foreach ( $this->runs as $run ) {
			fclose( $run );
		} $this->runs       = array();
		$this->buffer       = array();
		$this->buffer_bytes = 0; }
	/**
	 * Close the runs when the sort is released.
	 */
	public function __destruct() {
		$this->close(); }
}
