<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function hsp_smart_cache_uninstall() {
    delete_option( 'hsp_cache_settings' );

    if ( ! defined( 'HSP_CACHE_PATH' ) ) {
        define( 'HSP_CACHE_PATH', WP_CONTENT_DIR . '/cache/hsp-cache' );
    }

    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    WP_Filesystem();
    global $wp_filesystem;
    $fs = $wp_filesystem;

    if ( $fs ) {
        $object_dropin = WP_CONTENT_DIR . '/object-cache.php';
        if ( $fs->exists( $object_dropin ) ) {
            $fs->delete( $object_dropin );
        }

        hsp_smart_cache_delete_dir_contents( HSP_CACHE_PATH, $fs );
        if ( $fs->is_dir( HSP_CACHE_PATH ) ) {
            $fs->rmdir( HSP_CACHE_PATH, false );
        }

        $htaccess = ABSPATH . '.htaccess';
        if ( $fs->exists( $htaccess ) && $fs->is_writable( $htaccess ) ) {
            $contents = $fs->get_contents( $htaccess );
            if ( $contents !== false ) {
                $pattern = '/\n?# BEGIN HSP Smart Cache.*?# END HSP Smart Cache\n?/s';
                $contents = preg_replace( $pattern, '', $contents );
                $fs->put_contents( $htaccess, $contents );
            }
        }
    }
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function hsp_smart_cache_delete_dir_contents( $dir, $fs ) {
    if ( ! $fs || ! is_dir( $dir ) ) {
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
            hsp_smart_cache_delete_dir_contents( $path, $fs );
            $fs->rmdir( $path, false );
        } else {
            $fs->delete( $path );
        }
    }
}

hsp_smart_cache_uninstall();
