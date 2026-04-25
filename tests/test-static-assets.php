<?php

class HSPSC_Static_Assets_FS_Mock {
    public $existing = array();
    public $writable = array();
    public $contents = array();
    public $writes = array();

    public function exists( $path ) {
        return ! empty( $this->existing[ $path ] );
    }

    public function is_writable( $path ) {
        return ! empty( $this->writable[ $path ] );
    }

    public function get_contents( $path ) {
        return isset( $this->contents[ $path ] ) ? $this->contents[ $path ] : '';
    }

    public function put_contents( $path, $contents ) {
        $this->writes[] = array( 'path' => $path, 'contents' => $contents );
        $this->contents[ $path ] = $contents;
        $this->existing[ $path ] = true;
        return true;
    }
}

class HSPSC_Static_Assets_Test extends WP_UnitTestCase {
    private $original_wp_filesystem;

    public function set_up(): void {
        parent::set_up();
        global $wp_filesystem;
        $this->original_wp_filesystem = $wp_filesystem;
    }

    public function tear_down(): void {
        global $wp_filesystem;
        $wp_filesystem = $this->original_wp_filesystem;
        parent::tear_down();
    }

    public function test_apply_rules_skips_when_auto_write_disabled() {
        global $wp_filesystem;
        $wp_filesystem = new HSPSC_Static_Assets_FS_Mock();

        HSPSC_Static_Assets::apply_rules(
            null,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'static_asset_cache'      => true,
                    'static_asset_auto_write' => false,
                )
            )
        );

        $this->assertCount( 0, $wp_filesystem->writes );
    }

    public function test_apply_rules_writes_htaccess_block_when_enabled() {
        global $wp_filesystem;
        $wp_filesystem = new HSPSC_Static_Assets_FS_Mock();

        $htaccess = ABSPATH . '.htaccess';
        $wp_filesystem->existing[ $htaccess ] = true;
        $wp_filesystem->contents[ $htaccess ] = '# Existing rules';

        HSPSC_Static_Assets::apply_rules(
            null,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'static_asset_cache'       => true,
                    'static_asset_auto_write'  => true,
                    'static_asset_ttl'         => 1200,
                    'static_asset_immutable'   => true,
                    'static_asset_compression' => true,
                )
            )
        );

        $this->assertCount( 1, $wp_filesystem->writes );
        $this->assertStringContainsString( HSPSC_Static_Assets::HTACCESS_BEGIN, $wp_filesystem->writes[0]['contents'] );
        $this->assertStringContainsString( 'max-age=1200', $wp_filesystem->writes[0]['contents'] );
        $this->assertStringContainsString( 'avif', $wp_filesystem->writes[0]['contents'] );
        $this->assertStringContainsString( 'mp4', $wp_filesystem->writes[0]['contents'] );
        $this->assertStringContainsString( 'mp3', $wp_filesystem->writes[0]['contents'] );
    }

    public function test_apply_rules_removes_block_when_disabled() {
        global $wp_filesystem;
        $wp_filesystem = new HSPSC_Static_Assets_FS_Mock();

        $htaccess = ABSPATH . '.htaccess';
        $wp_filesystem->existing[ $htaccess ] = true;
        $wp_filesystem->contents[ $htaccess ] = "# Existing rules\n"
            . HSPSC_Static_Assets::HTACCESS_BEGIN . "\n"
            . "Header set Cache-Control \"public, max-age=600\"\n"
            . HSPSC_Static_Assets::HTACCESS_END . "\n";

        HSPSC_Static_Assets::apply_rules(
            null,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'static_asset_cache'      => false,
                    'static_asset_auto_write' => true,
                )
            )
        );

        $this->assertCount( 1, $wp_filesystem->writes );
        $this->assertStringNotContainsString( HSPSC_Static_Assets::HTACCESS_BEGIN, $wp_filesystem->writes[0]['contents'] );
    }

    public function test_cacheable_asset_url_detects_modern_media_formats() {
        $this->assertTrue( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/uploads/photo.avif?ver=1' ) ) );
        $this->assertTrue( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/uploads/movie.webm' ) ) );
        $this->assertTrue( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/uploads/audio.opus' ) ) );
        $this->assertTrue( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/uploads/icon.svg' ) ) );
        $this->assertFalse( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/api/data.json' ) ) );
    }
}
