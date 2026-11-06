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
	 * Stops direct execution of authenticated cache files before any HTML output.
	 */
	const PRIVATE_FILE_GUARD = "<?php exit; ?>\n";

	/** Maximum HTML bytes accepted from one public warming request. */
	const WARMING_RESPONSE_LIMIT = 4194304;

	/**
	 * Whether the early header filter removed WordPress's default login policy.
	 *
	 * @var bool
	 */
	protected static $authenticated_headers_pending = false;

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
		add_filter( 'wp_headers', array( __CLASS__, 'filter_authenticated_cache_headers' ), PHP_INT_MIN );
		add_action( 'shutdown', array( __CLASS__, 'restore_authenticated_cache_headers' ), -1 );
		// Let integrations finish redirects and declare private responses first.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_cache' ), PHP_INT_MAX - 1 );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), PHP_INT_MAX );
		add_action( 'shutdown', array( __CLASS__, 'save_buffer' ), 0 );
	}

	/**
	 * Removes only core's unmodified login policy before integration header filters.
	 *
	 * WordPress sends these headers before the wp action. Language resolution is
	 * deferred to the normal lookup after wp; every other eligibility guard runs
	 * here too. Later private/no-store headers remain a veto, even if identical to
	 * core's original value. Expires stays in the past until the final cache policy.
	 *
	 * @param array $headers Core response headers, before ordinary plugin filters.
	 * @return array Headers with only an eligible core-owned Cache-Control removed.
	 */
	public static function filter_authenticated_cache_headers( $headers ) {
		self::$authenticated_headers_pending = false;
		if ( ! HSPSC_Settings::get( 'page_cache' ) || ! HSPSC_Settings::get( 'cache_logged_in' )
			|| ! is_user_logged_in() || ! HSPSC_Utils::is_request_cacheable( false ) ) {
			return $headers;
		}
		$core_policy = 'no-cache, must-revalidate, max-age=0, no-store, private';
		if ( isset( $headers['Cache-Control'] ) && $core_policy === $headers['Cache-Control'] ) {
			unset( $headers['Cache-Control'] );
			self::$authenticated_headers_pending = true;
		}
		return $headers;
	}

	/**
	 * Restores browser privacy if an opted-in request ends without cache capture.
	 *
	 * @return void
	 */
	public static function restore_authenticated_cache_headers() {
		if ( self::$authenticated_headers_pending && ! self::$buffer_started && ! headers_sent() ) {
			nocache_headers();
			self::$authenticated_headers_pending = false;
		}
	}

	/**
	 * Serves a fresh eligible HTML response and terminates on a cache hit.
	 *
	 * @return void
	 */
	public static function maybe_serve_cache() {
		if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
			self::restore_authenticated_cache_headers();
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

		$ttl      = intval( HSPSC_Settings::get( 'page_cache_ttl', 3600 ) );
		$snapshot = self::open_cache_snapshot( $cache_file, $ttl );
		if ( false !== $snapshot ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
			header( 'X-HSPSC-Cache: HIT' );
			self::send_warming_proof( $cache_file, $snapshot['etag'] );
			if ( ! self::send_browser_cache_headers( $snapshot['etag'] ) ) {
				self::stream_cache_file( $snapshot['handle'] );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the same immutable file snapshot used for validation and output.
			fclose( $snapshot['handle'] );
			exit;
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
			self::restore_authenticated_cache_headers();
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
			$etag = self::get_content_etag( $cache_file, $html );
			self::send_browser_cache_headers( $etag, true );
			self::send_cache_status( 'MISS' );
			self::send_warming_proof( $cache_file, $etag );
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
		if ( 'BYPASS' === $status && ( 'response-headers' !== $reason || self::$authenticated_headers_pending ) ) {
			nocache_headers();
			self::$authenticated_headers_pending = false;
		}
		header( 'X-HSPSC-Cache: ' . $status );
		if ( '' !== $reason ) {
			header( 'X-HSPSC-Cache-Reason: ' . $reason );
		}
	}

	/**
	 * Emits HTML revalidation metadata and handles eligible conditional hits.
	 *
	 * @param string|null $etag Validator for the exact selected HTML bytes.
	 * @param bool        $is_miss Whether this response was generated instead of served from cache.
	 * @return bool Whether an eligible conditional hit selected a bodyless 304.
	 */
	protected static function send_browser_cache_headers( $etag = null, $is_miss = false ) {
		if ( headers_sent() ) {
			return false;
		}
		// The cache filename isolates every Accept representation, including image formats.
		header( 'Vary: Accept', false );
		$is_logged_in = is_user_logged_in();
		if ( ! HSPSC_Settings::get( 'browser_cache' ) ) {
			if ( $is_logged_in ) {
				nocache_headers();
			}
			self::$authenticated_headers_pending = false;
			return false;
		}

		$ttl = intval( HSPSC_Settings::get( 'browser_cache_html_ttl', 600 ) );

		if ( $is_logged_in && ! HSPSC_Settings::get( 'cache_logged_in' ) ) {
			return false;
		}

		// Revalidate HTML after session/currency changes; only the origin selects a variant.
		header( 'Cache-Control: private, no-cache, max-age=' . $ttl . ', must-revalidate' );
		self::$authenticated_headers_pending = false;

		if ( is_string( $etag ) ) {
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
					return true;
				}
			}
		}
		return false;
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
			$user_context = '|user:' . $user_id . ':' . wp_salt( 'auth' ) . ':' . wp_get_session_token();
		}

		$language_context = HSPSC_WPML::request_context();
		$key              = self::get_url_cache_key( $ssl . '://' . $host . $uri, '', $language_context );
		return $key && '' !== $user_context ? $key . '-user-' . md5( $user_context ) : $key;
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
	 * @param string      $key URL, language and user cache key.
	 * @param string|null $accept Explicit negotiated representation, or null to use the current request.
	 * @return string|null Cache filename, or null for an invalid request header.
	 */
	protected static function get_variant_cache_file_path( $key, $accept = null ) {
		$accept = $accept ?? self::get_request_accept();
		if ( ! $key || null === $accept ) {
			return null;
		}
		if ( false !== strpos( $key, '-user-' ) ) {
			return HSPSC_PATH . '/pages/private/' . $key . '-' . md5( $accept ) . '.php';
		}
		return HSPSC_PATH . '/pages/' . $key . '-' . md5( $accept ) . '.html';
	}

	/**
	 * Retains the exact negotiated media preferences for the current request.
	 *
	 * @return string|null Accept header, or null when malformed or oversized.
	 */
	protected static function get_request_accept() {
		if ( ! array_key_exists( 'HTTP_ACCEPT', $_SERVER ) ) {
			return '';
		}
		if ( ! is_string( $_SERVER['HTTP_ACCEPT'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve representation identity; validate control bytes and length below.
		$accept = wp_unslash( $_SERVER['HTTP_ACCEPT'] );
		return self::is_valid_warming_accept( $accept ) ? $accept : null;
	}

	/**
	 * Checks header type, length and control bytes without rewriting its identity.
	 *
	 * @param mixed $accept Exact HTTP Accept header bytes.
	 * @return bool Whether the value can be used as an Accept field and cache key.
	 */
	public static function is_valid_warming_accept( $accept ) {
		return is_string( $accept ) && strlen( $accept ) <= 8192 && ! preg_match( '/[\x00-\x08\x0a-\x1f\x7f]/', $accept );
	}

	/**
	 * Captures the initiating Accept value for a later anonymous rebuild request.
	 *
	 * @return string|null Exact header, HTML if absent, or null if invalid.
	 */
	public static function get_warming_accept() {
		return array_key_exists( 'HTTP_ACCEPT', $_SERVER ) ? self::get_request_accept() : 'text/html';
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
		return self::get_cache_info_for_file( self::get_cache_file_path_for_url( $url ) );
	}

	/**
	 * Reports the file state for the current URL, language, user session and Accept.
	 *
	 * @return array Cache availability, freshness, age, size and configured lifetime.
	 */
	public static function get_cache_info_for_request() {
		return self::get_cache_info_for_file( self::get_cache_file_path() );
	}

	/**
	 * Reads file metadata for a resolved cache representation.
	 *
	 * @param string|null $file Resolved cache file, or null when no safe key exists.
	 * @return array Cache availability, freshness, age, size and configured lifetime.
	 */
	protected static function get_cache_info_for_file( $file ) {
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
	 * Derives a representation-specific validator from the actual response bytes.
	 *
	 * @param string $cache_file Plugin-generated cache destination.
	 * @param string $html Exact HTML response body, without a private-file guard.
	 * @return string Quoted content validator.
	 */
	protected static function get_content_etag( $cache_file, $html ) {
		return '"' . md5( $cache_file . '|' . $html ) . '"';
	}

	/**
	 * Proves the selected anonymous cache key to a worker with a partial WPML map.
	 *
	 * @param string $cache_file Selected anonymous cache file.
	 * @param string $etag Validator of the selected HTML body.
	 * @return void
	 */
	protected static function send_warming_proof( $cache_file, $etag ) {
		if ( headers_sent() || self::is_private_cache_file( $cache_file ) || is_user_logged_in()
			|| ! isset( $_SERVER['HTTP_X_HSPSC_WARM_CHECK'] ) || ! is_string( $_SERVER['HTTP_X_HSPSC_WARM_CHECK'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate the opaque challenge without altering its bytes.
		$challenge = wp_unslash( $_SERVER['HTTP_X_HSPSC_WARM_CHECK'] );
		if ( preg_match( '/\A[a-f0-9]{32}\z/', $challenge ) ) {
			header( 'X-HSPSC-Warm-Proof: ' . self::get_warming_proof( $challenge, $cache_file, $etag ) );
		}
	}

	/**
	 * Binds the loopback challenge to the selected key and exact HTML validator.
	 *
	 * @param string $challenge Unique per-request challenge.
	 * @param string $cache_file Selected cache file path, used only as HMAC input.
	 * @param string $etag Validator of the selected HTML body.
	 * @return string Opaque signed proof without a path or language disclosure.
	 */
	protected static function get_warming_proof( $challenge, $cache_file, $etag ) {
		return hash_hmac( 'sha256', 'hspsc-warm|' . $challenge . '|' . basename( $cache_file ) . '|' . $etag, wp_salt( 'auth' ) );
	}

	/**
	 * Identifies authenticated files within the dedicated private cache subtree.
	 *
	 * @param string $cache_file Plugin-generated cache path.
	 * @return bool Whether the file requires the authenticated storage guard.
	 */
	protected static function is_private_cache_file( $cache_file ) {
		return strpos( $cache_file, HSPSC_PATH . '/pages/private/' ) === 0;
	}

	/**
	 * Opens and validates one stable file snapshot before committing a cache hit.
	 *
	 * Copy the opened file before publishing its headers. Some shared filesystems
	 * also mutate old open handles during replacement, so a descriptor alone is not
	 * a stable snapshot. The temporary stream uses at most 2 MiB of memory and spills
	 * larger responses into PHP's private temporary storage. Validate the complete
	 * copy before using it for both the content hash and response body.
	 *
	 * @param string   $cache_file Plugin-generated cache path.
	 * @param int      $ttl Maximum fresh file age in seconds.
	 * @param int|null $max_size Maximum HTML bytes to copy, or null for normal serving.
	 * @return array|false Open handle and content validator, or false for a miss.
	 */
	protected static function open_cache_snapshot( $cache_file, $ttl, $max_size = null ) {
		if ( is_link( $cache_file ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A missing or concurrently removed local cache is an ordinary miss; validate the opened snapshot before sending headers.
		$handle = @fopen( $cache_file, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$stat   = fstat( $handle );
		$offset = self::is_private_cache_file( $cache_file ) ? strlen( self::PRIVATE_FILE_GUARD ) : 0;
		$valid  = is_array( $stat ) && 0100000 === ( $stat['mode'] & 0170000 )
			&& time() - $stat['mtime'] <= $ttl && $stat['size'] >= $offset
			&& ( null === $max_size || $stat['size'] - $offset <= $max_size );
		if ( $valid && $offset > 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Validate the guard on the already-opened local cache source.
			$valid = self::PRIVATE_FILE_GUARD === fread( $handle, $offset );
		}
		$snapshot = false;
		if ( $valid ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- PHP's bounded temporary stream keeps a private immutable response snapshot outside the public cache directory.
			$snapshot = @fopen( 'php://temp/maxmemory:2097152', 'w+b' );
			$valid    = false !== $snapshot && stream_copy_to_stream( $handle, $snapshot ) === $stat['size'] - $offset
				&& rewind( $snapshot );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The source is no longer needed after copying; replacement cannot change the selected temporary snapshot.
		fclose( $handle );
		if ( $valid ) {
			$hash = hash_init( 'md5' );
			hash_update( $hash, $cache_file . '|' );
			$valid = hash_update_stream( $hash, $snapshot ) === $stat['size'] - $offset
				&& rewind( $snapshot );
			if ( $valid ) {
				return array(
					'handle' => $snapshot,
					'etag'   => '"' . hash_final( $hash ) . '"',
				);
			}
		}
		if ( is_resource( $snapshot ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Discard an incomplete or unreadable temporary copy before HIT.
			fclose( $snapshot );
		}
		return false;
	}

	/**
	 * Streams the same validated snapshot used to compute the response validator.
	 *
	 * @param resource $handle Open cache snapshot positioned at the HTML body.
	 * @return void
	 */
	protected static function stream_cache_file( $handle ) {
		fpassthru( $handle );
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
		$ttl     = intval( HSPSC_Settings::get( 'page_cache_ttl', 3600 ) );
		$deleted = HSPSC_Utils::delete_old_files( HSPSC_PATH . '/pages', $ttl, '.html' );
		$private = HSPSC_PATH . '/pages/private';
		if ( ! is_dir( $private ) || is_link( $private ) ) {
			return $deleted;
		}
		foreach ( (array) glob( $private . '/*.php' ) as $file ) {
			if ( is_link( $file ) || ! preg_match( '/\A[a-f0-9]{32}-user-[a-f0-9]{32}-[a-f0-9]{32}\.php\z/', basename( $file ) ) ) {
				continue;
			}
			$mtime = filemtime( $file );
			if ( false !== $mtime && $mtime < time() - $ttl ) {
				wp_delete_file( $file );
				if ( ! file_exists( $file ) ) {
					++$deleted;
				}
			}
		}
		return $deleted;
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
			if ( ! is_string( $generation ) || self::read_cache_generation( $lock ) !== $generation ) {
				return false;
			}
			return self::is_private_cache_file( $cache_file )
				? self::write_private_cache_file( $cache_file, $html )
				: HSPSC_Utils::atomic_write( $cache_file, $html );
		} finally {
			self::unlock_cache_state( $lock );
		}
	}

	/**
	 * Ensures deny rules before storing any authenticated response bytes.
	 *
	 * @return bool Whether the dedicated private directory is protected.
	 */
	protected static function ensure_private_cache_directory() {
		$dir = HSPSC_PATH . '/pages/private';
		if ( is_link( HSPSC_PATH . '/pages' ) || is_link( $dir ) || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) ) {
			return false;
		}
		$protections = array(
			'index.php'  => "<?php exit;\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
		);
		foreach ( $protections as $name => $contents ) {
			$file = $dir . '/' . $name;
			if ( is_link( $file ) || ( file_exists( $file ) && ! is_file( $file ) ) ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify the exact local access-deny file before persisting private HTML.
			$existing = is_file( $file ) ? file_get_contents( $file ) : false;
			if ( $contents !== $existing && ! HSPSC_Utils::atomic_write( $file, $contents ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Atomically stores guarded private HTML with guarded PHP temporary files too.
	 *
	 * @param string $cache_file Authenticated cache destination.
	 * @param string $html Exact HTML body.
	 * @return bool Whether the complete protected payload replaced the destination.
	 */
	protected static function write_private_cache_file( $cache_file, $html ) {
		if ( ! self::ensure_private_cache_directory() ) {
			return false;
		}
		$tmp = dirname( $cache_file ) . '/.hspsc-' . str_replace( '-', '', wp_generate_uuid4() ) . '.php';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation prevents following a temporary-file link; PHP extension and exit guard protect all private temporary bytes.
		$handle = @fopen( $tmp, 'x+b' );
		if ( false === $handle ) {
			return false;
		}
		$payload = self::PRIVATE_FILE_GUARD . $html;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A checked complete write precedes the same-directory private cache replacement.
		$written = fwrite( $handle, $payload );
		$success = strlen( $payload ) === $written && fflush( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively created private temporary stream before renaming.
		fclose( $handle );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private permissions complement the PHP guard and access-deny files where supported.
		@chmod( $tmp, 0600 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory atomic replacement preserves stable opened snapshots.
		if ( $success && @rename( $tmp, $cache_file ) ) {
			return true;
		}
		wp_delete_file( $tmp );
		return false;
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
	 * Clears generated anonymous pages while preserving private pages and assets.
	 *
	 * @return bool Whether invalidation and file removal completed.
	 */
	public static function clear_public_cache() {
		return self::clear_public_cache_keys( array() );
	}

	/**
	 * Clears every anonymous representation of a URL with a resolved language.
	 *
	 * @param string $url Absolute URL whose anonymous cache files should be removed.
	 * @return bool Whether this exact target could be invalidated.
	 */
	public static function clear_public_cache_for_url( $url ) {
		if ( ! self::is_warmable_url( $url ) ) {
			return false;
		}
		$context = self::get_public_warming_context( $url );
		return ! empty( $context['keys'] ) && self::clear_public_cache_keys( $context['keys'] );
	}

	/**
	 * Advances the generation before removing owned anonymous files under its lock.
	 *
	 * @param string[] $keys Resolved URL keys, or empty for all public cache entries.
	 * @return bool Whether the coordinated purge completed.
	 */
	protected static function clear_public_cache_keys( $keys ) {
		$lock = self::lock_cache_state();
		if ( false === $lock ) {
			return false;
		}
		try {
			$dir = HSPSC_PATH . '/pages';
			if ( is_link( $dir ) || ! is_dir( $dir ) ) {
				return false;
			}
			if ( false === self::advance_cache_generation( $lock ) ) {
				self::$request_generation = false;
				return false;
			}
			$files = array();
			foreach ( $keys ? $keys : array( '' ) as $key ) {
				$matches = glob( $dir . '/' . $key . '*.html', GLOB_NOSORT );
				if ( false === $matches ) {
					return false;
				}
				$files = array_merge( $files, $matches );
			}
			$owned = array_filter(
				$files,
				static function ( $file ) {
					return (bool) preg_match( '/\A[a-f0-9]{32}(?:-[a-f0-9]{32})?\.html\z/', basename( $file ) );
				}
			);
			return self::delete_cache_files( $owned );
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
		$files   = array_merge(
			(array) glob( HSPSC_PATH . '/pages/' . $key . '-*.html' ),
			(array) glob( HSPSC_PATH . '/pages/private/' . $key . '-user-*.php' )
		);
		$files[] = HSPSC_PATH . '/pages/' . $key . '.html';
		self::delete_cache_files( $files );
	}

	/**
	 * Deletes only supplied cache entries and checks that removal succeeded.
	 *
	 * @param string[] $files Local cache paths selected by the caller.
	 * @return bool Whether all selected entries were removed.
	 */
	protected static function delete_cache_files( $files ) {
		$fs      = HSPSC_Utils::get_filesystem();
		$deleted = true;
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) && ! is_link( $file ) ) {
				continue;
			}
			if ( $fs && ! is_link( $file ) ) {
				$fs->delete( $file );
			} else {
				wp_delete_file( $file );
			}
			clearstatcache( true, $file );
			if ( file_exists( $file ) || is_link( $file ) ) {
				$deleted = false;
			}
		}
		return $deleted;
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
		self::warm_public_url( $url, self::get_warming_accept() );
	}

	/**
	 * Warms an allowed site URL with a bounded request timeout.
	 *
	 * @param string $url Absolute URL to warm.
	 * @param int    $timeout Requested HTTP timeout in seconds, with a minimum of three.
	 * @return void
	 */
	public static function warm_url_with_timeout( $url, $timeout ) {
		self::warm_public_url( $url, self::get_warming_accept(), max( 3, intval( $timeout ) ) );
	}

	/**
	 * Warms one exact public representation and verifies its stored HTML.
	 *
	 * @param string      $url Absolute target URL on a configured origin.
	 * @param string|null $accept Exact Accept header captured by the initiating request.
	 * @param int         $timeout Per-request timeout in seconds.
	 * @return array Result with a cached, skipped or failed status and fixed reason.
	 */
	public static function warm_public_url( $url, $accept, $timeout = 8 ) {
		if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'cache-disabled',
			);
		}
		if ( ! self::is_valid_warming_accept( $accept ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'invalid-accept',
			);
		}
		$target = self::get_public_warming_target( $url );
		if ( '' !== $target['reason'] ) {
			return array(
				'status' => 'skipped',
				'reason' => $target['reason'],
			);
		}

		$headers   = array( 'Accept' => $accept );
		$challenge = '';
		if ( null === $target['language'] ) {
			$challenge                     = str_replace( '-', '', wp_generate_uuid4() );
			$headers['X-HSPSC-Warm-Check'] = $challenge;
		}
		$response = HSPSC_Utils::get_bounded_identity_response( $url, $headers, $timeout, self::WARMING_RESPONSE_LIMIT );
		$rejected = self::get_warming_response_failure( $response );
		if ( null !== $rejected ) {
			return $rejected;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::WARMING_RESPONSE_LIMIT ) {
			return array(
				'status' => 'failed',
				'reason' => 'response-size',
			);
		}
		$proof = wp_remote_retrieve_header( $response, 'x-hspsc-warm-proof' );
		foreach ( $target['keys'] as $key ) {
			$file = self::get_variant_cache_file_path( $key, $accept );
			$etag = self::get_content_etag( $file, $body );
			if ( '' !== $challenge && ( ! is_string( $proof ) || ! hash_equals( self::get_warming_proof( $challenge, $file, $etag ), $proof ) ) ) {
				continue;
			}
			return self::verify_warmed_file( $file, $etag );
		}
		return array(
			'status' => 'skipped',
			'reason' => 'unresolved-language',
		);
	}

	/**
	 * Rejects failed, non-HTML or private loopback responses before file checks.
	 *
	 * @param array|WP_Error $response HTTP API response.
	 * @return array|null Fixed result for a rejected response, or null for a candidate.
	 */
	protected static function get_warming_response_failure( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'request-error',
			);
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'response-status',
			);
		}
		$cache_status = wp_remote_retrieve_header( $response, 'x-hspsc-cache' );
		$bypass       = 'BYPASS' === $cache_status ? wp_remote_retrieve_header( $response, 'x-hspsc-cache-reason' ) : '';
		if ( in_array( $bypass, array( 'write-failed', 'buffer-conflict' ), true ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'write-failed',
			);
		}
		if ( in_array( $bypass, array( 'private-request', 'late-private-response' ), true ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'private-response',
			);
		}
		$type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! is_string( $type ) || 'text/html' !== strtolower( trim( explode( ';', $type )[0] ) ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'response-type',
			);
		}
		if ( ! HSPSC_Utils::is_identity_http_response( $response ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'response-encoding',
			);
		}
		if ( 'response-headers' === $bypass || self::has_private_warming_headers( $response ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'private-response',
			);
		}
		if ( ! in_array( $cache_status, array( 'HIT', 'MISS' ), true ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'cache-bypass',
			);
		}
		return null;
	}

	/**
	 * Distinguishes private origin responses from HSP's browser revalidation policy.
	 *
	 * @param array $response HTTP API response.
	 * @return bool Whether response headers prevent confirmed public warming.
	 */
	protected static function has_private_warming_headers( $response ) {
		$headers = wp_remote_retrieve_headers( $response );
		if ( isset( $headers['set-cookie'] ) || isset( $headers['location'] ) || isset( $headers['content-disposition'] )
			|| wp_remote_retrieve_cookies( $response ) ) {
			return true;
		}
		$policy = implode( ', ', (array) wp_remote_retrieve_header( $response, 'cache-control' ) );
		if ( preg_match( '/(?:\A|,)[ \t]*(?:private|no-store|no-cache)[ \t]*(?:=|,|\z)/i', $policy )
			&& ! preg_match( '/\Aprivate, *no-cache, *max-age=[0-9]+, *must-revalidate\z/i', trim( $policy ) ) ) {
			return true;
		}
		$vary = implode( ',', (array) wp_remote_retrieve_header( $response, 'vary' ) );
		$vary = array_filter( array_map( 'trim', explode( ',', strtolower( $vary ) ) ), 'strlen' );
		return (bool) array_diff( $vary, array( 'accept', 'accept-encoding' ) );
	}

	/**
	 * Confirms that the exact bytes received also exist in the fresh public file.
	 *
	 * @param string $file Expected anonymous cache file.
	 * @param string $etag Expected content validator derived from the full response.
	 * @return array Fixed result of the persisted cache verification.
	 */
	protected static function verify_warmed_file( $file, $etag ) {
		clearstatcache( true, $file );
		$snapshot = self::open_cache_snapshot( $file, (int) HSPSC_Settings::get( 'page_cache_ttl', 3600 ), self::WARMING_RESPONSE_LIMIT );
		if ( false === $snapshot ) {
			return array(
				'status' => 'failed',
				'reason' => 'cache-missing',
			);
		}
		$stat = fstat( $snapshot['handle'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the verified immutable local snapshot after comparing its validator.
		fclose( $snapshot['handle'] );
		if ( false === $stat || 0 === $stat['size'] ) {
			return array(
				'status' => 'failed',
				'reason' => 'cache-missing',
			);
		}
		if ( ! hash_equals( $snapshot['etag'], $etag ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'cache-mismatch',
			);
		}
		return array(
			'status' => 'cached',
			'reason' => 'cached',
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
	 * Checks the explicit target before planning a page or sitemap loopback.
	 *
	 * @param string $url Absolute target URL.
	 * @param bool   $for_sitemap Whether the target is a sitemap, not an HTML page.
	 * @return string Empty on acceptance, or a fixed skip reason.
	 */
	public static function validate_public_warming_url( $url, $for_sitemap = false ) {
		$target = self::get_public_warming_target( $url, $for_sitemap );
		return $target['reason'];
	}

	/**
	 * Resolves target privacy and candidate cache keys without changing the request.
	 *
	 * @param string $url Absolute target URL.
	 * @param bool   $for_sitemap Whether page-only checks should be omitted.
	 * @return array Skip reason and, for allowed pages, the cache context.
	 */
	protected static function get_public_warming_target( $url, $for_sitemap = false ) {
		if ( ! self::is_warmable_url( $url ) ) {
			return array( 'reason' => 'invalid-url' );
		}
		if ( HSPSC_Utils::is_warming_url_excluded( $url ) ) {
			return array( 'reason' => 'excluded' );
		}
		$context = $for_sitemap ? array() : self::get_public_warming_context( $url );
		if ( ! $for_sitemap && empty( $context['keys'] ) ) {
			return array( 'reason' => 'unresolved-language' );
		}
		$post_id = url_to_postid( $url );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		if ( $post instanceof WP_Post && ( '' !== $post->post_password || ! is_post_publicly_viewable( $post ) ) ) {
			return array( 'reason' => 'private-page' );
		}
		// Real sitemap endpoints have no queried post. Still reject a private page used as a sitemap URL.
		if ( ( ! $for_sitemap || $post instanceof WP_Post ) && HSPSC_Commerce::is_warming_url_dynamic( $post, $context['language'] ?? '' ) ) {
			return array( 'reason' => 'private-page' );
		}
		return array( 'reason' => '' ) + $context;
	}

	/**
	 * Lists exact URL keys for API-known languages on the approved target origin.
	 *
	 * An incomplete admin or cron WPML catalog cannot always select a language.
	 * In that case the loopback must prove which candidate the live request used.
	 *
	 * @param string $url Absolute target URL on an already validated origin.
	 * @return array Candidate keys and the resolved language, or null if ambiguous.
	 */
	protected static function get_public_warming_context( $url ) {
		$language  = HSPSC_WPML::url_context( $url );
		$languages = array( $language );
		if ( null === $language ) {
			$languages = array();
			$origin    = self::get_url_origin( $url );
			foreach ( HSPSC_WPML::language_urls() as $code => $root ) {
				if ( self::get_url_origin( $root ) === $origin ) {
					$languages[] = $code;
				}
			}
		}
		$keys = array();
		foreach ( $languages as $code ) {
			$key = self::get_url_cache_key( $url, '', $code );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}
		return array(
			'keys'     => $keys,
			'language' => $language,
		);
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
	public static function get_url_origin( $url ) {
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
