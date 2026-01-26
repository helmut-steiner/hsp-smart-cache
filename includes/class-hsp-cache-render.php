<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Cache_Render {
    public static function init() {
        add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_tag' ), 10, 3 );
        add_action( 'wp_head', array( __CLASS__, 'output_preconnects' ), 1 );
        add_action( 'wp_head', array( __CLASS__, 'output_preloads' ), 2 );
        add_action( 'wp_head', array( __CLASS__, 'output_critical_css' ), 3 );
    }

    public static function filter_script_tag( $tag, $handle, $src ) {
        if ( is_admin() ) {
            return $tag;
        }

        $defer = HSP_Cache_Settings::get( 'render_defer_js' );
        $async = HSP_Cache_Settings::get( 'render_async_js' );

        if ( ! $defer && ! $async ) {
            return $tag;
        }

        $defer_exclusions = self::parse_list( HSP_Cache_Settings::get( 'render_defer_exclusions', '' ) );
        $async_exclusions = self::parse_list( HSP_Cache_Settings::get( 'render_async_exclusions', '' ) );

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

    public static function output_preconnects() {
        if ( is_admin() ) {
            return;
        }
        $urls = self::parse_list( HSP_Cache_Settings::get( 'render_preconnect_urls', '' ) );
        foreach ( $urls as $url ) {
            $href = esc_url( $url );
            if ( $href ) {
                echo '<link rel="preconnect" href="' . $href . '" crossorigin />' . "\n";
            }
        }
    }

    public static function output_preloads() {
        if ( is_admin() ) {
            return;
        }
        $fonts = self::parse_list( HSP_Cache_Settings::get( 'render_preload_fonts', '' ) );
        foreach ( $fonts as $font ) {
            $href = esc_url( $font );
            if ( $href ) {
                $type = self::guess_font_type( $href );
                echo '<link rel="preload" href="' . $href . '" as="font" type="' . esc_attr( $type ) . '" crossorigin />' . "\n";
            }
        }

        $styles = self::parse_list( HSP_Cache_Settings::get( 'render_preload_css', '' ) );
        foreach ( $styles as $style ) {
            $href = esc_url( $style );
            if ( $href ) {
                echo '<link rel="preload" href="' . $href . '" as="style" />' . "\n";
            }
        }
    }

    public static function output_critical_css() {
        if ( is_admin() ) {
            return;
        }
        $css = HSP_Cache_Settings::get( 'render_critical_css', '' );
        if ( empty( $css ) ) {
            return;
        }
        echo "<style id=\"hsp-critical-css\">\n" . $css . "\n</style>\n";
    }

    protected static function parse_list( $value ) {
        if ( empty( $value ) ) {
            return array();
        }
        $parts = preg_split( '/[\r\n,]+/', $value );
        $clean = array();
        foreach ( (array) $parts as $part ) {
            $part = trim( $part );
            if ( $part !== '' ) {
                $clean[] = $part;
            }
        }
        return array_unique( $clean );
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
