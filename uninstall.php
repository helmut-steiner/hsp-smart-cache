<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function hspsc_uninstall() {
    delete_option( 'hspsc_settings' );

    if ( ! defined( 'HSPSC_PATH' ) ) {
        define( 'HSPSC_PATH', WP_CONTENT_DIR . '/cache/hspsc' );
    }

    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    WP_Filesystem();
    global $wp_filesystem;
    $fs = $wp_filesystem;

    if ( $fs ) {
        $object_dropin = WP_CONTENT_DIR . '/object-cache.php';
        if ( $fs->exists( $object_dropin ) && hspsc_uninstall_is_own_dropin( $object_dropin, $fs ) ) {
            $fs->delete( $object_dropin );
        }

        hspsc_delete_dir_contents( HSPSC_PATH, $fs );
        if ( $fs->is_dir( HSPSC_PATH ) ) {
            $fs->rmdir( HSPSC_PATH, false );
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
function hspsc_delete_dir_contents( $dir, $fs ) {
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
            hspsc_delete_dir_contents( $path, $fs );
            $fs->rmdir( $path, false );
        } else {
            $fs->delete( $path );
        }
    }
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function hspsc_uninstall_is_own_dropin( $path, $fs ) {
    if ( ! $fs || ! $fs->exists( $path ) ) {
        return false;
    }

    $contents = $fs->get_contents( $path );
    if ( ! is_string( $contents ) ) {
        return false;
    }

    $contents = substr( $contents, 0, 4096 );
    return strpos( $contents, 'HSPSC_File_Object_Cache' ) !== false
        || strpos( $contents, 'Drop-in object cache for HSP Smart Cache' ) !== false;
}

hspsc_uninstall();
