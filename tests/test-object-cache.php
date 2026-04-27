<?php

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
}
