<?php
/**
 * Registers, sanitizes, and memoizes plugin settings.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers, sanitizes, and memoizes plugin settings.
 */
class HSPSC_Settings {
	const OPTION_KEY = 'hspsc_settings';
	/**
	 * Resolved settings for this request, or null before loading.
	 *
	 * @var array|null
	 */
	protected static $options_cache = null;

	/**
	 * Registers settings hooks and invalidates request-local settings on updates.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'add_option_' . self::OPTION_KEY, array( __CLASS__, 'reset_cache' ), 10, 0 );
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'reset_cache' ), 10, 0 );
	}

	/**
	 * Returns the complete default configuration for all plugin modules.
	 *
	 * @return array Default setting values.
	 */
	public static function defaults() {
		return array(
			'page_cache'                => true,
			'page_cache_ttl'            => 3600,
			'page_cache_excluded_paths' => '',
			'cache_logged_in'           => false,
			'optimize_logged_in'        => false,
			'robots_disallow_ai'        => false,
			'browser_cache'             => true,
			'browser_cache_ttl'         => 600,
			'browser_cache_html_ttl'    => 600,
			'browser_cache_asset_ttl'   => 31536000,
			'static_asset_cache'        => true,
			'static_asset_ttl'          => 31536000,
			'static_asset_immutable'    => true,
			'static_asset_auto_write'   => false,
			'static_asset_compression'  => false,
			'render_defer_js'           => true,
			'render_async_js'           => false,
			'render_defer_exclusions'   => '',
			'render_async_exclusions'   => '',
			'render_preconnect_urls'    => '',
			'render_preload_fonts'      => '',
			'render_preload_css'        => '',
			'render_critical_css'       => '',
			'perf_lazy_images'          => true,
			'perf_lazy_iframes'         => true,
			'perf_decoding_async'       => true,
			'perf_disable_emojis'       => false,
			'perf_disable_embeds'       => false,
			'perf_disable_dashicons'    => false,
			'perf_dns_prefetch_urls'    => '',
			'preload_sitemap_url'       => '',
			'preload_timeout'           => 8,
			'minify_html'               => true,
			'minify_css'                => true,
			'minify_js'                 => true,
			'object_cache'              => false,
			'object_cache_default_ttl'  => 604800,
			'object_cache_max_ttl'      => 2592000,
			'cdn_enabled'               => false,
			'cdn_url'                   => '',
		);
	}

	/**
	 * Persists missing defaults without discarding existing configuration.
	 */
	public static function ensure_defaults() {
		$options = get_option( self::OPTION_KEY );
		if ( ! is_array( $options ) ) {
			update_option( self::OPTION_KEY, self::defaults() );
			self::$options_cache = self::defaults();
			return;
		}

		$merged = wp_parse_args( $options, self::defaults() );
		if ( $merged !== $options ) {
			update_option( self::OPTION_KEY, $merged );
			self::$options_cache = $merged;
		}
	}

	/**
	 * Returns a resolved setting or the supplied fallback for an unknown key.
	 *
	 * @param string $key Setting name.
	 * @param mixed  $default Fallback value for an unknown setting.
	 * @return mixed Resolved setting value.
	 */
	public static function get( $key, $default = null ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Keep the existing public callback signature and named-argument compatibility.
		$options = self::get_all();
		if ( array_key_exists( $key, $options ) ) {
			return $options[ $key ];
		}
		return $default;
	}

	/**
	 * Loads and memoizes stored settings merged with current defaults.
	 *
	 * @return array Complete resolved configuration.
	 */
	public static function get_all() {
		if ( null !== self::$options_cache ) {
			return self::$options_cache;
		}

		$options = get_option( self::OPTION_KEY, self::defaults() );
		if ( ! is_array( $options ) ) {
			$options = self::defaults();
		}

		self::$options_cache = wp_parse_args( $options, self::defaults() );
		return self::$options_cache;
	}

	/**
	 * Invalidates the request-local settings snapshot.
	 */
	public static function reset_cache() {
		self::$options_cache = null;
	}

	/**
	 * Registers the single plugin option and its sanitization callback.
	 */
	public static function register_settings() {
		register_setting( 'hspsc_settings_group', self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
	}

	/**
	 * Normalizes each setting according to its supported type and bounds.
	 * Critical CSS remains raw text; its style output boundary neutralizes closing tags.
	 *
	 * @param array $input Submitted plugin settings.
	 * @return array Sanitized configuration merged with defaults.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$output   = array();

		$output['page_cache']                = ! empty( $input['page_cache'] );
		$output['page_cache_excluded_paths'] = self::sanitize_page_cache_excluded_paths( $input['page_cache_excluded_paths'] ?? '' );
		$output['page_cache_ttl']            = isset( $input['page_cache_ttl'] ) ? max( 60, intval( $input['page_cache_ttl'] ) ) : $defaults['page_cache_ttl'];
		$output['cache_logged_in']           = ! empty( $input['cache_logged_in'] );
		$output['optimize_logged_in']        = ! empty( $input['optimize_logged_in'] );
		$output['robots_disallow_ai']        = ! empty( $input['robots_disallow_ai'] );
		$output['browser_cache']             = ! empty( $input['browser_cache'] );
		$legacy_ttl                          = isset( $input['browser_cache_ttl'] ) ? max( 60, intval( $input['browser_cache_ttl'] ) ) : $defaults['browser_cache_ttl'];
		$output['browser_cache_ttl']         = $legacy_ttl;
		$output['browser_cache_html_ttl']    = isset( $input['browser_cache_html_ttl'] ) ? max( 60, intval( $input['browser_cache_html_ttl'] ) ) : $legacy_ttl;
		$output['browser_cache_asset_ttl']   = isset( $input['browser_cache_asset_ttl'] ) ? max( 60, intval( $input['browser_cache_asset_ttl'] ) ) : $defaults['browser_cache_asset_ttl'];
		$output['static_asset_cache']        = ! empty( $input['static_asset_cache'] );
		$output['static_asset_ttl']          = isset( $input['static_asset_ttl'] ) ? max( 60, intval( $input['static_asset_ttl'] ) ) : $defaults['static_asset_ttl'];
		$output['static_asset_immutable']    = ! empty( $input['static_asset_immutable'] );
		$output['static_asset_auto_write']   = ! empty( $input['static_asset_auto_write'] );
		$output['static_asset_compression']  = ! empty( $input['static_asset_compression'] );
		$output['render_defer_js']           = ! empty( $input['render_defer_js'] );
		$output['render_async_js']           = ! empty( $input['render_async_js'] );
		$output['render_defer_exclusions']   = isset( $input['render_defer_exclusions'] ) ? sanitize_textarea_field( $input['render_defer_exclusions'] ) : '';
		$output['render_async_exclusions']   = isset( $input['render_async_exclusions'] ) ? sanitize_textarea_field( $input['render_async_exclusions'] ) : '';
		$output['render_preconnect_urls']    = isset( $input['render_preconnect_urls'] ) ? sanitize_textarea_field( $input['render_preconnect_urls'] ) : '';
		$output['render_preload_fonts']      = isset( $input['render_preload_fonts'] ) ? sanitize_textarea_field( $input['render_preload_fonts'] ) : '';
		$output['render_preload_css']        = isset( $input['render_preload_css'] ) ? sanitize_textarea_field( $input['render_preload_css'] ) : '';
		$output['render_critical_css']       = isset( $input['render_critical_css'] ) && is_string( $input['render_critical_css'] ) ? wp_check_invalid_utf8( $input['render_critical_css'], true ) : '';
		$output['perf_lazy_images']          = ! empty( $input['perf_lazy_images'] );
		$output['perf_lazy_iframes']         = ! empty( $input['perf_lazy_iframes'] );
		$output['perf_decoding_async']       = ! empty( $input['perf_decoding_async'] );
		$output['perf_disable_emojis']       = ! empty( $input['perf_disable_emojis'] );
		$output['perf_disable_embeds']       = ! empty( $input['perf_disable_embeds'] );
		$output['perf_disable_dashicons']    = ! empty( $input['perf_disable_dashicons'] );
		$output['perf_dns_prefetch_urls']    = isset( $input['perf_dns_prefetch_urls'] ) ? sanitize_textarea_field( $input['perf_dns_prefetch_urls'] ) : '';
		$output['preload_sitemap_url']       = isset( $input['preload_sitemap_url'] ) ? esc_url_raw( trim( $input['preload_sitemap_url'] ) ) : '';
		$output['preload_timeout']           = isset( $input['preload_timeout'] ) ? min( HSPSC_Rebuild::MAX_TIMEOUT, max( 3, intval( $input['preload_timeout'] ) ) ) : $defaults['preload_timeout'];
		$output['minify_html']               = ! empty( $input['minify_html'] );
		$output['minify_css']                = ! empty( $input['minify_css'] );
		$output['minify_js']                 = ! empty( $input['minify_js'] );
		$output['object_cache']              = ! empty( $input['object_cache'] );
		$output['object_cache_default_ttl']  = isset( $input['object_cache_default_ttl'] ) ? max( 0, intval( $input['object_cache_default_ttl'] ) ) : $defaults['object_cache_default_ttl'];
		$output['object_cache_max_ttl']      = isset( $input['object_cache_max_ttl'] ) ? max( 0, intval( $input['object_cache_max_ttl'] ) ) : $defaults['object_cache_max_ttl'];
		$output['cdn_enabled']               = ! empty( $input['cdn_enabled'] );
		$output['cdn_url']                   = isset( $input['cdn_url'] ) ? esc_url_raw( trim( $input['cdn_url'] ) ) : '';

		$sanitized           = wp_parse_args( $output, $defaults );
		self::$options_cache = $sanitized;

		return $sanitized;
	}

	/**
	 * Normalize exact URL paths without accepting hosts, queries, or wildcards.
	 *
	 * @param mixed $input One path per line.
	 * @return string Unique normalized paths.
	 */
	public static function sanitize_page_cache_excluded_paths( $input ) {
		if ( ! is_string( $input ) ) {
			return '';
		}

		$paths = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $input ) as $path ) {
			$path = HSPSC_Utils::normalize_page_cache_path( $path );
			if ( null !== $path ) {
				$paths[] = $path;
			}
		}
		return implode( "\n", array_unique( $paths ) );
	}
}
