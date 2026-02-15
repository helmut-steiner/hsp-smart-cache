<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Smart_Cache_Minify {
    public static function init() {
        add_filter( 'style_loader_src', array( __CLASS__, 'filter_style_src' ), 20, 2 );
        add_filter( 'script_loader_src', array( __CLASS__, 'filter_script_src' ), 20, 2 );
    }

    public static function filter_style_src( $src, $handle ) {
        if ( ! HSP_Smart_Cache_Settings::get( 'minify_css' ) ) {
            return $src;
        }
        return self::maybe_minify_asset( $src, 'css' );
    }

    public static function filter_script_src( $src, $handle ) {
        if ( ! HSP_Smart_Cache_Settings::get( 'minify_js' ) ) {
            return $src;
        }
        return self::maybe_minify_asset( $src, 'js' );
    }

    protected static function maybe_minify_asset( $src, $type ) {
        if ( HSP_Smart_Cache_Utils::is_backend_or_login_request() ) {
            return $src;
        }

        if ( empty( $src ) ) {
            return $src;
        }

        $path = HSP_Smart_Cache_Utils::normalize_url_path( $src );
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
        $target   = HSP_SMART_CACHE_PATH . '/assets/' . $filename;

        if ( ! file_exists( $target ) ) {
            HSP_Smart_Cache_Utils::ensure_cache_dirs();
            $contents = file_get_contents( $file_path );
            if ( $contents === false ) {
                return $src;
            }
            $minified = ( $type === 'css' ) ? self::minify_css( $contents ) : self::minify_js( $contents );
            file_put_contents( $target, $minified );
        }

        return HSP_SMART_CACHE_URL . '/assets/' . $filename;
    }

    public static function maybe_send_asset_headers() {
        if ( HSP_Smart_Cache_Utils::is_backend_or_login_request() ) {
            return;
        }
        if ( ! HSP_Smart_Cache_Settings::get( 'browser_cache' ) ) {
            return;
        }
        if ( headers_sent() ) {
            return;
        }

        $path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( strpos( $path, '/wp-content/cache/hsp-cache/assets/' ) === false ) {
            return;
        }

        $ttl = intval( HSP_Smart_Cache_Settings::get( 'browser_cache_asset_ttl', 604800 ) );
        header( 'Cache-Control: public, max-age=' . $ttl . ', immutable' );
    }

    public static function minify_html( $html ) {
        if ( HSP_Smart_Cache_Settings::get( 'minify_css' ) ) {
            $html = preg_replace_callback(
                '/<style\b([^>]*)>([\s\S]*?)<\/style>/i',
                function( $matches ) {
                    $attrs   = $matches[1];
                    $content = $matches[2];

                    if ( ! self::is_inline_style_minifiable( $attrs ) ) {
                        return $matches[0];
                    }

                    return '<style' . $attrs . '>' . self::minify_css( $content ) . '</style>';
                },
                $html
            );
        }

        if ( HSP_Smart_Cache_Settings::get( 'minify_js' ) ) {
            $html = preg_replace_callback(
                '/<script\b([^>]*)>([\s\S]*?)<\/script>/i',
                function( $matches ) {
                    $attrs   = $matches[1];
                    $content = $matches[2];

                    if ( ! self::is_inline_script_minifiable( $attrs ) ) {
                        return $matches[0];
                    }

                    return '<script' . $attrs . '>' . self::minify_js( $content ) . '</script>';
                },
                $html
            );
        }

        $html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );
        $html = preg_replace( '/>\s+</', '><', $html );
        return trim( $html );
    }

    protected static function is_inline_style_minifiable( $attrs ) {
        $type = self::get_tag_attribute( $attrs, 'type' );
        if ( ! $type ) {
            return true;
        }

        $type = strtolower( trim( explode( ';', $type )[0] ) );
        return ( $type === 'text/css' || $type === 'css' || strpos( $type, 'css' ) !== false );
    }

    protected static function is_inline_script_minifiable( $attrs ) {
        if ( preg_match( '/\ssrc\s*=\s*/i', $attrs ) ) {
            return false;
        }

        $type = self::get_tag_attribute( $attrs, 'type' );
        if ( ! $type ) {
            return true;
        }

        $type = strtolower( trim( explode( ';', $type )[0] ) );
        $allowed = array(
            'text/javascript',
            'application/javascript',
            'application/x-javascript',
            'text/ecmascript',
            'application/ecmascript',
            'module',
        );

        return in_array( $type, $allowed, true );
    }

    protected static function get_tag_attribute( $attrs, $name ) {
        if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $matches ) ) {
            if ( ! empty( $matches[2] ) ) {
                return $matches[2];
            }
            if ( ! empty( $matches[3] ) ) {
                return $matches[3];
            }
            if ( ! empty( $matches[4] ) ) {
                return $matches[4];
            }
        }

        return '';
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
        HSP_Smart_Cache_Utils::delete_dir_contents( HSP_SMART_CACHE_PATH . '/assets' );
    }
}
