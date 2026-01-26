<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Cache_Minify {
    public static function init() {
        add_filter( 'style_loader_src', array( __CLASS__, 'filter_style_src' ), 20, 2 );
        add_filter( 'script_loader_src', array( __CLASS__, 'filter_script_src' ), 20, 2 );
    }

    public static function filter_style_src( $src, $handle ) {
        if ( ! HSP_Cache_Settings::get( 'minify_css' ) ) {
            return $src;
        }
        return self::maybe_minify_asset( $src, 'css' );
    }

    public static function filter_script_src( $src, $handle ) {
        if ( ! HSP_Cache_Settings::get( 'minify_js' ) ) {
            return $src;
        }
        return self::maybe_minify_asset( $src, 'js' );
    }

    protected static function maybe_minify_asset( $src, $type ) {
        if ( is_admin() ) {
            return $src;
        }

        if ( empty( $src ) ) {
            return $src;
        }

        $path = HSP_Cache_Utils::normalize_url_path( $src );
        if ( ! $path ) {
            return $src;
        }

        $file_path = wp_normalize_path( ABSPATH . ltrim( $path, '/' ) );
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return $src;
        }

        if ( preg_match( '/\.min\.' . preg_quote( $type, '/' ) . '$/i', $file_path ) ) {
            return $src;
        }

        $mtime    = filemtime( $file_path );
        $hash     = md5( $file_path . '|' . $mtime );
        $filename = $hash . '.min.' . $type;
        $target   = HSP_CACHE_PATH . '/assets/' . $filename;

        if ( ! file_exists( $target ) ) {
            HSP_Cache_Utils::ensure_cache_dirs();
            $contents = file_get_contents( $file_path );
            if ( $contents === false ) {
                return $src;
            }
            $minified = ( $type === 'css' ) ? self::minify_css( $contents ) : self::minify_js( $contents );
            file_put_contents( $target, $minified );
        }

        return HSP_CACHE_URL . '/assets/' . $filename;
    }

    public static function maybe_send_asset_headers() {
        if ( is_admin() ) {
            return;
        }
        if ( ! HSP_Cache_Settings::get( 'browser_cache' ) ) {
            return;
        }
        if ( headers_sent() ) {
            return;
        }

        $path = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos( $path, '/wp-content/cache/hsp-cache/assets/' ) === false ) {
            return;
        }

        $ttl = intval( HSP_Cache_Settings::get( 'browser_cache_asset_ttl', 604800 ) );
        header( 'Cache-Control: public, max-age=' . $ttl . ', immutable' );
    }

    public static function minify_html( $html ) {
        $html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );
        $html = preg_replace( '/>\s+</', '><', $html );
        return trim( $html );
    }

    public static function minify_css( $css ) {
        $css = preg_replace( '!/\*.*?\*/!s', '', $css );
        $css = preg_replace( '/\s+/', ' ', $css );
        $css = str_replace( array( " \n", "\n ", "\n", "\t" ), '', $css );
        $css = preg_replace( '/\s*([{}|:;,])\s+/', '$1', $css );
        $css = preg_replace( '/;}/', '}', $css );
        return trim( $css );
    }

    public static function minify_js( $js ) {
        // Conservative minifier to avoid breaking JS (e.g., regex literals, template strings).
        $js = preg_replace( '/\/\*[\s\S]*?\*\//', '', $js );
        $js = str_replace( "\r", "", $js );
        return trim( $js );
    }

    public static function clear_cache() {
        HSP_Cache_Utils::delete_dir_contents( HSP_CACHE_PATH . '/assets' );
    }
}
