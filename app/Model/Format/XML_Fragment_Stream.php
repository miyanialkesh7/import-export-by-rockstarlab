<?php
/**
 * Seekable XML record boundaries; libxml remains the syntax and namespace validator.
 *
 * @package RockStarLab\ImportExport\Model\Format
 */

namespace RockStarLab\ImportExport\Model\Format;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streamed, chunked file handling for large imports, exports and media transfers: PHP stream handles, locks, private 0700 directories and atomic renames that WP_Filesystem does not provide (it also needs credentials, which cron and background requests lack).

defined( 'ABSPATH' ) || exit;
/**
 * Reads an XML file by byte position and splits it into complete records.
 */
class XML_Fragment_Stream {
	/**
	 * Handle of the XML file.
	 *
	 * @var resource|false
	 */
	private $stream;
	/**
	 * Bytes read but not yet consumed.
	 *
	 * @var string
	 */
	private $buffer   = '';
	/**
	 * Read position inside the buffer.
	 *
	 * @var int
	 */
	private $position = 0;
	/**
	 * File offset of the start of the buffer.
	 *
	 * @var int
	 */
	private $base     = 0;
	/**
	 * Character encoding of the file.
	 *
	 * @var string
	 */
	private $encoding;
	/**
	 * Bytes per character unit.
	 *
	 * @var int
	 */
	private $width;
	/**
	 * Whether multi-byte units are little-endian.
	 *
	 * @var bool
	 */
	private $little;
	/**
	 * Open an XML file at a byte offset.
	 *
	 * @param mixed $path     Path of the XML file.
	 * @param mixed $offset   Byte offset to start reading at.
	 * @param array $encoding Encoding details: name, byte width and byte order.
	 * @throws \RuntimeException When the file cannot be opened or positioned.
	 */
	public function __construct( $path, $offset, $encoding = array(
		'name'   => 'UTF-8',
		'width'  => 1,
		'little' => false,
	) ) {
		$this->encoding = $encoding['name'];
		$this->width    = $encoding['width'];
		$this->little   = $encoding['little'];
		$this->stream   = fopen( $path, 'rb' );
		if ( ! $this->stream || 0 !== fseek( $this->stream, $offset ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot seek XML source.', 'import-export-by-rockstarlab' ) );}
		$this->base = $offset;
	}
	/**
	 * Refill the read buffer when it is used up.
	 *
	 * @return bool False at the end of the file.
	 * @throws \RuntimeException When the spreadsheet data is invalid or cannot be read.
	 */
	private function fill() {
		if ( $this->position < strlen( $this->buffer ) ) {
			return true;}
		$this->base    += $this->position;
		$this->position = 0;
		$this->buffer   = fread( $this->stream, 65536 );
		if ( false === $this->buffer ) {
			throw new \RuntimeException( esc_html__( 'Cannot read XML source.', 'import-export-by-rockstarlab' ) );}
		return '' !== $this->buffer;
	}
	/**
	 * Read one byte.
	 *
	 * @return string|null Byte, or null at the end of the file.
	 */
	private function byte() {
		return $this->fill() ? $this->buffer[ $this->position++ ] : null;}
	/**
	 * Read one encoded character unit (one or more bytes, depending on the encoding).
	 *
	 * @return string|null Raw bytes, or null at the end of the file.
	 * @throws \RuntimeException When the encoding is incomplete.
	 */
	private function unit() {
		$raw = '';for ( $i = 0;$i < $this->width;++$i ) {
			$byte = $this->byte();
			if ( null === $byte ) {
				if ( '' !== $raw ) {
					throw new \RuntimeException( esc_html__( 'Incomplete XML encoding unit.', 'import-export-by-rockstarlab' ) );
				}return null;
			}$raw .= $byte;}
		return $raw;
	}
	/**
	 * Get the single-byte equivalent of a raw unit when it is a plain ASCII character.
	 *
	 * @param mixed $raw Raw bytes of the unit.
	 * @return string Character, or an empty string when the unit is not ASCII.
	 */
	private function symbol( $raw ) {
		if ( 1 === $this->width ) {
			return $raw;}
		$char = $this->little ? $raw[0] : $raw[ $this->width - 1 ];
		return $raw === ( $this->little ? $char . str_repeat( "\0", $this->width - 1 ) : str_repeat( "\0", $this->width - 1 ) . $char ) ? $char : "\x80";
	}
	/**
	 * Convert raw bytes of the file encoding to UTF-8.
	 *
	 * @param mixed $raw Raw bytes.
	 * @return string
	 * @throws \RuntimeException When the bytes are invalid for the encoding.
	 */
	private function decoded( $raw ) {
		if ( 'UTF-8' === $this->encoding ) {
			return $raw;}
		$value = @iconv( $this->encoding, 'UTF-8', $raw );
		if ( false === $value ) {
			throw new \RuntimeException( esc_html__( 'Invalid XML encoding.', 'import-export-by-rockstarlab' ) );}
		return $value;
	}
	/**
	 * Read the next token: a tag or the text before it.
	 *
	 * @return string|null Raw bytes of the token, or null at the end of the file.
	 * @throws \RuntimeException When the spreadsheet data is invalid or cannot be read.
	 */
	private function token() {
		$first = $this->unit();
		if ( null === $first ) {
			return null;}
		$raw = $first;
		if ( '<' !== $this->symbol( $first ) ) {
			while ( null !== ( $unit = $this->unit() ) ) {
				if ( '<' === $this->symbol( $unit ) ) {
							$this->position -= $this->width;
							break;
				}$raw .= $unit;}
			return $this->decoded( $raw );
		}
		$quote      = null;
		$terminator = null;
		$head       = '<';
		$tail       = '<';
		while ( null !== ( $unit = $this->unit() ) ) {
			$raw .= $unit;
			$char = $this->symbol( $unit );
			if ( strlen( $head ) < 9 ) {
				$head .= $char;
			}$tail = substr( $tail . $char, -3 );
			if ( '<!--' === $head ) {
				$terminator = '-->';
			} elseif ( '<![CDATA[' === $head ) {
				$terminator = ']]>';
			} elseif ( '<?' === $head ) {
				$terminator = '?>';}
			if ( $terminator ) {
				if ( substr( $tail, -strlen( $terminator ) ) === $terminator ) {
							return $this->decoded( $raw );
				}continue;}
			if ( null !== $quote ) {
				if ( $char === $quote ) {
							$quote = null;
				}continue;}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;}
			if ( '>' === $char ) {
				return $this->decoded( $raw );}
		}
		throw new \RuntimeException( esc_html__( 'Incomplete XML token.', 'import-export-by-rockstarlab' ) );
	}
	/**
	 * Each checkpoint starts after a complete outermost matching record.
	 *
	 * @param mixed $item      Name of the record element to split on.
	 * @param array $state     Parser state: byte offset, open-element stack and declaration.
	 * @param int   $max_bytes Stop after the first record that ends beyond this many bytes; 0 for no limit.
	 * @throws \RuntimeException When the XML is malformed.
	 */
	public function segments( $item, array $state, $max_bytes = 0 ) {
		$stack       = $state['stack'];
		$roots       = $state['roots'];
		$declaration = $state['declaration'];
		$prefix      = $declaration . implode( '', array_column( $stack, 'open' ) ) . ( ! $stack && $roots ? '<rsl-tail/>' : '' );
		$fragment    = '';
		$record      = null;
		while ( null !== ( $token = $this->token() ) ) {
			if ( preg_match( '/^<\?xml\s/', $token ) ) {
				$declaration = $token; }
			$fragment .= $token;
			if ( 0 === strncmp( $token, '<!DOCTYPE', 9 ) ) {
				throw new \RuntimeException( 'XML document types are unsupported.' );} elseif ( preg_match( '/^<\/([^\s>]+)\s*>$/D', $token, $match ) ) {
				$last = array_pop( $stack );
				if ( ! $last || $last['name'] !== $match[1] ) {
					throw new \RuntimeException( esc_html__( 'Unbalanced XML element.', 'import-export-by-rockstarlab' ) );}
				} elseif ( preg_match( '/^<([^!?\/\s>]+)(?:\s|\/?>)/', $token, $match ) ) {
					if ( ! $stack && ++$roots > 1 ) {
						throw new \RuntimeException( esc_html__( 'Multiple XML roots.', 'import-export-by-rockstarlab' ) );}
					$empty = '/>' === substr( $token, -2 );
					if ( ( is_array( $item ) ? in_array( substr( strrchr( ':' . $match[1], ':' ), 1 ), $item, true ) : $match[1] === $item ) && null === $record ) {
						$record = count( $stack );}
					if ( ! $empty ) {
						$stack[] = array(
							'name' => $match[1],
							'open' => $token,
						);}
				}
				if ( ( null !== $record && count( $stack ) === $record ) || ( null === $record && $max_bytes > 0 && strlen( $fragment ) >= $max_bytes ) ) {
					$suffix = '';
					foreach ( array_reverse( $stack ) as $ancestor ) {
						$suffix .= '</' . $ancestor['name'] . '>';}
					$next = array(
						'offset'      => $this->base + $this->position,
						'stack'       => $stack,
						'roots'       => $roots,
						'declaration' => $declaration,
					);
					yield array(
						'xml'    => $prefix . $fragment . $suffix,
						'prefix' => $prefix,
						'body'   => $fragment,
						'suffix' => $suffix,
						'next'   => $next,
					);
					$prefix   = $declaration . implode( '', array_column( $stack, 'open' ) ) . ( ! $stack && $roots ? '<rsl-tail/>' : '' );
					$fragment = '';
					$record   = null;
				}
		}
		if ( $stack || 0 === $roots ) {
			throw new \RuntimeException( esc_html__( 'Incomplete XML document.', 'import-export-by-rockstarlab' ) );}
		// Validate closing tags, trailing text and the empty-document case as well.
		yield array(
			'xml'    => $prefix . $fragment,
			'prefix' => $prefix,
			'body'   => $fragment,
			'suffix' => '',
			'next'   => array(
				'offset'      => $this->base + $this->position,
				'stack'       => array(),
				'roots'       => $roots,
				'declaration' => $declaration,
			),
			'eof'    => true,
		);
	}
	/**
	 * Close the file when the object is released.
	 */
	public function __destruct() {
		if ( is_resource( $this->stream ) ) {
			fclose( $this->stream );}}
}
