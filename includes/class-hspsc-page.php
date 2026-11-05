<?php
/**
 * Page-cache eligibility, response storage, revalidation and invalidation.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates isolated page-cache responses and safe invalidation.
 */
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
	 * Generation observed before WordPress loads page content.
	 *
	 * @var string|false|null
	 */
	protected static $request_generation = null;

	/**
	 * Generation associated with the active response buffer.
	 *
	 * @var string|null
	 */
	protected static $buffer_generation = null;

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

	/**
	 * Registers page-cache lookup, buffering and shutdown callbacks.
	 *
	 * @return void
	 */
	public static function init() {
		// Bricks loads global data during theme bootstrap, before parse_request.
		self::capture_request_generation();
		// Let integrations finish redirects and declare private responses first.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_cache' ), PHP_INT_MAX - 1 );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), PHP_INT_MAX );
		add_action( 'shutdown', array( __CLASS__, 'save_buffer' ), 0 );
	}

	/**
	 * Serves a fresh eligible HTML response and terminates on a cache hit.
	 *
	 * @return void
	 */
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
		if ( false === self::$request_generation ) {
			self::send_cache_status( 'BYPASS', 'write-failed' );
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
				header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
				header( 'X-HSPSC-Cache: HIT' );
				self::send_browser_cache_headers( $cache_file );
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
			$generation = self::$request_generation;
			if ( null === $generation ) {
				$generation = self::get_cache_generation();
			}
			if ( false === $generation ) {
				self::send_cache_status( 'BYPASS', 'write-failed' );
				return;
			}
			self::$buffer_ready       = false;
			self::$buffer_interrupted = false;
			ob_start( array( get_called_class(), 'filter_buffer' ) );
			self::$buffer_started    = true;
			self::$buffer_level      = ob_get_level();
			self::$buffer_cache_file = $cache_file;
			self::$buffer_generation = $generation;
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
		$generation = self::$buffer_generation;
		$complete   = self::$buffer_started && self::$buffer_ready
			&& ! self::$buffer_interrupted && ! ( $phase & ( PHP_OUTPUT_HANDLER_CLEAN | PHP_OUTPUT_HANDLER_FLUSH ) );

		self::$buffer_started     = false;
		self::$buffer_level       = 0;
		self::$buffer_cache_file  = null;
		self::$buffer_generation  = null;
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
		if ( self::write_cache_file( $cache_file, $html, $generation ) ) {
			self::send_browser_cache_headers( $cache_file, true );
			self::send_cache_status( 'MISS' );
			self::maybe_cleanup_expired_cache();
		} else {
			self::send_cache_status( 'BYPASS', 'write-failed' );
		}

		return $html;
	}

	/**
	 * Rejects private, non-HTML, unsuccessful or unsupported response variants.
	 *
	 * @param int|null      $status HTTP status to inspect; null uses the current response.
	 * @param string[]|null $headers Response headers; null reads the current response headers.
	 * @return bool Whether the response is safe for shared page storage.
	 */
	protected static function should_cache_response( $status = null, $headers = null ) {
		$status  = null === $status ? http_response_code() : $status;
		$headers = null === $headers ? headers_list() : $headers;
		if ( 200 !== (int) $status ) {
			return false;
		}

		$is_html = false;
		foreach ( $headers as $header ) {
			if ( stripos( $header, 'Content-Encoding:' ) === 0 && strtolower( trim( substr( $header, 17 ) ) ) !== 'identity' ) {
				return false;
			}
			if ( stripos( $header, 'Content-Type:' ) === 0 ) {
				$type = strtolower( trim( explode( ';', substr( $header, 13 ) )[0] ) );
				if ( 'text/html' !== $type ) {
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

	/**
	 * Emits HTML revalidation metadata and handles eligible conditional hits.
	 *
	 * @param string|null $cache_file Selected cache file, if one exists.
	 * @param bool        $is_miss Whether this response was generated instead of served from cache.
	 * @return void
	 */
	protected static function send_browser_cache_headers( $cache_file = null, $is_miss = false ) {
		if ( headers_sent() ) {
			return;
		}
		// The cache filename isolates every Accept representation, including image formats.
		header( 'Vary: Accept', false );
		if ( ! HSPSC_Settings::get( 'browser_cache' ) ) {
			return;
		}

		$ttl          = intval( HSPSC_Settings::get( 'browser_cache_html_ttl', 600 ) );
		$is_logged_in = is_user_logged_in();

		if ( $is_logged_in && ! HSPSC_Settings::get( 'cache_logged_in' ) ) {
			return;
		}

		// Revalidate HTML after session/currency changes; only the origin selects a variant.
		header( 'Cache-Control: private, no-cache, max-age=' . $ttl . ', must-revalidate' );

		if ( $cache_file && file_exists( $cache_file ) ) {
			$etag = '"' . md5( $cache_file . '|' . filemtime( $cache_file ) ) . '"';
			header( 'ETag: ' . $etag );

			if ( ! $is_miss ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Fully validate opaque entity tags without changing their bytes.
				$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) : null;
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The coding parser validates the complete field before accepting an alias.
				$accept_encoding = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) : null;

				$match = self::get_revalidation_match( $if_none_match, $etag, $accept_encoding );
				if ( null !== $match ) {
					// Empty 304 responses can skip the server's normal ETag/Vary rewrite.
					header( 'ETag: ' . $match['etag'] );
					header( 'Vary: Accept-Encoding', false );
					if ( '' !== $match['encoding'] ) {
						// Prevent downstream compression from appending another suffix.
						header( 'Content-Encoding: ' . $match['encoding'] );
					}
					status_header( 304 );
					exit;
				}
			}
		}
	}

	/**
	 * Matches a complete conditional field against the selected cache validator.
	 *
	 * Apache can append a known compression suffix after PHP has sent its ETag.
	 * Accept only those exact aliases, for a coding the current request accepts.
	 * Returned header values are built from the trusted validator, never input.
	 *
	 * @param mixed  $if_none_match   Unslashed If-None-Match field.
	 * @param string $etag            Current trusted, quoted cache validator.
	 * @param mixed  $accept_encoding Unslashed Accept-Encoding field.
	 * @return array|null Trusted ETag and content coding, or null for no match.
	 */
	protected static function get_revalidation_match( $if_none_match, $etag, $accept_encoding = null ) {
		if ( ! is_string( $if_none_match ) || strlen( $if_none_match ) > 8192
			|| preg_match( '/[\x00-\x08\x0a-\x1f\x7f]/', $if_none_match )
			|| ! is_string( $etag ) || ! preg_match( '/\A"([a-f0-9]{32})"\z/', $etag, $current ) ) {
			return null;
		}

		$field = trim( $if_none_match, " \t" );
		if ( '*' === $field ) {
			return self::is_revalidation_encoding_accepted( $accept_encoding, 'identity' )
				? array(
					'etag'     => $etag,
					'encoding' => '',
				) : null;
		}

		$tags   = array();
		$length = strlen( $field );
		$offset = 0;
		while ( $offset < $length ) {
			// Recipients ignore empty comma-separated list members and optional whitespace.
			while ( $offset < $length && false !== strpos( " \t,", $field[ $offset ] ) ) {
				++$offset;
			}
			if ( $offset === $length ) {
				break;
			}
			$weak = false;
			if ( 'W/' === substr( $field, $offset, 2 ) ) {
				$weak    = true;
				$offset += 2;
			}
			if ( $offset === $length || '"' !== $field[ $offset ] ) {
				return null;
			}
			++$offset;
			$start = $offset;
			while ( $offset < $length && '"' !== $field[ $offset ] ) {
				$byte = ord( $field[ $offset ] );
				if ( 0x21 !== $byte && ! ( $byte >= 0x23 && $byte <= 0x7e ) && $byte < 0x80 ) {
					return null;
				}
				++$offset;
			}
			if ( $offset === $length ) {
				return null;
			}
			$tags[] = array(
				'value' => substr( $field, $start, $offset - $start ),
				'weak'  => $weak,
			);
			++$offset;
			while ( $offset < $length && false !== strpos( " \t", $field[ $offset ] ) ) {
				++$offset;
			}
			if ( $offset < $length && ',' !== $field[ $offset ] ) {
				return null;
			}
		}

		// Validate the whole list before considering any member a match.
		$accepted = array();
		$suffixes = array(
			'identity' => '',
			'gzip'     => '-gzip',
			'br'       => '-br',
		);
		foreach ( $tags as $tag ) {
			foreach ( $suffixes as $encoding => $suffix ) {
				if ( $current[1] . $suffix !== $tag['value'] ) {
					continue;
				}
				if ( ! array_key_exists( $encoding, $accepted ) ) {
					$accepted[ $encoding ] = self::is_revalidation_encoding_accepted( $accept_encoding, $encoding );
				}
				if ( $accepted[ $encoding ] ) {
					return array(
						'etag'     => ( $tag['weak'] ? 'W/' : '' ) . '"' . $current[1] . $suffix . '"',
						'encoding' => 'identity' === $encoding ? '' : $encoding,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Checks whether a compression alias is acceptable for this request.
	 *
	 * Explicit coding weights override the wildcard. Malformed fields or duplicate
	 * codings are ambiguous, so they require a normal response instead of a 304.
	 *
	 * @param mixed  $accept_encoding Unslashed Accept-Encoding field.
	 * @param string $encoding        Known content coding to check.
	 * @return bool Whether the coding has an unambiguous, positive weight.
	 */
	protected static function is_revalidation_encoding_accepted( $accept_encoding, $encoding ) {
		if ( null === $accept_encoding ) {
			return 'identity' === $encoding;
		}
		if ( ! is_string( $accept_encoding ) || strlen( $accept_encoding ) > 8192
			|| preg_match( '/[\x00-\x08\x0a-\x1f\x7f]/', $accept_encoding ) ) {
			return false;
		}

		$weights = array();
		foreach ( explode( ',', $accept_encoding ) as $member ) {
			$member = trim( $member, " \t" );
			if ( '' === $member ) {
				continue;
			}
			if ( ! preg_match( '/\A([!#$%&\'*+\-.^_`|~0-9a-z]+)(?:[ \t]*;[ \t]*q=(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?))?\z/i', $member, $parts ) ) {
				return false;
			}
			$coding = strtolower( $parts[1] );
			if ( array_key_exists( $coding, $weights ) ) {
				return false;
			}
			$weights[ $coding ] = isset( $parts[2] ) ? (float) $parts[2] : 1.0;
		}

		if ( array_key_exists( $encoding, $weights ) ) {
			return $weights[ $encoding ] > 0;
		}
		if ( 'identity' === $encoding ) {
			return ! isset( $weights['*'] ) || $weights['*'] > 0;
		}
		return isset( $weights['*'] ) && $weights['*'] > 0;
	}

	/**
	 * Builds the current request key after validating its URL and privacy context.
	 *
	 * @return string Hashed URL, user and language key, or an empty string on failure.
	 */
	protected static function get_cache_key() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate the unslashed host and URI below before building a key; sanitizing would merge distinct requests.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reject control bytes, fragments and invalid URL syntax below without rewriting request identity.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$ssl = is_ssl() ? 'https' : 'http';
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

	/**
	 * Resolves the cache file for the current request and Accept representation.
	 *
	 * @return string|null Cache path, or null when the request cannot be keyed.
	 */
	protected static function get_cache_file_path() {
		$key = self::get_cache_key();
		return self::get_variant_cache_file_path( $key );
	}

	/**
	 * Resolves an anonymous URL cache file using the initiating Accept header.
	 *
	 * @param string $url Absolute URL to resolve.
	 * @return string|null Cache path, or null for an invalid URL or representation.
	 */
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

	/**
	 * Builds a namespaced cache key from a URL and explicit isolation contexts.
	 *
	 * @param string            $url Absolute HTTP or HTTPS URL.
	 * @param string            $user_context Optional user identity suffix.
	 * @param string|false|null $language_context Resolved language; false resolves it from the URL, and null rejects it.
	 * @return string Cache key, or an empty string when the URL or language is unsafe.
	 */
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
		$host = strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) && ! ( 'http' === $scheme && 80 === $parts['port'] ) && ! ( 'https' === $scheme && 443 === $parts['port'] ) ) {
			$host .= ':' . $parts['port'];
		}
		$path             = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query            = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$uri              = self::normalize_cache_uri( $path . $query );
		$language_context = '' !== $language_context ? '|language:' . $language_context : '';
		return md5( 'v4|' . $scheme . '://' . $host . $uri . $user_context . $language_context );
	}

	/**
	 * Reports the current anonymous cache state for one URL representation.
	 *
	 * @param string $url Absolute URL to inspect.
	 * @return array Cache availability, freshness, age, size and configured lifetime.
	 */
	public static function get_cache_info_for_url( $url ) {
		$file   = self::get_cache_file_path_for_url( $url );
		$ttl    = intval( HSPSC_Settings::get( 'page_cache_ttl', 3600 ) );
		$exists = $file && file_exists( $file );
		$mtime  = $exists ? filemtime( $file ) : false;
		$age    = $mtime ? max( 0, time() - $mtime ) : null;

		return array(
			'enabled' => (bool) HSPSC_Settings::get( 'page_cache' ),
			'file'    => $file,
			'exists'  => (bool) $exists,
			'fresh'   => $exists && null !== $age && $age <= $ttl,
			'age'     => $age,
			'ttl'     => $ttl,
			'size'    => $exists ? (int) filesize( $file ) : 0,
		);
	}

	/**
	 * Removes marketing arguments and sorts meaningful arguments in cache keys.
	 *
	 * @param string $uri Request path and optional query string.
	 * @return string Normalized URI used only for cache identity.
	 */
	protected static function normalize_cache_uri( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) ) {
			return '/';
		}

		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';
		if ( '' === $query ) {
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

	/**
	 * Lists known marketing arguments that do not vary cached page output.
	 *
	 * @return string[] Query argument names omitted from cache keys.
	 */
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

	/**
	 * Streams trusted cached HTML without buffering the complete file in memory.
	 *
	 * @param string $cache_file Plugin-generated page-cache path.
	 * @return void
	 */
	protected static function stream_cache_file( $cache_file ) {
		if ( ! is_readable( $cache_file ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A local stream avoids loading an entire generated HTML response into memory.
		$handle = fopen( $cache_file, 'rb' );
		if ( $handle ) {
			fpassthru( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local response stream opened above.
			fclose( $handle );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fallback reads only the plugin-generated local cache path, never a remote URL.
		$contents = file_get_contents( $cache_file );
		if ( false !== $contents ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Emit trusted cached HTML unchanged; escaping would corrupt the page.
			echo $contents;
		}
	}

	/**
	 * Schedules at most one opportunistic page-cache cleanup per hour.
	 *
	 * @return void
	 */
	protected static function maybe_cleanup_expired_cache() {
		if ( get_transient( 'hspsc_page_cache_gc' ) ) {
			return;
		}

		set_transient( 'hspsc_page_cache_gc', 1, HOUR_IN_SECONDS );
		self::cleanup_expired_cache();
	}

	/**
	 * Removes expired HTML files from the disposable page-cache subtree.
	 *
	 * @return int Number of removed files.
	 */
	public static function cleanup_expired_cache() {
		$ttl = intval( HSPSC_Settings::get( 'page_cache_ttl', 3600 ) );
		return HSPSC_Utils::delete_old_files( HSPSC_PATH . '/pages', $ttl, '.html' );
	}

	/**
	 * Captures invalidation state before themes or the main query load content.
	 */
	public static function capture_request_generation() {
		self::$request_generation = null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the exact raw GET token is accepted; normalizing other methods could make them eligible.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		if ( HSPSC_Settings::get( 'page_cache' ) && 'GET' === $method && ! HSPSC_Utils::is_backend_or_login_request() ) {
			self::$request_generation = self::get_cache_generation();
		}
	}

	/**
	 * Locks a stable state file outside the disposable page directory.
	 *
	 * Never unlink or atomically replace this file during a purge: every worker
	 * must lock the same inode. Locks cover short reads/writes, never rendering.
	 *
	 * @return resource|false Locked stream, or false when coordination is unsafe.
	 */
	protected static function lock_cache_state() {
		HSPSC_Utils::ensure_cache_dirs();
		$path = HSPSC_PATH . '/.page-cache-state';
		if ( is_link( HSPSC_PATH ) || is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Keep a stable inode for flock; concurrent permission failures return false without leaking local paths.
		$lock = @fopen( $path, 'c+b' );
		if ( false === $lock ) {
			return false;
		}
		if ( ! flock( $lock, LOCK_EX ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the failed local lock stream.
			fclose( $lock );
			return false;
		}
		return $lock;
	}

	/**
	 * Releases the stable generation lock and closes its local stream.
	 *
	 * @param resource $lock Locked state stream.
	 * @return void
	 */
	protected static function unlock_cache_state( $lock ) {
		flock( $lock, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The lock must remain tied to this local stream until released.
		fclose( $lock );
	}

	/**
	 * Reads the generation under the same lock used by purges and final writes.
	 *
	 * @param resource $lock Locked state stream.
	 * @return string|false Generation, or false for unreadable/corrupt state.
	 */
	protected static function read_cache_generation( $lock ) {
		rewind( $lock );
		$generation = stream_get_contents( $lock, 64 );
		if ( '' === $generation ) {
			return self::advance_cache_generation( $lock );
		}
		return is_string( $generation ) && preg_match( '/\A[a-f0-9]{32}\z/', $generation ) ? $generation : false;
	}

	/**
	 * Changes the generation even when a purge finds no cached files.
	 *
	 * @param resource $lock Locked state stream.
	 * @return string|false New generation, or false on a storage failure.
	 */
	protected static function advance_cache_generation( $lock ) {
		$generation = str_replace( '-', '', wp_generate_uuid4() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write under the existing flock without replacing the stable state inode.
		if ( ! rewind( $lock ) || ! ftruncate( $lock, 0 ) || strlen( $generation ) !== fwrite( $lock, $generation ) || ! fflush( $lock ) ) {
			return false;
		}
		return $generation;
	}

	/**
	 * Reads or initializes the current persistent generation under its lock.
	 *
	 * @return string|false Current generation, or false on a coordination failure.
	 */
	protected static function get_cache_generation() {
		$lock = self::lock_cache_state();
		if ( false === $lock ) {
			return false;
		}
		try {
			return self::read_cache_generation( $lock );
		} finally {
			self::unlock_cache_state( $lock );
		}
	}

	/**
	 * Prevents a pre-purge render from overwriting a newer cached response.
	 *
	 * @param string $cache_file Cache destination.
	 * @param string $html       Complete eligible HTML.
	 * @param string $generation Generation captured before rendering.
	 * @return bool Whether the response was saved.
	 */
	protected static function write_cache_file( $cache_file, $html, $generation ) {
		if ( false === self::$request_generation ) {
			return false;
		}
		$lock = self::lock_cache_state();
		if ( false === $lock ) {
			return false;
		}
		try {
			return is_string( $generation ) && self::read_cache_generation( $lock ) === $generation
				&& HSPSC_Utils::atomic_write( $cache_file, $html );
		} finally {
			self::unlock_cache_state( $lock );
		}
	}

	/**
	 * Invalidates in-flight renders and removes all stored page responses.
	 *
	 * @return bool Whether the persistent cache generation advanced successfully.
	 */
	public static function clear_cache() {
		$lock = self::lock_cache_state();
		if ( false === $lock ) {
			self::$request_generation = false;
			HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/pages' );
			return false;
		}
		try {
			$advanced = self::advance_cache_generation( $lock );
			if ( false === $advanced ) {
				self::$request_generation = false;
			}
			HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/pages' );
			return false !== $advanced;
		} finally {
			self::unlock_cache_state( $lock );
		}
	}

	/**
	 * Invalidates every Accept variant for a URL and rejects older in-flight renders.
	 *
	 * @param string $url Absolute URL whose cached responses should be removed.
	 * @return bool|null Generation update result, or null when no targeted key is available.
	 */
	public static function clear_cache_for_url( $url ) {
		$key = self::get_url_cache_key( $url );
		if ( ! $key && null === HSPSC_WPML::url_context( $url ) ) {
			// Administrative translation jobs may not have a reliable URL context.
			self::clear_cache();
			return;
		}
		if ( $key ) {
			$lock = self::lock_cache_state();
			if ( false === $lock ) {
				self::$request_generation = false;
				self::delete_cache_files_for_key( $key );
				return false;
			}
			try {
				// Conservatively reject all in-flight renders; retain unrelated files.
				$advanced = self::advance_cache_generation( $lock );
				if ( false === $advanced ) {
					self::$request_generation = false;
				}
				self::delete_cache_files_for_key( $key );
				return false !== $advanced;
			} finally {
				self::unlock_cache_state( $lock );
			}
		}
	}

	/**
	 * Deletes every stored Accept variant for an already validated cache key.
	 *
	 * @param string $key Validated URL cache key, including its language scope.
	 * @return void
	 */
	protected static function delete_cache_files_for_key( $key ) {
		$files   = (array) glob( HSPSC_PATH . '/pages/' . $key . '-*.html' );
		$files[] = HSPSC_PATH . '/pages/' . $key . '.html';
		$fs      = HSPSC_Utils::get_filesystem();
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

	/**
	 * Invalidates cached responses for each nonempty URL in a collection.
	 *
	 * @param string[] $urls Absolute URLs to invalidate.
	 * @return void
	 */
	public static function clear_cache_for_urls( $urls ) {
		foreach ( (array) $urls as $url ) {
			if ( $url ) {
				self::clear_cache_for_url( $url );
			}
		}
	}

	/**
	 * Invalidates a post and its dependent public page-cache output.
	 *
	 * @param int $post_id Post whose content or visibility changed.
	 * @return void
	 */
	public static function clear_cache_for_post( $post_id ) {
		$urls = array();

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$urls[] = $permalink;
		}

		$page_on_front  = (int) get_option( 'page_on_front' );
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

	/**
	 * Determines whether a post change can affect shared public listings.
	 *
	 * @param int $post_id Changed or deleted post ID.
	 * @return bool Whether all page-cache entries should be removed.
	 */
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

	/**
	 * Fetches an allowed site URL without following redirects to populate its cache.
	 *
	 * @param string $url Absolute URL to warm.
	 * @return void
	 */
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

	/**
	 * Warms an allowed site URL with a bounded request timeout.
	 *
	 * @param string $url Absolute URL to warm.
	 * @param int    $timeout Requested HTTP timeout in seconds, with a minimum of three.
	 * @return void
	 */
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

	/**
	 * Warms each nonempty URL through the same origin restrictions.
	 *
	 * @param string[] $urls Absolute URLs to warm.
	 * @return void
	 */
	public static function warm_urls( $urls ) {
		foreach ( (array) $urls as $url ) {
			if ( $url ) {
				self::warm_url( $url );
			}
		}
	}

	/**
	 * Checks that a URL has an exact configured site or WPML language origin.
	 *
	 * @param string $url Absolute URL to validate.
	 * @return bool Whether the URL may be fetched by the cache warmer.
	 */
	public static function is_warmable_url( $url ) {
		$origin = self::get_url_origin( $url );
		if ( '' === $origin ) {
			return false;
		}

		$roots           = array_merge( array( home_url(), site_url() ), array_values( HSPSC_WPML::language_urls() ) );
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
