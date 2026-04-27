<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Object {
    public static function init() {
        // No runtime hooks needed yet.
    }

    public static function sync_dropin() {
        if ( HSPSC_Settings::get( 'object_cache' ) ) {
            self::install_dropin();
        } else {
            self::remove_dropin();
        }
    }

    public static function install_dropin() {
        $source = trailingslashit( dirname( __DIR__ ) ) . 'dropins/object-cache.php';
        $target = WP_CONTENT_DIR . '/object-cache.php';

        if ( file_exists( $target ) && ! self::is_own_dropin( $target ) ) {
            return false;
        }

        if ( file_exists( $source ) ) {
            return @copy( $source, $target );
        }

        return false;
    }

    public static function remove_dropin() {
        $target = WP_CONTENT_DIR . '/object-cache.php';
        if ( file_exists( $target ) && self::is_own_dropin( $target ) ) {
            return wp_delete_file( $target );
        }

        return false;
    }

    public static function flush_cache() {
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/object' );
    }

    protected static function is_own_dropin( $path ) {
        if ( ! is_readable( $path ) ) {
            return false;
        }

        $contents = file_get_contents( $path, false, null, 0, 4096 );
        if ( ! is_string( $contents ) ) {
            return false;
        }

        return strpos( $contents, 'HSPSC_File_Object_Cache' ) !== false
            || strpos( $contents, 'Drop-in object cache for HSP Smart Cache' ) !== false;
    }
}
