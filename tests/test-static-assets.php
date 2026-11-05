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

    public function test_mutable_assets_revalidate_before_versioned_asset_exception() {
        $rules = HSPSC_Static_Assets::get_htaccess_rules( 1200, true );
        $mutable_header = 'Header set Cache-Control "public, no-cache, max-age=0, must-revalidate"';
        $versioned_header = 'Header set Cache-Control "public, max-age=1200, immutable" "expr=';

        $this->assertStringContainsString( $mutable_header, $rules );
        $this->assertStringContainsString( $versioned_header, $rules );
        $this->assertLessThan( strpos( $rules, $versioned_header ), strpos( $rules, $mutable_header ) );
        $this->assertStringContainsString( '&& -f %{REQUEST_FILENAME}', $rules );
        $this->assertStringNotContainsString( 'QUERY_STRING', $rules );
    }

    public function test_versioned_asset_rule_uses_the_configured_cache_url() {
        $rules = HSPSC_Static_Assets::get_htaccess_rules( 1200, false );
        $pattern = HSPSC_Static_Assets_Rules_Fixture::generated_asset_pattern( HSPSC_URL );

        $this->assertStringContainsString( 'm#' . $pattern . '#', $rules );
        $this->assertStringContainsString( 'max-age=1200', $rules );
        $this->assertStringNotContainsString( 'immutable', $rules );
    }

    /**
     * Long browser lifetimes require both the owned path and generated filename.
     *
     * @dataProvider generated_asset_paths
     */
    public function test_generated_asset_rule_matches_only_owned_versioned_files( $path, $matches ) {
        $pattern = HSPSC_Static_Assets_Rules_Fixture::generated_asset_pattern( 'https://example.org/site/content/cache.v2+/hspsc' );

        $this->assertSame( $matches, 1 === preg_match( '#' . $pattern . '#', $path ) );
    }

    public function generated_asset_paths() {
        $hash = str_repeat( 'a1', 16 );
        $prefix = '/site/content/cache.v2+/hspsc/assets/';

        return array(
            'generated CSS'         => array( $prefix . $hash . '.min.css', true ),
            'generated JS'          => array( $prefix . $hash . '.min.js', true ),
            'unversioned CSS'       => array( $prefix . 'styles.css', false ),
            'unversioned minified'  => array( $prefix . 'styles.min.css', false ),
            'unowned directory'     => array( '/site/content/uploads/' . $hash . '.min.css', false ),
            'another installation'  => array( '/content/cache.v2+/hspsc/assets/' . $hash . '.min.css', false ),
            'regex metacharacters'  => array( '/site/content/cacheXv22/hspsc/assets/' . $hash . '.min.css', false ),
            'nested directory'      => array( $prefix . 'nested/' . $hash . '.min.css', false ),
            'image'                 => array( $prefix . $hash . '.min.png', false ),
            'extra path suffix'     => array( $prefix . $hash . '.min.css/extra', false ),
            'invalid hash'          => array( $prefix . str_repeat( 'z', 32 ) . '.min.css', false ),
            'different hash length' => array( $prefix . str_repeat( 'a', 31 ) . '.min.css', false ),
        );
    }

    public function test_unsafe_configured_paths_cannot_inject_apache_expressions() {
        foreach ( array( '/cache/"quoted', '/cache/with space', '/cache/\\backslash', '/cache/%{REQUEST_URI}' ) as $path ) {
            $this->assertSame( '', HSPSC_Static_Assets_Rules_Fixture::generated_asset_pattern( 'https://example.org' . $path ) );
        }
    }

    public function test_updating_rules_replaces_owned_old_policy_and_preserves_other_blocks() {
        global $wp_filesystem;
        $wp_filesystem = new HSPSC_Static_Assets_FS_Mock();
        $htaccess = ABSPATH . '.htaccess';
        $wp_filesystem->existing[ $htaccess ] = true;
        $wp_filesystem->contents[ $htaccess ] = "# Before HSP\n"
            . HSPSC_Static_Assets::HTACCESS_BEGIN . "\n"
            . "Header set Cache-Control \"public, max-age=999999\"\n"
            . HSPSC_Static_Assets::HTACCESS_END . "\n# After HSP\n";

        HSPSC_Static_Assets::apply_rules(
            null,
            array_merge( HSPSC_Settings::defaults(), array( 'static_asset_auto_write' => true ) )
        );

        $updated = $wp_filesystem->contents[ $htaccess ];
        $this->assertSame( 1, substr_count( $updated, HSPSC_Static_Assets::HTACCESS_BEGIN ) );
        $this->assertStringContainsString( '# Before HSP', $updated );
        $this->assertStringContainsString( '# After HSP', $updated );
        $this->assertStringNotContainsString( 'max-age=999999', $updated );
        $this->assertStringContainsString( 'no-cache, max-age=0, must-revalidate', $updated );
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

    /**
     * Upgrades refresh managed rules while preserving the manual-write opt-out.
     *
     * @dataProvider schema_upgrade_write_preferences
     */
    public function test_schema_upgrade_respects_static_rule_write_opt_in( $auto_write ) {
        $original_settings = get_option( HSPSC_Settings::OPTION_KEY );
        $original_schema   = get_option( 'hspsc_cache_schema' );
        $htaccess          = ABSPATH . '.htaccess';
        $original_htaccess = file_exists( $htaccess ) ? file_get_contents( $htaccess ) : null;
        $foreign_block     = "# BEGIN Another Plugin\nHeader set X-Other-Plugin preserved\n# END Another Plugin\n";
        $old_rules         = $foreign_block . HSPSC_Static_Assets::HTACCESS_BEGIN . "\n"
            . "Header set Cache-Control \"public, max-age=999999, immutable\"\n"
            . HSPSC_Static_Assets::HTACCESS_END . "\n";

        try {
            update_option(
                HSPSC_Settings::OPTION_KEY,
                array_merge(
                    HSPSC_Settings::defaults(),
                    array(
                        'static_asset_auto_write' => $auto_write,
                        'static_asset_ttl'        => 1200,
                    )
                )
            );
            HSPSC_Settings::reset_cache();
            // Settings updates apply today's rules; restore the pre-upgrade file afterward.
            $this->assertSame( strlen( $old_rules ), file_put_contents( $htaccess, $old_rules ) );
            update_option( 'hspsc_cache_schema', 4 );

            HSPSC_Plugin::maybe_upgrade_cache_schema();

            $updated = file_get_contents( $htaccess );
            $this->assertSame( HSPSC_Plugin::CACHE_SCHEMA, (int) get_option( 'hspsc_cache_schema' ) );
            if ( $auto_write ) {
                $this->assertStringContainsString( $foreign_block, $updated );
                $this->assertSame( 1, substr_count( $updated, HSPSC_Static_Assets::HTACCESS_BEGIN ) );
                $this->assertStringNotContainsString( 'max-age=999999', $updated );
                $this->assertStringContainsString( 'no-cache, max-age=0, must-revalidate', $updated );
                $this->assertStringContainsString( 'max-age=1200, immutable', $updated );
            } else {
                $this->assertSame( $old_rules, $updated );
            }
        } finally {
            if ( false === $original_settings ) {
                delete_option( HSPSC_Settings::OPTION_KEY );
            } else {
                update_option( HSPSC_Settings::OPTION_KEY, $original_settings );
            }
            HSPSC_Settings::reset_cache();
            if ( false === $original_schema ) {
                delete_option( 'hspsc_cache_schema' );
            } else {
                update_option( 'hspsc_cache_schema', $original_schema );
            }
            // Restoring settings can write server rules too, so restore the file last.
            if ( null === $original_htaccess ) {
                wp_delete_file( $htaccess );
            } else {
                file_put_contents( $htaccess, $original_htaccess );
            }
        }
    }

    public function schema_upgrade_write_preferences() {
        return array(
            'managed rules' => array( true ),
            'manual rules'  => array( false ),
        );
    }

    public function test_cacheable_asset_url_detects_modern_media_formats() {
        $this->assertTrue( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/uploads/photo.avif?ver=1' ) ) );
        $this->assertTrue( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/uploads/movie.webm' ) ) );
        $this->assertTrue( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/uploads/audio.opus' ) ) );
        $this->assertTrue( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/uploads/icon.svg' ) ) );
        $this->assertFalse( HSPSC_Static_Assets::is_cacheable_asset_url( content_url( '/api/data.json' ) ) );
    }
}

class HSPSC_Static_Assets_Rules_Fixture extends HSPSC_Static_Assets {
    public static function generated_asset_pattern( $cache_url ) {
        return parent::get_generated_asset_path_pattern( $cache_url );
    }
}
