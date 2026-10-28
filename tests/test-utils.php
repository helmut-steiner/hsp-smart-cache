<?php

class HSPSC_Utils_Test extends WP_UnitTestCase {
    public function test_cache_dirs_can_be_created() {
        HSPSC_Utils::ensure_cache_dirs();

        $this->assertDirectoryExists( HSPSC_PATH . '/pages' );
        $this->assertDirectoryExists( HSPSC_PATH . '/assets' );
        $this->assertDirectoryExists( HSPSC_PATH . '/object' );
    }

	/**
	 * Known private requests avoid expensive language and storefront discovery.
	 */
	public function test_private_requests_skip_language_and_commerce_lookups() {
		$server   = $_SERVER;
		$cookies  = $_COOKIE;
		$query    = $_GET;
		$user_id  = get_current_user_id();
		$pagenow  = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		$settings = get_option( HSPSC_Settings::OPTION_KEY );
		$lookups  = 0;
		$commerce = 0;
		$language_api = static function () use ( &$lookups ) {
			++$lookups;
			return null;
		};
		$private_pages = static function ( $ids ) use ( &$commerce ) {
			++$commerce;
			return $ids;
		};
		$hooks = array( 'wpml_current_language', 'wpml_default_language', 'wpml_active_languages', 'wpml_permalink' );

		$this->go_to( home_url( '/' ) );
		wp_set_current_user( 0 );
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		$GLOBALS['pagenow'] = 'index.php';
		$_GET              = array();
		$_SERVER['QUERY_STRING'] = '';
		foreach ( $hooks as $hook ) {
			add_filter( $hook, $language_api );
		}
		add_filter( 'hspsc_private_commerce_page_ids', $private_pages );
		try {
			$this->assertNull( HSPSC_WPML::request_context() );
			$this->assertSame( 1, $lookups, 'The optional language API is active for this test.' );
			$lookups = 0;
			$contexts = array(
				array( 'POST', '/', array() ),
				array( 'GET', '/', array( 'fct_cart_hash' => 'private-shopper' ) ),
				array( 'GET', '/checkout/', array() ),
			);
			foreach ( $contexts as $context ) {
				$_SERVER['REQUEST_METHOD'] = $context[0];
				$_SERVER['REQUEST_URI']    = $context[1];
				$_COOKIE                  = $context[2];
				$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
				$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
				$this->assertSame( 0, $lookups );
			}
			$_SERVER['REQUEST_URI'] = '/';
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
			$this->assertSame( 0, $lookups );
			$this->assertSame( 0, $commerce );
		} finally {
			foreach ( $hooks as $hook ) {
				remove_filter( $hook, $language_api );
			}
			remove_filter( 'hspsc_private_commerce_page_ids', $private_pages );
			$_SERVER = $server;
			$_COOKIE = $cookies;
			$_GET = $query;
			$GLOBALS['pagenow'] = $pagenow;
			wp_set_current_user( $user_id );
			if ( false === $settings ) {
				delete_option( HSPSC_Settings::OPTION_KEY );
			} else {
				update_option( HSPSC_Settings::OPTION_KEY, $settings );
			}
			HSPSC_Settings::reset_cache();
		}
	}

    public function test_normalize_url_path() {
        $path = HSPSC_Utils::normalize_url_path( content_url( '/style.css?ver=1' ) );
        $this->assertSame( '/wp-content/style.css', $path );
    }

    public function test_normalize_url_path_rejects_external_hosts() {
        $path = HSPSC_Utils::normalize_url_path( 'https://cdn.example.com/wp-content/style.css?ver=1' );

        $this->assertNull( $path );
    }

    public function test_delete_old_files_removes_only_expired_matching_files() {
        HSPSC_Utils::ensure_cache_dirs();

        $root = HSPSC_PATH . '/pages/old-file-test';
        wp_mkdir_p( $root );
        $old_html = $root . '/old.html';
        $fresh_html = $root . '/fresh.html';
        $old_txt = $root . '/old.txt';

        file_put_contents( $old_html, 'old' );
        file_put_contents( $fresh_html, 'fresh' );
        file_put_contents( $old_txt, 'old txt' );
        touch( $old_html, time() - 7200 );
        touch( $old_txt, time() - 7200 );

        $deleted = HSPSC_Utils::delete_old_files( $root, 3600, '.html' );

        $this->assertSame( 1, $deleted );
        $this->assertFileDoesNotExist( $old_html );
        $this->assertFileExists( $fresh_html );
        $this->assertFileExists( $old_txt );

        HSPSC_Utils::delete_dir_contents( $root );
        @rmdir( $root );
    }

    public function test_login_request_is_treated_as_backend() {
        $original_pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
        $original_uri     = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

        $GLOBALS['pagenow']    = 'wp-login.php';
        $_SERVER['REQUEST_URI'] = '/wp-login.php';

        $this->assertTrue( HSPSC_Utils::is_backend_or_login_request() );

        if ( null === $original_pagenow ) {
            unset( $GLOBALS['pagenow'] );
        } else {
            $GLOBALS['pagenow'] = $original_pagenow;
        }

        if ( null === $original_uri ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $original_uri;
        }
    }

    public function test_expired_file_count_does_not_depend_on_delete_return_value() {
        HSPSC_Utils::ensure_cache_dirs();
        $root = HSPSC_PATH . '/pages/delete-result-test';
        wp_mkdir_p( $root );
        $file = $root . '/expired.html';
        file_put_contents( $file, 'expired' );
        touch( $file, time() - 7200 );
        $delete_without_result = static function ( $path ) use ( $file ) {
            if ( $path === $file ) {
                unlink( $path );
                return '';
            }
            return $path;
        };
        add_filter( 'wp_delete_file', $delete_without_result );
        try {
            $this->assertSame( 1, HSPSC_Utils::delete_old_files( $root, 3600, '.html' ) );
            $this->assertFileDoesNotExist( $file );
        } finally {
            remove_filter( 'wp_delete_file', $delete_without_result );
            HSPSC_Utils::delete_dir_contents( $root );
            rmdir( $root );
        }
    }

    public function test_login_request_is_not_cacheable() {
        $original_pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
        $original_uri     = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
        $original_method  = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;

        $GLOBALS['pagenow']     = 'wp-login.php';
        $_SERVER['REQUEST_URI']  = '/wp-login.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );

        if ( null === $original_pagenow ) {
            unset( $GLOBALS['pagenow'] );
        } else {
            $GLOBALS['pagenow'] = $original_pagenow;
        }

        if ( null === $original_uri ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $original_uri;
        }

        if ( null === $original_method ) {
            unset( $_SERVER['REQUEST_METHOD'] );
        } else {
            $_SERVER['REQUEST_METHOD'] = $original_method;
        }
    }

    public function test_sensitive_cookie_request_is_not_cacheable() {
        $original_cookie = $_COOKIE;
        $original_uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
        $original_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;

        $_COOKIE = array( 'woocommerce_items_in_cart' => '1' );
        $_SERVER['REQUEST_URI'] = '/shop/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        HSPSC_Utils::reset_request_cache();

        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );

        $_COOKIE = $original_cookie;
        if ( null === $original_uri ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $original_uri;
        }
        if ( null === $original_method ) {
            unset( $_SERVER['REQUEST_METHOD'] );
        } else {
            $_SERVER['REQUEST_METHOD'] = $original_method;
        }
    }

    public function test_sensitive_uri_request_is_not_cacheable() {
        $original_uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
        $original_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;

        $_SERVER['REQUEST_URI'] = '/checkout/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        HSPSC_Utils::reset_request_cache();

        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );

        if ( null === $original_uri ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $original_uri;
        }
        if ( null === $original_method ) {
            unset( $_SERVER['REQUEST_METHOD'] );
        } else {
            $_SERVER['REQUEST_METHOD'] = $original_method;
        }
    }

    public function test_apply_robots_rules_appends_ai_block_when_enabled() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'robots_disallow_ai' => true ) )
        );

        $output = HSPSC_Utils::apply_robots_rules( "User-agent: *\nDisallow:", true );

        $this->assertStringContainsString( 'User-agent: GPTBot', $output );
        $this->assertStringContainsString( 'User-agent: Amazonbot', $output );
    }

    public function test_is_editor_or_builder_request_detects_builder_query() {
        $original_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
        $original_query = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : null;

        $_SERVER['REQUEST_URI'] = '/?bricks=run';
        $_SERVER['QUERY_STRING'] = 'bricks=run';

        $this->assertTrue( HSPSC_Utils::is_editor_or_builder_request() );

        if ( null === $original_uri ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $original_uri;
        }

        if ( null === $original_query ) {
            unset( $_SERVER['QUERY_STRING'] );
        } else {
            $_SERVER['QUERY_STRING'] = $original_query;
        }
    }

    public function test_should_apply_frontend_optimizations_is_false_for_logged_in_when_disabled() {
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'optimize_logged_in' => false ) )
        );

        $this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );

        wp_set_current_user( 0 );
    }

    public function test_delete_dir_contents_removes_nested_files() {
        HSPSC_Utils::ensure_cache_dirs();

        $root = HSPSC_PATH . '/pages/delete-test';
        $nested = $root . '/nested';
        wp_mkdir_p( $nested );
        file_put_contents( $root . '/a.txt', 'a' );
        file_put_contents( $nested . '/b.txt', 'b' );

        HSPSC_Utils::delete_dir_contents( $root );

        $this->assertDirectoryExists( $root );
        $this->assertFileDoesNotExist( $root . '/a.txt' );
        $this->assertFileDoesNotExist( $nested . '/b.txt' );
    }

    public function test_atomic_write_creates_parent_directory_and_replaces_file() {
        $path = HSPSC_PATH . '/pages/atomic-write-test/file.txt';

        $this->assertTrue( HSPSC_Utils::atomic_write( $path, 'first' ) );
        $this->assertSame( 'first', file_get_contents( $path ) );

        $this->assertTrue( HSPSC_Utils::atomic_write( $path, 'second' ) );
        $this->assertSame( 'second', file_get_contents( $path ) );

        wp_delete_file( $path );
        @rmdir( dirname( $path ) );
    }

    public function test_cache_cleanup_does_not_follow_directory_symlinks() {
        $root = HSPSC_PATH . '/pages/symlink-cleanup';
        $outside = HSPSC_PATH . '/assets/symlink-preserved';
        wp_mkdir_p( $root );
        wp_mkdir_p( $outside );
        $file = $outside . '/private.html';
        file_put_contents( $file, 'keep' );
        touch( $file, time() - 7200 );
        $link = $root . '/outside';

        try {
            if ( ! function_exists( 'symlink' ) || ! @symlink( $outside, $link ) ) {
                $this->markTestSkipped( 'Directory symlinks are unavailable.' );
            }
            $this->assertSame( 0, HSPSC_Utils::delete_old_files( $root, 3600, '.html' ) );
            $this->assertFileExists( $file );
            HSPSC_Utils::delete_dir_contents( $root );
            $this->assertFileExists( $file );
            $this->assertFalse( is_link( $link ) );
        } finally {
            if ( is_link( $link ) ) {
                unlink( $link );
            }
            wp_delete_file( $file );
            @rmdir( $root );
            @rmdir( $outside );
        }
    }
}
