<?php

class HSPSC_Page_Test extends WP_UnitTestCase {
	protected function invoke_page( $name, ...$args ) {
		$method = new ReflectionMethod( 'HSPSC_Page', $name );
		$method->setAccessible( true );
		return $method->invokeArgs( null, $args );
	}

	/**
	 * Authenticated responses have executable guards and URL-wide invalidation.
	 */
	public function test_authenticated_cache_files_are_guarded_and_all_users_invalidate_together() {
		$server = $_SERVER;
		$user   = get_current_user_id();
		$files  = array();
		$url    = home_url( '/authenticated-storage/' );
		try {
			$home = wp_parse_url( home_url() );
			$_SERVER['HTTP_HOST']   = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			$_SERVER['REQUEST_URI'] = '/authenticated-storage/';
			$_SERVER['HTTP_ACCEPT'] = 'text/html';
			wp_set_current_user( self::factory()->user->create() );
			$first   = $this->get_current_cache_path();
			$files[] = $first;
			$this->assertTrue( $this->invoke_page( 'write_private_cache_file', $first, '<html>First user</html>' ) );
			wp_set_current_user( self::factory()->user->create() );
			$second  = $this->get_current_cache_path();
			$files[] = $second;
			$this->assertTrue( $this->invoke_page( 'write_private_cache_file', $second, '<html>Second user</html>' ) );
			$public  = HSPSC_Page::get_cache_file_path_for_url( $url );
			$files[] = $public;
			file_put_contents( $public, '<html>Anonymous visitor</html>' );

			$this->assertNotSame( $first, $second );
			$this->assertStringStartsWith( HSPSC_PATH . '/pages/private/', $first );
			$this->assertStringEndsWith( '.php', $first );
			$this->assertStringEndsWith( '.html', $public );
			$this->assertSame( HSPSC_Page::PRIVATE_FILE_GUARD . '<html>First user</html>', file_get_contents( $first ) );
			foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $protection ) {
				$this->assertFileExists( dirname( $first ) . '/' . $protection );
			}
			$snapshot = $this->invoke_page( 'open_cache_snapshot', $first, 3600 );
			$this->assertIsArray( $snapshot );
			ob_start();
			$this->invoke_page( 'stream_cache_file', $snapshot['handle'] );
			$this->assertSame( '<html>First user</html>', ob_get_clean() );
			fclose( $snapshot['handle'] );
			$this->assertSame( $snapshot['etag'], $this->invoke_page( 'get_content_etag', $first, '<html>First user</html>' ) );

			HSPSC_Page::clear_cache_for_url( $url );
			foreach ( $files as $file ) {
				$this->assertFileDoesNotExist( $file );
			}
		} finally {
			foreach ( $files as $file ) {
				wp_delete_file( $file );
			}
			$_SERVER = $server;
			wp_set_current_user( $user );
		}
	}

	/**
	 * Unguarded private files are unusable and cleanup preserves deny metadata.
	 */
	public function test_private_cache_requires_its_guard_and_cleanup_preserves_protections() {
		$server = $_SERVER;
		$user   = get_current_user_id();
		$file   = null;
		try {
			$home = wp_parse_url( home_url() );
			$_SERVER['HTTP_HOST']   = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			$_SERVER['REQUEST_URI'] = '/guarded-storage/';
			wp_set_current_user( self::factory()->user->create() );
			$file = $this->get_current_cache_path();
			$this->assertTrue( $this->invoke_page( 'write_private_cache_file', $file, '<html>Private body</html>' ) );
			file_put_contents( $file, '<html>Missing guard</html>' );
			$this->assertFalse( $this->invoke_page( 'open_cache_snapshot', $file, 3600 ) );
			$this->assertTrue( $this->invoke_page( 'write_private_cache_file', $file, '<html>Private body</html>' ) );
			$old = time() - (int) HSPSC_Settings::get( 'page_cache_ttl' ) - 10;
			touch( $file, $old );
			foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $protection ) {
				touch( dirname( $file ) . '/' . $protection, $old );
			}
			clearstatcache();
			HSPSC_Page::cleanup_expired_cache();
			$this->assertFileDoesNotExist( $file );
			foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $protection ) {
				$this->assertFileExists( dirname( $file ) . '/' . $protection );
			}
		} finally {
			if ( $file ) {
				wp_delete_file( $file );
			}
			$_SERVER = $server;
			wp_set_current_user( $user );
		}
	}

	/**
	 * Distinct login sessions cannot share cached nonce-bearing frontend HTML.
	 */
	public function test_authenticated_cache_key_isolates_login_sessions() {
		$server  = $_SERVER;
		$cookies = $_COOKIE;
		$user    = get_current_user_id();
		try {
			$home = wp_parse_url( home_url() );
			$_SERVER['HTTP_HOST']   = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			$_SERVER['REQUEST_URI'] = '/session-isolation/';
			$user_id = self::factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in', 'first-session' );
			$first = $this->get_current_cache_path();
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in', 'second-session' );
			$this->assertNotSame( $first, $this->get_current_cache_path() );
		} finally {
			$_SERVER = $server;
			$_COOKIE = $cookies;
			wp_set_current_user( $user );
		}
	}

	/**
	 * Failed access-deny setup must not persist any authenticated response body.
	 */
	public function test_private_write_fails_closed_when_protection_is_blocked() {
		$private = HSPSC_PATH . '/pages/private';
		$file    = $private . '/' . str_repeat( 'a', 32 ) . '-user-' . str_repeat( 'b', 32 ) . '-' . str_repeat( 'c', 32 ) . '.php';
		$this->assertTrue( $this->invoke_page( 'ensure_private_cache_directory' ) );
		$rules = file_get_contents( $private . '/.htaccess' );
		wp_delete_file( $private . '/.htaccess' );
		mkdir( $private . '/.htaccess' );
		try {
			$this->assertFalse( $this->invoke_page( 'write_private_cache_file', $file, '<html>Must remain unsaved</html>' ) );
			$this->assertFileDoesNotExist( $file );
		} finally {
			rmdir( $private . '/.htaccess' );
			HSPSC_Utils::atomic_write( $private . '/.htaccess', $rules );
			wp_delete_file( $file );
		}
	}

	/**
	 * Core's login default can be replaced without accepting later private headers.
	 */
	public function test_authenticated_header_filter_preserves_integration_privacy_vetoes() {
		$server  = $_SERVER;
		$cookies = $_COOKIE;
		$user    = get_current_user_id();
		$options = HSPSC_Settings::get_all();
		$core    = array(
			'Content-Type'  => 'text/html; charset=UTF-8',
			'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private',
			'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
		);
		$late = static function ( $headers ) {
			$headers['Cache-Control'] = 'private, no-store';
			return $headers;
		};
		try {
			$this->go_to( home_url( '/' ) );
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_COOKIE = array();
			wp_set_current_user( self::factory()->user->create() );
			update_option( HSPSC_Settings::OPTION_KEY, array_merge( $options, array( 'page_cache' => true, 'cache_logged_in' => true ) ) );
			$filtered = HSPSC_Page::filter_authenticated_cache_headers( $core );
			$this->assertArrayNotHasKey( 'Cache-Control', $filtered );
			$this->assertSame( $core['Expires'], $filtered['Expires'] );
			$custom = array_merge( $core, array( 'Cache-Control' => 'private, no-store' ) );
			$this->assertSame( $custom, HSPSC_Page::filter_authenticated_cache_headers( $custom ) );
			add_filter( 'wp_headers', $late, 10 );
			$filtered = apply_filters( 'wp_headers', $core );
			$this->assertSame( 'private, no-store', $filtered['Cache-Control'] );
			$lines = array();
			foreach ( $filtered as $name => $value ) {
				$lines[] = $name . ': ' . $value;
			}
			$this->assertFalse( $this->invoke_page( 'should_cache_response', 200, $lines ) );
			update_option( HSPSC_Settings::OPTION_KEY, array_merge( $options, array( 'cache_logged_in' => false ) ) );
			$this->assertSame( $core, HSPSC_Page::filter_authenticated_cache_headers( $core ) );
		} finally {
			remove_filter( 'wp_headers', $late, 10 );
			update_option( HSPSC_Settings::OPTION_KEY, $options );
			$_SERVER = $server;
			$_COOKIE = $cookies;
			wp_set_current_user( $user );
		}
	}
    protected function get_cache_path_for_url( $url ) {
        $ref = new ReflectionClass( 'HSPSC_Page' );
        $method = $ref->getMethod( 'get_cache_file_path_for_url' );
        $method->setAccessible( true );
        return $method->invoke( null, $url );
    }

    protected function get_current_cache_path() {
        $ref = new ReflectionClass( 'HSPSC_Page' );
        $method = $ref->getMethod( 'get_cache_file_path' );
        $method->setAccessible( true );
        return $method->invoke( null );
    }

    public function test_clear_cache_for_url_removes_file() {
        HSPSC_Utils::ensure_cache_dirs();
        $url = home_url( '/sample-page/' );
        $file = $this->get_cache_path_for_url( $url );

        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSPSC_Page::clear_cache_for_url( $url );
        $this->assertFileDoesNotExist( $file );
    }

    public function test_clear_cache_for_post_clears_permalink() {
        $post_id = self::factory()->post->create( array(
            'post_title' => 'Cache Test',
            'post_status' => 'publish',
        ) );
        $url = get_permalink( $post_id );
        $file = $this->get_cache_path_for_url( $url );

        HSPSC_Utils::ensure_cache_dirs();
        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSPSC_Page::clear_cache_for_post( $post_id );
        $this->assertFileDoesNotExist( $file );
    }

    public function test_cache_key_ignores_marketing_query_arguments() {
        $base = $this->get_cache_path_for_url( home_url( '/sample-page/?page=2' ) );
        $with_tracking = $this->get_cache_path_for_url( home_url( '/sample-page/?utm_source=newsletter&fbclid=abc&page=2' ) );

        $this->assertSame( $base, $with_tracking );
    }

    public function test_cache_key_keeps_meaningful_query_arguments() {
        $base = $this->get_cache_path_for_url( home_url( '/sample-page/?page=2' ) );
        $filtered = $this->get_cache_path_for_url( home_url( '/sample-page/?page=3' ) );

        $this->assertNotSame( $base, $filtered );
    }

    public function test_cache_info_reports_fresh_and_stale_files() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'page_cache' => true, 'page_cache_ttl' => 60 ) )
        );

        HSPSC_Utils::ensure_cache_dirs();
        $url = home_url( '/cache-info/' );
        $file = HSPSC_Page::get_cache_file_path_for_url( $url );
        file_put_contents( $file, '<html>cached</html>' );

        $fresh = HSPSC_Page::get_cache_info_for_url( $url );
        $this->assertTrue( $fresh['enabled'] );
        $this->assertTrue( $fresh['exists'] );
        $this->assertTrue( $fresh['fresh'] );
        $this->assertSame( strlen( '<html>cached</html>' ), $fresh['size'] );

        touch( $file, time() - 120 );
        clearstatcache( true, $file );

        $stale = HSPSC_Page::get_cache_info_for_url( $url );
        $this->assertTrue( $stale['exists'] );
        $this->assertFalse( $stale['fresh'] );
        $this->assertGreaterThanOrEqual( 60, $stale['age'] );

        wp_delete_file( $file );
    }

    public function test_logged_in_cache_key_varies_by_user() {
        $_SERVER['HTTP_HOST'] = wp_parse_url( home_url(), PHP_URL_HOST );
        $_SERVER['REQUEST_URI'] = '/member-area/';
        $_SERVER['HTTPS'] = is_ssl() ? 'on' : 'off';

        $user_a = self::factory()->user->create();
        $user_b = self::factory()->user->create();

        wp_set_current_user( $user_a );
        $path_a = $this->get_current_cache_path();

        wp_set_current_user( $user_b );
        $path_b = $this->get_current_cache_path();

        wp_set_current_user( 0 );

        $this->assertNotSame( $path_a, $path_b );
    }

    public function test_cleanup_expired_cache_removes_old_html_files() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'page_cache_ttl' => 3600 ) )
        );

        HSPSC_Utils::ensure_cache_dirs();
        $old_file = HSPSC_PATH . '/pages/expired-test.html';
        $fresh_file = HSPSC_PATH . '/pages/fresh-test.html';

        file_put_contents( $old_file, 'old' );
        file_put_contents( $fresh_file, 'fresh' );
        touch( $old_file, time() - 7200 );

        $deleted = HSPSC_Page::cleanup_expired_cache();

        $this->assertSame( 1, $deleted );
        $this->assertFileDoesNotExist( $old_file );
        $this->assertFileExists( $fresh_file );

        wp_delete_file( $fresh_file );
    }

    public function test_warm_url_with_timeout_issues_request_when_page_cache_enabled() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'page_cache' => true ) )
        );

        $captured = array();
        $mock_http = static function( $preempt, $args, $url ) use ( &$captured ) {
            $captured[] = array( 'url' => $url, 'args' => $args );
            return array(
                'headers'  => array(),
                'body'     => 'ok',
                'response' => array( 'code' => 200, 'message' => 'OK' ),
                'cookies'  => array(),
                'filename' => null,
            );
        };

        add_filter( 'pre_http_request', $mock_http, 10, 3 );
        try {
            HSPSC_Page::warm_url_with_timeout( home_url( '/warm-me/' ), 4 );
        } finally {
            remove_filter( 'pre_http_request', $mock_http, 10 );
        }

        $this->assertCount( 1, $captured );
        $this->assertSame( home_url( '/warm-me/' ), $captured[0]['url'] );
        $this->assertSame( 4, $captured[0]['args']['timeout'] );
        $this->assertNotFalse( $captured[0]['args']['sslverify'] );
    }

    public function test_warm_url_rejects_external_urls() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'page_cache' => true ) )
        );

        $captured = array();
        $mock_http = static function( $preempt, $args, $url ) use ( &$captured ) {
            $captured[] = $url;
            return array(
                'headers'  => array(),
                'body'     => 'ok',
                'response' => array( 'code' => 200, 'message' => 'OK' ),
                'cookies'  => array(),
                'filename' => null,
            );
        };

        add_filter( 'pre_http_request', $mock_http, 10, 3 );
        try {
            HSPSC_Page::warm_url_with_timeout( 'https://example.net/warm-me/', 4 );
        } finally {
            remove_filter( 'pre_http_request', $mock_http, 10 );
        }

        $this->assertSame( array(), $captured );
    }

    public function test_warm_urls_calls_remote_get_for_each_url() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'page_cache' => true ) )
        );

        $urls = array( home_url( '/a/' ), home_url( '/b/' ) );
        $captured = array();
        $mock_http = static function( $preempt, $args, $url ) use ( &$captured ) {
            $captured[] = $url;
            return array(
                'headers'  => array(),
                'body'     => 'ok',
                'response' => array( 'code' => 200, 'message' => 'OK' ),
                'cookies'  => array(),
                'filename' => null,
            );
        };

        add_filter( 'pre_http_request', $mock_http, 10, 3 );
        try {
            HSPSC_Page::warm_urls( $urls );
        } finally {
            remove_filter( 'pre_http_request', $mock_http, 10 );
        }

        $this->assertCount( 2, $captured );
        $this->assertSame( $urls, $captured );
    }
}
