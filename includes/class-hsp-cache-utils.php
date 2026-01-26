<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Cache_Utils {
    public static function ensure_cache_dirs() {
        $paths = array(
            HSP_CACHE_PATH,
            HSP_CACHE_PATH . '/pages',
            HSP_CACHE_PATH . '/assets',
            HSP_CACHE_PATH . '/object',
        );

        foreach ( $paths as $path ) {
            if ( ! is_dir( $path ) ) {
                wp_mkdir_p( $path );
            }
        }
    }

    public static function delete_dir_contents( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = scandir( $dir );
        if ( ! $items ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) ) {
                self::delete_dir_contents( $path );
                @rmdir( $path );
            } else {
                @unlink( $path );
            }
        }
    }

    public static function is_request_cacheable() {
        if ( is_admin() ) {
            return false;
        }
        if ( is_user_logged_in() && ! HSP_Cache_Settings::get( 'cache_logged_in' ) ) {
            return false;
        }
        if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
            return false;
        }
        if ( ! empty( $_POST ) || ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'GET' ) ) {
            return false;
        }
        if ( is_preview() || is_feed() || is_robots() || is_trackback() ) {
            return false;
        }
        return true;
    }

    public static function normalize_url_path( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['path'] ) ) {
            return null;
        }
        return '/' . ltrim( $parts['path'], '/' );
    }
}
