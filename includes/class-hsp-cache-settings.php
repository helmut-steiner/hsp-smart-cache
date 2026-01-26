<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Cache_Settings {
    const OPTION_KEY = 'hsp_cache_settings';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    }

    public static function defaults() {
        return array(
            'page_cache'     => true,
            'page_cache_ttl' => 3600,
            'cache_logged_in' => false,
            'browser_cache'  => true,
            'browser_cache_ttl' => 600,
            'browser_cache_html_ttl' => 600,
            'browser_cache_asset_ttl' => 31536000,
            'static_asset_cache' => true,
            'static_asset_ttl' => 31536000,
            'static_asset_immutable' => true,
            'static_asset_auto_write' => false,
            'static_asset_compression' => false,
            'render_defer_js' => true,
            'render_async_js' => false,
            'render_defer_exclusions' => '',
            'render_async_exclusions' => '',
            'render_preconnect_urls' => '',
            'render_preload_fonts' => '',
            'render_preload_css' => '',
            'render_critical_css' => '',
            'perf_lazy_images' => true,
            'perf_lazy_iframes' => true,
            'perf_decoding_async' => true,
            'perf_disable_emojis' => false,
            'perf_disable_embeds' => false,
            'perf_disable_dashicons' => false,
            'perf_dns_prefetch_urls' => '',
            'preload_enabled' => false,
            'preload_sitemap_url' => '',
            'preload_limit' => 50,
            'preload_timeout' => 8,
            'minify_html'    => true,
            'minify_css'     => true,
            'minify_js'      => true,
            'object_cache'   => false,
            'cdn_enabled'    => false,
            'cdn_url'        => '',
        );
    }

    public static function ensure_defaults() {
        $options = get_option( self::OPTION_KEY );
        if ( ! is_array( $options ) ) {
            update_option( self::OPTION_KEY, self::defaults() );
            return;
        }

        $merged = wp_parse_args( $options, self::defaults() );
        if ( $merged !== $options ) {
            update_option( self::OPTION_KEY, $merged );
        }
    }

    public static function get( $key, $default = null ) {
        $options = get_option( self::OPTION_KEY, self::defaults() );
        if ( ! is_array( $options ) ) {
            $options = self::defaults();
        }
        if ( array_key_exists( $key, $options ) ) {
            return $options[ $key ];
        }
        return $default;
    }

    public static function register_settings() {
        register_setting( 'hsp_cache_settings_group', self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
    }

    public static function sanitize( $input ) {
        $defaults = self::defaults();
        $output   = array();

        $output['page_cache']     = ! empty( $input['page_cache'] );
        $output['page_cache_ttl'] = isset( $input['page_cache_ttl'] ) ? max( 60, intval( $input['page_cache_ttl'] ) ) : $defaults['page_cache_ttl'];
        $output['cache_logged_in'] = ! empty( $input['cache_logged_in'] );
        $output['browser_cache']  = ! empty( $input['browser_cache'] );
        $legacy_ttl = isset( $input['browser_cache_ttl'] ) ? max( 60, intval( $input['browser_cache_ttl'] ) ) : $defaults['browser_cache_ttl'];
        $output['browser_cache_ttl'] = $legacy_ttl;
        $output['browser_cache_html_ttl'] = isset( $input['browser_cache_html_ttl'] ) ? max( 60, intval( $input['browser_cache_html_ttl'] ) ) : $legacy_ttl;
        $output['browser_cache_asset_ttl'] = isset( $input['browser_cache_asset_ttl'] ) ? max( 60, intval( $input['browser_cache_asset_ttl'] ) ) : $defaults['browser_cache_asset_ttl'];
        $output['static_asset_cache'] = ! empty( $input['static_asset_cache'] );
        $output['static_asset_ttl'] = isset( $input['static_asset_ttl'] ) ? max( 60, intval( $input['static_asset_ttl'] ) ) : $defaults['static_asset_ttl'];
        $output['static_asset_immutable'] = ! empty( $input['static_asset_immutable'] );
        $output['static_asset_auto_write'] = ! empty( $input['static_asset_auto_write'] );
        $output['static_asset_compression'] = ! empty( $input['static_asset_compression'] );
        $output['render_defer_js'] = ! empty( $input['render_defer_js'] );
        $output['render_async_js'] = ! empty( $input['render_async_js'] );
        $output['render_defer_exclusions'] = isset( $input['render_defer_exclusions'] ) ? sanitize_textarea_field( $input['render_defer_exclusions'] ) : '';
        $output['render_async_exclusions'] = isset( $input['render_async_exclusions'] ) ? sanitize_textarea_field( $input['render_async_exclusions'] ) : '';
        $output['render_preconnect_urls'] = isset( $input['render_preconnect_urls'] ) ? sanitize_textarea_field( $input['render_preconnect_urls'] ) : '';
        $output['render_preload_fonts'] = isset( $input['render_preload_fonts'] ) ? sanitize_textarea_field( $input['render_preload_fonts'] ) : '';
        $output['render_preload_css'] = isset( $input['render_preload_css'] ) ? sanitize_textarea_field( $input['render_preload_css'] ) : '';
        $output['render_critical_css'] = isset( $input['render_critical_css'] ) ? wp_kses_post( $input['render_critical_css'] ) : '';
        $output['perf_lazy_images'] = ! empty( $input['perf_lazy_images'] );
        $output['perf_lazy_iframes'] = ! empty( $input['perf_lazy_iframes'] );
        $output['perf_decoding_async'] = ! empty( $input['perf_decoding_async'] );
        $output['perf_disable_emojis'] = ! empty( $input['perf_disable_emojis'] );
        $output['perf_disable_embeds'] = ! empty( $input['perf_disable_embeds'] );
        $output['perf_disable_dashicons'] = ! empty( $input['perf_disable_dashicons'] );
        $output['perf_dns_prefetch_urls'] = isset( $input['perf_dns_prefetch_urls'] ) ? sanitize_textarea_field( $input['perf_dns_prefetch_urls'] ) : '';
        $output['preload_enabled'] = ! empty( $input['preload_enabled'] );
        $output['preload_sitemap_url'] = isset( $input['preload_sitemap_url'] ) ? esc_url_raw( trim( $input['preload_sitemap_url'] ) ) : '';
        $output['preload_limit'] = isset( $input['preload_limit'] ) ? max( 1, intval( $input['preload_limit'] ) ) : $defaults['preload_limit'];
        $output['preload_timeout'] = isset( $input['preload_timeout'] ) ? max( 3, intval( $input['preload_timeout'] ) ) : $defaults['preload_timeout'];
        $output['minify_html']    = ! empty( $input['minify_html'] );
        $output['minify_css']     = ! empty( $input['minify_css'] );
        $output['minify_js']      = ! empty( $input['minify_js'] );
        $output['object_cache']   = ! empty( $input['object_cache'] );
        $output['cdn_enabled']    = ! empty( $input['cdn_enabled'] );
        $output['cdn_url']        = isset( $input['cdn_url'] ) ? esc_url_raw( trim( $input['cdn_url'] ) ) : '';

        return wp_parse_args( $output, $defaults );
    }
}
