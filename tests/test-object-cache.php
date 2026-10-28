<?php

require_once dirname( __DIR__ ) . '/dropins/object-cache.php';

class HSPSC_Test_Object_Cache extends HSPSC_File_Object_Cache {
    protected function get_signing_key() {
        return 'hspsc-object-cache-regression-test-secret';
    }

    public function file_path_for( $key, $group = 'default' ) {
        return $this->get_file_path( $key, $group );
    }

    public function cache_key_for( $key, $group = 'default' ) {
        return $this->cache_key( $key, $group );
    }

    public function payload_for( $key, $group = 'default' ) {
        $file = $this->get_file_path( $key, $group );
        return $this->decode_payload( file_get_contents( $file ), $file );
    }

    public function set_expired( $key, $value, $group = 'default' ) {
        return $this->store( $key, $value, $group, time() - 1 );
    }
}

class HSPSC_Test_Unkeyed_Object_Cache extends HSPSC_Test_Object_Cache {
    protected function get_signing_key() {
        return '';
    }
}

class HSPSC_Test_Derived_Object_Cache extends HSPSC_File_Object_Cache {
    public $derivations = 0;
    public $test_key = 'hspsc-object-cache-regression-test-secret';

    protected function derive_signing_key() {
        ++$this->derivations;
        return $this->test_key;
    }
}

class HSPSC_Test_GC_Object_Cache extends HSPSC_Test_Object_Cache {
    public $cleanups = 0;
    public $marker_writes = 0;
    public $fail_marker = false;

    public function cleanup_expired_cache() {
        ++$this->cleanups;
        return parent::cleanup_expired_cache();
    }

    protected function fs_put_contents( $path, $contents ) {
        if ( $path === $this->cleanup_lock_file ) {
            ++$this->marker_writes;
            if ( $this->fail_marker ) {
                return false;
            }
        }
        return parent::fs_put_contents( $path, $contents );
    }
}

class HSPSC_Test_Settings_Bootstrap_Object_Cache extends HSPSC_Test_Object_Cache {
    public $settings_depth = 0;
    public $max_settings_depth = 0;

    protected function get_setting( $key, $default ) {
        ++$this->settings_depth;
        $this->max_settings_depth = max( $this->max_settings_depth, $this->settings_depth );
        if ( $this->settings_depth > 8 ) {
            throw new RuntimeException( 'Object-cache settings recursively loaded alloptions.' );
        }
        try {
            return parent::get_setting( $key, $default );
        } finally {
            --$this->settings_depth;
        }
    }
}

class HSPSC_Test_Replaced_Object_Cache extends HSPSC_Test_Object_Cache {
    public $before_delete;

    protected function delete_if_unchanged( $file, $observed ) {
        if ( is_callable( $this->before_delete ) ) {
            $callback = $this->before_delete;
            $this->before_delete = null;
            $callback();
        }
        return parent::delete_if_unchanged( $file, $observed );
    }
}

class HSPSC_Test_Cached_Object {
    public static $wakeups = 0;
    public $value = 'original';

    public function __wakeup() {
        self::$wakeups++;
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

    public function test_signing_key_is_derived_once_for_keyed_and_memory_only_instances() {
        $cache = new HSPSC_Test_Derived_Object_Cache();
        $this->assertTrue( $cache->set( 'derived-key', 'signed value' ) );
        $this->assertSame( 'signed value', $cache->get( 'derived-key', 'default', true ) );
        $this->assertSame( 'signed value', $cache->get( 'derived-key' ) );
        $this->assertSame( 1, $cache->derivations );

        $unkeyed = new HSPSC_Test_Derived_Object_Cache();
        $unkeyed->test_key = '';
        $this->assertTrue( $unkeyed->set( 'no-derived-key', false ) );
        $this->assertFalse( $unkeyed->get( 'no-derived-key', 'default', true, $found ) );
        $this->assertTrue( $found );
        $this->assertSame( 1, $unkeyed->derivations );
    }

    public function test_real_option_loading_is_bounded_after_flushing_the_global_file_cache() {
        global $wp_object_cache;
        $original_cache = $wp_object_cache;
        $expected = array_merge( HSPSC_Settings::defaults(), array( 'object_cache_default_ttl' => 120, 'object_cache_max_ttl' => 240 ) );
        update_option( HSPSC_Settings::OPTION_KEY, $expected );
        $cache = new HSPSC_Test_Settings_Bootstrap_Object_Cache();
        try {
            $wp_object_cache = $cache;
            for ( $pass = 0; $pass < 2; ++$pass ) {
                wp_cache_flush();
                HSPSC_Settings::reset_cache();
                $this->assertSame( $expected, HSPSC_Settings::get_all() );
                $this->assertLessThanOrEqual( 2, $cache->max_settings_depth );
                $this->assertSame( 0, $cache->settings_depth );
                $this->assertTrue( wp_cache_set( 'settings-bootstrap-value', 'value' ) );
                $this->assertSame( 'value', wp_cache_get( 'settings-bootstrap-value', 'default', true ) );
                $payload = $cache->payload_for( 'settings-bootstrap-value' );
                $this->assertGreaterThan( time(), $payload['expire'] );
                $this->assertLessThanOrEqual( time() + 120, $payload['expire'] );
            }
        } finally {
            $wp_object_cache = $original_cache;
            HSPSC_Settings::reset_cache();
        }
    }

    public function test_gc_runs_once_per_interval_and_failed_marker_writes_retry_without_scanning() {
        $marker = HSPSC_PATH . '/object/.gc-lock';
        wp_delete_file( $marker );
        $cache = new HSPSC_Test_GC_Object_Cache();
        for ( $i = 0; $i < 10; ++$i ) {
            $this->assertTrue( $cache->set( 'gc-single-' . $i, 'value' ) );
        }
        $this->assertSame( 1, $cache->cleanups );
        $this->assertSame( 1, $cache->marker_writes );

        wp_delete_file( $marker );
        $retry = new HSPSC_Test_GC_Object_Cache();
        $retry->fail_marker = true;
        $this->assertTrue( $retry->set( 'gc-marker-failed', 'value' ) );
        $this->assertSame( 0, $retry->cleanups );
        $retry->fail_marker = false;
        $this->assertTrue( $retry->set( 'gc-marker-retried', 'value' ) );
        $this->assertTrue( $retry->set( 'gc-marker-reused', 'value' ) );
        $this->assertSame( 1, $retry->cleanups );
        $this->assertSame( 2, $retry->marker_writes );
    }

    public function test_disabled_idle_initialization_skips_completed_migration_but_explicit_sync_purges() {
        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
        HSPSC_Object::remove_dropin();
        $this->assertTrue( HSPSC_Object::secure_legacy_cache() );
        $this->assertSame( HSPSC_Object::SECURITY_REVISION, (int) get_option( HSPSC_Object::SECURITY_REVISION_OPTION ) );
        $probe = HSPSC_PATH . '/object/idle-scan-probe.cache';
        // A harmless probe detects a repeated tree walk without storing private data.
        file_put_contents( $probe, 'migration scan probe' );

        HSPSC_Object::init();
        $this->assertFileExists( $probe );
        HSPSC_Object::sync_dropin();
        $this->assertFileDoesNotExist( $probe );
    }

    public function test_cache_flush_preserves_storage_protection_without_writing_a_migration_marker() {
        delete_option( HSPSC_Object::SECURITY_REVISION_OPTION );
        $legacy = HSPSC_PATH . '/object/flush-storage-only.cache';
        file_put_contents( $legacy, 'legacy probe' );

        HSPSC_Object::flush_cache();

        $this->assertFileDoesNotExist( $legacy );
        $this->assertFalse( get_option( HSPSC_Object::SECURITY_REVISION_OPTION, false ) );
        $this->assertFileExists( HSPSC_PATH . '/object/index.php' );
        $this->assertFileExists( HSPSC_PATH . '/object/.htaccess' );
        $this->assertFileExists( HSPSC_PATH . '/object/web.config' );
    }

    public function test_failed_legacy_cleanup_does_not_record_a_completed_migration() {
        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
        HSPSC_Object::remove_dropin();
        $legacy = HSPSC_PATH . '/object/migration-retry.cache';
        file_put_contents( $legacy, 'legacy probe' );
        $prevent_delete = static function ( $file ) use ( $legacy ) {
            return $file === $legacy ? '' : $file;
        };
        add_filter( 'wp_delete_file', $prevent_delete );
        try {
            $this->assertFalse( HSPSC_Object::secure_legacy_cache() );
            $this->assertSame( 0, (int) get_option( HSPSC_Object::SECURITY_REVISION_OPTION, 0 ) );
            $this->assertFileExists( $legacy );
        } finally {
            remove_filter( 'wp_delete_file', $prevent_delete );
        }
        HSPSC_Object::init();
        $this->assertFileDoesNotExist( $legacy );
        $this->assertSame( HSPSC_Object::SECURITY_REVISION, (int) get_option( HSPSC_Object::SECURITY_REVISION_OPTION ) );
    }

    public function test_disabled_initialization_still_removes_an_owned_dropin_after_migration() {
        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
        $this->assertTrue( HSPSC_Object::secure_legacy_cache() );
        file_put_contents( $this->target_file, '<?php /* Drop-in object cache for HSP Smart Cache */ // old writer' );
        $legacy = HSPSC_PATH . '/object/late-writer.cache';
        file_put_contents( $legacy, 'late legacy probe' );

        HSPSC_Object::init();
        $this->assertFileDoesNotExist( $this->target_file );
        $this->assertFileDoesNotExist( $legacy );
        $this->assertSame( PHP_INT_MAX, has_action( 'shutdown', array( 'HSPSC_Object', 'secure_legacy_cache' ) ) );
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

    /**
     * Checks deletion independently of the WordPress helper's return value.
     */
    public function test_remove_dropin_checks_file_state_when_deletion_returns_no_success_status() {
        $this->assertTrue( HSPSC_Object::install_dropin() );
        $target = $this->target_file;
        $delete_without_status = static function ( $file ) use ( $target ) {
            if ( $file === $target ) {
                unlink( $file );
                return '';
            }
            return $file;
        };
        add_filter( 'wp_delete_file', $delete_without_status );
        try {
            $this->assertTrue( HSPSC_Object::remove_dropin() );
            $this->assertFileDoesNotExist( $target );
        } finally {
            remove_filter( 'wp_delete_file', $delete_without_status );
        }
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

        $payload = $cache->payload_for( $key );
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'expire', $payload );
        $this->assertGreaterThan( time(), (int) $payload['expire'] );
        $this->assertLessThanOrEqual( time() + HSPSC_Settings::get( 'object_cache_default_ttl', 604800 ) + 5, (int) $payload['expire'] );
    }

    public function test_add_does_not_overwrite_existing_falsey_value() {
        $cache = new HSPSC_Test_Object_Cache();

        $this->assertTrue( $cache->set( 'falsey-add-test', '0', 'default', 60 ) );
        $this->assertFalse( $cache->add( 'falsey-add-test', 'replacement', 'default', 60 ) );

        $this->assertSame( '0', $cache->get( 'falsey-add-test', 'default' ) );
    }

    public function test_object_cache_keeps_distinct_raw_keys() {
        $cache = new HSPSC_Test_Object_Cache();

        $cache->set( 'foo@bar', 'with-at', 'default', 60 );
        $cache->set( 'foobar', 'plain', 'default', 60 );

        $this->assertSame( 'with-at', $cache->get( 'foo@bar', 'default' ) );
        $this->assertSame( 'plain', $cache->get( 'foobar', 'default' ) );
        $this->assertNotSame( $cache->file_path_for( 'foo@bar' ), $cache->file_path_for( 'foobar' ) );
    }

    public function test_object_cache_sanitizes_group_for_paths_and_prefixes_global_groups() {
        $cache = new HSPSC_Test_Object_Cache();

        $cache->add_global_groups( array( 'global group' ) );
        $cache->set( 'same-key', 'site-value', 'default', 60 );
        $cache->set( 'same-key', 'global-value', 'global group', 60 );

        $this->assertStringContainsString( '/global_group-', wp_normalize_path( $cache->file_path_for( 'same-key', 'global group' ) ) );
        $this->assertStringStartsWith( 'global:', $cache->cache_key_for( 'same-key', 'global group' ) );
        $this->assertNotSame( $cache->file_path_for( 'same-key', 'default' ), $cache->file_path_for( 'same-key', 'global group' ) );
        $this->assertSame( 'site-value', $cache->get( 'same-key', 'default' ) );
        $this->assertSame( 'global-value', $cache->get( 'same-key', 'global group' ) );
    }

    public function test_set_caps_long_ttl_to_maximum() {
        $cache = new HSPSC_Test_Object_Cache();
        $key = 'ttl-cap-test';

        $cache->set( $key, 'value', 'default', YEAR_IN_SECONDS * 5 );

        $file = $cache->file_path_for( $key );
        $this->assertFileExists( $file );

        $payload = $cache->payload_for( $key );
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

    public function test_cache_files_are_guarded_php_and_do_not_output_values() {
        $cache = new HSPSC_Test_Object_Cache();
        $this->assertTrue( $cache->set( 'private-user', array( 'email' => 'private@example.test', 'password_hash' => 'secret-hash' ) ) );
        $path = $cache->file_path_for( 'private-user' );
        $this->assertSame( 'php', pathinfo( $path, PATHINFO_EXTENSION ) );
        $this->assertStringStartsWith( HSPSC_File_Object_Cache::FILE_HEADER, file_get_contents( $path ) );

        $result = $this->run_php_script( $path );
        $this->assertSame( 0, $result['exit'] );
        $this->assertSame( '', $result['stdout'] );
        $this->assertSame( '', $result['stderr'] );
    }

    public function test_flush_preserves_http_protection_and_cached_values_are_removed() {
        $cache = new HSPSC_Test_Object_Cache();
        $cache->set( 'protected-flush', 'private' );
        $cache->flush();

        $this->assertFileDoesNotExist( $cache->file_path_for( 'protected-flush' ) );
        $this->assertStringContainsString( 'Require all denied', file_get_contents( HSPSC_PATH . '/object/.htaccess' ) );
        $this->assertStringContainsString( 'accessType="Deny"', file_get_contents( HSPSC_PATH . '/object/web.config' ) );
        $this->assertFileExists( HSPSC_PATH . '/object/index.php' );
        $this->assertTrue( $cache->set( 'after-flush', 'new-value' ) );
        $this->assertSame( 'new-value', ( new HSPSC_Test_Object_Cache() )->get( 'after-flush' ) );
    }

    public function test_initialization_deletes_plaintext_legacy_files_without_deserializing() {
        HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/object' );
        $legacy = HSPSC_PATH . '/object/users/03/' . md5( 'legacy-user' ) . '.cache';
        wp_mkdir_p( dirname( $legacy ) );
        file_put_contents( $legacy, serialize( new HSPSC_Test_Cached_Object() ) );
        HSPSC_Test_Cached_Object::$wakeups = 0;

        new HSPSC_Test_Object_Cache();

        $this->assertFileDoesNotExist( $legacy );
        $this->assertSame( 0, HSPSC_Test_Cached_Object::$wakeups );
    }

    public function test_tampered_values_are_rejected_before_object_instantiation() {
        $cache = new HSPSC_Test_Object_Cache();
        $cache->set( 'tampered-object', new HSPSC_Test_Cached_Object() );
        $file = $cache->file_path_for( 'tampered-object' );
        file_put_contents( $file, str_replace( 'original', 'tampered', file_get_contents( $file ) ) );
        HSPSC_Test_Cached_Object::$wakeups = 0;

        $other = new HSPSC_Test_Object_Cache();
        $this->assertFalse( $other->get( 'tampered-object', 'default', true, $found ) );
        $this->assertFalse( $found );
        $this->assertSame( 0, HSPSC_Test_Cached_Object::$wakeups );
        $this->assertFileDoesNotExist( $file );
    }

    public function test_garbage_collection_never_instantiates_even_authenticated_objects() {
        $cache = new HSPSC_Test_Object_Cache();
        $cache->set( 'gc-object', new HSPSC_Test_Cached_Object() );
        HSPSC_Test_Cached_Object::$wakeups = 0;

        $cache->cleanup_expired_cache();

        $this->assertSame( 0, HSPSC_Test_Cached_Object::$wakeups );
        $this->assertFileExists( $cache->file_path_for( 'gc-object' ) );
    }

    public function test_authenticated_payload_cannot_be_copied_to_a_different_key() {
        $cache = new HSPSC_Test_Object_Cache();
        $cache->set( 'source-key', 'private-source-value' );
        $cache->set( 'target-key', 'target-value' );
        copy( $cache->file_path_for( 'source-key' ), $cache->file_path_for( 'target-key' ) );

        $this->assertFalse( ( new HSPSC_Test_Object_Cache() )->get( 'target-key', 'default', true, $found ) );
        $this->assertFalse( $found );
    }

    public function test_without_secret_keys_values_remain_in_memory_only() {
        $cache = new HSPSC_Test_Unkeyed_Object_Cache();
        $this->assertTrue( $cache->set( 'unkeyed', 'private' ) );
        $this->assertSame( 'private', $cache->get( 'unkeyed', 'default', true, $found ) );
        $this->assertTrue( $found );
        $this->assertFileDoesNotExist( $cache->file_path_for( 'unkeyed' ) );
    }

    public function test_null_and_false_are_found_across_cache_instances() {
        $cache = new HSPSC_Test_Object_Cache();
        foreach ( array( null, false, 0, '', array() ) as $index => $value ) {
            $key = 'falsey-' . $index;
            $this->assertTrue( $cache->set( $key, $value ) );
            $other = new HSPSC_Test_Object_Cache();
            $this->assertSame( $value, $other->get( $key, 'default', false, $found ) );
            $this->assertTrue( $found );
            $this->assertFalse( $other->add( $key, 'replacement' ) );
        }
    }

    public function test_cached_objects_are_cloned_on_set_and_get() {
        $cache = new HSPSC_Test_Object_Cache();
        $object = new HSPSC_Test_Cached_Object();
        $cache->set( 'object-clone', $object );
        $object->value = 'changed-after-set';
        $fetched = $cache->get( 'object-clone' );
        $this->assertSame( 'original', $fetched->value );
        $fetched->value = 'changed-after-get';
        $this->assertSame( 'original', $cache->get( 'object-clone' )->value );
        $this->assertSame( 'original', ( new HSPSC_Test_Object_Cache() )->get( 'object-clone' )->value );
    }

    public function test_runtime_expiration_and_nonpersistent_forced_reads() {
        $cache = new HSPSC_Test_Object_Cache();
        $cache->add_non_persistent_groups( array( 'request-only' ) );
        $cache->set( 'forced-local', null, 'request-only' );
        $this->assertNull( $cache->get( 'forced-local', 'request-only', true, $found ) );
        $this->assertTrue( $found );
        $cache->set_expired( 'expired-local', 'old', 'request-only' );
        $this->assertFalse( $cache->get( 'expired-local', 'request-only', false, $found ) );
        $this->assertFalse( $found );
        $cache->set_expired( 'expired-persistent', 'old' );
        $this->assertFalse( $cache->get( 'expired-persistent', 'default', false, $found ) );
        $this->assertFalse( $found );
    }

    public function test_replace_and_add_recheck_the_persistent_value() {
        $first = new HSPSC_Test_Object_Cache();
        $second = new HSPSC_Test_Object_Cache();
        $this->assertFalse( $first->replace( 'replace-target', 'missing' ) );
        $first->get( 'replace-target' );
        $second->set( 'replace-target', false );
        $this->assertFalse( $first->add( 'replace-target', 'wrong' ) );
        $this->assertTrue( $first->replace( 'replace-target', 'replacement' ) );
        $this->assertSame( 'replacement', $second->get( 'replace-target', 'default', true ) );
    }

    public function test_groups_that_sanitize_to_the_same_path_remain_distinct() {
        $cache = new HSPSC_Test_Object_Cache();
        $cache->set( 'key', 'space', 'shop cart' );
        $cache->set( 'key', 'underscore', 'shop_cart' );
        $cache->set( 'b:c', 'colon-key', 'a' );
        $cache->set( 'c', 'colon-group', 'a:b' );
        $this->assertSame( 'space', $cache->get( 'key', 'shop cart' ) );
        $this->assertSame( 'underscore', $cache->get( 'key', 'shop_cart' ) );
        $this->assertSame( 'colon-key', $cache->get( 'b:c', 'a' ) );
        $this->assertSame( 'colon-group', $cache->get( 'c', 'a:b' ) );
    }

    public function test_increment_preserves_expiry_and_decrement_stops_at_zero() {
        $cache = new HSPSC_Test_Object_Cache();
        $cache->set( 'counter-expiration', 2, 'default', 60 );
        $before = $cache->payload_for( 'counter-expiration' );
        $this->assertSame( 3, $cache->incr( 'counter-expiration' ) );
        $this->assertSame( $before['expire'], $cache->payload_for( 'counter-expiration' )['expire'] );
        $this->assertSame( 0, $cache->decr( 'counter-expiration', 5 ) );
    }

    public function test_cleanup_does_not_delete_a_value_replaced_after_its_expiry_check() {
        $cache = new HSPSC_Test_Replaced_Object_Cache();
        $cache->set_expired( 'replaced-during-cleanup', 'expired' );
        $cache->before_delete = static function () {
            $writer = new HSPSC_Test_Object_Cache();
            $writer->set( 'replaced-during-cleanup', 'fresh', 'default', 60 );
        };

        $cache->cleanup_expired_cache();

        $this->assertSame( 'fresh', $cache->get( 'replaced-during-cleanup', 'default', true ) );
    }

    public function test_owned_dropin_is_refreshed_and_plaintext_removed_when_disabled() {
        file_put_contents( $this->target_file, '<?php /* Drop-in object cache for HSP Smart Cache */ // old version' );
        $legacy = HSPSC_PATH . '/object/' . md5( 'old' ) . '.cache';
        wp_mkdir_p( dirname( $legacy ) );
        file_put_contents( $legacy, 'private-old-value' );
        $this->assertTrue( HSPSC_Object::install_dropin() );
        $this->assertSame( hash_file( 'sha256', dirname( __DIR__ ) . '/dropins/object-cache.php' ), hash_file( 'sha256', $this->target_file ) );
        $this->assertFileDoesNotExist( $legacy );

        wp_delete_file( $this->target_file );
        file_put_contents( $legacy, 'left-after-deactivation' );
        HSPSC_Object::remove_dropin();
        $this->assertFileDoesNotExist( $legacy );
    }

    public function test_legacy_cleanup_does_not_follow_directory_symlinks() {
        $outside = sys_get_temp_dir() . '/hspsc-object-outside-' . wp_generate_password( 8, false );
        wp_mkdir_p( $outside );
        $file = $outside . '/private.cache';
        file_put_contents( $file, 'outside-value' );
        $link = HSPSC_PATH . '/object/outside-link';
        wp_mkdir_p( dirname( $link ) );
        if ( ! @symlink( $outside, $link ) ) {
            unlink( $file );
            rmdir( $outside );
            $this->markTestSkipped( 'Directory symlinks unavailable.' );
        }
        try {
            HSPSC_Object::secure_legacy_cache();
            ( new HSPSC_Test_Object_Cache() )->flush();
            $this->assertSame( 'outside-value', file_get_contents( $file ) );
        } finally {
            unlink( $link );
            unlink( $file );
            rmdir( $outside );
        }
    }

    public function test_standalone_early_dropin_supports_replace_without_wordpress_helpers() {
        $script = $this->write_php_script(
            'define( "AUTH_KEY", str_repeat( "a", 64 ) );' .
            'require ' . var_export( dirname( __DIR__ ) . '/dropins/object-cache.php', true ) . ';' .
            'wp_cache_init(); wp_cache_set( "early-replace", "initial" );' .
            'echo json_encode( array( wp_cache_replace( "early-replace", "updated" ), wp_cache_get( "early-replace" ) ) );'
        );
        try {
            $result = $this->run_php_script( $script );
            $this->assertSame( 0, $result['exit'], $result['stderr'] );
            $this->assertSame( array( true, 'updated' ), json_decode( $result['stdout'], true ) );
        } finally {
            unlink( $script );
        }
    }

    public function test_concurrent_processes_do_not_lose_counter_updates() {
        $cache = new HSPSC_Test_Object_Cache();
        $cache->set( 'concurrent-counter', 0, 'default', 60 );
        $script = $this->write_php_script(
            'require ' . var_export( dirname( __DIR__ ) . '/dropins/object-cache.php', true ) . ';' .
            'class HSPSC_Concurrent_Test_Cache extends HSPSC_File_Object_Cache {' .
            'protected function get_signing_key() { return "hspsc-object-cache-regression-test-secret"; }}' .
            '$cache = new HSPSC_Concurrent_Test_Cache();' .
            'for ( $i = 0; $i < 50; $i++ ) { if ( false === $cache->incr( "concurrent-counter" ) ) { exit( 2 ); } }'
        );
        $workers = array();
        try {
            for ( $i = 0; $i < 2; $i++ ) {
                $workers[] = $this->start_php_script( $script );
            }
            foreach ( $workers as $worker ) {
                $result = $this->finish_php_script( $worker );
                $this->assertSame( 0, $result['exit'], $result['stderr'] );
            }
            $this->assertSame( 100, $cache->get( 'concurrent-counter', 'default', true ) );
        } finally {
            unlink( $script );
        }
    }

    private function write_php_script( $body ) {
        $path = tempnam( sys_get_temp_dir(), 'hspsc-object-test-' );
        file_put_contents( $path, '<?php define( "ABSPATH", ' . var_export( ABSPATH, true ) . ' ); define( "WP_CONTENT_DIR", ' . var_export( WP_CONTENT_DIR, true ) . ' );' . $body );
        return $path;
    }

    private function run_php_script( $path ) {
        return $this->finish_php_script( $this->start_php_script( $path ) );
    }

    private function start_php_script( $path ) {
        if ( ! function_exists( 'proc_open' ) ) {
            $this->markTestSkipped( 'PHP process execution unavailable.' );
        }
        $process = proc_open( array( PHP_BINARY, $path ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
        $this->assertIsResource( $process );
        fclose( $pipes[0] );
        return array( $process, $pipes );
    }

    private function finish_php_script( $worker ) {
        list( $process, $pipes ) = $worker;
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        return array( 'exit' => proc_close( $process ), 'stdout' => $stdout, 'stderr' => $stderr );
    }
}
