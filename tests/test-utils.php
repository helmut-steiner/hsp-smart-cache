<?php

class HSPSC_Utils_Test extends WP_UnitTestCase {
    public function test_cache_dirs_can_be_created() {
        HSPSC_Utils::ensure_cache_dirs();

        $this->assertDirectoryExists( HSPSC_PATH . '/pages' );
        $this->assertDirectoryExists( HSPSC_PATH . '/assets' );
        $this->assertDirectoryExists( HSPSC_PATH . '/object' );
    }

    public function test_normalize_url_path() {
        $path = HSPSC_Utils::normalize_url_path( 'https://example.com/wp-content/style.css?ver=1' );
        $this->assertSame( '/wp-content/style.css', $path );
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
}
