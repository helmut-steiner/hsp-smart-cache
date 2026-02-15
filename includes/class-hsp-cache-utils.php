<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Smart_Cache_Utils {
    public static function get_filesystem() {
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem;
    }

    public static function ensure_cache_dirs() {
        $paths = array(
            HSP_SMART_CACHE_PATH,
            HSP_SMART_CACHE_PATH . '/pages',
            HSP_SMART_CACHE_PATH . '/assets',
            HSP_SMART_CACHE_PATH . '/object',
        );

        foreach ( $paths as $path ) {
            if ( ! is_dir( $path ) ) {
                wp_mkdir_p( $path );
            }
        }
    }

    public static function apply_robots_rules( $output, $public ) {
        if ( ! $public ) {
            return $output;
        }
        if ( ! HSP_Smart_Cache_Settings::get( 'robots_disallow_ai' ) ) {
            return $output;
        }

        $output = trim( (string) $output );
        if ( $output !== '' ) {
            $output .= "\n";
        }

        $output .= implode( "\n", self::get_ai_robots_lines() );

        return $output . "\n";
    }

    protected static function get_ai_robots_lines() {
        return array(
            'User-agent: GPTBot',
            'Disallow: /',
            '',
            'User-agent: ChatGPT-User',
            'Disallow: /',
            '',
            'User-agent: CCBot',
            'Disallow: /',
            '',
            'User-agent: ClaudeBot',
            'Disallow: /',
            '',
            'User-agent: Claude-Web',
            'Disallow: /',
            '',
            'User-agent: Applebot',
            'Disallow: /',
            '',
            'User-agent: Google-Extended',
            'Disallow: /',
            '',
            'User-agent: PerplexityBot',
            'Disallow: /',
            '',
            'User-agent: YouBot',
            'Disallow: /',
            '',
            'User-agent: Bytespider',
            'Disallow: /',
            '',
            'User-agent: Amazonbot',
            'Disallow: /',
        );
    }

    public static function delete_dir_contents( $dir ) {
        $fs = self::get_filesystem();
        if ( ! $fs ) {
            return;
        }
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = scandir( $dir );
        if ( ! $items ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) ) {
                self::delete_dir_contents( $path );
                $fs->rmdir( $path, false );
            } else {
                $fs->delete( $path );
            }
        }
    }

    public static function is_request_cacheable() {
        if ( self::is_backend_or_login_request() ) {
            return false;
        }
        if ( is_user_logged_in() && ! HSP_Smart_Cache_Settings::get( 'cache_logged_in' ) ) {
            return false;
        }
        if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
            return false;
        }
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        if ( $method !== 'GET' ) {
            return false;
        }
        if ( is_preview() || is_feed() || is_robots() || is_trackback() ) {
            return false;
        }
        return true;
    }

    public static function is_backend_or_login_request() {
        if ( is_admin() ) {
            return true;
        }

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return true;
        }

        $pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
        if ( $pagenow === 'wp-login.php' || $pagenow === 'wp-register.php' ) {
            return true;
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '';
        if ( $uri === '' ) {
            return false;
        }

        return strpos( $uri, '/wp-login.php' ) !== false
            || strpos( $uri, '/wp-register.php' ) !== false
            || strpos( $uri, '/wp-admin/' ) !== false;
    }

    public static function normalize_url_path( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['path'] ) ) {
            return null;
        }
        return '/' . ltrim( $parts['path'], '/' );
    }
}
