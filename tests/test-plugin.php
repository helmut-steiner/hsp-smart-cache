<?php

class HSPSC_Plugin_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        HSPSC_Utils::ensure_cache_dirs();
    }

    public function test_upgrader_process_complete_flushes_caches_on_core_plugin_theme_updates() {
        $page_file  = HSPSC_PATH . '/pages/test-page.html';
        $asset_file = HSPSC_PATH . '/assets/test-asset.min.js';
        $object_file = HSPSC_PATH . '/object/test-object.cache';

        file_put_contents( $page_file, 'cached page' );
        file_put_contents( $asset_file, 'cached asset' );
        file_put_contents( $object_file, 'cached object' );

        $this->assertFileExists( $page_file );
        $this->assertFileExists( $asset_file );
        $this->assertFileExists( $object_file );

        HSPSC_Plugin::handle_upgrader_process_complete( null, array(
            'action' => 'update',
            'type'   => 'plugin',
        ) );

        $this->assertFileDoesNotExist( $page_file );
        $this->assertFileDoesNotExist( $asset_file );
        $this->assertFileDoesNotExist( $object_file );

        file_put_contents( $page_file, 'cached page' );
        file_put_contents( $asset_file, 'cached asset' );

        HSPSC_Plugin::handle_upgrader_process_complete( null, array(
            'action' => 'update',
            'type'   => 'theme',
        ) );

        $this->assertFileDoesNotExist( $page_file );
        $this->assertFileDoesNotExist( $asset_file );

        file_put_contents( $page_file, 'cached page' );

        HSPSC_Plugin::handle_upgrader_process_complete( null, array(
            'action' => 'update',
            'type'   => 'core',
        ) );

        $this->assertFileDoesNotExist( $page_file );
    }

    public function test_upgrader_process_complete_ignores_non_update_actions() {
        $page_file = HSPSC_PATH . '/pages/test-page.html';
        file_put_contents( $page_file, 'cached page' );

        HSPSC_Plugin::handle_upgrader_process_complete( null, array(
            'action' => 'install',
            'type'   => 'plugin',
        ) );

        $this->assertFileExists( $page_file );

        wp_delete_file( $page_file );
    }

    public function test_render_affecting_option_changes_flush_page_and_asset_caches() {
        $page_file  = HSPSC_PATH . '/pages/render-option-page.html';
        $asset_file = HSPSC_PATH . '/assets/render-option-asset.min.css';

        file_put_contents( $page_file, 'cached page' );
        file_put_contents( $asset_file, 'cached asset' );

        HSPSC_Plugin::handle_updated_option( 'bricks_global_settings', array(), array() );

        $this->assertFileDoesNotExist( $page_file );
        $this->assertFileDoesNotExist( $asset_file );
    }

    public function test_non_render_affecting_option_does_not_flush_render_caches() {
        $page_file  = HSPSC_PATH . '/pages/non-render-option-page.html';
        $asset_file = HSPSC_PATH . '/assets/non-render-option-asset.min.css';

        file_put_contents( $page_file, 'cached page' );
        file_put_contents( $asset_file, 'cached asset' );

        HSPSC_Plugin::handle_updated_option( 'unrelated_option', 'old', 'new' );

        $this->assertFileExists( $page_file );
        $this->assertFileExists( $asset_file );

        wp_delete_file( $page_file );
        wp_delete_file( $asset_file );
    }

    public function test_init_registers_expected_hooks() {
        HSPSC_Plugin::init();

        $this->assertNotFalse( has_action( 'send_headers', array( 'HSPSC_Minify', 'maybe_send_asset_headers' ) ) );
        $this->assertNotFalse( has_filter( 'robots_txt', array( 'HSPSC_Plugin', 'filter_robots_txt' ) ) );
        $this->assertNotFalse( has_action( 'save_post', array( 'HSPSC_Plugin', 'handle_post_change' ) ) );
        $this->assertNotFalse( has_action( 'deleted_post', array( 'HSPSC_Plugin', 'handle_post_delete' ) ) );
        $this->assertNotFalse( has_action( 'comment_post', array( 'HSPSC_Plugin', 'handle_comment_change' ) ) );
        $this->assertNotFalse( has_action( 'updated_option', array( 'HSPSC_Plugin', 'handle_updated_option' ) ) );
        $this->assertNotFalse( has_action( 'added_option', array( 'HSPSC_Plugin', 'handle_added_option' ) ) );
        $this->assertNotFalse( has_action( 'deleted_option', array( 'HSPSC_Plugin', 'handle_deleted_option' ) ) );
        $this->assertNotFalse( has_action( 'bricks/generate_css_file', array( 'HSPSC_Plugin', 'flush_render_caches' ) ) );
    }

    public function test_cache_schema_upgrade_purges_legacy_pages_and_assets_only_once() {
        $page = HSPSC_PATH . '/pages/legacy-schema.html';
        $asset = HSPSC_PATH . '/assets/legacy-schema.min.js';
        $object = HSPSC_PATH . '/object/schema-preserved.txt';
        file_put_contents( $page, 'legacy page' );
        file_put_contents( $asset, 'legacy asset' );
        file_put_contents( $object, 'object' );
        delete_option( 'hspsc_cache_schema' );
        try {
            HSPSC_Plugin::maybe_upgrade_cache_schema();
            $this->assertFileDoesNotExist( $page );
            $this->assertFileDoesNotExist( $asset );
            $this->assertFileExists( $object );
            $this->assertSame( HSPSC_Plugin::CACHE_SCHEMA, (int) get_option( 'hspsc_cache_schema' ) );

            file_put_contents( $page, 'fresh page' );
            HSPSC_Plugin::maybe_upgrade_cache_schema();
            $this->assertFileExists( $page );
        } finally {
            wp_delete_file( $page );
            wp_delete_file( $asset );
            wp_delete_file( $object );
        }
    }
}
