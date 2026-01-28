<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Smart_Cache_Page {
    protected static $buffer_started = false;

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_cache' ), 0 );
        add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
        add_action( 'shutdown', array( __CLASS__, 'save_buffer' ), 0 );
    }

    public static function maybe_serve_cache() {
        if ( ! HSP_Smart_Cache_Settings::get( 'page_cache' ) ) {
            return;
        }
        if ( ! HSP_Smart_Cache_Utils::is_request_cacheable() ) {
            return;
        }

        $cache_file = self::get_cache_file_path();
        if ( ! $cache_file ) {
            return;
        }

        $ttl = intval( HSP_Smart_Cache_Settings::get( 'page_cache_ttl', 3600 ) );
        if ( file_exists( $cache_file ) ) {
            $age = time() - filemtime( $cache_file );
            if ( $age <= $ttl ) {
                self::send_browser_cache_headers( $cache_file );
                header( 'X-HSP-Cache: HIT' );
                $fs = HSP_Smart_Cache_Utils::get_filesystem();
                if ( $fs ) {
                    $contents = $fs->get_contents( $cache_file );
                    if ( $contents !== false ) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $contents;
                    }
                } else {
                    $contents = file_get_contents( $cache_file );
                    if ( $contents !== false ) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $contents;
                    }
                }
                exit;
            }
        }
    }

    public static function start_buffer() {
        if ( ! HSP_Smart_Cache_Settings::get( 'page_cache' ) ) {
            return;
        }
        if ( ! HSP_Smart_Cache_Utils::is_request_cacheable() ) {
            return;
        }

        if ( ! self::$buffer_started ) {
            ob_start();
            self::$buffer_started = true;
        }
    }

    public static function save_buffer() {
        if ( ! self::$buffer_started ) {
            return;
        }

        $html = ob_get_contents();
        if ( $html === false ) {
            return;
        }

        if ( ! self::should_cache_response() ) {
            ob_end_flush();
            return;
        }

        if ( HSP_Smart_Cache_Settings::get( 'minify_html' ) ) {
            $html = HSP_Smart_Cache_Minify::minify_html( $html );
        }

        $cache_file = self::get_cache_file_path();
        if ( $cache_file ) {
            HSP_Smart_Cache_Utils::ensure_cache_dirs();
            file_put_contents( $cache_file, $html );
            self::send_browser_cache_headers( $cache_file, true );
            header( 'X-HSP-Cache: MISS' );
        }

        ob_end_flush();
    }

    protected static function should_cache_response() {
        $status = function_exists( 'http_response_code' ) ? http_response_code() : 200;
        if ( $status >= 300 && $status < 400 ) {
            return false;
        }

        foreach ( headers_list() as $header ) {
            if ( stripos( $header, 'Location:' ) === 0 ) {
                return false;
            }
        }

        return true;
    }

    protected static function send_browser_cache_headers( $cache_file = null, $is_miss = false ) {
        if ( headers_sent() ) {
            return;
        }
        if ( ! HSP_Smart_Cache_Settings::get( 'browser_cache' ) ) {
            return;
        }

        $ttl = intval( HSP_Smart_Cache_Settings::get( 'browser_cache_html_ttl', 600 ) );
        $is_logged_in = is_user_logged_in();

        if ( $is_logged_in && ! HSP_Smart_Cache_Settings::get( 'cache_logged_in' ) ) {
            return;
        }

        if ( $is_logged_in ) {
            header( 'Cache-Control: private, max-age=' . $ttl );
        } else {
            header( 'Cache-Control: public, max-age=' . $ttl . ', s-maxage=' . $ttl );
        }

        if ( $cache_file && file_exists( $cache_file ) ) {
            $etag = '"' . md5( $cache_file . '|' . filemtime( $cache_file ) ) . '"';
            header( 'ETag: ' . $etag );

            $if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
            if ( ! $is_miss && $if_none_match === $etag ) {
                status_header( 304 );
                exit;
            }
        }
    }

    protected static function get_cache_key() {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'localhost';
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        $ssl  = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';

        return md5( $ssl . '://' . $host . $uri );
    }

    protected static function get_cache_file_path() {
        $key = self::get_cache_key();
        if ( ! $key ) {
            return null;
        }
        return HSP_SMART_CACHE_PATH . '/pages/' . $key . '.html';
    }

    protected static function get_cache_file_path_for_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return null;
        }
        $scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'http';
        $path   = isset( $parts['path'] ) ? $parts['path'] : '/';
        $query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
        $key    = md5( $scheme . '://' . $parts['host'] . $path . $query );
        return HSP_SMART_CACHE_PATH . '/pages/' . $key . '.html';
    }

    public static function clear_cache() {
        HSP_Smart_Cache_Utils::delete_dir_contents( HSP_SMART_CACHE_PATH . '/pages' );
    }

    public static function clear_cache_for_url( $url ) {
        $file = self::get_cache_file_path_for_url( $url );
        if ( $file && file_exists( $file ) ) {
            $fs = HSP_Smart_Cache_Utils::get_filesystem();
            if ( $fs ) {
                $fs->delete( $file );
            } else {
                wp_delete_file( $file );
            }
        }
    }

    public static function clear_cache_for_urls( $urls ) {
        foreach ( (array) $urls as $url ) {
            if ( $url ) {
                self::clear_cache_for_url( $url );
            }
        }
    }

    public static function clear_cache_for_post( $post_id ) {
        $urls = array();

        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            $urls[] = $permalink;
        }

        $page_on_front = (int) get_option( 'page_on_front' );
        $page_for_posts = (int) get_option( 'page_for_posts' );

        if ( $page_on_front === (int) $post_id ) {
            $urls[] = home_url( '/' );
        }

        if ( $page_for_posts === (int) $post_id ) {
            $urls[] = get_permalink( $page_for_posts );
        }

        self::clear_cache_for_urls( array_unique( $urls ) );
    }

    public static function warm_url( $url ) {
        if ( ! HSP_Smart_Cache_Settings::get( 'page_cache' ) ) {
            return;
        }
        wp_remote_get(
            $url,
            array(
                'timeout'   => 8,
                'sslverify' => false,
            )
        );
    }

    public static function warm_url_with_timeout( $url, $timeout ) {
        if ( ! HSP_Smart_Cache_Settings::get( 'page_cache' ) ) {
            return;
        }
        wp_remote_get(
            $url,
            array(
                'timeout'   => max( 3, intval( $timeout ) ),
                'sslverify' => false,
            )
        );
    }

    public static function warm_urls( $urls ) {
        foreach ( (array) $urls as $url ) {
            if ( $url ) {
                self::warm_url( $url );
            }
        }
    }
}
