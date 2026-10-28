<?php

class HSPSC_Page_Revalidation_Test extends WP_UnitTestCase {

	const ETAG      = '"0123456789abcdef0123456789abcdef"';
	const GZIP_ETAG = '"0123456789abcdef0123456789abcdef-gzip"';
	const BR_ETAG   = '"0123456789abcdef0123456789abcdef-br"';

	/**
	 * Invokes the conditional matcher without relying on CLI response headers.
	 *
	 * @param mixed  $field    If-None-Match field.
	 * @param mixed  $encoding Accept-Encoding field.
	 * @param string $etag     Current cache validator.
	 * @return array|null Trusted response metadata, or no match.
	 */
	private function get_match( $field, $encoding = null, $etag = self::ETAG ) {
		$method = new ReflectionMethod( 'HSPSC_Page', 'get_revalidation_match' );
		$method->setAccessible( true );
		return $method->invoke( null, $field, $etag, $encoding );
	}

	/**
	 * Complete legal fields can identify the current representation and coding.
	 *
	 * @dataProvider matching_fields
	 */
	public function test_matching_fields_return_only_canonical_response_metadata( $field, $accept_encoding, $etag, $encoding ) {
		$this->assertSame(
			array( 'etag' => $etag, 'encoding' => $encoding ),
			$this->get_match( $field, $accept_encoding )
		);
	}

	public function matching_fields() {
		return array(
			'exact'             => array( self::ETAG, null, self::ETAG, '' ),
			'weak'              => array( 'W/' . self::ETAG, null, 'W/' . self::ETAG, '' ),
			'list'              => array( '"old", W/' . self::ETAG . ', "older"', null, 'W/' . self::ETAG, '' ),
			'nginx weak gzip'   => array( 'W/' . self::ETAG, 'gzip', 'W/' . self::ETAG, '' ),
			'quoted comma'      => array( '"unrelated,tag", ' . self::ETAG, null, self::ETAG, '' ),
			'literal backslash' => array( '"literal' . chr( 92 ) . '", ' . self::ETAG, null, self::ETAG, '' ),
			'opaque obs-text'   => array( '"foreign' . chr( 0x80 ) . '", ' . self::ETAG, null, self::ETAG, '' ),
			'optional spaces'   => array( " \t" . self::ETAG . "\t ", null, self::ETAG, '' ),
			'empty members'     => array( ', , ' . self::ETAG . ',, ', null, self::ETAG, '' ),
			'wildcard'          => array( " \t*\t ", null, self::ETAG, '' ),
			'gzip'              => array( self::GZIP_ETAG, 'gzip', self::GZIP_ETAG, 'gzip' ),
			'weak gzip'         => array( 'W/' . self::GZIP_ETAG, 'gzip;q=0.5', 'W/' . self::GZIP_ETAG, 'gzip' ),
			'Brotli'            => array( self::BR_ETAG, 'br, gzip', self::BR_ETAG, 'br' ),
			'weak Brotli list'  => array( '"old", W/' . self::BR_ETAG, 'br', 'W/' . self::BR_ETAG, 'br' ),
			'coding wildcard'   => array( self::GZIP_ETAG, '*;q=0.5', self::GZIP_ETAG, 'gzip' ),
		);
	}

	/**
	 * Malformed fields cannot be repaired into a matching validator.
	 *
	 * @dataProvider nonmatching_fields
	 */
	public function test_malformed_or_unrelated_fields_do_not_revalidate( $field ) {
		$this->assertNull( $this->get_match( $field, 'gzip, br' ) );
	}

	public function nonmatching_fields() {
		return array(
			'missing'                  => array( null ),
			'array'                    => array( array( self::ETAG ) ),
			'number'                   => array( 1 ),
			'empty'                    => array( '' ),
			'empty list'               => array( ', ,,' ),
			'foreign tag'              => array( '"1123456789abcdef0123456789abcdef"' ),
			'unquoted'                 => array( '0123456789abcdef0123456789abcdef' ),
			'lowercase weakness'       => array( 'w/' . self::ETAG ),
			'spaced weakness'          => array( 'W/ ' . self::ETAG ),
			'weak wildcard'            => array( 'W/*' ),
			'wildcard list prefix'     => array( '*,' . self::ETAG ),
			'wildcard list suffix'     => array( self::ETAG . ',*' ),
			'double wildcard'          => array( '*,*' ),
			'missing list comma'       => array( self::ETAG . ' "other"' ),
			'trailing junk'            => array( self::ETAG . 'garbage' ),
			'late unterminated tag'    => array( self::ETAG . ', "unterminated' ),
			'late invalid member'      => array( self::ETAG . ', invalid' ),
			'embedded space'           => array( '"foreign tag", ' . self::ETAG ),
			'embedded tab'             => array( '"foreign' . "\t" . 'tag", ' . self::ETAG ),
			'CRLF'                     => array( self::ETAG . "\r\nX-Test: injected" ),
			'null byte'                => array( self::ETAG . "\0" ),
			'DEL'                      => array( self::ETAG . chr( 0x7f ) ),
			'oversized'                => array( self::ETAG . str_repeat( ' ', 8193 ) ),
			'percent escape preserved' => array( '"0123456789abcdef0123456789abcdef%41"' ),
			'HTML preserved'           => array( '<b>' . self::ETAG . '</b>' ),
			'unknown coding suffix'    => array( '"0123456789abcdef0123456789abcdef-zstd"' ),
			'duplicate gzip suffix'    => array( '"0123456789abcdef0123456789abcdef-gzip-gzip"' ),
			'mixed coding suffixes'    => array( '"0123456789abcdef0123456789abcdef-gzip-br"' ),
			'uppercase opaque suffix'  => array( '"0123456789abcdef0123456789abcdef-GZIP"' ),
		);
	}

	/**
	 * Compression aliases need an unambiguous positive weight for that coding.
	 *
	 * @dataProvider encoding_fields
	 */
	public function test_compression_aliases_follow_encoding_weights( $encoding, $field, $expected ) {
		$etag  = 'gzip' === $encoding ? self::GZIP_ETAG : self::BR_ETAG;
		$match = $this->get_match( $etag, $field );
		if ( $expected ) {
			$this->assertSame( array( 'etag' => $etag, 'encoding' => $encoding ), $match );
		} else {
			$this->assertNull( $match );
		}
	}

	public function encoding_fields() {
		return array(
			'explicit gzip'          => array( 'gzip', 'gzip', true ),
			'case insensitive'       => array( 'gzip', 'GZip', true ),
			'positive fraction'      => array( 'gzip', 'gzip;q=0.001', true ),
			'one with fraction'      => array( 'gzip', 'gzip;q=1.000', true ),
			'OWS and uppercase Q'    => array( 'gzip', "gzip \t; Q=0.5", true ),
			'empty list members'     => array( 'gzip', ',gzip,,', true ),
			'wildcard positive'      => array( 'gzip', 'br, *;q=0.5', true ),
			'explicit beats zero'    => array( 'gzip', '*;q=0, gzip;q=1', true ),
			'explicit zero first'    => array( 'gzip', 'gzip;q=0, *;q=1', false ),
			'explicit zero last'     => array( 'gzip', '*;q=1, gzip;q=0', false ),
			'Brotli explicit zero'   => array( 'br', 'br;q=0, *;q=1', false ),
			'Brotli explicit wins'   => array( 'br', '*;q=0, br;q=0.8', true ),
			'missing'                => array( 'gzip', null, false ),
			'array'                  => array( 'gzip', array( 'gzip' ), false ),
			'empty'                  => array( 'gzip', '', false ),
			'other coding'           => array( 'gzip', 'br', false ),
			'zero wildcard'          => array( 'gzip', '*;q=0', false ),
			'zero quality'           => array( 'gzip', 'gzip;q=0.000', false ),
			'zero with decimal'      => array( 'gzip', 'gzip;q=0.', false ),
			'conflicting duplicate'  => array( 'gzip', 'gzip;q=1, gzip;q=0', false ),
			'identical duplicate'    => array( 'gzip', 'gzip, gzip', false ),
			'duplicate case'         => array( 'gzip', 'gzip, GZIP', false ),
			'duplicate wildcard'     => array( 'gzip', '*;q=0, *;q=1', false ),
			'missing leading zero'   => array( 'gzip', 'gzip;q=.5', false ),
			'quality above one'      => array( 'gzip', 'gzip;q=1.001', false ),
			'invalid integer'        => array( 'gzip', 'gzip;q=2', false ),
			'excess precision'       => array( 'gzip', 'gzip;q=0.1234', false ),
			'unsupported parameter'  => array( 'gzip', 'gzip;level=1', false ),
			'extra parameter'        => array( 'gzip', 'gzip;q=1;foo=bar', false ),
			'spaced equals'          => array( 'gzip', 'gzip;q = 1', false ),
			'late malformed member'  => array( 'gzip', 'gzip, br;q=invalid', false ),
			'control bytes'          => array( 'gzip', "gzip\r\nX-Test: injected", false ),
			'oversized'              => array( 'gzip', 'gzip' . str_repeat( ' ', 8193 ), false ),
		);
	}

	/**
	 * Plain validators and wildcard matches cannot imply an excluded identity body.
	 *
	 * @dataProvider identity_encoding_fields
	 */
	public function test_identity_metadata_respects_explicit_exclusions( $field, $expected ) {
		foreach ( array( self::ETAG, '*' ) as $validator ) {
			$match = $this->get_match( $validator, $field );
			if ( $expected ) {
				$this->assertSame( array( 'etag' => self::ETAG, 'encoding' => '' ), $match );
			} else {
				$this->assertNull( $match );
			}
		}
	}

	public function identity_encoding_fields() {
		return array(
			'no coding field'            => array( null, true ),
			'empty coding field'         => array( '', true ),
			'identity by default'        => array( 'gzip, br', true ),
			'explicit identity allowed'  => array( 'identity;q=1, *;q=0, gzip;q=1', true ),
			'explicit identity excluded' => array( 'identity;q=0, gzip;q=1', false ),
			'wildcard identity excluded' => array( '*;q=0, gzip;q=1', false ),
			'identity overrides star'    => array( '*;q=1, identity;q=0', false ),
			'malformed quality'         => array( 'gzip;q=invalid', false ),
			'ambiguous coding field'    => array( 'gzip;q=1, gzip;q=0', false ),
			'invalid coding type'       => array( array( 'gzip' ), false ),
		);
	}

	/**
	 * A validator for another HTML/image negotiation context cannot match.
	 */
	public function test_other_accept_context_validator_cannot_revalidate_the_current_page() {
		$server = $_SERVER;
		$method = new ReflectionMethod( 'HSPSC_Page', 'get_cache_file_path' );
		$method->setAccessible( true );
		try {
			$home = wp_parse_url( home_url() );
			$_SERVER['HTTP_HOST']   = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			$_SERVER['REQUEST_URI'] = '/conditional-context/';
			$_SERVER['HTTP_ACCEPT'] = 'text/html';
			$old_file = $method->invoke( null );
			$_SERVER['HTTP_ACCEPT'] = 'text/html,image/webp';
			$current_file = $method->invoke( null );
			$old_etag     = '"' . md5( $old_file . '|12345' ) . '-gzip"';
			$current_etag = '"' . md5( $current_file . '|12345' ) . '"';

			$this->assertNotSame( $old_file, $current_file );
			$this->assertNull( $this->get_match( $old_etag, 'gzip', $current_etag ) );
		} finally {
			$_SERVER = $server;
		}
	}
}
