<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Render {
    protected static $parsed_list_cache = array();
    protected static $managed_strategies = array();

    public static function init() {
        add_action( 'wp_print_scripts', array( __CLASS__, 'apply_script_strategies' ), PHP_INT_MAX );
        add_action( 'wp_print_footer_scripts', array( __CLASS__, 'apply_script_strategies' ), 0 );
        add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_tag' ), PHP_INT_MAX - 1, 3 );
        add_action( 'wp_head', array( __CLASS__, 'output_preconnects' ), 1 );
        add_action( 'wp_head', array( __CLASS__, 'output_preloads' ), 2 );
        add_action( 'wp_head', array( __CLASS__, 'output_critical_css' ), 3 );
    }

    public static function apply_script_strategies() {
        global $wp_scripts;

        $defer = HSPSC_Settings::get( 'render_defer_js' );
        $async = HSPSC_Settings::get( 'render_async_js' );
        if ( ! $defer && ! $async ) {
            return;
        }
        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            return;
        }

        // WordPress 6.3+ resolves eligible strategies across the dependency tree.
        // Older versions must keep their blocking tags instead of guessing order.
        if ( ! ( $wp_scripts instanceof WP_Scripts ) || ! method_exists( $wp_scripts, 'get_eligible_loading_strategy' ) ) {
            return;
        }

        $defer_exclusions = self::parse_list( HSPSC_Settings::get( 'render_defer_exclusions', '' ) );
        $async_exclusions = self::parse_list( HSPSC_Settings::get( 'render_async_exclusions', '' ) );

        // Resolve dependency membership once, including scripts registered for later output.
        $has_dependents = array();
        foreach ( $wp_scripts->registered as $registered ) {
            foreach ( (array) $registered->deps as $dependency ) {
                $has_dependents[ $dependency ] = true;
            }
        }
        foreach ( $wp_scripts->registered as $handle => $registered ) {
            if ( ! $registered->src || in_array( $handle, $wp_scripts->done, true ) || $wp_scripts->get_data( $handle, 'strategy' ) ) {
                continue;
            }
            if ( in_array( $handle, $defer_exclusions, true ) || in_array( $handle, $async_exclusions, true ) || self::script_has_inline_extras( $handle ) || isset( $has_dependents[ $handle ] ) || HSPSC_Minify::is_script_excluded( $handle, $registered->src ) ) {
                continue;
            }
            $strategy = $defer ? 'defer' : 'async';
            if ( wp_script_add_data( $handle, 'strategy', $strategy ) ) {
                self::$managed_strategies[ $handle ] = array( 'script' => $registered, 'strategy' => $strategy );
            }
        }
    }

    public static function filter_script_tag( $tag, $handle, $src ) {
        global $wp_scripts;

        $managed = isset( self::$managed_strategies[ $handle ] ) ? self::$managed_strategies[ $handle ] : null;
        $owns_strategy = $managed && $wp_scripts instanceof WP_Scripts && isset( $wp_scripts->registered[ $handle ] )
            && $managed['script'] === $wp_scripts->registered[ $handle ] && $managed['strategy'] === $wp_scripts->get_data( $handle, 'strategy' );
        if ( $owns_strategy && ( ! HSPSC_Utils::should_apply_frontend_optimizations() || preg_match( '/\s(?:data-no-optimize|data-cfasync|type\s*=\s*[\'\"]?module)(?:\s|=|[\'\"]|>)/i', $tag ) ) ) {
            // Late opt-out attributes can come from another tag filter. Only leaf
            // scripts receive our strategies, so returning to blocking is safe.
            wp_script_add_data( $handle, 'strategy', '' );
            unset( self::$managed_strategies[ $handle ] );
            $attribute = preg_quote( $managed['strategy'], '/' );
            return preg_replace_callback(
                '/<script\b[^>]*>/i',
                static function ( $matches ) use ( $attribute ) {
                    if ( ! preg_match( '/\ssrc\s*=/i', $matches[0] ) ) {
                        return $matches[0];
                    }
                    return preg_replace( '/\s' . $attribute . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|>)/i', '', $matches[0] );
                },
                $tag
            );
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
