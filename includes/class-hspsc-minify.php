<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Minify {
    protected static $asset_url_cache = array();

    public static function init() {
        add_filter( 'style_loader_src', array( __CLASS__, 'filter_style_src' ), 20, 2 );
        add_filter( 'script_loader_src', array( __CLASS__, 'filter_script_src' ), 20, 2 );
    }

    public static function filter_style_src( $src, $handle ) {
        if ( ! HSPSC_Settings::get( 'minify_css' ) ) {
            return $src;
        }
        return self::maybe_minify_asset( $src, 'css' );
    }

    public static function filter_script_src( $src, $handle ) {
        if ( ! HSPSC_Settings::get( 'minify_js' ) ) {
            return $src;
        }
        return self::maybe_minify_asset( $src, 'js' );
    }

    protected static function maybe_minify_asset( $src, $type ) {
        $cache_key = $type . '|' . $src;
        if ( array_key_exists( $cache_key, self::$asset_url_cache ) ) {
            return self::$asset_url_cache[ $cache_key ];
        }

        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            self::$asset_url_cache[ $cache_key ] = $src;
            return $src;
        }

        if ( empty( $src ) ) {
            self::$asset_url_cache[ $cache_key ] = $src;
            return $src;
        }

        $path = HSPSC_Utils::normalize_url_path( $src );
        if ( ! $path ) {
            self::$asset_url_cache[ $cache_key ] = $src;
            return $src;
        }

        $file_path = self::resolve_asset_file_path( $path, $type );
        if ( ! $file_path ) {
            self::$asset_url_cache[ $cache_key ] = $src;
            return $src;
        }
        if ( self::is_minified_asset( $file_path, $type ) ) {
            self::$asset_url_cache[ $cache_key ] = $src;
            return $src;
        }

        $mtime = filemtime( $file_path );
        if ( $mtime === false ) {
            self::$asset_url_cache[ $cache_key ] = $src;
            return $src;
        }

        $file_size = filesize( $file_path );
        $max_size  = (int) apply_filters( 'hspsc_max_minify_asset_size', 5 * 1024 * 1024, $type, $file_path );
        if ( $file_size === false || $file_size > max( 1, $max_size ) ) {
            self::$asset_url_cache[ $cache_key ] = $src;
            return $src;
        }

        $hash     = md5( $file_path . '|' . $mtime );
        $filename = $hash . '.min.' . $type;
        $target   = HSPSC_PATH . '/assets/' . $filename;

        if ( ! file_exists( $target ) ) {
            HSPSC_Utils::ensure_cache_dirs();
            $contents = file_get_contents( $file_path );
            if ( $contents === false ) {
                self::$asset_url_cache[ $cache_key ] = $src;
                return $src;
            }
            $minified = ( $type === 'css' ) ? self::minify_css( $contents ) : self::minify_js( $contents );
            if ( HSPSC_Utils::atomic_write( $target, $minified ) ) {
                self::maybe_cleanup_asset_cache();
            } else {
                self::$asset_url_cache[ $cache_key ] = $src;
                return $src;
            }
        }

        self::$asset_url_cache[ $cache_key ] = HSPSC_URL . '/assets/' . $filename;
        return self::$asset_url_cache[ $cache_key ];
    }

    protected static function resolve_asset_file_path( $url_path, $type ) {
        $file_path = ABSPATH . ltrim( $url_path, '/' );
        $real_path = realpath( $file_path );

        if ( ! $real_path || ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
            return null;
        }

        if ( ! self::is_allowed_asset_extension( $real_path, $type ) ) {
            return null;
        }

        if ( ! self::is_path_in_allowed_asset_root( $real_path ) ) {
            return null;
        }

        return wp_normalize_path( $real_path );
    }

    protected static function is_allowed_asset_extension( $file_path, $type ) {
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $allowed   = apply_filters(
            'hspsc_minify_asset_extensions',
            array(
                'css' => array( 'css' ),
                'js'  => array( 'js', 'mjs' ),
            )
        );

        if ( empty( $allowed[ $type ] ) || ! is_array( $allowed[ $type ] ) ) {
            return false;
        }

        return in_array( $extension, array_map( 'strtolower', $allowed[ $type ] ), true );
    }

    protected static function is_minified_asset( $file_path, $type ) {
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        return self::is_allowed_asset_extension( $file_path, $type )
            && preg_match( '/\.min\.' . preg_quote( $extension, '/' ) . '$/i', $file_path );
    }

    protected static function is_path_in_allowed_asset_root( $file_path ) {
        $roots = array( ABSPATH );

        foreach ( array( 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
            if ( defined( $constant ) ) {
                $roots[] = constant( $constant );
            }
        }

        if ( function_exists( 'get_theme_root' ) ) {
            $roots[] = get_theme_root();
        }

        $roots     = (array) apply_filters( 'hspsc_minify_asset_roots', array_filter( array_unique( $roots ) ) );
        $file_path = wp_normalize_path( $file_path );

        foreach ( $roots as $root ) {
            $real_root = realpath( $root );
            if ( ! $real_root ) {
                continue;
            }

            $real_root = rtrim( wp_normalize_path( $real_root ), '/' );
            if ( $file_path === $real_root || strpos( $file_path, $real_root . '/' ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    public static function maybe_send_asset_headers() {
        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            return;
        }
        if ( ! HSPSC_Settings::get( 'browser_cache' ) ) {
            return;
        }
        if ( headers_sent() ) {
            return;
        }

        $path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( strpos( $path, '/wp-content/cache/hspsc/assets/' ) === false ) {
            return;
        }

        $ttl = intval( HSPSC_Settings::get( 'browser_cache_asset_ttl', 604800 ) );
        header( 'Cache-Control: public, max-age=' . $ttl . ', immutable' );
    }

    public static function minify_html( $html ) {
        if ( HSPSC_Settings::get( 'minify_css' ) ) {
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

        if ( HSPSC_Settings::get( 'minify_js' ) ) {
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
        HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/assets' );
        self::$asset_url_cache = array();
    }

    protected static function maybe_cleanup_asset_cache() {
        if ( get_transient( 'hspsc_asset_cache_gc' ) ) {
            return;
        }

        set_transient( 'hspsc_asset_cache_gc', 1, DAY_IN_SECONDS );
        self::cleanup_old_assets();
    }

    public static function cleanup_old_assets() {
        return HSPSC_Utils::delete_old_files( HSPSC_PATH . '/assets', MONTH_IN_SECONDS );
    }
}
