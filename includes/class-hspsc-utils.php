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
        if ( ! is_dir( $dir ) || is_link( $dir ) ) {
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
            if ( is_link( $path ) ) {
                wp_delete_file( $path );
            } elseif ( is_dir( $path ) ) {
                self::delete_dir_contents( $path );
                $fs->rmdir( $path, false );
            } else {
                $fs->delete( $path );
            }
        }
    }

    public static function is_request_cacheable() {
        // Sessions and no-cache flags can change after the initial cache lookup.
        if ( self::is_backend_or_login_request() || self::has_private_request_context() ) {
            return false;
        }
        if ( is_user_logged_in() && ! HSPSC_Settings::get( 'cache_logged_in' ) ) {
            return false;
        }
        if ( self::has_sensitive_cookies() || self::is_sensitive_request_uri() ) {
            return false;
        }
        if ( self::get_request_method() !== 'GET' ) {
            return false;
        }
        if ( is_preview() || is_feed() || is_robots() || is_trackback() || is_search() || is_404() || post_password_required() ) {
            return false;
        }
        if ( class_exists( 'HSPSC_WPML', false ) && null === HSPSC_WPML::request_context() ) {
            return false;
        }
        if ( HSPSC_Commerce::is_dynamic_request() ) {
            return false;
        }

        return (bool) apply_filters( 'hspsc_is_request_cacheable', true );
    }

    public static function is_backend_or_login_request() {
        if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
            return true;
        }
        if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
            return true;
        }

        $pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
        if ( $pagenow === 'wp-login.php' || $pagenow === 'wp-register.php' || self::is_editor_or_builder_request() ) {
            return true;
        }

        $uri = strtolower( rawurldecode( self::get_request_uri() ) );
        return strpos( $uri, '/wp-login.php' ) !== false
            || strpos( $uri, '/wp-register.php' ) !== false
            || strpos( $uri, '/wp-admin/' ) !== false;
    }

    public static function should_apply_frontend_optimizations() {
        if ( self::is_backend_or_login_request() || self::has_private_request_context() ) {
            return false;
        }
        if ( is_user_logged_in() && ! HSPSC_Settings::get( 'optimize_logged_in', false ) ) {
            return false;
        }
        if ( self::get_request_method() !== 'GET' || self::has_sensitive_cookies( false ) || self::is_sensitive_request_uri() ) {
            return false;
        }
        if ( class_exists( 'HSPSC_WPML', false ) && null === HSPSC_WPML::request_context() ) {
            return false;
        }
        if ( HSPSC_Commerce::is_dynamic_request() ) {
            return false;
        }

        return (bool) apply_filters( 'hspsc_should_apply_frontend_optimizations', true );
    }

    public static function is_editor_or_builder_request() {
        $args = self::get_request_query_args();
        if ( isset( $args['bricks'] ) && is_string( $args['bricks'] ) && in_array( $args['bricks'], array( 'run', 'preview' ), true ) ) {
            return true;
        }
        if ( isset( $args['kspreview'] ) && $args['kspreview'] === 'true' ) {
            return true;
        }
        return array_key_exists( 'customize_changeset_uuid', $args );
    }

    protected static function get_request_method() {
        return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
    }

    protected static function get_request_uri() {
        return isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
            ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    }

    protected static function get_request_query_args() {
        $args = array();
        $query = wp_parse_url( self::get_request_uri(), PHP_URL_QUERY );
        if ( is_string( $query ) ) {
            wp_parse_str( $query, $args );
        }
        if ( isset( $_SERVER['QUERY_STRING'] ) && is_string( $_SERVER['QUERY_STRING'] ) ) {
            $server_args = array();
            wp_parse_str( wp_unslash( $_SERVER['QUERY_STRING'] ), $server_args );
            $args = array_merge( $args, $server_args );
        }
        if ( ! empty( $_GET ) && is_array( $_GET ) ) {
            $args = array_merge( $args, wp_unslash( $_GET ) );
        }
        return array_change_key_case( $args, CASE_LOWER );
    }

    protected static function has_private_request_context() {
        if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
            return true;
        }
        if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
            return true;
        }
        foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'HTTP_X_WP_NONCE', 'HTTP_CART_TOKEN', 'HTTP_X_WC_STORE_API_NONCE' ) as $header ) {
            if ( isset( $_SERVER[ $header ] ) && $_SERVER[ $header ] !== '' ) {
                return true;
            }
        }
        if ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && is_string( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) === 'xmlhttprequest' ) {
            return true;
        }
        return false;
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
        if ( ! is_dir( $dir ) || is_link( $dir ) ) {
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
            if ( is_link( $path ) ) {
                continue;
            }
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
            if ( false !== $mtime && $mtime < $cutoff ) {
                // WordPress versions before 6.7 do not return a deletion result.
                wp_delete_file( $path );
                if ( ! file_exists( $path ) ) {
                    ++$deleted;
                }
            }
        }

        return $deleted;
    }

    public static function atomic_write( $path, $contents ) {
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        $tmp = self::temp_file( $dir, basename( $path ) );
        if ( ! $tmp ) {
            return false;
        }

        $bytes = file_put_contents( $tmp, $contents, LOCK_EX );
        if ( $bytes === false || $bytes < strlen( $contents ) ) {
            wp_delete_file( $tmp );
            return false;
        }

        @chmod( $tmp, 0644 );
        if ( @rename( $tmp, $path ) ) {
            return true;
        }

        wp_delete_file( $path );
        if ( @rename( $tmp, $path ) ) {
            return true;
        }

        wp_delete_file( $tmp );
        return false;
    }

    protected static function temp_file( $dir, $filename ) {
        if ( ! function_exists( 'wp_tempnam' ) && defined( 'ABSPATH' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if ( function_exists( 'wp_tempnam' ) ) {
            return wp_tempnam( $filename, $dir );
        }

        return tempnam( $dir, '.tmp-' );
    }

    public static function reset_request_cache() {
        self::$cacheable_request = array();
        self::$frontend_optimizations = array();
        self::$backend_or_login_request = array();
        self::$editor_or_builder_request = array();
    }

    protected static function has_sensitive_cookies( $include_authentication = true ) {
        if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
            return false;
        }

        $sensitive_prefixes = array(
            'wp-postpass_',
            'wp_woocommerce_session_',
            'store_notice',
            'edd_session_',
            'comment_author_',
        );
        if ( $include_authentication ) {
            $sensitive_prefixes[] = 'wordpress_logged_in_';
        }

        $sensitive_names = array(
            'woocommerce_items_in_cart',
            'woocommerce_cart_hash',
            'woocommerce_recently_viewed',
            'edd_items_in_cart',
            'easy_digital_downloads',
            'edd_cart_messages',
            'edd_purchase',
            'edd_cart_fees',
            'edd_resume_payment',
            'edd_cart',
            'edd_cart_token',
            'edd_saved_cart',
            'cart_discounts',
            'preset_discount',
            '_wp_session',
            'wordpress_no_cache',
            'iawp_ignore_visitor',
            'fct_cart_hash',
            'fct_currency',
            'client_currency',
            'client_currency_language',
            '_wcml_order_currency',
            'wcml_client_currency',
            'wcml_client_currency_language',
            'wcml_client_country',
            'PHPSESSID',
        );
        if ( function_exists( 'session_name' ) && session_name() !== '' ) {
            $sensitive_names[] = session_name();
        }

        foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
            $cookie_name = (string) $cookie_name;
            if ( in_array( $cookie_name, $sensitive_names, true ) ) {
                return true;
            }

            foreach ( $sensitive_prefixes as $prefix ) {
                if ( strpos( $cookie_name, $prefix ) === 0 ) {
                    return true;
                }
            }
        }

        return (bool) apply_filters( 'hspsc_has_sensitive_cookies', false, $_COOKIE );
    }

    protected static function is_sensitive_request_uri() {
        $args = self::get_request_query_args();
        $sensitive_args = array(
            'wc-ajax', 'wc-api', 'add-to-cart', 'remove_item', 'undo_item',
            'order-pay', 'order-received', 'download_file', 'key', '_wpnonce',
            'fct_cart_hash', 'fluent-cart', 'rest_route', 'preview',
            'wcmlc',
            'edd_action', 'add_to_cart', 'discount', 'payment_key', 'eddfile',
            'customize_changeset_uuid', 'token', 'access_token', 'cart_token', 'session_id',
        );
        foreach ( $sensitive_args as $arg ) {
            if ( array_key_exists( $arg, $args ) ) {
                return true;
            }
        }
        if ( isset( $args['action'] ) && is_string( $args['action'] ) && strpos( $args['action'], 'fluent_cart_' ) === 0 ) {
            return true;
        }

        $uri = strtolower( rawurldecode( self::get_request_uri() ) );
        $sensitive_fragments = array(
            '/cart',
            '/checkout',
            '/my-account',
            '/account',
            '/wp-json/',
            'wc-ajax=',
            'add-to-cart=',
            'preview=',
            'customize_changeset_uuid=',
        );

        foreach ( $sensitive_fragments as $fragment ) {
            if ( strpos( $uri, $fragment ) !== false ) {
                return true;
            }
        }

        return (bool) apply_filters( 'hspsc_is_sensitive_request_uri', false, $uri );
    }
}
