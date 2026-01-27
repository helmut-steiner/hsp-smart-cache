<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Smart_Cache_CDN {
    public static function init() {
        add_filter( 'style_loader_src', array( __CLASS__, 'rewrite_url' ), 30 );
        add_filter( 'script_loader_src', array( __CLASS__, 'rewrite_url' ), 30 );
        add_filter( 'wp_get_attachment_url', array( __CLASS__, 'rewrite_url' ), 30 );
    }

    public static function rewrite_url( $url ) {
        if ( ! HSP_Smart_Cache_Settings::get( 'cdn_enabled' ) ) {
            return $url;
        }

        $cdn = HSP_Smart_Cache_Settings::get( 'cdn_url' );
        if ( empty( $cdn ) ) {
            return $url;
        }

        if ( ! preg_match( '/\.(css|js|png|jpg|jpeg|gif|svg|webp|woff2|woff|ttf|eot)(\?.*)?$/i', $url ) ) {
            return $url;
        }

        $site_url   = site_url();
        $content_url = content_url();
        $cdn        = rtrim( $cdn, '/' );

        if ( strpos( $url, $content_url ) === 0 ) {
            return $cdn . substr( $url, strlen( $content_url ) );
        }

        if ( strpos( $url, $site_url ) === 0 ) {
            return $cdn . substr( $url, strlen( $site_url ) );
        }

        return $url;
    }
}
