<?php

class HSPSC_Preload_Test extends WP_UnitTestCase {
	public function test_sitemap_url_defaults_to_the_actual_core_index() {
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'preload_sitemap_url' => '' ) ) );
		$this->assertSame( wp_sitemaps_get_server()->index->get_index_url(), HSPSC_Preload::get_sitemap_url() );

		$url = home_url( '/custom-index.xml' );
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'preload_sitemap_url' => $url ) ) );
		$this->assertSame( $url, HSPSC_Preload::get_sitemap_url() );
	}

	public function test_parses_core_sitemap_prologue_and_decodes_only_direct_locations() {
		$target = add_query_arg( array( 'page' => 2, 'lang' => 'de' ), home_url( '/catalog/' ) );
		$cdata  = home_url( '/another/' );
		$body   = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<?xml-stylesheet type="text/xsl" href="/wp-sitemap.xsl"?>'
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
			. '<!-- <url><loc>https://example.net/not-a-page/</loc></url> -->'
			. '<url><loc>' . htmlspecialchars( $target, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</loc><image:image><image:loc>https://example.net/picture.jpg</image:loc></image:image></url>'
			. '<url><loc><![CDATA[' . $cdata . ']]></loc></url></urlset>';
		$args   = array();
		$mock   = static function ( $preempt, $request_args ) use ( $body, &$args ) {
			$args = $request_args;
			return array( 'response' => array( 'code' => 200 ), 'body' => $body );
		};
		add_filter( 'pre_http_request', $mock, 10, 2 );
		try {
			$result = HSPSC_Preload::fetch_sitemap( home_url( '/wp-sitemap.xml' ), 6 );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( '', $result['reason'] );
		$this->assertSame( 'urlset', $result['kind'] );
		$this->assertSame( array( $target, $cdata ), $result['locations'] );
		$this->assertSame( strlen( $body ), $result['bytes'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 6, $args['timeout'] );
		$this->assertSame( array(), $args['cookies'] );
		$this->assertSame( 'application/xml,text/xml,*/*;q=0.1', $args['headers']['Accept'] );
		$this->assertSame( 'identity', $args['headers']['Accept-Encoding'] );
		$this->assertFalse( $args['decompress'] );
		$this->assertSame( HSPSC_Preload::MAX_DOCUMENT_BYTES + 1, $args['limit_response_size'] );
	}

	public function test_discovers_core_plain_permalink_sitemap_index_children() {
		$root       = add_query_arg( 'sitemap', 'index', home_url( '/' ) );
		$stylesheet = add_query_arg( 'sitemap-stylesheet', 'index', home_url( '/' ) );
		$child      = add_query_arg( array( 'sitemap' => 'posts', 'sitemap-subtype' => 'post', 'paged' => 1 ), home_url( '/' ) );
		$body       = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<?xml-stylesheet type="text/xsl" href="' . htmlspecialchars( $stylesheet, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '" ?>' . "\n"
			. '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>'
			. htmlspecialchars( $child, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</loc></sitemap></sitemapindex>' . "\n";
		$mock = static function () use ( $body ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => $body,
			);
		};
		add_filter( 'pre_http_request', $mock );
		try {
			$result = HSPSC_Preload::fetch_sitemap( $root, 8 );
		} finally {
			remove_filter( 'pre_http_request', $mock );
		}
		$this->assertSame( 'sitemapindex', $result['kind'] );
		$this->assertSame( array( $child ), $result['locations'] );
		$this->assertSame( '', $result['reason'] );
	}

	public function test_external_and_admin_sitemap_targets_never_make_http_requests() {
		$mock = function () {
			$this->fail( 'An unsafe sitemap reached the HTTP API.' );
		};
		add_filter( 'pre_http_request', $mock );
		try {
			$this->assertSame( 'invalid-url', HSPSC_Preload::fetch_sitemap( 'https://elsewhere.example/sitemap.xml', 8 )['reason'] );
			$this->assertSame( 'excluded', HSPSC_Preload::fetch_sitemap( admin_url( 'options-general.php' ), 8 )['reason'] );
		} finally {
			remove_filter( 'pre_http_request', $mock );
		}
	}

	/** @dataProvider failed_documents */
	public function test_failed_documents_report_reason_without_partial_locations( $body, $status, $budget, $reason ) {
		$mock = static function ( $preempt, $args ) use ( $body, $status, $budget ) {
			if ( HSPSC_Preload::MAX_DOCUMENT_BYTES + 1 === $budget ) {
				$body = str_repeat( 'x', $args['limit_response_size'] );
			}
			return array( 'response' => array( 'code' => $status ), 'body' => $body );
		};
		add_filter( 'pre_http_request', $mock, 10, 2 );
		try {
			$result = HSPSC_Preload::fetch_sitemap( home_url( '/sitemap.xml' ), 8, $budget );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( $reason, $result['reason'] );
		$this->assertSame( array(), $result['locations'] );
	}

	public static function failed_documents() {
		$valid = '<urlset><url><loc>https://example.org/page/</loc></url></urlset>';
		return array(
			'redirect'           => array( $valid, 301, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'response-status' ),
			'truncated XML'      => array( substr( $valid, 0, -9 ), 200, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'invalid-sitemap' ),
			'non-sitemap HTML'   => array( '<html><body>Not XML</body></html>', 200, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'invalid-sitemap' ),
			'non-string body'    => array( array( $valid ), 200, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'invalid-sitemap' ),
			'mismatched root'    => array( str_replace( '<urlset>', '<urlset-extra>', $valid ), 200, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'invalid-sitemap' ),
			'mismatched entry'   => array( str_replace( '<url>', '<url-extra>', $valid ), 200, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'invalid-sitemap' ),
			'mismatched loc'     => array( str_replace( '<loc>', '<loc-extra>', $valid ), 200, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'invalid-sitemap' ),
			'junk after PI'      => array( '<?xml-stylesheet href="/sitemap.xsl?a=b"?> junk ?>' . $valid, 200, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'invalid-sitemap' ),
			'DTD'                => array( '<!DOCTYPE urlset [<!ENTITY unsafe SYSTEM "file:///etc/passwd">]>' . $valid, 200, HSPSC_Preload::MAX_DOCUMENT_BYTES, 'invalid-sitemap' ),
			'document byte cap'  => array( '', 200, HSPSC_Preload::MAX_DOCUMENT_BYTES + 1, 'sitemap-too-large' ),
			'aggregate byte cap' => array( $valid, 200, 16, 'discovery-byte-limit' ),
		);
	}
}
