<?php
/**
 * Generates safe asset variants and preserves dynamic output boundaries.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates safe asset variants and preserves dynamic output boundaries.
 */
class HSPSC_Minify {
	/**
	 * Generated asset URLs memoized by source URL and asset family.
	 *
	 * @var array
	 */
	protected static $asset_url_cache = array();
	/**
	 * Original script sources indexed by registered handle.
	 *
	 * @var array
	 */
	protected static $original_script_urls = array();
	/**
	 * Original transformed stylesheet sources indexed by handle.
	 *
	 * @var array
	 */
	protected static $original_style_urls = array();

	/**
	 * Registers asset transformation and late opt-out restoration filters.
	 */
	public static function init() {
		add_filter( 'style_loader_src', array( __CLASS__, 'filter_style_src' ), 20, 2 );
		add_filter( 'script_loader_src', array( __CLASS__, 'filter_script_src' ), 20, 2 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'restore_excluded_script_url' ), PHP_INT_MAX, 3 );
		add_filter( 'style_loader_tag', array( __CLASS__, 'restore_excluded_style_url' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Minifies eligible styles while preserving native RTL and private requests.
	 *
	 * @param string $src Original stylesheet URL.
	 * @param string $handle Registered or synthetic style handle.
	 * @return string Original or generated asset URL.
	 */
	public static function filter_style_src( $src, $handle ) {
		global $wp_styles;

		unset( self::$original_style_urls[ $handle ] );
		if ( ! HSPSC_Settings::get( 'minify_css' ) || ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
			return $src;
		}
		if ( self::is_native_rtl_stylesheet( $handle ) ) {
			return $src;
		}
		if ( $wp_styles instanceof WP_Styles && isset( $wp_styles->registered[ $handle ] ) && self::has_excluded_metadata( (array) $wp_styles->registered[ $handle ]->extra ) ) {
			return $src;
		}
		$minified = self::maybe_minify_asset( $src, 'css' );
		if ( $src !== $minified ) {
			self::$original_style_urls[ $handle ] = $src;
		}
		return $minified;
	}

	/**
	 * Leaves WordPress in control of native RTL stylesheet filename selection.
	 *
	 * Core filters companion sources with a synthetic "-rtl" handle before
	 * replacing their filename, but filters both final tags with the base handle.
	 * Relocating either source can break that substitution or restore the LTR URL.
	 *
	 * @param string $handle Registered or synthetic stylesheet handle.
	 * @return bool Whether WordPress is selecting a native RTL stylesheet pair.
	 */
	protected static function is_native_rtl_stylesheet( $handle ) {
		global $wp_styles;

		if ( ! ( $wp_styles instanceof WP_Styles ) || 'rtl' !== $wp_styles->text_direction ) {
			return false;
		}
		if ( ! empty( $wp_styles->registered[ $handle ]->extra['rtl'] ) ) {
			return true;
		}
		if ( str_ends_with( $handle, '-rtl' ) ) {
			$base_handle = substr( $handle, 0, -4 );
			return ! empty( $wp_styles->registered[ $base_handle ]->extra['rtl'] );
		}
		return false;
	}

	/**
	 * Minifies eligible script sources after integration exclusions.
	 *
	 * @param string $src Original script URL.
	 * @param string $handle Registered script handle.
	 * @return string Original or generated asset URL.
	 */
	public static function filter_script_src( $src, $handle ) {
		if ( ! HSPSC_Settings::get( 'minify_js' ) || self::is_script_excluded( $handle, $src ) ) {
			return $src;
		}
		self::$original_script_urls[ $handle ] = $src;
		return self::maybe_minify_asset( $src, 'js' );
	}

	/**
	 * Checks integration handles, sensitive origins, and script metadata.
	 *
	 * @param string $handle Registered script handle.
	 * @param string $src Script URL, when available.
	 * @return bool Whether transformation must be bypassed.
	 */
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

	/**
	 * Recognizes module and explicit optimization opt-out metadata.
	 *
	 * @param array $extra Registered asset metadata.
	 * @return bool Whether the metadata prohibits optimization.
	 */
	protected static function has_excluded_metadata( $extra ) {
		$attributes = isset( $extra['attributes'] ) && is_array( $extra['attributes'] ) ? $extra['attributes'] : array();
		foreach ( array( $extra, $attributes ) as $metadata ) {
			if ( array_key_exists( 'data-no-optimize', $metadata ) || array_key_exists( 'data-cfasync', $metadata ) || ( isset( $metadata['type'] ) && strtolower( $metadata['type'] ) === 'module' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Restores a script source when its final tag or request opts out.
	 *
	 * @param string $tag Filtered script markup.
	 * @param string $handle Registered script handle.
	 * @param string $src Filtered script URL.
	 * @return string Markup with the appropriate source URL.
	 */
	public static function restore_excluded_script_url( $tag, $handle, $src ) {
		if ( isset( self::$original_script_urls[ $handle ] ) ) {
			return self::restore_asset_url_attribute( $tag, 'script', 'src', $src, self::$original_script_urls[ $handle ] );
		}
		return $tag;
	}

	/**
	 * Restores a transformed stylesheet when its final tag or request opts out.
	 *
	 * @param string $tag Filtered stylesheet markup.
	 * @param string $handle Registered stylesheet handle.
	 * @param string $href Filtered stylesheet URL.
	 * @param string $media Media attribute supplied by WordPress.
	 * @return string Markup with the appropriate source URL.
	 */
	public static function restore_excluded_style_url( $tag, $handle, $href, $media = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Keep the existing public callback signature and named-argument compatibility.
		if ( isset( self::$original_style_urls[ $handle ] ) ) {
			return self::restore_asset_url_attribute( $tag, 'link', 'href', $href, self::$original_style_urls[ $handle ] );
		}
		return $tag;
	}

	/**
	 * Restore the actual tag attribute after another filter changes its URL form.
	 *
	 * Some integrations add module attributes and make the URL root-relative
	 * after script_loader_src. Match the HTML attribute instead of the previous
	 * absolute URL, and leave other attributes and inline payloads untouched.
	 *
	 * @param string $tag          Loader HTML, which may include inline scripts.
	 * @param string $tag_name     External asset tag name.
	 * @param string $attribute    URL attribute name.
	 * @param string $current_url  Filtered loader URL, possibly HTML-escaped.
	 * @param string $original_url Original asset URL before minification.
	 * @return string Loader HTML with only the excluded asset URL restored.
	 */
	protected static function restore_asset_url_attribute( $tag, $tag_name, $attribute, $current_url, $original_url ) {
		$current_url  = wp_specialchars_decode( $current_url, ENT_QUOTES );
		$relative_url = wp_make_link_relative( $current_url );
		$processor    = new WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( $tag_name ) ) {
			$url = $processor->get_attribute( $attribute );
			if ( ! is_string( $url ) || ( $url !== $current_url && $url !== $relative_url ) ) {
				continue;
			}
			if ( HSPSC_Utils::should_apply_frontend_optimizations()
				&& null === $processor->get_attribute( 'data-no-optimize' )
				&& null === $processor->get_attribute( 'data-cfasync' )
				&& ! ( 'script' === $tag_name && 'module' === strtolower( (string) $processor->get_attribute( 'type' ) ) ) ) {
				return $tag;
			}
			$processor->set_attribute( $attribute, esc_url_raw( $original_url ) );
			return $processor->get_updated_html();
		}
		return $tag;
	}

	/**
	 * Reuses or atomically generates an eligible local asset variant.
	 *
	 * @param string $src Original asset URL.
	 * @param string $type Asset family, css or js.
	 * @return string Generated URL or the unchanged source URL.
	 */
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
		if ( false === $mtime ) {
			self::$asset_url_cache[ $cache_key ] = $src;
			return $src;
		}

		$file_size = filesize( $file_path );
		$max_size  = (int) apply_filters( 'hspsc_max_minify_asset_size', 5 * 1024 * 1024, $type, $file_path );
		if ( false === $file_size || $file_size > max( 1, $max_size ) ) {
			self::$asset_url_cache[ $cache_key ] = $src;
			return $src;
		}

		// Preserve explicit URL versions even when deployments retain file timestamps.
		$query    = (string) wp_parse_url( $src, PHP_URL_QUERY );
		$hash     = md5( 'hspsc-assets-v3|' . $file_path . '|' . $mtime . '|' . $query );
		$filename = $hash . '.min.' . $type;
		$target   = HSPSC_PATH . '/assets/' . $filename;

		if ( is_link( $target ) || ( file_exists( $target ) && ( ! is_file( $target ) || ! is_readable( $target ) ) ) ) {
			self::$asset_url_cache[ $cache_key ] = $src;
			return $src;
		}
		if ( ! file_exists( $target ) ) {
			// Existing entries have already passed the source relocation checks.
			$contents = static::read_asset_contents( $file_path );
			if ( false === $contents || ! self::can_relocate_asset( $contents, $file_path, $type ) ) {
				self::$asset_url_cache[ $cache_key ] = $src;
				return $src;
			}
			HSPSC_Utils::ensure_cache_dirs();
			$minified = ( 'css' === $type ) ? self::minify_css( $contents ) : self::minify_js( $contents );
			if ( HSPSC_Utils::atomic_write( $target, $minified ) ) {
				self::maybe_cleanup_asset_cache();
			} else {
				self::$asset_url_cache[ $cache_key ] = $src;
				return $src;
			}
		}
		// Refresh at most daily so an in-flight render cannot race aged-file GC.
		$target_mtime = filemtime( $target );
		if ( false === $target_mtime || ( time() - $target_mtime >= DAY_IN_SECONDS && ! self::refresh_asset_usage( $target ) ) ) {
			self::$asset_url_cache[ $cache_key ] = $src;
			return $src;
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the validated, size-limited local source directly without filesystem credential prompts.
		return file_get_contents( $file_path );
	}

	/**
	 * Rejects assets whose relative references require their original directory.
	 *
	 * @param string $contents Source asset bytes.
	 * @param string $file_path Validated local source path.
	 * @param string $type Asset family, css or js.
	 * @return bool Whether relocation is safe.
	 */
	protected static function can_relocate_asset( $contents, $file_path, $type ) {
		if ( 'css' === $type ) {
			// Relative references resolve against the original stylesheet URL.
			return ! preg_match( '/(?:url\s*\(|@import\b)/i', $contents );
		}

		// Module URLs and relative chunks must stay in their original directory.
		return strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) !== 'mjs'
			&& ! preg_match( '/\b(?:import|export)\b|currentScript/', $contents );
	}

	/**
	 * Resolves a readable source inside allowed roots with an approved extension.
	 *
	 * @param string $url_path Local URL path.
	 * @param string $type Asset family, css or js.
	 * @return string|null Normalized local path, or null when disallowed.
	 */
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

	/**
	 * Checks a source extension against the configured asset-family allowlist.
	 *
	 * @param string $file_path Local source path.
	 * @param string $type Asset family, css or js.
	 * @return bool Whether this extension is supported.
	 */
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

	/**
	 * Detects an already-minified filename within the allowed asset family.
	 *
	 * @param string $file_path Local source path.
	 * @param string $type Asset family, css or js.
	 * @return bool Whether the file already uses a minified filename.
	 */
	protected static function is_minified_asset( $file_path, $type ) {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		return self::is_allowed_asset_extension( $file_path, $type )
			&& preg_match( '/\.min\.' . preg_quote( $extension, '/' ) . '$/i', $file_path );
	}

	/**
	 * Checks the resolved asset path against trusted WordPress filesystem roots.
	 *
	 * @param string $file_path Resolved local source path.
	 * @return bool Whether the path is within an allowed root.
	 */
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

	/**
	 * Sends immutable headers only for validated generated-asset requests.
	 */
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

	/**
	 * Validates the request method, hashed filename, and generated asset location.
	 *
	 * @return bool Whether this request targets an existing owned asset.
	 */
	protected static function is_generated_asset_request() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact GET comparison rejects every other value or type without coercion.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		if ( 'GET' !== $method || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate the unslashed path against the exact owned filename pattern and realpath boundary below.
		$path   = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		$prefix = wp_parse_url( HSPSC_URL . '/assets/', PHP_URL_PATH );
		if ( ! is_string( $path ) || ! $prefix || ! preg_match( '~^' . preg_quote( $prefix, '~' ) . '[a-f0-9]{32}\.min\.(?:css|js)$~D', $path ) ) {
			return false;
		}
		$target = HSPSC_PATH . '/assets/' . basename( $path );
		$root   = realpath( HSPSC_PATH . '/assets' );
		$real   = realpath( $target );
		return $root && $real && ! is_link( $target ) && is_file( $real )
			&& strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) === 0;
	}

	/**
	 * Removes safe HTML comments while preserving text-sensitive markup.
	 *
	 * @param string $html Complete HTML response.
	 * @return string Optimized HTML with sensitive regions preserved.
	 */
	public static function minify_html( $html ) {
		if ( preg_match( '/<(?:template|plaintext|xmp|listing)\b/i', $html ) ) {
			// Templates may nest and contain markup with significant whitespace.
			return $html;
		}
		// Protect text-sensitive elements before processing any inline markup.
		$protected = array();
		$prefix    = 'HSPSC' . wp_generate_password( 20, false, false );
		$html      = preg_replace_callback(
			'~<(pre|textarea|template|script|style)\b[^>]*>[\s\S]*?</\1\s*>~i',
			static function ( $matches ) use ( &$protected, $prefix ) {
				$key               = $prefix . count( $protected );
				$protected[ $key ] = $matches[0];
				return $key;
			},
			$html
		);

		// Scripts and styles are preserved here: their contents are not HTML.
		$html = preg_replace( '/<!--(?!\s*(?:\[if|\/?(?:wp:|ko\b)))[\s\S]*?-->/', '', $html );
		return strtr( trim( $html ), $protected );
	}

	/**
	 * Compacts simple CSS while retaining constructs that require a full parser.
	 *
	 * @param string $css Source stylesheet bytes.
	 * @return string Safely compacted CSS.
	 */
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

	/**
	 * Trims surrounding JavaScript whitespace without changing syntax.
	 *
	 * @param string $js Source JavaScript bytes.
	 * @return string Trimmed JavaScript.
	 */
	public static function minify_js( $js ) {
		// Without a JavaScript parser, comments cannot be distinguished reliably
		// from strings, template literals and regular expressions.
		return trim( $js );
	}

	/**
	 * Clears generated assets and request-local transformation state.
	 */
	public static function clear_cache() {
		HSPSC_Utils::delete_dir_contents( HSPSC_PATH . '/assets' );
		self::$asset_url_cache      = array();
		self::$original_script_urls = array();
		self::$original_style_urls  = array();
	}

	/**
	 * Throttles generated-asset garbage collection to once per day.
	 */
	protected static function maybe_cleanup_asset_cache() {
		if ( get_transient( 'hspsc_asset_cache_gc' ) ) {
			return;
		}

		set_transient( 'hspsc_asset_cache_gc', 1, DAY_IN_SECONDS );
		self::cleanup_old_assets();
	}

	/**
	 * Removes aged files from the generated-asset cache directory.
	 *
	 * @return int Number of files deleted.
	 */
	public static function cleanup_old_assets() {
		$directory = HSPSC_PATH . '/assets';
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			return 0;
		}
		$items = scandir( $directory );
		if ( false === $items ) {
			return 0;
		}
		$candidates = array();
		$cutoff     = time() - MONTH_IN_SECONDS;
		foreach ( $items as $item ) {
			$path = $directory . '/' . $item;
			if ( preg_match( '/\A[a-f0-9]{32}\.min\.(?:css|js)\z/', $item ) && ! is_link( $path ) && is_file( $path ) ) {
				$mtime = filemtime( $path );
				if ( false !== $mtime && $mtime < $cutoff ) {
					$candidates[ $item ] = $path;
				}
			}
		}
		if ( ! $candidates ) {
			return 0;
		}
		$references = self::get_fresh_page_asset_references( $candidates );
		if ( false === $references ) {
			return 0;
		}
		$deleted = 0;
		foreach ( $candidates as $name => $path ) {
			// Another request may have reused this asset while pages were scanned.
			clearstatcache( true, $path );
			$mtime = is_file( $path ) && ! is_link( $path ) ? filemtime( $path ) : false;
			if ( ! isset( $references[ $name ] ) && false !== $mtime && $mtime < $cutoff ) {
				wp_delete_file( $path );
				if ( ! file_exists( $path ) ) {
					++$deleted;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Records recent reuse without rewriting asset contents or touching every hit.
	 *
	 * @param string $path Validated generated asset path.
	 * @return bool Whether its usage timestamp was refreshed.
	 */
	protected static function refresh_asset_usage( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.PHP.NoSilencedErrors.Discouraged -- A checked generated file needs a cheap best-effort timestamp refresh; failure falls back to the original source.
		return @touch( $path );
	}

	/**
	 * Protects aged assets referenced by any still-fresh public or private page.
	 *
	 * This scan runs only during garbage collection, never on asset cache hits.
	 * Unreadable page storage prevents deletion rather than risking broken output.
	 *
	 * @param array $candidates Generated basenames mapped to their paths.
	 * @return array|false Referenced candidate names, or false on a read failure.
	 */
	protected static function get_fresh_page_asset_references( $candidates ) {
		$references = array();
		$ttl        = intval( HSPSC_Settings::get( 'page_cache_ttl', 3600 ) );
		$patterns   = array(
			HSPSC_PATH . '/pages'         => '/\A[a-f0-9]{32}-[a-f0-9]{32}\.html\z/',
			HSPSC_PATH . '/pages/private' => '/\A[a-f0-9]{32}-user-[a-f0-9]{32}-[a-f0-9]{32}\.php\z/',
		);
		foreach ( $patterns as $directory => $pattern ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}
			if ( is_link( $directory ) ) {
				return false;
			}
			$items = scandir( $directory );
			if ( false === $items ) {
				return false;
			}
			foreach ( $items as $item ) {
				$path = $directory . '/' . $item;
				if ( ! preg_match( $pattern, $item ) || ! is_file( $path ) || is_link( $path ) ) {
					continue;
				}
				$mtime = filemtime( $path );
				if ( false === $mtime ) {
					return false;
				}
				if ( time() - $mtime > $ttl ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Stream a validated owned page to keep garbage-collection memory bounded.
				$handle = fopen( $path, 'rb' );
				if ( false === $handle ) {
					return false;
				}
				$tail = '';
				while ( ! feof( $handle ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read the bounded stream opened above.
					$chunk = fread( $handle, 65536 );
					if ( false === $chunk ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned read stream before abandoning cleanup.
						fclose( $handle );
						return false;
					}
					$text = $tail . $chunk;
					preg_match_all( '/[a-f0-9]{32}\.min\.(?:css|js)/', $text, $matches );
					foreach ( $matches[0] as $name ) {
						if ( isset( $candidates[ $name ] ) ) {
							$references[ $name ] = true;
						}
					}
					$tail = substr( $text, -64 );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned bounded read stream.
				fclose( $handle );
			}
		}
		return $references;
	}
}
