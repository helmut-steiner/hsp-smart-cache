<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Render {
    protected static $parsed_list_cache = array();

    public static function init() {
        add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_tag' ), 10, 3 );
        add_action( 'wp_head', array( __CLASS__, 'output_preconnects' ), 1 );
        add_action( 'wp_head', array( __CLASS__, 'output_preloads' ), 2 );
        add_action( 'wp_head', array( __CLASS__, 'output_critical_css' ), 3 );
    }

    public static function filter_script_tag( $tag, $handle, $src ) {
        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            return $tag;
        }

        if ( self::script_has_inline_extras( $handle ) ) {
            return $tag;
        }

        $defer = HSPSC_Settings::get( 'render_defer_js' );
        $async = HSPSC_Settings::get( 'render_async_js' );

        if ( ! $defer && ! $async ) {
            return $tag;
        }

        $defer_exclusions = self::parse_list( HSPSC_Settings::get( 'render_defer_exclusions', '' ) );
        $async_exclusions = self::parse_list( HSPSC_Settings::get( 'render_async_exclusions', '' ) );

        if ( in_array( $handle, $defer_exclusions, true ) || in_array( $handle, $async_exclusions, true ) ) {
            return $tag;
        }

        if ( $defer && strpos( $tag, ' defer' ) === false ) {
            $tag = str_replace( ' src=', ' defer src=', $tag );
        } elseif ( $async && strpos( $tag, ' async' ) === false ) {
            $tag = str_replace( ' src=', ' async src=', $tag );
        }

        return $tag;
    }

    protected static function script_has_inline_extras( $handle ) {
        global $wp_scripts;

        if ( ! ( $wp_scripts instanceof WP_Scripts ) ) {
            return false;
        }

        if ( empty( $wp_scripts->registered[ $handle ] ) ) {
            return false;
        }

        $registered = $wp_scripts->registered[ $handle ];
        $extra = isset( $registered->extra ) && is_array( $registered->extra ) ? $registered->extra : array();

        return ! empty( $extra['before'] ) || ! empty( $extra['after'] ) || ! empty( $extra['data'] );
    }

    public static function output_preconnects() {
        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            return;
        }
        $urls = self::parse_list( HSPSC_Settings::get( 'render_preconnect_urls', '' ) );
        foreach ( $urls as $url ) {
            $href = esc_url( $url );
            if ( $href ) {
                printf( '<link rel="preconnect" href="%s" crossorigin />' . "\n", esc_url( $href ) );
            }
        }
    }

    public static function output_preloads() {
        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            return;
        }
        $fonts = self::parse_list( HSPSC_Settings::get( 'render_preload_fonts', '' ) );
        foreach ( $fonts as $font ) {
            $href = esc_url( $font );
            if ( $href ) {
                $type = self::guess_font_type( $href );
                printf( '<link rel="preload" href="%s" as="font" type="%s" crossorigin />' . "\n", esc_url( $href ), esc_attr( $type ) );
            }
        }

        $styles = self::parse_list( HSPSC_Settings::get( 'render_preload_css', '' ) );
        foreach ( $styles as $style ) {
            $href = esc_url( $style );
            if ( $href ) {
                printf( '<link rel="preload" href="%s" as="style" />' . "\n", esc_url( $href ) );
            }
        }
    }

    public static function output_critical_css() {
        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            return;
        }
        $css = HSPSC_Settings::get( 'render_critical_css', '' );
        if ( empty( $css ) ) {
            return;
        }
        echo "<style id=\"hsp-critical-css\">\n" . esc_html( $css ) . "\n</style>\n";
    }

    protected static function parse_list( $value ) {
        $cache_key = md5( (string) $value );
        if ( array_key_exists( $cache_key, self::$parsed_list_cache ) ) {
            return self::$parsed_list_cache[ $cache_key ];
        }

        if ( empty( $value ) ) {
            self::$parsed_list_cache[ $cache_key ] = array();
            return self::$parsed_list_cache[ $cache_key ];
        }
        $parts = preg_split( '/[\r\n,]+/', $value );
        $clean = array();
        foreach ( (array) $parts as $part ) {
            $part = trim( $part );
            if ( $part !== '' ) {
                $clean[] = $part;
            }
        }
        self::$parsed_list_cache[ $cache_key ] = array_values( array_unique( $clean ) );
        return self::$parsed_list_cache[ $cache_key ];
    }

    protected static function guess_font_type( $url ) {
        if ( preg_match( '/\.woff2($|\?)/i', $url ) ) {
            return 'font/woff2';
        }
        if ( preg_match( '/\.woff($|\?)/i', $url ) ) {
            return 'font/woff';
        }
        if ( preg_match( '/\.ttf($|\?)/i', $url ) ) {
            return 'font/ttf';
        }
        if ( preg_match( '/\.otf($|\?)/i', $url ) ) {
            return 'font/otf';
        }
        return 'font/woff2';
    }
}
