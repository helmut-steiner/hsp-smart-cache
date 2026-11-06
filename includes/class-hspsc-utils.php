<?php
/**
 * Shared cache eligibility and filesystem helpers.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides shared request privacy checks and generated-file operations.
 */
class HSPSC_Utils {
	/**
	 * Legacy page-eligibility state cleared by reset_request_cache().
	 *
	 * @var array
	 */
	protected static $cacheable_request = array();

	/**
	 * Legacy optimization state cleared by reset_request_cache().
	 *
	 * @var array
	 */
	protected static $frontend_optimizations = array();

	/**
	 * Legacy backend-detection state cleared by reset_request_cache().
	 *
	 * @var array
	 */
	protected static $backend_or_login_request = array();

	/**
	 * Legacy editor-detection state cleared by reset_request_cache().
	 *
	 * @var array
	 */
	protected static $editor_or_builder_request = array();

	/**
	 * Whether this request has already ensured the cache directories.
	 *
	 * @var bool
	 */
	protected static $cache_dirs_ensured = false;

	/**
	 * Initializes and returns the WordPress filesystem adapter.
	 *
	 * @return WP_Filesystem_Base|false|null Adapter, or null when initialization fails.
	 */
	public static function get_filesystem() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}

	/**
	 * Creates the disposable cache directories once per request.
	 *
	 * @return void
	 */
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

	/**
	 * Appends configured AI crawler exclusions to public robots.txt output.
	 *
	 * @param string   $output Existing robots.txt output.
	 * @param bool|int $public Whether WordPress considers the site public.
	 * @return string Filtered robots.txt output.
	 */
	public static function apply_robots_rules( $output, $public ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.publicFound -- Preserves the public helper parameter name corresponding to the robots_txt hook argument.
		if ( ! $public ) {
			return $output;
		}
		if ( ! HSPSC_Settings::get( 'robots_disallow_ai' ) ) {
			return $output;
		}

		$output = trim( (string) $output );
		if ( '' !== $output ) {
			$output .= "\n";
		}

		$output .= implode( "\n", self::get_ai_robots_lines() );

		return $output . "\n";
	}

	/**
	 * Builds robots.txt directives for the supported AI crawler names.
	 *
	 * @return string[] Directive lines, including section separators.
	 */
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

	/**
	 * Deletes a cache directory's contents without following symbolic links.
	 *
	 * @param string $dir Absolute cache directory path.
	 * @return void
	 */
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
			if ( '.' === $item || '..' === $item ) {
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

	/**
	 * Reevaluates privacy and representation guards for page-cache eligibility.
	 *
	 * @param bool $resolve_language Whether the initialized WPML language context is required.
	 * @return bool Whether this request may use the page cache.
	 */
	public static function is_request_cacheable( $resolve_language = true ) {
		// Sessions and no-cache flags can change after the initial cache lookup.
		if ( self::is_backend_or_login_request() || self::has_private_request_context() ) {
			return false;
		}
		$is_logged_in = is_user_logged_in();
		if ( $is_logged_in && ! HSPSC_Settings::get( 'cache_logged_in' ) ) {
			return false;
		}
		// Only a verified user may ignore the login cookie; all other private cookies remain exclusions.
		if ( self::is_page_cache_path_excluded() || self::has_sensitive_cookies( ! $is_logged_in ) || self::is_sensitive_request_uri() ) {
			return false;
		}
		if ( self::get_request_method() !== 'GET' ) {
			return false;
		}
		if ( is_preview() || is_feed() || is_robots() || is_trackback() || is_search() || is_404() || post_password_required() ) {
			return false;
		}
		if ( $resolve_language && class_exists( 'HSPSC_WPML', false ) && null === HSPSC_WPML::request_context() ) {
			return false;
		}
		if ( HSPSC_Commerce::is_dynamic_request() ) {
			return false;
		}

		return (bool) apply_filters( 'hspsc_is_request_cacheable', true );
	}

	/**
	 * Match configured exact URL paths independently of query parameters.
	 *
	 * Checked before serving a hit and again before writing the rendered page.
	 * Frontend asset optimizations have a separate eligibility policy.
	 *
	 * @return bool Whether this path is excluded from page caching.
	 */
	public static function is_page_cache_path_excluded() {
		return self::is_configured_cache_path_excluded( wp_parse_url( self::get_request_uri(), PHP_URL_PATH ) );
	}

	/**
	 * Tests one target path against the configured exact exclusions.
	 *
	 * @param string|null $path URL path before normalization.
	 * @return bool Whether the configured exclusions contain the path.
	 */
	protected static function is_configured_cache_path_excluded( $path ) {
		$configured = HSPSC_Settings::get( 'page_cache_excluded_paths', '' );
		if ( ! is_string( $configured ) || '' === trim( $configured ) ) {
			return false;
		}

		$path = self::normalize_page_cache_path( $path );
		if ( null === $path ) {
			return false;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $configured ) as $excluded ) {
			if ( self::normalize_page_cache_path( $excluded ) === $path ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize a host-relative path for exact exclusion matching.
	 *
	 * @param mixed $path URL path, including an installation subdirectory if used.
	 * @return string|null Normalized path with a trailing slash, or invalid input.
	 */
	public static function normalize_page_cache_path( $path ) {
		if ( ! is_string( $path ) ) {
			return null;
		}
		$path = trim( $path );
		if ( '' === $path || '/' !== $path[0] || str_starts_with( $path, '//' ) ) {
			return null;
		}
		$path = rawurldecode( $path );
		if ( preg_match( '/[\x00-\x1F\x7F?#\\\\*<>]/', $path ) ) {
			return null;
		}

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
			} else {
				$segments[] = $segment;
			}
		}
		// Preserve literal percent signs when a saved path is normalized again.
		return empty( $segments ) ? '/' : '/' . str_replace( '%', '%25', implode( '/', $segments ) ) . '/';
	}

	/**
	 * Identifies backend, login, API, cron, and editor requests.
	 *
	 * @return bool Whether shared frontend caching must be bypassed.
	 */
	public static function is_backend_or_login_request() {
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return true;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return true;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		if ( 'wp-login.php' === $pagenow || 'wp-register.php' === $pagenow || self::is_editor_or_builder_request() ) {
			return true;
		}

		$path = self::normalize_page_cache_path( wp_parse_url( self::get_request_uri(), PHP_URL_PATH ) );
		return self::is_wordpress_backend_path( $path );
	}

	/**
	 * Checks a normalized path for WordPress administrative or action endpoints.
	 *
	 * @param string|null $path Normalized target path.
	 * @return bool Whether it is invalid or resolves to a backend endpoint.
	 */
	protected static function is_wordpress_backend_path( $path ) {
		return null === $path || (bool) preg_match( '~/(?:wp-admin|wp-login\.php|wp-register\.php|wp-json)(?:/|$)~i', $path );
	}

	/**
	 * Applies request URI exclusions to a planned public GET without impersonating it.
	 *
	 * @param string $url Absolute target on an already validated origin.
	 * @return bool Whether warming must avoid fetching the target.
	 */
	public static function is_warming_url_excluded( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['fragment'] ) ) {
			return true;
		}
		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';
		$args  = array();
		wp_parse_str( $query, $args );
		$args = array_change_key_case( $args, CASE_LOWER );
		// A warmer must never initiate action links, searches, feeds or moderation previews.
		$actions    = array( 'action', 'logout', 'redirect_to', 'doing_wp_cron', 's', 'feed', 'unapproved', 'moderation-hash', 'hspsc_notice' );
		$normalized = self::normalize_page_cache_path( $path );
		return self::is_wordpress_backend_path( $normalized )
			|| (bool) preg_match( '~/(?:wp-(?:signup|activate|cron|comments-post|trackback|mail)\.php|xmlrpc\.php)(?:/|$)~i', $normalized )
			|| self::is_configured_cache_path_excluded( $path )
			|| self::has_editor_query_args( $args )
			|| (bool) array_intersect( $actions, array_keys( $args ) )
			|| self::is_sensitive_uri( $path . ( '' === $query ? '' : '?' . $query ), $args );
	}

	/**
	 * Reevaluates request privacy before changing frontend assets or markup.
	 *
	 * @return bool Whether frontend optimization is allowed.
	 */
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

	/**
	 * Recognizes supported builder and Customizer query markers.
	 *
	 * @return bool Whether the request belongs to an editor or preview.
	 */
	public static function is_editor_or_builder_request() {
		return self::has_editor_query_args( self::get_request_query_args() );
	}

	/**
	 * Checks parsed query arguments for supported editor markers.
	 *
	 * @param array $args Query arguments keyed by lowercase names.
	 * @return bool Whether an editor marker is present.
	 */
	protected static function has_editor_query_args( $args ) {
		if ( isset( $args['bricks'] ) && is_string( $args['bricks'] ) && in_array( $args['bricks'], array( 'run', 'preview' ), true ) ) {
			return true;
		}
		if ( isset( $args['kspreview'] ) && 'true' === $args['kspreview'] ) {
			return true;
		}
		return array_key_exists( 'customize_changeset_uuid', $args );
	}

	/**
	 * Reads the request method without turning malformed methods into valid GETs.
	 *
	 * @return string Uppercase method, defaulting to GET when absent.
	 */
	protected static function get_request_method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Consumers accept only the exact GET token; sanitizing malformed input into GET could make a private request cacheable.
			? strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
	}

	/**
	 * Reads the unslashed request URI for exact path and privacy checks.
	 *
	 * @return string Request URI, or an empty string when unavailable.
	 */
	protected static function get_request_uri() {
		return isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserves the exact URI for privacy checks and parsed exclusion matching; this value is not rendered or used as a filesystem path.
			? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	}

	/**
	 * Merges query arguments from the URI, server, and WordPress request state.
	 *
	 * @return array Query arguments indexed by lowercase names.
	 */
	protected static function get_request_query_args() {
		$args  = array();
		$query = wp_parse_url( self::get_request_uri(), PHP_URL_QUERY );
		if ( is_string( $query ) ) {
			wp_parse_str( $query, $args );
		}
		if ( isset( $_SERVER['QUERY_STRING'] ) && is_string( $_SERVER['QUERY_STRING'] ) ) {
			$server_args = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed arguments are checked for sensitive keys and exact builder markers; sanitization could erase a bypass marker.
			wp_parse_str( wp_unslash( $_SERVER['QUERY_STRING'] ), $server_args );
			$args = array_merge( $args, $server_args );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cache eligibility inspection must work for anonymous requests without a nonce.
		if ( ! empty( $_GET ) && is_array( $_GET ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only detects sensitive query keys and exact preview markers; this does not execute a requested action.
			$args = array_merge( $args, wp_unslash( $_GET ) );
		}
		return array_change_key_case( $args, CASE_LOWER );
	}

	/**
	 * Detects explicit no-cache flags, sessions, authentication, and AJAX markers.
	 *
	 * @return bool Whether the request must remain private.
	 */
	protected static function has_private_request_context() {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return true;
		}
		if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
			return true;
		}
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'HTTP_X_WP_NONCE', 'HTTP_CART_TOKEN', 'HTTP_X_WC_STORE_API_NONCE' ) as $header ) {
			if ( isset( $_SERVER[ $header ] ) && '' !== $_SERVER[ $header ] ) {
				return true;
			}
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only an exact case-insensitive protocol marker triggers a privacy bypass; no header bytes are rendered or stored.
		if ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && is_string( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) === 'xmlhttprequest' ) {
			return true;
		}
		return false;
	}

	/**
	 * Extracts a URL path only for local or configured WordPress asset hosts.
	 *
	 * @param string $url Absolute or host-relative asset URL.
	 * @return string|null Host-relative path, or null for an unsupported URL.
	 */
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

			$host          = strtolower( $parts['host'] );
			$allowed_hosts = array_map( 'strtolower', array_unique( $allowed_hosts ) );

			if ( ! in_array( $host, $allowed_hosts, true ) ) {
				return null;
			}
		}

		return '/' . ltrim( $parts['path'], '/' );
	}

	/**
	 * Fetches an approved public URL into private temporary storage.
	 *
	 * Requests can decode compressed response bodies despite the HTTP API flag.
	 * Streaming keeps the response body out of that decoder. Inspect the encoding
	 * before reading, and retain one extra byte so callers can detect truncation.
	 *
	 * @param string $url Explicit URL already approved by the caller.
	 * @param array  $headers Accept and optional warming challenge headers.
	 * @param int    $timeout Timeout in seconds.
	 * @param int    $max_bytes Maximum accepted body size.
	 * @return array|WP_Error WordPress HTTP response with a bounded identity body.
	 */
	public static function get_bounded_identity_response( $url, $headers, $timeout, $max_bytes ) {
		$file = self::create_private_http_temp_file();
		if ( false === $file ) {
			return new WP_Error( 'hspsc_http_temp', __( 'Private temporary storage is unavailable.', 'hsp-smart-cache' ) );
		}
		$headers                    = array_intersect_key(
			$headers,
			array(
				'Accept'             => true,
				'X-HSPSC-Warm-Check' => true,
			)
		);
		$headers['Accept-Encoding'] = 'identity';
		$used_curl                  = false;
		$mark_curl                  = static function ( $handle, $args, $target ) use ( $url, $file, &$used_curl ) {
			if ( $url === $target && ( $args['filename'] ?? null ) === $file ) {
				$used_curl = true;
			}
		};
		add_action( 'http_api_curl', $mark_curl, 10, 3 );
		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => max( 1, min( 30, (int) $timeout ) ),
					'redirection'         => 0,
					'limit_response_size' => $max_bytes + 1,
					'decompress'          => false,
					'cookies'             => array(),
					'headers'             => $headers,
					'stream'              => true,
					'filename'            => $file,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( ! is_array( $response ) ) {
				return new WP_Error( 'hspsc_http_read' );
			}
			$body = '';
			if ( self::is_identity_http_response( $response ) ) {
				$inline = wp_remote_retrieve_body( $response );
				// HTTP filters can supply an inline fixture without using the stream.
				$body = is_string( $inline ) && '' !== $inline
					? substr( $inline, 0, $max_bytes + 1 )
					: self::read_bounded_http_file( $file, $max_bytes, ! $used_curl && self::has_chunked_http_framing( $response ) );
				if ( false === $body ) {
					return new WP_Error( 'hspsc_http_read', __( 'The temporary HTTP response could not be read.', 'hsp-smart-cache' ) );
				}
			}
			$response['body']     = $body;
			$response['filename'] = null;
			return $response;
		} finally {
			remove_action( 'http_api_curl', $mark_curl, 10 );
			wp_delete_file( $file );
		}
	}

	/**
	 * Checks whether a response used the requested identity content encoding.
	 *
	 * @param array $response WordPress HTTP response.
	 * @return bool Whether the body can be read without decompression.
	 */
	public static function is_identity_http_response( $response ) {
		$encoding = wp_remote_retrieve_header( $response, 'content-encoding' );
		return is_string( $encoding ) && in_array( strtolower( trim( $encoding ) ), array( '', 'identity' ), true );
	}

	/**
	 * Creates a restricted temporary file outside configured web roots.
	 *
	 * @return string|false Temporary file path, or false when no safe directory exists.
	 */
	protected static function create_private_http_temp_file() {
		$public_roots  = array( ABSPATH, WP_CONTENT_DIR );
		$document_root = filter_input( INPUT_SERVER, 'DOCUMENT_ROOT', FILTER_UNSAFE_RAW );
		if ( is_string( $document_root ) && '' !== $document_root ) {
			$public_roots[] = $document_root;
		}
		$public_roots = array_filter( array_map( 'realpath', $public_roots ) );
		foreach ( array_unique( array( get_temp_dir(), sys_get_temp_dir() ) ) as $candidate ) {
			$directory = realpath( $candidate );
			if ( false === $directory || ! is_dir( $directory ) || ! wp_is_writable( $directory ) ) {
				continue;
			}
			$path = strtolower( trailingslashit( wp_normalize_path( $directory ) ) );
			foreach ( $public_roots as $root ) {
				if ( str_starts_with( $path, strtolower( trailingslashit( wp_normalize_path( $root ) ) ) ) ) {
					continue 2;
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A racing permission failure must return a fixed error without revealing a private path.
			$file = @tempnam( $directory, 'hspsc-http-' );
			if ( false !== $file ) {
				if ( realpath( dirname( $file ) ) === $directory ) {
					return $file;
				}
				// tempnam can fall back to another directory when creation fails.
				wp_delete_file( $file );
			}
		}
		return false;
	}

	/**
	 * Reads the original transfer framing retained by WordPress's Requests wrapper.
	 *
	 * @param array $response WordPress HTTP response.
	 * @return bool Whether the response used chunked transfer framing.
	 */
	protected static function has_chunked_http_framing( $response ) {
		$wrapper = $response['http_response'] ?? null;
		return $wrapper instanceof WP_HTTP_Requests_Response && (bool) preg_match(
			'/^(?:Transfer-Encoding):[ \t]*chunked[ \t]*\r?$/im',
			$wrapper->get_response_object()->raw
		);
	}

	/**
	 * Reads a bounded identity body, undoing socket transport framing when present.
	 *
	 * @param string $file Private temporary file created for this request.
	 * @param int    $max_bytes Maximum accepted body size.
	 * @param bool   $chunked Whether raw chunk framing remains in the streamed file.
	 * @return string|false Bounded body, or false on a read failure.
	 */
	protected static function read_bounded_http_file( $file, $max_bytes, $chunked ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The local stream limits bytes and can remove transport framing without exposing file warnings.
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		try {
			$stat = fstat( $handle );
			if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ) {
				return false;
			}
			// Leave an oversized wire body intact so callers can detect the extra byte.
			if ( $chunked && $stat['size'] <= $max_bytes && false === stream_filter_append( $handle, 'dechunk', STREAM_FILTER_READ ) ) {
				return false;
			}
			return stream_get_contents( $handle, $max_bytes + 1 );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the bounded read stream before unlinking the temporary file.
			fclose( $handle );
		}
	}

	/**
	 * Deletes aged cache files matching an optional extension without following links.
	 *
	 * @param string $dir Absolute cache directory path.
	 * @param int    $max_age Minimum file age in seconds, clamped to at least one.
	 * @param string $extension Required filename suffix, or empty for any file.
	 * @return int Number of files deleted.
	 */
	public static function delete_old_files( $dir, $max_age, $extension = '' ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return 0;
		}

		$deleted = 0;
		$items   = scandir( $dir );
		if ( ! $items ) {
			return 0;
		}

		$max_age = max( 1, intval( $max_age ) );
		$cutoff  = time() - $max_age;

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;
			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				$deleted  += self::delete_old_files( $path, $max_age, $extension );
				$remaining = scandir( $path );
				if ( $remaining && count( array_diff( $remaining, array( '.', '..' ) ) ) === 0 ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort removal of a checked empty cache directory may race another writer; failure must not interrupt cleanup.
					@rmdir( $path );
				}
				continue;
			}

			if ( '' !== $extension && substr( $path, -strlen( $extension ) ) !== $extension ) {
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

	/**
	 * Replaces a generated cache file through a locked, same-directory temporary file.
	 *
	 * @param string $path Absolute filesystem path.
	 * @param string $contents Exact bytes to write.
	 * @return bool Whether every byte was written and the file was replaced.
	 */
	public static function atomic_write( $path, $contents ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$tmp = self::temp_file( $dir, basename( $path ) );
		if ( ! $tmp ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic cache replacement needs a locked write to a same-directory temporary file; transferred filesystem writes do not provide this guarantee.
		$bytes = file_put_contents( $tmp, $contents, LOCK_EX );
		if ( false === $bytes || $bytes < strlen( $contents ) ) {
			wp_delete_file( $tmp );
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Applies local temporary-file permissions where supported; platforms without chmod support still use the checked atomic replacement.
		@chmod( $tmp, 0644 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename provides atomic cache replacement; a checked failure takes the platform fallback or cleanup path.
		if ( @rename( $tmp, $path ) ) {
			return true;
		}

		wp_delete_file( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename provides atomic cache replacement; a checked failure takes the platform fallback or cleanup path.
		if ( @rename( $tmp, $path ) ) {
			return true;
		}

		wp_delete_file( $tmp );
		return false;
	}

	/**
	 * Creates a temporary file in the destination directory for atomic replacement.
	 *
	 * @param string $dir Absolute cache directory path.
	 * @param string $filename Destination basename used for the temporary filename.
	 * @return string|false Temporary path, or false when creation fails.
	 */
	protected static function temp_file( $dir, $filename ) {
		if ( ! function_exists( 'wp_tempnam' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( function_exists( 'wp_tempnam' ) ) {
			return wp_tempnam( $filename, $dir );
		}

		return tempnam( $dir, '.tmp-' );
	}

	/**
	 * Resets request-local state and captures the current page-cache generation.
	 *
	 * @return void
	 */
	public static function reset_request_cache() {
		self::$cacheable_request         = array();
		self::$frontend_optimizations    = array();
		self::$backend_or_login_request  = array();
		self::$editor_or_builder_request = array();
		if ( class_exists( 'HSPSC_Page', false ) ) {
			HSPSC_Page::capture_request_generation();
		}
	}

	/**
	 * Detects privacy-sensitive cookie names independently of their values.
	 *
	 * @param bool $include_authentication Whether login cookies also force a bypass.
	 * @return bool Whether the request carries a sensitive cookie.
	 */
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

	/**
	 * Detects private commerce, preview, token, and API request markers.
	 *
	 * @return bool Whether the request URI requires a cache bypass.
	 */
	protected static function is_sensitive_request_uri() {
		return self::is_sensitive_uri( self::get_request_uri(), self::get_request_query_args() );
	}

	/**
	 * Checks a relative URI and its parsed query for private request markers.
	 *
	 * @param string $uri Relative target URI.
	 * @param array  $args Query arguments keyed by lowercase names.
	 * @return bool Whether it carries a private marker.
	 */
	protected static function is_sensitive_uri( $uri, $args ) {
		$sensitive_args = array(
			'wc-ajax',
			'wc-api',
			'add-to-cart',
			'remove_item',
			'undo_item',
			'order-pay',
			'order-received',
			'download_file',
			'key',
			'_wpnonce',
			'fct_cart_hash',
			'fluent-cart',
			'rest_route',
			'preview',
			'wcmlc',
			'edd_action',
			'add_to_cart',
			'discount',
			'payment_key',
			'eddfile',
			'customize_changeset_uuid',
			'token',
			'access_token',
			'cart_token',
			'session_id',
		);
		foreach ( $sensitive_args as $arg ) {
			if ( array_key_exists( $arg, $args ) ) {
				return true;
			}
		}
		if ( isset( $args['action'] ) && is_string( $args['action'] ) && strpos( $args['action'], 'fluent_cart_' ) === 0 ) {
			return true;
		}

		$uri                 = strtolower( rawurldecode( $uri ) );
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
