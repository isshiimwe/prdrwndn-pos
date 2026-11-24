<?php
/**
 * A very small PDF writer for receipts: Helvetica text, lines, boxes and
 * one JPEG logo. No outside libraries, so it works on any host.
 *
 * Coordinates are in points from the top-left corner of the page.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_PDF {

	/** Character widths (1/1000 em) for codes 32 to 255 in WinAnsi, from the standard Helvetica metrics. */
	const WIDTHS = array(
		'H' => array(278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500),
		'HB' => array(278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556),
	);

	private $w;
	private $h;
	private $pages = array();
	private $page  = -1;
	private $jpeg  = null;

	public function __construct( $width, $height ) {
		$this->w = (float) $width;
		$this->h = (float) $height;
	}

	public function width() {
		return $this->w;
	}

	public function height() {
		return $this->h;
	}

	public function add_page() {
		$this->pages[] = '';
		$this->page    = count( $this->pages ) - 1;
	}

	private function out( $s ) {
		if ( $this->page < 0 ) {
			$this->add_page();
		}
		$this->pages[ $this->page ] .= $s . "\n";
	}

	private static function n( $v ) {
		return rtrim( rtrim( number_format( (float) $v, 3, '.', '' ), '0' ), '.' );
	}

	/** UTF-8 to the Windows-1252 bytes the standard fonts use. Unknown characters become "?". */
	public static function encode( $s ) {
		$s = (string) $s;
		$s = strtr( $s, array( "\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ' ) );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$out = '';
			foreach ( preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
				$b    = @mb_convert_encoding( $ch, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$out .= ( false === $b || '' === $b || ( '?' === $b && '?' !== $ch ) ) ? '?' : $b;
			}
			return $out;
		}
		return preg_replace( '/[^\x20-\x7E]/', '?', $s );
	}

	/** Width of text in points. */
	public function text_width( $s, $size, $bold = false ) {
		$tab = self::WIDTHS[ $bold ? 'HB' : 'H' ];
		$sum = 0;
		$raw = self::encode( $s );
		$len = strlen( $raw );
		for ( $i = 0; $i < $len; $i++ ) {
			$c    = ord( $raw[ $i ] );
			$sum += ( $c >= 32 ) ? $tab[ $c - 32 ] : 0;
		}
		return $sum * $size / 1000;
	}

	/** Split text into lines no wider than $max points. */
	public function wrap( $s, $size, $max, $bold = false ) {
		$lines = array();
		foreach ( preg_split( "/\r\n|\n|\r/", (string) $s ) as $para ) {
			$line = '';
			foreach ( preg_split( '/\s+/', trim( $para ) ) as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( '' === $line || $this->text_width( $try, $size, $bold ) <= $max ) {
					$line = $try;
				} else {
					$lines[] = $line;
					$line    = $word;
				}
				while ( $this->text_width( $line, $size, $bold ) > $max && mb_strlen( $line ) > 1 ) {
					$cut = mb_strlen( $line ) - 1;
					while ( $cut > 1 && $this->text_width( mb_substr( $line, 0, $cut ), $size, $bold ) > $max ) {
						--$cut;
					}
					$lines[] = mb_substr( $line, 0, $cut );
					$line    = mb_substr( $line, $cut );
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Text with its baseline at $y.
	 *
	 * @param string $align left, right (x is the right edge) or center (x is the middle).
	 * @param float  $gray  0 is black, 1 is white.
	 */
	public function text( $x, $y, $s, $size, $bold = false, $align = 'left', $gray = 0 ) {
		$w = $this->text_width( $s, $size, $bold );
		if ( 'right' === $align ) {
			$x -= $w;
		} elseif ( 'center' === $align ) {
			$x -= $w / 2;
		}
		$raw = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), self::encode( $s ) );
		$this->out( sprintf( 'BT %s g /%s %s Tf %s %s Td (%s) Tj ET', self::n( $gray ), $bold ? 'F2' : 'F1', self::n( $size ), self::n( $x ), self::n( $this->h - $y ), $raw ) );
	}

	public function line( $x1, $y1, $x2, $y2, $width = 0.5, $gray = 0, $dash = 0 ) {
		$d = $dash ? sprintf( '[%s %s] 0 d ', self::n( $dash ), self::n( $dash ) ) : '[] 0 d ';
		$this->out( sprintf( 'q %s%s G %s w %s %s m %s %s l S Q', $d, self::n( $gray ), self::n( $width ), self::n( $x1 ), self::n( $this->h - $y1 ), self::n( $x2 ), self::n( $this->h - $y2 ) ) );
	}

	/** Filled box; $y is the top edge. */
	public function box( $x, $y, $w, $h, $gray = 0 ) {
		$this->out( sprintf( '%s g %s %s %s %s re f', self::n( $gray ), self::n( $x ), self::n( $this->h - $y - $h ), self::n( $w ), self::n( $h ) ) );
	}

	/** Many filled squares in one go (QR codes). Each item: x, y (top), size. */
	public function squares( $list, $gray = 0 ) {
		$parts = array();
		foreach ( $list as $sq ) {
			$parts[] = sprintf( '%s %s %s %s re', self::n( $sq[0] ), self::n( $this->h - $sq[1] - $sq[2] ), self::n( $sq[2] ), self::n( $sq[2] ) );
		}
		if ( $parts ) {
			$this->out( self::n( $gray ) . " g\n" . implode( "\n", $parts ) . "\nf" );
		}
	}

	/** A JPEG file drawn at x, y (top) with the given size. One JPEG per document. */
	public function jpeg( $path, $x, $y, $w, $h ) {
		if ( null === $this->jpeg ) {
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$data = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			if ( ! $info || 2 !== $info[2] || false === $data ) {
				return;
			}
			$this->jpeg = array(
				'w'     => (int) $info[0],
				'h'     => (int) $info[1],
				'space' => ( isset( $info['channels'] ) && 1 === (int) $info['channels'] ) ? 'DeviceGray' : 'DeviceRGB',
				'data'  => $data,
			);
		}
		$this->out( sprintf( 'q %s 0 0 %s %s %s cm /Im1 Do Q', self::n( $w ), self::n( $h ), self::n( $x ), self::n( $this->h - $y - $h ) ) );
	}

	/** The finished PDF file. */
	public function output( $title = '' ) {
		if ( ! $this->pages ) {
			$this->add_page();
		}
		$objs   = array();
		$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[] = ''; // Pages, filled in below.
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$xobj   = '';
		if ( $this->jpeg ) {
			$objs[] = array(
				sprintf( '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>', $this->jpeg['w'], $this->jpeg['h'], $this->jpeg['space'], strlen( $this->jpeg['data'] ) ),
				$this->jpeg['data'],
			);
			$xobj = ' /XObject << /Im1 ' . count( $objs ) . ' 0 R >>';
		}
		$res  = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . $xobj . ' >>';
		$kids = array();
		foreach ( $this->pages as $content ) {
			$stream = function_exists( 'gzcompress' ) ? gzcompress( $content ) : $content;
			$filter = function_exists( 'gzcompress' ) ? ' /Filter /FlateDecode' : '';
			$objs[] = array( '<< /Length ' . strlen( $stream ) . $filter . ' >>', $stream );
			$cid    = count( $objs );
			$objs[] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources %s /Contents %d 0 R >>', self::n( $this->w ), self::n( $this->h ), $res, $cid );
			$kids[] = count( $objs ) . ' 0 R';
		}
		$objs[1] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		$info    = '';
		if ( '' !== $title ) {
			$objs[] = '<< /Title (' . str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), self::encode( $title ) ) . ') /Producer (PRDRWNDN POS) >>';
			$info   = ' /Info ' . count( $objs ) . ' 0 R';
		}

		$pdf  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offs = array();
		foreach ( $objs as $i => $o ) {
			$offs[] = strlen( $pdf );
			$pdf   .= ( $i + 1 ) . " 0 obj\n";
			$pdf   .= is_array( $o ) ? $o[0] . "\nstream\n" . $o[1] . "\nendstream" : $o;
			$pdf   .= "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offs as $o ) {
			$pdf .= sprintf( "%010d 00000 n \n", $o );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objs ) + 1 ) . ' /Root 1 0 R' . $info . " >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}
}
