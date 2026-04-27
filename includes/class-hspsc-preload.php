<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Preload {
    public static function run() {
        if ( ! HSPSC_Settings::get( 'preload_enabled' ) ) {
            return array( 'ok' => false, 'count' => 0 );
        }

        $sitemap = HSPSC_Settings::get( 'preload_sitemap_url' );
        if ( empty( $sitemap ) ) {
            $sitemap = home_url( '/sitemap.xml' );
        }
        if ( ! HSPSC_Page::is_warmable_url( $sitemap ) ) {
            return array( 'ok' => false, 'count' => 0, 'error' => 'invalid_sitemap_url' );
        }

        $limit = intval( HSPSC_Settings::get( 'preload_limit', 50 ) );
        $timeout = intval( HSPSC_Settings::get( 'preload_timeout', 8 ) );

        $urls = self::fetch_sitemap_urls( $sitemap, $limit );
        $count = 0;
        foreach ( $urls as $url ) {
            HSPSC_Page::warm_url_with_timeout( $url, $timeout );
            $count++;
        }

        return array( 'ok' => true, 'count' => $count );
    }

    protected static function fetch_sitemap_urls( $sitemap_url, $limit ) {
        $response = wp_remote_get( $sitemap_url, array( 'timeout' => 8 ) );
        if ( is_wp_error( $response ) ) {
            return array();
        }
        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return array();
        }

        preg_match_all( '/<loc>([^<]+)<\/loc>/i', $body, $matches );
        if ( empty( $matches[1] ) ) {
            return array();
        }

        $urls = array();
        foreach ( $matches[1] as $url ) {
            $url = esc_url_raw( trim( $url ) );
            if ( $url && HSPSC_Page::is_warmable_url( $url ) ) {
                $urls[] = $url;
            }
            if ( count( $urls ) >= $limit ) {
                break;
            }
        }

        return $urls;
    }
}
