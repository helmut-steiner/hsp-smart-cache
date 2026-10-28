<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Page {
	/**
	 * Whether the owned output buffer is still active.
	 *
	 * @var bool
	 */
	protected static $buffer_started = false;

	/**
	 * Stack depth of the owned output buffer.
	 *
	 * @var int
	 */
	protected static $buffer_level = 0;

	/**
	 * Cache destination captured before rendering starts.
	 *
	 * @var string|null
	 */
	protected static $buffer_cache_file = null;

	/**
	 * Whether shutdown has authorized final response capture.
	 *
	 * @var bool
	 */
	protected static $buffer_ready = false;

	/**
	 * Whether an earlier callback discarded or flushed part of the response.
	 *
	 * @var bool
	 */
	protected static $buffer_interrupted = false;

    public static function init() {
        // Let integrations finish redirects and declare private responses first.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_cache' ), PHP_INT_MAX - 1 );
        add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), PHP_INT_MAX );
        add_action( 'shutdown', array( __CLASS__, 'save_buffer' ), 0 );
    }

    public static function maybe_serve_cache() {
        if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
            return;
        }
        if ( ! HSPSC_Utils::is_request_cacheable() ) {
            self::send_cache_status( 'BYPASS', 'private-request' );
            return;
        }
        if ( ! self::should_cache_response() ) {
            self::send_cache_status( 'BYPASS', 'response-headers' );
            return;
        }

        $cache_file = self::get_cache_file_path();
        if ( ! $cache_file ) {
            self::send_cache_status( 'BYPASS', 'request-key' );
            return;
        }

        $ttl = intval( HSPSC_Settings::get( 'page_cache_ttl', 3600 ) );
        if ( file_exists( $cache_file ) ) {
            $age = time() - filemtime( $cache_file );
            if ( $age <= $ttl ) {
                self::send_browser_cache_headers( $cache_file );
                header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
                header( 'X-HSPSC-Cache: HIT' );
                self::stream_cache_file( $cache_file );
                exit;
            }
        }
        self::send_cache_status( 'MISS' );
    }

	/**
	 * Starts an owned output handler for an eligible page response.
	 *
	 * @return void
	 */
	public static function start_buffer() {
		if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
			return;
		}
		if ( ! HSPSC_Utils::is_request_cacheable() ) {
			self::send_cache_status( 'BYPASS', 'private-request' );
			return;
		}

		if ( ! self::$buffer_started ) {
			$cache_file = self::get_cache_file_path();
			if ( ! $cache_file ) {
				self::send_cache_status( 'BYPASS', 'request-key' );
				return;
			}
			self::$buffer_ready       = false;
			self::$buffer_interrupted = false;
			ob_start( array( get_called_class(), 'filter_buffer' ) );
			self::$buffer_started    = true;
			self::$buffer_level      = ob_get_level();
			self::$buffer_cache_file = $cache_file;
		}
	}

	/**
	 * Authorizes final capture while leaving nested handlers for core to drain.
	 *
	 * @return void
	 */
	public static function save_buffer() {
		if ( ! self::$buffer_started ) {
			return;
		}

		self::$buffer_ready = true;
		if ( ob_get_level() === self::$buffer_level ) {
			// Only close our own buffer. Core drains any nested buffers at shutdown.
			ob_end_flush();
		}
	}

	/**
	 * Captures the complete response after nested output handlers have finished.
	 *
	 * The handler returns output rather than operating on the buffer stack. A
	 * flush, clean or close before shutdown makes the response ineligible because
	 * the final callback alone can no longer prove that it contains the full body.
	 *
	 * @param string $html  Buffered response body.
	 * @param int    $phase Output handler phase flags.
	 * @return string Response body, preserved when caching is unsafe.
	 */
	public static function filter_buffer( $html, $phase ) {
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			self::$buffer_interrupted = true;
			self::send_cache_status( 'BYPASS', 'buffer-conflict' );
			return $html;
		}

		$cache_file = self::$buffer_cache_file;
		$complete   = self::$buffer_started && self::$buffer_ready
			&& ! self::$buffer_interrupted && ! ( $phase & ( PHP_OUTPUT_HANDLER_CLEAN | PHP_OUTPUT_HANDLER_FLUSH ) );

		self::$buffer_started     = false;
		self::$buffer_level       = 0;
		self::$buffer_cache_file  = null;
		self::$buffer_ready       = false;
		self::$buffer_interrupted = false;

		if ( ! $complete ) {
			self::send_cache_status( 'BYPASS', 'buffer-conflict' );
			return $html;
		}

		if ( ! HSPSC_Settings::get( 'page_cache' ) || ! HSPSC_Utils::is_request_cacheable()
			|| self::get_cache_file_path() !== $cache_file || ! static::should_cache_response() ) {
			self::send_cache_status( 'BYPASS', 'late-private-response' );
			return $html;
		}

		if ( HSPSC_Settings::get( 'minify_html' ) && HSPSC_Utils::should_apply_frontend_optimizations() ) {
			$html = HSPSC_Minify::minify_html( $html );
		}

		HSPSC_Utils::ensure_cache_dirs();
		if ( HSPSC_Utils::atomic_write( $cache_file, $html ) ) {
			self::send_browser_cache_headers( $cache_file, true );
			self::send_cache_status( 'MISS' );
			self::maybe_cleanup_expired_cache();
		} else {
			self::send_cache_status( 'BYPASS', 'write-failed' );
		}

		return $html;
	}

    protected static function should_cache_response( $status = null, $headers = null ) {
        $status = $status === null ? http_response_code() : $status;
        $headers = $headers === null ? headers_list() : $headers;
        if ( (int) $status !== 200 ) {
            return false;
        }

        $is_html = false;
        foreach ( $headers as $header ) {
            if ( stripos( $header, 'Content-Encoding:' ) === 0 && strtolower( trim( substr( $header, 17 ) ) ) !== 'identity' ) {
                return false;
            }
            if ( stripos( $header, 'Content-Type:' ) === 0 ) {
                $type = strtolower( trim( explode( ';', substr( $header, 13 ) )[0] ) );
                if ( $type !== 'text/html' ) {
                    return false;
                }
                $is_html = true;
            }
            if ( stripos( $header, 'Location:' ) === 0 || stripos( $header, 'Content-Disposition:' ) === 0 ) {
                return false;
            }

            if ( stripos( $header, 'Set-Cookie:' ) === 0 ) {
                return false;
            }

            if ( stripos( $header, 'Cache-Control:' ) === 0 ) {
                $cache_control = strtolower( $header );
                if ( strpos( $cache_control, 'no-store' ) !== false || strpos( $cache_control, 'private' ) !== false || strpos( $cache_control, 'no-cache' ) !== false ) {
                    return false;
                }
            }
            if ( stripos( $header, 'Vary:' ) === 0 ) {
                $vary = array_map( 'trim', explode( ',', strtolower( substr( $header, 5 ) ) ) );
                if ( array_diff( $vary, array( 'accept-encoding', 'accept' ) ) ) {
                    return false;
                }
            }
        }

        return $is_html && (bool) apply_filters( 'hspsc_should_cache_response', true, $status, $headers );
    }

	/**
	 * Makes cache failures diagnosable without exposing visitor data or file paths.
	 *
	 * @param string $status Cache status.
	 * @param string $reason Fixed reason code for a bypass.
	 * @return void
	 */
	protected static function send_cache_status( $status, $reason = '' ) {
		if ( headers_sent() ) {
			return;
		}
		if ( 'BYPASS' === $status && 'response-headers' !== $reason ) {
			nocache_headers();
		}
		header( 'X-HSPSC-Cache: ' . $status );
		if ( '' !== $reason ) {
			header( 'X-HSPSC-Cache-Reason: ' . $reason );
		}
	}

    protected static function send_browser_cache_headers( $cache_file = null, $is_miss = false ) {
        if ( headers_sent() ) {
            return;
        }
        // The cache filename isolates every Accept representation, including image formats.
        header( 'Vary: Accept', false );
        if ( ! HSPSC_Settings::get( 'browser_cache' ) ) {
            return;
        }

        $ttl = intval( HSPSC_Settings::get( 'browser_cache_html_ttl', 600 ) );
        $is_logged_in = is_user_logged_in();

        if ( $is_logged_in && ! HSPSC_Settings::get( 'cache_logged_in' ) ) {
            return;
        }

        // Revalidate HTML after session/currency changes; only the origin selects a variant.
        header( 'Cache-Control: private, no-cache, max-age=' . $ttl . ', must-revalidate' );

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
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $ssl  = is_ssl() ? 'https' : 'http';
        if ( preg_match( '/[\x00-\x20\x7f]/', $host . $uri ) || false !== strpbrk( $host, '/?#\\' ) || false !== strpos( $uri, '#' ) || strpos( $uri, '/' ) !== 0 || strpos( $uri, '//' ) === 0 ) {
            return '';
        }
        $user_context = '';

        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            if ( $user_id <= 0 ) {
                return '';
            }
            $user_context = '|user:' . $user_id . ':' . wp_salt( 'auth' );
        }

        $language_context = HSPSC_WPML::request_context();
        return self::get_url_cache_key( $ssl . '://' . $host . $uri, $user_context, $language_context );
    }

    protected static function get_cache_file_path() {
        $key = self::get_cache_key();
        return self::get_variant_cache_file_path( $key );
    }

    public static function get_cache_file_path_for_url( $url ) {
        $key = self::get_url_cache_key( $url );
        return self::get_variant_cache_file_path( $key );
    }

	/**
	 * Isolates content negotiated by SEO and image plugins without guessing formats.
	 *
	 * @param string $key URL, language and user cache key.
	 * @return string|null Cache filename, or null for an invalid request header.
	 */
	protected static function get_variant_cache_file_path( $key ) {
		$accept = self::get_request_accept();
		if ( ! $key || null === $accept ) {
			return null;
		}
		return HSPSC_PATH . '/pages/' . $key . '-' . md5( $accept ) . '.html';
	}

	/**
	 * Retains the exact negotiated media preferences for the current request.
	 *
	 * @return string|null Accept header, or null when malformed or oversized.
	 */
	protected static function get_request_accept() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) ) {
			return '';
		}
		if ( ! is_string( $_SERVER['HTTP_ACCEPT'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve representation identity; validate control bytes and length below.
		$accept = wp_unslash( $_SERVER['HTTP_ACCEPT'] );
		if ( strlen( $accept ) > 8192 || preg_match( '/[\x00-\x08\x0a-\x1f\x7f]/', $accept ) ) {
			return null;
		}
		return $accept;
	}

    protected static function get_url_cache_key( $url, $user_context = '', $language_context = false ) {
        if ( preg_match( '/[\x00-\x20\x7f]/', (string) $url ) ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return '';
        }
        $scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return '';
        }
        if ( false === $language_context ) {
            $language_context = HSPSC_WPML::url_context( $url );
        }
        if ( null === $language_context ) {
            return '';
        }
        $host   = strtolower( (string) $parts['host'] );
        if ( ! empty( $parts['port'] ) && ! ( $scheme === 'http' && $parts['port'] === 80 ) && ! ( $scheme === 'https' && $parts['port'] === 443 ) ) {
            $host .= ':' . $parts['port'];
        }
        $path   = isset( $parts['path'] ) ? $parts['path'] : '/';
        $query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
        $uri    = self::normalize_cache_uri( $path . $query );
        $language_context = '' !== $language_context ? '|language:' . $language_context : '';
        return md5( 'v4|' . $scheme . '://' . $host . $uri . $user_context . $language_context );
    }

    public static function get_cache_info_for_url( $url ) {
        $file = self::get_cache_file_path_for_url( $url );
        $ttl = intval( HSPSC_Settings::get( 'page_cache_ttl', 3600 ) );
        $exists = $file && file_exists( $file );
        $mtime = $exists ? filemtime( $file ) : false;
        $age = $mtime ? max( 0, time() - $mtime ) : null;

        return array(
            'enabled' => (bool) HSPSC_Settings::get( 'page_cache' ),
            'file'    => $file,
            'exists'  => (bool) $exists,
            'fresh'   => $exists && $age !== null && $age <= $ttl,
            'age'     => $age,
            'ttl'     => $ttl,
            'size'    => $exists ? (int) filesize( $file ) : 0,
        );
    }

    protected static function normalize_cache_uri( $uri ) {
        $parts = wp_parse_url( $uri );
        if ( ! is_array( $parts ) ) {
            return '/';
        }

        $path = isset( $parts['path'] ) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = isset( $parts['query'] ) ? $parts['query'] : '';
        if ( $query === '' ) {
            return $path;
        }

        wp_parse_str( $query, $args );
        foreach ( array_keys( $args ) as $key ) {
            $normalized_key = strtolower( (string) $key );
            if ( in_array( $normalized_key, self::ignored_cache_query_args(), true ) || strpos( $normalized_key, 'utm_' ) === 0 ) {
                unset( $args[ $key ] );
            }
        }

        if ( empty( $args ) ) {
            return $path;
        }

        ksort( $args );
        return $path . '?' . http_build_query( $args, '', '&' );
    }

    protected static function ignored_cache_query_args() {
        return array(
            'fbclid',
            'gclid',
            'gclsrc',
            'dclid',
            'msclkid',
            'mc_cid',
            'mc_eid',
            '_ga',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'utm_id',
        );
    }

    protected static function stream_cache_file( $cache_file ) {
        if ( ! is_readable( $cache_file ) ) {
            return;
        }

        $handle = fopen( $cache_file, 'rb' );
        if ( $handle ) {
            fpassthru( $handle );
            fclose( $handle );
            return;
        }

        $contents = file_get_contents( $cache_file );
        if ( $contents !== false ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $contents;
        }
    }

    protected static function maybe_cleanup_expired_cache() {
        if ( get_transient( 'hspsc_page_cache_gc' ) ) {
            return;
        }

        set_transient( 'hspsc_page_cache_gc', 1, HOUR_IN_SECONDS );
        self::cleanup_expired_cache();
    }

    public static function cleanup_expired_cache() {
        $ttl = intval( HSPSC_Settings::get( 'page_cache_ttl', 3600 ) );
        return HSPSC_Utils::delete_old_files( HSPSC_PATH . '/pages', $ttl, '.html' );
    }

    public static function clear_cache() {
        HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/pages' );
    }

    public static function clear_cache_for_url( $url ) {
        $key = self::get_url_cache_key( $url );
        if ( ! $key && null === HSPSC_WPML::url_context( $url ) ) {
            // Administrative translation jobs may not have a reliable URL context.
            self::clear_cache();
            return;
        }
        if ( $key ) {
            $files = (array) glob( HSPSC_PATH . '/pages/' . $key . '-*.html' );
            $files[] = HSPSC_PATH . '/pages/' . $key . '.html';
            $fs = HSPSC_Utils::get_filesystem();
            foreach ( $files as $file ) {
                if ( ! is_file( $file ) && ! is_link( $file ) ) {
                    continue;
                }
                if ( $fs ) {
                    $fs->delete( $file );
                } else {
                    wp_delete_file( $file );
                }
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

        if ( apply_filters( 'hspsc_flush_page_cache_on_post_change', self::should_flush_all_for_post_change( $post_id ), $post_id, $urls ) ) {
            self::clear_cache();
        }
    }

    protected static function should_flush_all_for_post_change( $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( ! $post_type ) {
            return true;
        }

        $post_type_object = get_post_type_object( $post_type );
        if ( ! $post_type_object || empty( $post_type_object->public ) ) {
            return false;
        }

        return true;
    }

    public static function warm_url( $url ) {
        if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
            return;
        }
        if ( ! self::is_warmable_url( $url ) ) {
            return;
        }
        wp_remote_get(
            $url,
            array(
                'timeout'     => 8,
                'redirection' => 0,
                'headers'     => array( 'Accept' => self::get_request_accept() ?? '' ),
            )
        );
    }

    public static function warm_url_with_timeout( $url, $timeout ) {
        if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
            return;
        }
        if ( ! self::is_warmable_url( $url ) ) {
            return;
        }
        wp_remote_get(
            $url,
            array(
                'timeout'     => max( 3, intval( $timeout ) ),
                'redirection' => 0,
                'headers'     => array( 'Accept' => self::get_request_accept() ?? '' ),
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

    public static function is_warmable_url( $url ) {
        $origin = self::get_url_origin( $url );
        if ( '' === $origin ) {
            return false;
        }

        $roots = array_merge( array( home_url(), site_url() ), array_values( HSPSC_WPML::language_urls() ) );
        $allowed_origins = array_filter( array_map( array( __CLASS__, 'get_url_origin' ), $roots ) );

        return in_array( $origin, $allowed_origins, true );
    }

	/**
	 * Return an exact HTTP origin for a trusted warming destination.
	 *
	 * @param string $url Absolute URL.
	 * @return string Normalized scheme, host and port, or an empty string.
	 */
	protected static function get_url_origin( $url ) {
		if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}
}
