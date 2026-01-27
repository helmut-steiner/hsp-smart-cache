<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Smart_Cache_Object {
    public static function init() {
        // No runtime hooks needed yet.
    }

    public static function sync_dropin() {
        if ( HSP_Cache_Settings::get( 'object_cache' ) ) {
            self::install_dropin();
        } else {
            self::remove_dropin();
        }
    }

    public static function install_dropin() {
        $source = trailingslashit( dirname( __DIR__ ) ) . 'dropins/object-cache.php';
        $target = WP_CONTENT_DIR . '/object-cache.php';

        if ( file_exists( $source ) ) {
            @copy( $source, $target );
        }
    }

    public static function remove_dropin() {
        $target = WP_CONTENT_DIR . '/object-cache.php';
        if ( file_exists( $target ) ) {
            wp_delete_file( $target );
        }
    }

    public static function flush_cache() {
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        HSP_Cache_Utils::delete_dir_contents( HSP_SMART_CACHE_PATH . '/object' );
    }
}
