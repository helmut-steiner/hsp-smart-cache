<?php

class HSP_Smart_Cache_Admin_Test extends WP_UnitTestCase {
    private $original_get;
    private $original_request;

    public function set_up(): void {
        parent::set_up();
        HSP_Smart_Cache_Utils::ensure_cache_dirs();

        $this->original_get = $_GET;
        $this->original_request = $_REQUEST;

        add_filter( 'hsp_smart_cache_skip_admin_exit', '__return_true' );
    }

    public function tear_down(): void {
        remove_filter( 'hsp_smart_cache_skip_admin_exit', '__return_true' );

        $_GET = $this->original_get;
        $_REQUEST = $this->original_request;

        parent::tear_down();
    }

    public function test_init_registers_admin_hooks() {
        HSP_Smart_Cache_Admin::init();

        $this->assertNotFalse( has_action( 'admin_menu', array( 'HSP_Smart_Cache_Admin', 'register_menu' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hsp_cache_clear', array( 'HSP_Smart_Cache_Admin', 'handle_clear_cache' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hsp_cache_run_tests', array( 'HSP_Smart_Cache_Admin', 'handle_run_tests' ) ) );
        $this->assertNotFalse( has_action( 'admin_bar_menu', array( 'HSP_Smart_Cache_Admin', 'register_admin_bar' ) ) );
    }

    public function test_register_admin_bar_adds_main_cache_node_for_admin_user() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $bar = new WP_Admin_Bar();
        $bar->initialize();

        HSP_Smart_Cache_Admin::register_admin_bar( $bar );

        $this->assertNotNull( $bar->get_node( 'hsp-cache' ) );
        $this->assertNotNull( $bar->get_node( 'hsp-cache-clear-all' ) );

        wp_set_current_user( 0 );
    }

    public function test_handle_settings_update_clears_page_and_asset_caches() {
        $page_file  = HSP_SMART_CACHE_PATH . '/pages/admin-test-page.html';
        $asset_file = HSP_SMART_CACHE_PATH . '/assets/admin-test.min.js';

        file_put_contents( $page_file, 'page' );
        file_put_contents( $asset_file, 'asset' );

        $this->assertFileExists( $page_file );
        $this->assertFileExists( $asset_file );

        update_option( HSP_Smart_Cache_Settings::OPTION_KEY, HSP_Smart_Cache_Settings::defaults() );
        HSP_Smart_Cache_Admin::handle_settings_update( array(), array() );

        $this->assertFileDoesNotExist( $page_file );
        $this->assertFileDoesNotExist( $asset_file );
    }

    public function test_handle_clear_all_flushes_cache_and_redirects_back() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $page_file = HSP_SMART_CACHE_PATH . '/pages/admin-clear-all-test.html';
        file_put_contents( $page_file, 'cached page' );
        $this->assertFileExists( $page_file );

        $_GET['_wpnonce'] = wp_create_nonce( 'hsp_cache_clear_all' );
        $_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
        $_GET['hsp_return'] = rawurlencode( home_url( '/return-clear-all/' ) );

        $redirect_to = '';
        $capture_redirect = static function( $location ) use ( &$redirect_to ) {
            $redirect_to = $location;
            return $location;
        };
        add_filter( 'wp_redirect', $capture_redirect );

        try {
            HSP_Smart_Cache_Admin::handle_clear_all();
        } finally {
            remove_filter( 'wp_redirect', $capture_redirect );
        }

        $this->assertFileDoesNotExist( $page_file );
        $this->assertSame( home_url( '/return-clear-all/' ), $redirect_to );

        wp_set_current_user( 0 );
    }

    public function test_handle_clear_current_removes_requested_url_cache() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $target_url = home_url( '/clear-current-target/' );
        $ref = new ReflectionClass( 'HSP_Smart_Cache_Page' );
        $method = $ref->getMethod( 'get_cache_file_path_for_url' );
        $method->setAccessible( true );
        $cache_file = $method->invoke( null, $target_url );
        file_put_contents( $cache_file, 'cached' );
        $this->assertFileExists( $cache_file );

        $_GET['_wpnonce'] = wp_create_nonce( 'hsp_cache_clear_current' );
        $_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
        $_GET['hsp_return'] = rawurlencode( $target_url );

        HSP_Smart_Cache_Admin::handle_clear_current();

        $this->assertFileDoesNotExist( $cache_file );
        wp_set_current_user( 0 );
    }

    public function test_handle_run_preload_sets_result_transient() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $sitemap_url = home_url( '/admin-preload-sitemap.xml' );
        update_option(
            HSP_Smart_Cache_Settings::OPTION_KEY,
            array_merge(
                HSP_Smart_Cache_Settings::defaults(),
                array(
                    'page_cache'          => true,
                    'preload_enabled'     => true,
                    'preload_sitemap_url' => $sitemap_url,
                    'preload_limit'       => 1,
                )
            )
        );

        $_GET['_wpnonce'] = wp_create_nonce( 'hsp_cache_run_preload' );
        $_REQUEST['_wpnonce'] = $_GET['_wpnonce'];

        $mock_http = static function( $preempt, $args, $url ) use ( $sitemap_url ) {
            if ( $url === $sitemap_url ) {
                return array(
                    'headers'  => array(),
                    'body'     => '<urlset><url><loc>' . esc_url_raw( home_url( '/preload-one/' ) ) . '</loc></url></urlset>',
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'cookies'  => array(),
                    'filename' => null,
                );
            }

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
            HSP_Smart_Cache_Admin::handle_run_preload();
        } finally {
            remove_filter( 'pre_http_request', $mock_http, 10 );
        }

        $result = get_transient( 'hsp_cache_preload_result' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'ok', $result );
        $this->assertArrayHasKey( 'count', $result );

        wp_set_current_user( 0 );
    }
}
