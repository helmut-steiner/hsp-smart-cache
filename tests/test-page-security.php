<?php

class HSPSC_Page_Security_Test extends WP_UnitTestCase {
    private $server;
    private $cookies;
    private $request;
    private $buffer_level;

    public function set_up(): void {
        parent::set_up();
        $this->server = $_SERVER;
        $this->cookies = $_COOKIE;
        $this->request = $_GET;
        $this->buffer_level = ob_get_level();
        $_COOKIE = array();
        $_GET = array();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $home = wp_parse_url( home_url() );
        $_SERVER['HTTP_HOST'] = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
        $_SERVER['HTTPS'] = $home['scheme'] === 'https' ? 'on' : 'off';
        $_SERVER['SERVER_PORT'] = isset( $home['port'] ) ? $home['port'] : ( $home['scheme'] === 'https' ? 443 : 80 );
        unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );
        wp_set_current_user( 0 );
        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
        $this->go_to( home_url( '/' ) );
        HSPSC_Utils::reset_request_cache();
        HSPSC_Utils::ensure_cache_dirs();
    }

    public function tear_down(): void {
        while ( ob_get_level() > $this->buffer_level ) {
            ob_end_clean();
        }
        foreach ( array( 'buffer_started' => false, 'buffer_level' => 0, 'buffer_cache_file' => null ) as $name => $value ) {
            $property = new ReflectionProperty( 'HSPSC_Page', $name );
            $property->setAccessible( true );
            $property->setValue( null, $value );
        }
        $_SERVER = $this->server;
        $_COOKIE = $this->cookies;
        $_GET = $this->request;
        HSPSC_Utils::reset_request_cache();
        parent::tear_down();
    }

    private function invoke_page( $method, ...$args ) {
        $reflection = new ReflectionMethod( 'HSPSC_Page', $method );
        $reflection->setAccessible( true );
        return $reflection->invokeArgs( null, $args );
    }

    public function test_encoded_query_values_do_not_collide_with_empty_values() {
        $_SERVER['REQUEST_URI'] = '/products/?currency=%55%53%44';
        $encoded = $this->invoke_page( 'get_cache_file_path' );
        $_SERVER['REQUEST_URI'] = '/products/?currency=';
        $empty = $this->invoke_page( 'get_cache_file_path' );

        $this->assertNotSame( $encoded, $empty );
        $this->assertSame( $encoded, HSPSC_Page::get_cache_file_path_for_url( home_url( '/products/?currency=USD' ) ) );
    }

    public function test_encoded_paths_use_the_same_key_for_lookup_and_invalidation() {
        $_SERVER['REQUEST_URI'] = '/gr%C3%BCn/?filter=%C3%B6';
        $file = $this->invoke_page( 'get_cache_file_path' );
        $this->assertSame( $file, HSPSC_Page::get_cache_file_path_for_url( home_url( $_SERVER['REQUEST_URI'] ) ) );
        file_put_contents( $file, '<html>green</html>' );
        HSPSC_Page::clear_cache_for_url( home_url( $_SERVER['REQUEST_URI'] ) );
        $this->assertFileDoesNotExist( $file );
    }

    public function test_non_default_ports_are_part_of_the_key() {
        $this->assertNotSame(
            HSPSC_Page::get_cache_file_path_for_url( 'https://example.org:8443/shop/' ),
            HSPSC_Page::get_cache_file_path_for_url( 'https://example.org/shop/' )
        );
    }

	/**
	 * HTML, Markdown and image-capable requests cannot reuse each other's HTML.
	 */
	public function test_accept_variants_have_distinct_files_and_clear_together() {
		$_SERVER['REQUEST_URI'] = '/negotiated-page/';
		$_SERVER['HTTP_ACCEPT'] = 'text/html';
		$html = $this->invoke_page( 'get_cache_file_path' );
		file_put_contents( $html, '<html>original images</html>' );
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		$markdown = $this->invoke_page( 'get_cache_file_path' );
		$this->assertNotSame( $html, $markdown );
		$this->assertFileDoesNotExist( $markdown );
		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/webp';
		$webp = $this->invoke_page( 'get_cache_file_path' );
		$this->assertNotSame( $html, $webp );
		file_put_contents( $webp, '<html>webp images</html>' );
		$this->assertSame( $webp, HSPSC_Page::get_cache_file_path_for_url( home_url( '/negotiated-page/' ) ) );
		$_SERVER['HTTP_ACCEPT'] = '*/*';
		HSPSC_Page::clear_cache_for_url( home_url( '/negotiated-page/' ) );
		$this->assertFileDoesNotExist( $html );
		$this->assertFileDoesNotExist( $webp );
	}

	/**
	 * Invalid header inputs cannot produce cache filenames or warming headers.
	 */
	public function test_accept_variant_rejects_malformed_headers() {
		foreach ( array( array( 'text/html' ), "text/html\r\nX-Test: injected", str_repeat( 'a', 8193 ) ) as $accept ) {
			$_SERVER['HTTP_ACCEPT'] = $accept;
			$this->assertNull( $this->invoke_page( 'get_cache_file_path' ) );
		}
	}

	/**
	 * Manual warming populates the same negotiated representation as the caller.
	 */
	public function test_warming_forwards_valid_accept_preferences() {
		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/webp;q=0.9';
		$headers = array();
		$mock = static function ( $preempt, $args ) use ( &$headers ) {
			$headers[] = $args['headers']['Accept'];
			return new WP_Error( 'mock_response' );
		};
		add_filter( 'pre_http_request', $mock, 10, 2 );
		try {
			HSPSC_Page::warm_url( home_url( '/negotiated-page/' ) );
			HSPSC_Page::warm_url_with_timeout( home_url( '/negotiated-page/' ), 3 );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( array( $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_ACCEPT'] ), $headers );
	}

	/**
	 * Malformed Host authorities cannot turn another path into a shared key.
	 */
	public function test_host_delimiters_cannot_alias_another_cache_entry() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$_SERVER['REQUEST_URI'] = '/unrelated/';
		foreach ( array( '#', '?', '/', '\\' ) as $delimiter ) {
			$_SERVER['HTTP_HOST'] = wp_slash( $host . $delimiter );
			$this->assertNull( $this->invoke_page( 'get_cache_file_path' ), $delimiter );
		}
	}

	/**
	 * A raw request target cannot use fragments to alias a canonical URL.
	 */
	public function test_request_uri_with_a_literal_fragment_is_rejected() {
		$_SERVER['REQUEST_URI'] = '/catalog/#anything';
		$this->assertNull( $this->invoke_page( 'get_cache_file_path' ) );
	}

	/**
	 * Only configured HTTP origins are eligible for server-side warming.
	 */
	public function test_warming_rejects_credentials_and_unconfigured_origins() {
		$home   = wp_parse_url( home_url() );
		$scheme = $home['scheme'];
		$host   = $home['host'];
		$port   = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $scheme ? 443 : 80 );
		$other  = 65534 === $port ? 65533 : 65534;
		$this->assertTrue( HSPSC_Page::is_warmable_url( home_url( '/catalog/?lang=de' ) ) );
		$this->assertFalse( HSPSC_Page::is_warmable_url( $scheme . '://' . $host . ':' . $other . '/' ) );
		$this->assertFalse( HSPSC_Page::is_warmable_url( $scheme . '://user:pass@' . $host . ':' . $port . '/' ) );
		$this->assertFalse( HSPSC_Page::is_warmable_url( 'https://external.example/catalog/' ) );
		$this->assertFalse( HSPSC_Page::is_warmable_url( 'file:///etc/passwd' ) );
	}

	/**
	 * A permitted origin cannot redirect warming to an untrusted destination.
	 */
	public function test_page_warming_disables_redirects() {
		$requests = array();
		$mock     = static function ( $preempt, $args, $url ) use ( &$requests ) {
			$requests[] = array( $url, $args['redirection'] );
			return new WP_Error( 'mock_response' );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			HSPSC_Page::warm_url( home_url( '/catalog/' ) );
			HSPSC_Page::warm_url_with_timeout( home_url( '/catalog/?lang=de' ), 3 );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame(
			array( array( home_url( '/catalog/' ), 0 ), array( home_url( '/catalog/?lang=de' ), 0 ) ),
			$requests
		);
	}

    public function test_lookup_waits_for_dynamic_response_callbacks() {
        $this->assertSame( PHP_INT_MAX - 1, has_action( 'template_redirect', array( 'HSPSC_Page', 'maybe_serve_cache' ) ) );
        $this->assertSame( PHP_INT_MAX, has_action( 'template_redirect', array( 'HSPSC_Page', 'start_buffer' ) ) );
    }

    /** @dataProvider response_cases */
    public function test_only_complete_public_html_is_cacheable( $status, $headers, $expected ) {
        $this->assertSame( $expected, $this->invoke_page( 'should_cache_response', $status, $headers ) );
    }

    public function response_cases() {
        $html = array( 'Content-Type: text/html; charset=UTF-8' );
        return array(
            'html' => array( 200, $html, true ),
            'compression' => array( 200, array_merge( $html, array( 'Vary: Accept-Encoding' ) ), true ),
            'negotiated representation' => array( 200, array_merge( $html, array( 'Vary: Accept, Accept-Encoding' ) ), true ),
            'markdown' => array( 200, array( 'Content-Type: text/markdown; charset=UTF-8' ), false ),
            'compressed PHP body' => array( 200, array_merge( $html, array( 'Content-Encoding: gzip' ) ), false ),
            'identity body' => array( 200, array_merge( $html, array( 'Content-Encoding: identity' ) ), true ),
            'json' => array( 200, array( 'Content-Type: application/json' ), false ),
            'missing type' => array( 200, array(), false ),
            'partial' => array( 206, $html, false ),
            'redirect' => array( 302, $html, false ),
            'download' => array( 200, array_merge( $html, array( 'Content-Disposition: attachment; filename=data.html' ) ), false ),
            'cookie' => array( 200, array_merge( $html, array( 'Set-Cookie: session=customer' ) ), false ),
            'private' => array( 200, array_merge( $html, array( 'Cache-Control: private' ) ), false ),
            'no-store' => array( 200, array_merge( $html, array( 'Cache-Control: no-store' ) ), false ),
            'no-cache' => array( 200, array_merge( $html, array( 'Cache-Control: no-cache' ) ), false ),
            'cookie variants' => array( 200, array_merge( $html, array( 'Vary: Cookie' ) ), false ),
            'language variants' => array( 200, array_merge( $html, array( 'Vary: Accept-Language' ) ), false ),
        );
    }

    public function test_late_cart_session_is_not_written_and_output_is_preserved() {
        $file = $this->invoke_page( 'get_cache_file_path' );
        wp_delete_file( $file );
        ob_start();
        HSPSC_Page::start_buffer();
        echo '<html>private cart</html>';
        $_COOKIE['fct_cart_hash'] = 'late-customer-session';
        HSPSC_Page::save_buffer();
        $this->assertSame( '<html>private cart</html>', ob_get_clean() );
        $this->assertFileDoesNotExist( $file );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_late_donotcachepage_is_honored() {
        $file = $this->invoke_page( 'get_cache_file_path' );
        wp_delete_file( $file );
        ob_start();
        HSPSC_Page::start_buffer();
        echo '<html>private shortcode</html>';
        define( 'DONOTCACHEPAGE', true );
        HSPSC_Page::save_buffer();
        $this->assertSame( '<html>private shortcode</html>', ob_get_clean() );
        $this->assertFileDoesNotExist( $file );
    }

    public function test_nested_output_buffers_are_not_consumed_or_cached() {
        $file = $this->invoke_page( 'get_cache_file_path' );
        wp_delete_file( $file );
        HSPSC_Page::start_buffer();
        echo 'page prefix';
        ob_start();
        echo 'nested fragment';
        $level = ob_get_level();
        HSPSC_Page::save_buffer();
        $this->assertSame( $level, ob_get_level() );
        $this->assertSame( 'nested fragment', ob_get_clean() );
        $this->assertSame( 'page prefix', ob_get_clean() );
        $this->assertFileDoesNotExist( $file );
    }
}
