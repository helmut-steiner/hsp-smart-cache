<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$option_key = 'hsp_cache_settings';

delete_option( $option_key );

define( 'HSP_CACHE_PATH', WP_CONTENT_DIR . '/cache/hsp-cache' );

$object_dropin = WP_CONTENT_DIR . '/object-cache.php';
if ( file_exists( $object_dropin ) ) {
    @unlink( $object_dropin );
}

function hsp_cache_delete_dir_contents( $dir ) {
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
            hsp_cache_delete_dir_contents( $path );
            @rmdir( $path );
        } else {
            @unlink( $path );
        }
    }
}

hsp_cache_delete_dir_contents( HSP_CACHE_PATH );
@rmdir( HSP_CACHE_PATH );

$htaccess = ABSPATH . '.htaccess';
if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
    $contents = file_get_contents( $htaccess );
    if ( $contents !== false ) {
        $pattern = '/\n?# BEGIN HSP Smart Cache.*?# END HSP Smart Cache\n?/s';
        $contents = preg_replace( $pattern, '', $contents );
        file_put_contents( $htaccess, $contents );
    }
}
