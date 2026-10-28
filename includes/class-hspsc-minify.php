<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Minify {
    protected static $asset_url_cache = array();
    protected static $original_script_urls = array();
    protected static $original_style_urls = array();

    public static function init() {
        add_filter( 'style_loader_src', array( __CLASS__, 'filter_style_src' ), 20, 2 );
        add_filter( 'script_loader_src', array( __CLASS__, 'filter_script_src' ), 20, 2 );
        add_filter( 'script_loader_tag', array( __CLASS__, 'restore_excluded_script_url' ), PHP_INT_MAX, 3 );
        add_filter( 'style_loader_tag', array( __CLASS__, 'restore_excluded_style_url' ), PHP_INT_MAX, 4 );
    }

    public static function filter_style_src( $src, $handle ) {
        global $wp_styles;

        if ( ! HSPSC_Settings::get( 'minify_css' ) ) {
            return $src;
        }
        if ( $wp_styles instanceof WP_Styles && isset( $wp_styles->registered[ $handle ] ) && self::has_excluded_metadata( (array) $wp_styles->registered[ $handle ]->extra ) ) {
            return $src;
        }
        self::$original_style_urls[ $handle ] = $src;
        return self::maybe_minify_asset( $src, 'css' );
    }

    public static function filter_script_src( $src, $handle ) {
        if ( ! HSPSC_Settings::get( 'minify_js' ) || self::is_script_excluded( $handle, $src ) ) {
            return $src;
        }
        self::$original_script_urls[ $handle ] = $src;
        return self::maybe_minify_asset( $src, 'js' );
    }

    public static function is_script_excluded( $handle, $src = '' ) {
        global $wp_scripts;

        $patterns = (array) apply_filters(
            'hspsc_script_optimization_exclusions',
			array(
				'jquery',
				'jquery-*',
				'wc-*',
				'woocommerce*',
				'fluent-cart-*',
				'fluentcart-*',
				'fct-*',
				'wpml-*',
				'sitepress-*',
				'wcml-*',
				'browser-redirect',
				'jquery-cookie',
				'stripe*',
				'paypal*',
				'cloudflare-turnstile',
			)
        );
        foreach ( $patterns as $pattern ) {
            $pattern = (string) $pattern;
            if ( $pattern === $handle || ( substr( $pattern, -1 ) === '*' && strpos( $handle, substr( $pattern, 0, -1 ) ) === 0 ) ) {
                return true;
            }
        }

        if ( preg_match( '~(?:js\.stripe\.com|paypal\.com/sdk/|challenges\.cloudflare\.com/)~i', $src ) ) {
            return true;
        }

        if ( $wp_scripts instanceof WP_Scripts && isset( $wp_scripts->registered[ $handle ] ) ) {
            $extra = (array) $wp_scripts->registered[ $handle ]->extra;
            return self::has_excluded_metadata( $extra );
        }

        return false;
    }

    protected static function has_excluded_metadata( $extra ) {
        $attributes = isset( $extra['attributes'] ) && is_array( $extra['attributes'] ) ? $extra['attributes'] : array();
        foreach ( array( $extra, $attributes ) as $metadata ) {
            if ( array_key_exists( 'data-no-optimize', $metadata ) || array_key_exists( 'data-cfasync', $metadata ) || ( isset( $metadata['type'] ) && strtolower( $metadata['type'] ) === 'module' ) ) {
                return true;
            }
        }
        return false;
    }

    public static function restore_excluded_script_url( $tag, $handle, $src ) {
        if ( isset( self::$original_script_urls[ $handle ] ) && ( ! HSPSC_Utils::should_apply_frontend_optimizations() || self::has_optimization_exclusion( $tag ) || strtolower( self::get_tag_attribute( $tag, 'type' ) ) === 'module' ) ) {
            return str_replace( esc_url( $src ), esc_url( self::$original_script_urls[ $handle ] ), $tag );
        }
        return $tag;
    }

    public static function restore_excluded_style_url( $tag, $handle, $href, $media = '' ) {
        if ( isset( self::$original_style_urls[ $handle ] ) && ( ! HSPSC_Utils::should_apply_frontend_optimizations() || self::has_optimization_exclusion( $tag ) ) ) {
            return str_replace( esc_url( $href ), esc_url( self::$original_style_urls[ $handle ] ), $tag );
        }
        return $tag;
    }

    protected static function has_optimization_exclusion( $attrs ) {
        return (bool) preg_match( '/\sdata-(?:no-optimize|cfasync)(?:\s|=|>|$)/i', $attrs );
    }

    protected static function maybe_minify_asset( $src, $type ) {
        // Sessions and no-cache signals can change after an earlier asset lookup.
        // Check them before memoization, and never memoize an eligibility bypass.
        if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
            return $src;
        }

        $cache_key = $type . '|' . $src;
        if ( array_key_exists( $cache_key, self::$asset_url_cache ) ) {
            return self::$asset_url_cache[ $cache_key ];
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

        // Invalidate assets generated by the previous, unsafe minifiers.
        $hash     = md5( 'hspsc-assets-v2|' . $file_path . '|' . $mtime );
        $filename = $hash . '.min.' . $type;
        $target   = HSPSC_PATH . '/assets/' . $filename;

        if ( is_link( $target ) || ( file_exists( $target ) && ( ! is_file( $target ) || ! is_readable( $target ) ) ) ) {
            self::$asset_url_cache[ $cache_key ] = $src;
            return $src;
        }
        if ( ! file_exists( $target ) ) {
            // Existing v2 entries have already passed the source relocation checks.
            $contents = static::read_asset_contents( $file_path );
            if ( $contents === false || ! self::can_relocate_asset( $contents, $file_path, $type ) ) {
                self::$asset_url_cache[ $cache_key ] = $src;
                return $src;
            }
            HSPSC_Utils::ensure_cache_dirs();
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

	/**
	 * Reads an asset only when a generated entry needs to be built.
	 *
	 * @param string $file_path Validated local asset path.
	 * @return string|false Source contents, or false on failure.
	 */
	protected static function read_asset_contents( $file_path ) {
		return file_get_contents( $file_path );
	}

    protected static function can_relocate_asset( $contents, $file_path, $type ) {
        if ( $type === 'css' ) {
            // Relative references resolve against the original stylesheet URL.
            return ! preg_match( '/(?:url\s*\(|@import\b)/i', $contents );
        }

        // Module URLs and relative chunks must stay in their original directory.
        return strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) !== 'mjs'
            && ! preg_match( '/\b(?:import|export)\b|currentScript/', $contents );
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

        if ( ! self::is_generated_asset_request() ) {
            return;
        }

        $ttl = intval( HSPSC_Settings::get( 'browser_cache_asset_ttl', 604800 ) );
        header( 'Cache-Control: public, max-age=' . $ttl . ', immutable' );
    }

    protected static function is_generated_asset_request() {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ( $method !== 'GET' || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }
        $path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        $prefix = wp_parse_url( HSPSC_URL . '/assets/', PHP_URL_PATH );
        if ( ! is_string( $path ) || ! $prefix || ! preg_match( '~^' . preg_quote( $prefix, '~' ) . '[a-f0-9]{32}\.min\.(?:css|js)$~D', $path ) ) {
            return false;
        }
        $target = HSPSC_PATH . '/assets/' . basename( $path );
        $root = realpath( HSPSC_PATH . '/assets' );
        $real = realpath( $target );
        return $root && $real && ! is_link( $target ) && is_file( $real )
            && strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) === 0;
    }

    public static function minify_html( $html ) {
        if ( preg_match( '/<(?:template|plaintext|xmp|listing)\b/i', $html ) ) {
            // Templates may nest and contain markup with significant whitespace.
            return $html;
        }
        // Protect text-sensitive elements before processing any inline markup.
        $protected = array();
        $prefix = 'HSPSC' . wp_generate_password( 20, false, false );
        $html = preg_replace_callback(
            '~<(pre|textarea|template|script|style)\b[^>]*>[\s\S]*?</\1\s*>~i',
            static function ( $matches ) use ( &$protected, $prefix ) {
                $key = $prefix . count( $protected );
                $protected[ $key ] = $matches[0];
                return $key;
            },
            $html
        );

        // Scripts and styles are preserved here: their contents are not HTML.
        $html = preg_replace( '/<!--(?!\s*(?:\[if|\/?(?:wp:|ko\b)))[\s\S]*?-->/', '', $html );
        return strtr( trim( $html ), $protected );
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
        // Complex CSS needs a parser; preserve strings, escapes and custom values.
        if ( preg_match( '~[\'"\\\\]|/\*|--~', $css ) ) {
            return trim( $css );
        }
        $css = preg_replace( '/\s+/', ' ', $css );
        $css = preg_replace( '/\s*([{};,])\s*/', '$1', $css );
        $css = preg_replace( '/:\s+/', ':', $css );
        $css = preg_replace( '/;}/', '}', $css );
        return trim( $css );
    }

    public static function minify_js( $js ) {
        // Without a JavaScript parser, comments cannot be distinguished reliably
        // from strings, template literals and regular expressions.
        return trim( $js );
    }

    public static function clear_cache() {
        HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/assets' );
        self::$asset_url_cache = array();
        self::$original_script_urls = array();
        self::$original_style_urls = array();
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
