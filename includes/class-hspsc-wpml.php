<?php
/**
 * Optional WPML language isolation and translated page invalidation.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves public language contexts without changing WPML's active language.
 *
 * WPML's browser language selection runs in JavaScript. Its preference cookies
 * must not become visitor-specific cache keys; the URL and display language
 * establish the context. Object-cache groups remain under WordPress ownership.
 */
class HSPSC_WPML {
	/**
	 * Whether translated output needs one page purge at the end of the request.
	 *
	 * @var bool
	 */
	private static $purge_queued = false;

	/**
	 * Registers optional integration callbacks, including delayed WPML loading.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wpml_loaded', array( __CLASS__, 'register_hooks' ) );
		self::register_hooks();
	}

	/**
	 * Detects WPML without interpreting a single compatibility filter as activation.
	 *
	 * @return bool Whether WPML or its complete public language API is available.
	 */
	private static function is_active() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress', false ) ) {
			return true;
		}
		foreach ( array( 'wpml_current_language', 'wpml_default_language', 'wpml_active_languages', 'wpml_permalink' ) as $hook ) {
			if ( false === has_filter( $hook ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Registers documented translation events and WPML settings invalidation.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( ! self::is_active() ) {
			return;
		}
		// The broad string_updated notification also fires for unchanged frontend refreshes.
		$hooks = array(
			'wpml_translation_job_saved',
			'wpml_pro_translation_completed',
			'wpml_update_active_languages',
			'wpml_st_add_string_translation',
			'wpml_st_before_remove_strings',
			'wpml_st_string_unregistered',
			'wpml_st_language_of_strings_changed',
			'wpml_st_update_string',
			'wpml_set_element_language_details',
			'wpml_delete_package',
			'wpml_delete_package_action',
			'wcml_update_extra_fields',
		);
		foreach ( array( 'icl_sitepress_settings', 'icl_st_settings' ) as $option ) {
			$hooks[] = 'add_option_' . $option;
			$hooks[] = 'update_option_' . $option;
			$hooks[] = 'delete_option_' . $option;
		}
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'queue_page_purge' ), PHP_INT_MAX, 0 );
		}
	}

	/**
	 * Coalesces repeated string or translation changes into a single page purge.
	 *
	 * @return void
	 */
	public static function queue_page_purge() {
		if ( self::$purge_queued ) {
			return;
		}
		self::$purge_queued = true;
		add_action( 'shutdown', array( __CLASS__, 'purge_queued_pages' ), PHP_INT_MAX );
	}

	/**
	 * Purges translated page output after rendering, preserving assets and objects.
	 *
	 * @return void
	 */
	public static function purge_queued_pages() {
		if ( ! self::$purge_queued ) {
			return;
		}
		self::$purge_queued = false;
		remove_action( 'shutdown', array( __CLASS__, 'purge_queued_pages' ), PHP_INT_MAX );
		HSPSC_Page::clear_cache();
	}

	/**
	 * Resolves the live request without memoizing cookies or the display language.
	 *
	 * @return string|null Empty when inactive, language code when safe, null to bypass.
	 */
	public static function request_context() {
		if ( ! self::is_active() ) {
			return '';
		}
		$current = self::language_code( apply_filters( 'wpml_current_language', null ) );
		if ( null === $current ) {
			return null;
		}
		// Raw routing bytes are validated as a URL below, rather than rewritten.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL identity must match the actual request.
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL identity must match the actual request.
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $host || false !== strpbrk( $host, '/?#' ) || '' === $uri || '/' !== substr( $uri, 0, 1 ) || '//' === substr( $uri, 0, 2 ) || false !== strpos( $uri, '#' ) ) {
			return null;
		}
		$catalog = self::language_catalog();
		if ( null === $catalog ) {
			return null;
		}
		$context = self::resolve_url_context( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri, $catalog );
		if ( $current !== $context ) {
			return null;
		}
		foreach ( $_COOKIE as $name => $value ) {
			$is_current    = in_array( $name, array( 'wp-wpml_current_language', '_icl_current_language' ), true );
			$is_preference = 1 === preg_match( '/^_icl_visitor_(?:chosen_lang|lang_js)(?:_[0-9]+)?$/D', (string) $name );
			if ( ! $is_current && ! $is_preference ) {
				continue;
			}
			$code = self::language_code( is_string( $value ) ? wp_unslash( $value ) : null );
			if ( null === $code || ! isset( $catalog['roots'][ $code ] ) ) {
				return null;
			}
			if ( $is_current && $current !== $code ) {
				return null;
			}
		}
		return $current;
	}

	/**
	 * Resolves an explicit URL for invalidation or warming without language switches.
	 *
	 * Unknown language roots before WordPress's wp action deliberately return null,
	 * allowing the caller to invalidate all pages instead of guessing a language.
	 *
	 * @param string $url Absolute public URL.
	 * @return string|null Empty when inactive, language code when safe, null if unknown.
	 */
	public static function url_context( $url ) {
		if ( ! self::is_active() ) {
			return '';
		}
		return self::resolve_url_context( $url, self::language_catalog() );
	}

	/**
	 * Resolves a URL against a catalog already read for this eligibility check.
	 *
	 * Catalogs are not retained across checks: language APIs can change while a
	 * response is rendered, just as cookies and privacy flags can change.
	 *
	 * @param string     $url     Absolute public URL.
	 * @param array|null $catalog Fresh language roots and completeness metadata.
	 * @return string|null Language code when safe, null if unknown.
	 */
	private static function resolve_url_context( $url, $catalog ) {
		$parts = self::url_parts( $url );
		if ( null === $catalog || null === $parts ) {
			return null;
		}
		$matches = array();
		$length  = -1;
		foreach ( $catalog['roots'] as $code => $root ) {
			if ( $parts['origin'] !== $root['origin'] || ! self::path_matches( $parts['path'], $root['path'] ) ) {
				continue;
			}
			$root_length = strlen( $root['path'] );
			if ( $root_length > $length ) {
				$matches = array();
				$length  = $root_length;
			}
			if ( $root_length === $length ) {
				$matches[ $code ] = $root;
			}
		}
		$contexts = array();
		foreach ( $matches as $code => $root ) {
			if ( null !== $parts['language'] ) {
				$expected = null !== $root['language'] ? $root['language'] : $code;
				if ( $expected !== $parts['language'] ) {
					continue;
				}
			} elseif ( null !== $root['language'] && $code !== $catalog['default'] ) {
				continue;
			}
			if ( ! $catalog['complete'] && ! $root['explicit'] && untrailingslashit( $parts['path'] ) !== untrailingslashit( $root['path'] ) && null === $parts['language'] ) {
				continue;
			}
			$contexts[] = $code;
		}
		return 1 === count( $contexts ) ? $contexts[0] : null;
	}

	/**
	 * Returns roots converted by WPML's public permalink API for trusted warming.
	 *
	 * @return array<string,string> Language codes mapped to absolute root URLs.
	 */
	public static function language_urls() {
		$catalog = self::is_active() ? self::language_catalog() : null;
		$urls    = array();
		if ( null !== $catalog ) {
			foreach ( $catalog['roots'] as $code => $root ) {
				$urls[ $code ] = $root['url'];
			}
		}
		return $urls;
	}

	/**
	 * Reads enabled roots only when the documented language APIs can resolve them.
	 *
	 * @return array|null Validated roots, default language, and completeness flag.
	 */
	private static function language_catalog() {
		foreach ( array( 'wpml_current_language', 'wpml_default_language', 'wpml_active_languages', 'wpml_permalink' ) as $hook ) {
			if ( false === has_filter( $hook ) ) {
				return null;
			}
		}
		$default = self::language_code( apply_filters( 'wpml_default_language', null ) );
		$base    = trailingslashit( (string) get_option( 'home' ) );
		$parsed  = self::url_parts( $base );
		if ( null === $default || null === $parsed ) {
			return null;
		}
		$complete = did_action( 'wp' ) && ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI );
		$codes    = array( $default );
		if ( $complete ) {
			if ( false === has_filter( 'wpml_active_languages' ) ) {
				return null;
			}
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( ! is_array( $languages ) || empty( $languages ) ) {
				return null;
			}
			$codes = array();
			foreach ( array_keys( $languages ) as $code ) {
				$valid = self::language_code( $code );
				if ( null === $valid ) {
					return null;
				}
				$codes[] = $valid;
			}
			if ( ! in_array( $default, $codes, true ) ) {
				return null;
			}
		} else {
			$current = self::language_code( apply_filters( 'wpml_current_language', null ) );
			if ( null !== $current ) {
				$codes[] = $current;
			}
		}
		$roots = array();
		foreach ( array_unique( $codes ) as $code ) {
			$url  = apply_filters( 'wpml_permalink', $base, $code, false );
			$root = self::url_parts( $url );
			if ( null === $root ) {
				return null;
			}
			$root['url']      = $url;
			$root['explicit'] = $root['origin'] !== $parsed['origin'] || $root['path'] !== $parsed['path'] || null !== $root['language'];
			$roots[ $code ]   = $root;
		}
		return array(
			'roots'    => $roots,
			'default'  => $default,
			'complete' => (bool) $complete,
		);
	}

	/**
	 * Validates public language identifiers without changing their URL spelling.
	 *
	 * @param mixed $code Candidate language code.
	 * @return string|null Normalized code or null for unsupported input.
	 */
	private static function language_code( $code ) {
		if ( ! is_string( $code ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,31}$/iD', $code ) || 'all' === strtolower( $code ) ) {
			return null;
		}
		return strtolower( $code );
	}

	/**
	 * Validates an absolute HTTP URL and retains its language query argument.
	 *
	 * @param mixed $url Candidate URL.
	 * @return array|null Origin, path and optional language token, or null.
	 */
	private static function url_parts( $url ) {
		if ( ! is_string( $url ) || '' === $url || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( $port < 1 || $port > 65535 ) {
			return null;
		}
		$query = array();
		wp_parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		$language = null;
		if ( array_key_exists( 'lang', $query ) ) {
			$language = self::language_code( $query['lang'] );
			if ( null === $language ) {
				return null;
			}
		}
		return array(
			'origin'   => $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port,
			'path'     => isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/',
			'language' => $language,
		);
	}

	/**
	 * Matches a language root at a path boundary rather than a textual prefix.
	 *
	 * @param string $path Request path.
	 * @param string $root Configured language root path.
	 * @return bool Whether the request belongs below the root.
	 */
	private static function path_matches( $path, $root ) {
		return untrailingslashit( $path ) === untrailingslashit( $root ) || 0 === strpos( $path, trailingslashit( $root ) );
	}
}
