<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Utils {
    protected static $cacheable_request = array();
    protected static $frontend_optimizations = array();
    protected static $backend_or_login_request = array();
    protected static $editor_or_builder_request = array();
    protected static $cache_dirs_ensured = false;

    public static function get_filesystem() {
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem;
    }

    public static function ensure_cache_dirs() {
        if ( self::$cache_dirs_ensured ) {
            return;
        }

        $paths = array(
            HSPSC_PATH,
            HSPSC_PATH . '/pages',
            HSPSC_PATH . '/assets',
            HSPSC_PATH . '/object',
        );

        foreach ( $paths as $path ) {
            if ( ! is_dir( $path ) ) {
                wp_mkdir_p( $path );
            }
        }

        self::$cache_dirs_ensured = true;
    }

    public static function apply_robots_rules( $output, $public ) {
        if ( ! $public ) {
            return $output;
        }
        if ( ! HSPSC_Settings::get( 'robots_disallow_ai' ) ) {
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
        $signature = self::get_request_signature();
        if ( array_key_exists( $signature, self::$cacheable_request ) ) {
            return self::$cacheable_request[ $signature ];
        }

        if ( self::is_backend_or_login_request() ) {
            self::$cacheable_request[ $signature ] = false;
            return self::$cacheable_request[ $signature ];
        }
        if ( is_user_logged_in() && ! HSPSC_Settings::get( 'cache_logged_in' ) ) {
            self::$cacheable_request[ $signature ] = false;
            return self::$cacheable_request[ $signature ];
        }
        if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
            self::$cacheable_request[ $signature ] = false;
            return self::$cacheable_request[ $signature ];
        }
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        if ( $method !== 'GET' ) {
            self::$cacheable_request[ $signature ] = false;
            return self::$cacheable_request[ $signature ];
        }
        if ( is_preview() || is_feed() || is_robots() || is_trackback() ) {
            self::$cacheable_request[ $signature ] = false;
            return self::$cacheable_request[ $signature ];
        }
        self::$cacheable_request[ $signature ] = true;
        return self::$cacheable_request[ $signature ];
    }

    public static function is_backend_or_login_request() {
        $signature = self::get_request_signature();
        if ( array_key_exists( $signature, self::$backend_or_login_request ) ) {
            return self::$backend_or_login_request[ $signature ];
        }

        if ( is_admin() ) {
            self::$backend_or_login_request[ $signature ] = true;
            return self::$backend_or_login_request[ $signature ];
        }

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            self::$backend_or_login_request[ $signature ] = true;
            return self::$backend_or_login_request[ $signature ];
        }

        $pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
        if ( $pagenow === 'wp-login.php' || $pagenow === 'wp-register.php' ) {
            self::$backend_or_login_request[ $signature ] = true;
            return self::$backend_or_login_request[ $signature ];
        }

        if ( self::is_editor_or_builder_request() ) {
            self::$backend_or_login_request[ $signature ] = true;
            return self::$backend_or_login_request[ $signature ];
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '';
        if ( $uri === '' ) {
            self::$backend_or_login_request[ $signature ] = false;
            return self::$backend_or_login_request[ $signature ];
        }

        self::$backend_or_login_request[ $signature ] = strpos( $uri, '/wp-login.php' ) !== false
            || strpos( $uri, '/wp-register.php' ) !== false
            || strpos( $uri, '/wp-admin/' ) !== false;
        return self::$backend_or_login_request[ $signature ];
    }

    public static function should_apply_frontend_optimizations() {
        $signature = self::get_request_signature();
        if ( array_key_exists( $signature, self::$frontend_optimizations ) ) {
            return self::$frontend_optimizations[ $signature ];
        }

        if ( self::is_backend_or_login_request() ) {
            self::$frontend_optimizations[ $signature ] = false;
            return self::$frontend_optimizations[ $signature ];
        }

        if ( is_user_logged_in() && ! HSPSC_Settings::get( 'optimize_logged_in', false ) ) {
            self::$frontend_optimizations[ $signature ] = false;
            return self::$frontend_optimizations[ $signature ];
        }

        self::$frontend_optimizations[ $signature ] = true;
        return self::$frontend_optimizations[ $signature ];
    }

    public static function is_editor_or_builder_request() {
        $signature = self::get_request_signature();
        if ( array_key_exists( $signature, self::$editor_or_builder_request ) ) {
            return self::$editor_or_builder_request[ $signature ];
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '';
        $query = isset( $_SERVER['QUERY_STRING'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) ) : '';

        if ( strpos( $uri, 'bricks=run' ) !== false || strpos( $query, 'bricks=run' ) !== false ) {
            self::$editor_or_builder_request[ $signature ] = true;
            return self::$editor_or_builder_request[ $signature ];
        }

        if ( strpos( $uri, 'bricks=preview' ) !== false || strpos( $query, 'bricks=preview' ) !== false ) {
            self::$editor_or_builder_request[ $signature ] = true;
            return self::$editor_or_builder_request[ $signature ];
        }

        if ( strpos( $uri, 'kspreview=true' ) !== false || strpos( $query, 'kspreview=true' ) !== false ) {
            self::$editor_or_builder_request[ $signature ] = true;
            return self::$editor_or_builder_request[ $signature ];
        }

        if ( isset( $_GET['customize_changeset_uuid'] ) ) {
            self::$editor_or_builder_request[ $signature ] = true;
            return self::$editor_or_builder_request[ $signature ];
        }

        self::$editor_or_builder_request[ $signature ] = false;
        return self::$editor_or_builder_request[ $signature ];
    }

    public static function normalize_url_path( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['path'] ) ) {
            return null;
        }

        if ( ! empty( $parts['host'] ) ) {
            $allowed_hosts = array_filter(
                array(
                    wp_parse_url( home_url(), PHP_URL_HOST ),
                    wp_parse_url( site_url(), PHP_URL_HOST ),
                    wp_parse_url( content_url(), PHP_URL_HOST ),
                )
            );

            $host = strtolower( $parts['host'] );
            $allowed_hosts = array_map( 'strtolower', array_unique( $allowed_hosts ) );

            if ( ! in_array( $host, $allowed_hosts, true ) ) {
                return null;
            }
        }

        return '/' . ltrim( $parts['path'], '/' );
    }

    public static function delete_old_files( $dir, $max_age, $extension = '' ) {
        if ( ! is_dir( $dir ) ) {
            return 0;
        }

        $deleted = 0;
        $items = scandir( $dir );
        if ( ! $items ) {
            return 0;
        }

        $max_age = max( 1, intval( $max_age ) );
        $cutoff = time() - $max_age;

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $path = $dir . '/' . $item;
            if ( is_dir( $path ) ) {
                $deleted += self::delete_old_files( $path, $max_age, $extension );
                $remaining = scandir( $path );
                if ( $remaining && count( array_diff( $remaining, array( '.', '..' ) ) ) === 0 ) {
                    @rmdir( $path );
                }
                continue;
            }

            if ( $extension !== '' && substr( $path, -strlen( $extension ) ) !== $extension ) {
                continue;
            }

            $mtime = filemtime( $path );
            if ( $mtime !== false && $mtime < $cutoff && wp_delete_file( $path ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public static function reset_request_cache() {
        self::$cacheable_request = array();
        self::$frontend_optimizations = array();
        self::$backend_or_login_request = array();
        self::$editor_or_builder_request = array();
    }

    protected static function get_request_signature() {
        $server_parts = array(
            isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : '',
            isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
            isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : '',
            isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '',
            is_admin() ? 'admin' : 'front',
            function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ? 'ajax' : 'normal',
            is_user_logged_in() ? 'logged-in' : 'guest',
            HSPSC_Settings::get( 'cache_logged_in' ) ? 'cache-users' : 'guest-only',
            HSPSC_Settings::get( 'optimize_logged_in', false ) ? 'opt-users' : 'guest-opt',
        );

        return md5( implode( '|', $server_parts ) );
    }
}
