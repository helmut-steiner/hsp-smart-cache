<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_CDN {
    protected static $config_cache = array();

    public static function init() {
        add_filter( 'style_loader_src', array( __CLASS__, 'rewrite_url' ), 30 );
        add_filter( 'script_loader_src', array( __CLASS__, 'rewrite_url' ), 30 );
        add_filter( 'wp_get_attachment_url', array( __CLASS__, 'rewrite_url' ), 30 );
    }

    public static function rewrite_url( $url ) {
        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            return $url;
        }

        $config = self::get_config();

        if ( ! $config['enabled'] ) {
            return $url;
        }

        if ( empty( $config['cdn_url'] ) ) {
            return $url;
        }

        if ( ! preg_match( '/\.(css|js|png|jpg|jpeg|gif|svg|webp|woff2|woff|ttf|eot)(\?.*)?$/i', $url ) ) {
            return $url;
        }

        if ( strpos( $url, $config['content_url'] ) === 0 ) {
            return $config['cdn_url'] . substr( $url, strlen( $config['content_url'] ) );
        }

        if ( strpos( $url, $config['site_url'] ) === 0 ) {
            return $config['cdn_url'] . substr( $url, strlen( $config['site_url'] ) );
        }

        return $url;
    }

    protected static function get_config() {
        $settings = HSPSC_Settings::get_all();
        $signature = md5(
            wp_json_encode(
                array(
                    ! empty( $settings['cdn_enabled'] ),
                    isset( $settings['cdn_url'] ) ? $settings['cdn_url'] : '',
                    site_url(),
                    content_url(),
                )
            )
        );

        if ( isset( self::$config_cache[ $signature ] ) ) {
            return self::$config_cache[ $signature ];
        }

        self::$config_cache[ $signature ] = array(
            'enabled'     => ! empty( $settings['cdn_enabled'] ),
            'cdn_url'     => isset( $settings['cdn_url'] ) ? rtrim( $settings['cdn_url'], '/' ) : '',
            'site_url'    => site_url(),
            'content_url' => content_url(),
        );

        return self::$config_cache[ $signature ];
    }
}
