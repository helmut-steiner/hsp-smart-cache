<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$hsp_cache_option_key = 'hsp_cache_settings';

delete_option( $hsp_cache_option_key );

define( 'HSP_CACHE_PATH', WP_CONTENT_DIR . '/cache/hsp-cache' );

$hsp_cache_fs = null;
require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
global $wp_filesystem;
$hsp_cache_fs = $wp_filesystem;

$hsp_cache_object_dropin = WP_CONTENT_DIR . '/object-cache.php';
if ( $hsp_cache_fs && $hsp_cache_fs->exists( $hsp_cache_object_dropin ) ) {
    $hsp_cache_fs->delete( $hsp_cache_object_dropin );
}

function hsp_smart_cache_delete_dir_contents( $dir ) {
    global $hsp_cache_fs;
    if ( ! $hsp_cache_fs ) {
        return;
    }
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
            hsp_smart_cache_delete_dir_contents( $path );
            $hsp_cache_fs->rmdir( $path, false );
        } else {
            $hsp_cache_fs->delete( $path );
        }
    }
}

hsp_smart_cache_delete_dir_contents( HSP_CACHE_PATH );
if ( $hsp_cache_fs && $hsp_cache_fs->is_dir( HSP_CACHE_PATH ) ) {
    $hsp_cache_fs->rmdir( HSP_CACHE_PATH, false );
}

$hsp_cache_htaccess = ABSPATH . '.htaccess';
if ( $hsp_cache_fs && $hsp_cache_fs->exists( $hsp_cache_htaccess ) && $hsp_cache_fs->is_writable( $hsp_cache_htaccess ) ) {
    $contents = $hsp_cache_fs->get_contents( $hsp_cache_htaccess );
    if ( $contents !== false ) {
        $pattern = '/\n?# BEGIN HSP Smart Cache.*?# END HSP Smart Cache\n?/s';
        $contents = preg_replace( $pattern, '', $contents );
        $hsp_cache_fs->put_contents( $hsp_cache_htaccess, $contents );
    }
}
