<?php

class HSP_Smart_Cache_Utils_Test extends WP_UnitTestCase {
    public function test_cache_dirs_can_be_created() {
        HSP_Smart_Cache_Utils::ensure_cache_dirs();

        $this->assertDirectoryExists( HSP_SMART_CACHE_PATH . '/pages' );
        $this->assertDirectoryExists( HSP_SMART_CACHE_PATH . '/assets' );
        $this->assertDirectoryExists( HSP_SMART_CACHE_PATH . '/object' );
    }

    public function test_normalize_url_path() {
        $path = HSP_Smart_Cache_Utils::normalize_url_path( 'https://example.com/wp-content/style.css?ver=1' );
        $this->assertSame( '/wp-content/style.css', $path );
    }

    public function test_login_request_is_treated_as_backend() {
        $original_pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
        $original_uri     = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

        $GLOBALS['pagenow']    = 'wp-login.php';
        $_SERVER['REQUEST_URI'] = '/wp-login.php';

        $this->assertTrue( HSP_Smart_Cache_Utils::is_backend_or_login_request() );

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

    public function test_login_request_is_not_cacheable() {
        $original_pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
        $original_uri     = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
        $original_method  = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;

        $GLOBALS['pagenow']     = 'wp-login.php';
        $_SERVER['REQUEST_URI']  = '/wp-login.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertFalse( HSP_Smart_Cache_Utils::is_request_cacheable() );

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
}
