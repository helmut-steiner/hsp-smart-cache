<?php

class HSP_Smart_Cache_Static_Assets_FS_Mock {
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

class HSP_Smart_Cache_Static_Assets_Test extends WP_UnitTestCase {
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
        $wp_filesystem = new HSP_Smart_Cache_Static_Assets_FS_Mock();

        HSP_Smart_Cache_Static_Assets::apply_rules(
            null,
            array_merge(
                HSP_Smart_Cache_Settings::defaults(),
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
        $wp_filesystem = new HSP_Smart_Cache_Static_Assets_FS_Mock();

        $htaccess = ABSPATH . '.htaccess';
        $wp_filesystem->existing[ $htaccess ] = true;
        $wp_filesystem->contents[ $htaccess ] = '# Existing rules';

        HSP_Smart_Cache_Static_Assets::apply_rules(
            null,
            array_merge(
                HSP_Smart_Cache_Settings::defaults(),
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
        $this->assertStringContainsString( HSP_Smart_Cache_Static_Assets::HTACCESS_BEGIN, $wp_filesystem->writes[0]['contents'] );
        $this->assertStringContainsString( 'max-age=1200', $wp_filesystem->writes[0]['contents'] );
    }

    public function test_apply_rules_removes_block_when_disabled() {
        global $wp_filesystem;
        $wp_filesystem = new HSP_Smart_Cache_Static_Assets_FS_Mock();

        $htaccess = ABSPATH . '.htaccess';
        $wp_filesystem->existing[ $htaccess ] = true;
        $wp_filesystem->contents[ $htaccess ] = "# Existing rules\n"
            . HSP_Smart_Cache_Static_Assets::HTACCESS_BEGIN . "\n"
            . "Header set Cache-Control \"public, max-age=600\"\n"
            . HSP_Smart_Cache_Static_Assets::HTACCESS_END . "\n";

        HSP_Smart_Cache_Static_Assets::apply_rules(
            null,
            array_merge(
                HSP_Smart_Cache_Settings::defaults(),
                array(
                    'static_asset_cache'      => false,
                    'static_asset_auto_write' => true,
                )
            )
        );

        $this->assertCount( 1, $wp_filesystem->writes );
        $this->assertStringNotContainsString( HSP_Smart_Cache_Static_Assets::HTACCESS_BEGIN, $wp_filesystem->writes[0]['contents'] );
    }
}
