<?php

class HSP_Smart_Cache_Plugin_Test extends WP_UnitTestCase {
    protected function set_up(): void {
        parent::set_up();
        HSP_Smart_Cache_Utils::ensure_cache_dirs();
    }

    public function test_upgrader_process_complete_flushes_caches_on_core_plugin_theme_updates() {
        $page_file  = HSP_SMART_CACHE_PATH . '/pages/test-page.html';
        $asset_file = HSP_SMART_CACHE_PATH . '/assets/test-asset.min.js';
        $object_file = HSP_SMART_CACHE_PATH . '/object/test-object.cache';

        file_put_contents( $page_file, 'cached page' );
        file_put_contents( $asset_file, 'cached asset' );
        file_put_contents( $object_file, 'cached object' );

        $this->assertFileExists( $page_file );
        $this->assertFileExists( $asset_file );
        $this->assertFileExists( $object_file );

        HSP_Smart_Cache_Plugin::handle_upgrader_process_complete( null, array(
            'action' => 'update',
            'type'   => 'plugin',
        ) );

        $this->assertFileDoesNotExist( $page_file );
        $this->assertFileDoesNotExist( $asset_file );
        $this->assertFileDoesNotExist( $object_file );

        file_put_contents( $page_file, 'cached page' );
        file_put_contents( $asset_file, 'cached asset' );

        HSP_Smart_Cache_Plugin::handle_upgrader_process_complete( null, array(
            'action' => 'update',
            'type'   => 'theme',
        ) );

        $this->assertFileDoesNotExist( $page_file );
        $this->assertFileDoesNotExist( $asset_file );

        file_put_contents( $page_file, 'cached page' );

        HSP_Smart_Cache_Plugin::handle_upgrader_process_complete( null, array(
            'action' => 'update',
            'type'   => 'core',
        ) );

        $this->assertFileDoesNotExist( $page_file );
    }

    public function test_upgrader_process_complete_ignores_non_update_actions() {
        $page_file = HSP_SMART_CACHE_PATH . '/pages/test-page.html';
        file_put_contents( $page_file, 'cached page' );

        HSP_Smart_Cache_Plugin::handle_upgrader_process_complete( null, array(
            'action' => 'install',
            'type'   => 'plugin',
        ) );

        $this->assertFileExists( $page_file );

        wp_delete_file( $page_file );
    }

    public function test_init_registers_expected_hooks() {
        HSP_Smart_Cache_Plugin::init();

        $this->assertNotFalse( has_action( 'send_headers', array( 'HSP_Smart_Cache_Minify', 'maybe_send_asset_headers' ) ) );
        $this->assertNotFalse( has_filter( 'robots_txt', array( 'HSP_Smart_Cache_Plugin', 'filter_robots_txt' ) ) );
        $this->assertNotFalse( has_action( 'save_post', array( 'HSP_Smart_Cache_Plugin', 'handle_post_change' ) ) );
        $this->assertNotFalse( has_action( 'deleted_post', array( 'HSP_Smart_Cache_Plugin', 'handle_post_delete' ) ) );
        $this->assertNotFalse( has_action( 'comment_post', array( 'HSP_Smart_Cache_Plugin', 'handle_comment_change' ) ) );
    }
}
