<?php

require_once dirname( __DIR__ ) . '/dropins/object-cache.php';

class HSPSC_Test_Object_Cache extends HSPSC_File_Object_Cache {
    public function file_path_for( $key, $group = 'default' ) {
        return $this->get_file_path( $key, $group );
    }
}

class HSPSC_Object_Test extends WP_UnitTestCase {
    private $target_file;
    private $target_backup;
    private $had_existing_target;

    public function set_up(): void {
        parent::set_up();

        $this->target_file = WP_CONTENT_DIR . '/object-cache.php';
        $this->had_existing_target = file_exists( $this->target_file );
        $this->target_backup = $this->had_existing_target ? file_get_contents( $this->target_file ) : null;
    }

    public function tear_down(): void {
        if ( $this->had_existing_target ) {
            if ( false !== $this->target_backup ) {
                file_put_contents( $this->target_file, $this->target_backup );
            }
        } elseif ( file_exists( $this->target_file ) ) {
            wp_delete_file( $this->target_file );
        }

        HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/object' );

        parent::tear_down();
    }

    public function test_sync_dropin_installs_when_enabled() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'object_cache' => true ) )
        );

        HSPSC_Object::sync_dropin();

        $this->assertFileExists( $this->target_file );
        $contents = file_get_contents( $this->target_file );
        $this->assertStringContainsString( 'HSPSC_File_Object_Cache', (string) $contents );
    }

    public function test_sync_dropin_removes_when_disabled() {
        HSPSC_Object::install_dropin();
        $this->assertFileExists( $this->target_file );

        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'object_cache' => false ) )
        );

        HSPSC_Object::sync_dropin();

        $this->assertFileDoesNotExist( $this->target_file );
    }

    public function test_sync_dropin_preserves_other_plugin_dropin() {
        file_put_contents( $this->target_file, 'temporary drop-in from another plugin' );

        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'object_cache' => false ) )
        );

        HSPSC_Object::sync_dropin();

        $this->assertFileExists( $this->target_file );
        $this->assertSame( 'temporary drop-in from another plugin', file_get_contents( $this->target_file ) );
    }

    public function test_install_dropin_does_not_overwrite_other_plugin_dropin() {
        file_put_contents( $this->target_file, 'temporary drop-in from another plugin' );

        $installed = HSPSC_Object::install_dropin();

        $this->assertFalse( $installed );
        $this->assertSame( 'temporary drop-in from another plugin', file_get_contents( $this->target_file ) );
    }

    public function test_install_and_remove_dropin_methods() {
        HSPSC_Object::install_dropin();
        $this->assertFileExists( $this->target_file );

        HSPSC_Object::remove_dropin();
        $this->assertFileDoesNotExist( $this->target_file );
    }

    public function test_set_applies_default_ttl_to_unbounded_writes() {
        $cache = new HSPSC_Test_Object_Cache();
        $key = 'ttl-default-test';

        $cache->set( $key, 'value', 'default', 0 );

        $file = $cache->file_path_for( $key );
        $this->assertFileExists( $file );

        $payload = @unserialize( file_get_contents( $file ) );
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'expire', $payload );
        $this->assertGreaterThan( time(), (int) $payload['expire'] );
        $this->assertLessThanOrEqual( time() + HSPSC_Settings::get( 'object_cache_default_ttl', 604800 ) + 5, (int) $payload['expire'] );
    }

    public function test_set_caps_long_ttl_to_maximum() {
        $cache = new HSPSC_Test_Object_Cache();
        $key = 'ttl-cap-test';

        $cache->set( $key, 'value', 'default', YEAR_IN_SECONDS * 5 );

        $file = $cache->file_path_for( $key );
        $this->assertFileExists( $file );

        $payload = @unserialize( file_get_contents( $file ) );
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'expire', $payload );
        $this->assertLessThanOrEqual( time() + HSPSC_Settings::get( 'object_cache_max_ttl', 2592000 ) + 5, (int) $payload['expire'] );
    }

    public function test_cleanup_expired_cache_removes_legacy_unbounded_files() {
        $cache = new HSPSC_Test_Object_Cache();
        $key = 'ttl-legacy-test';
        $file = $cache->file_path_for( $key );

        wp_mkdir_p( dirname( $file ) );
        file_put_contents( $file, serialize( array( 'expire' => 0, 'value' => 'legacy' ) ) );

        $legacy_age = HSPSC_Settings::get( 'object_cache_max_ttl', 2592000 ) + 60;
        touch( $file, time() - $legacy_age );

        $deleted = $cache->cleanup_expired_cache();

        $this->assertGreaterThanOrEqual( 1, $deleted );
        $this->assertFileDoesNotExist( $file );
    }
}
