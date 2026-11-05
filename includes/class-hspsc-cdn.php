<?php
/**
 * Rewrites eligible public asset URLs to the configured CDN.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites eligible public asset URLs to the configured CDN.
 */
class HSPSC_CDN {
	/**
	 * Resolved CDN configurations indexed by their settings signature.
	 *
	 * @var array
	 */
	protected static $config_cache = array();

	/**
	 * Registers CDN filters after asset minification.
	 */
	public static function init() {
		add_filter( 'style_loader_src', array( __CLASS__, 'rewrite_url' ), 30 );
		add_filter( 'script_loader_src', array( __CLASS__, 'rewrite_script_url' ), 30, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'rewrite_url' ), 30 );
	}

	/**
	 * Preserves integration script URLs before applying optional CDN rewriting.
	 *
	 * @param string $url    Script source URL.
	 * @param string $handle Registered script handle.
	 * @return string Original or rewritten URL.
	 */
	public static function rewrite_script_url( $url, $handle ) {
		if ( ! HSPSC_Settings::get( 'cdn_enabled' ) || ! HSPSC_Settings::get( 'cdn_url' ) ) {
			return $url;
		}
		if ( HSPSC_Minify::is_script_excluded( $handle, $url ) ) {
			return $url;
		}
		return self::rewrite_url( $url );
	}

	/**
	 * Rewrites an eligible local static URL while preserving private requests.
	 *
	 * @param string $url Original asset URL.
	 * @return string Original or CDN URL.
	 */
	public static function rewrite_url( $url ) {
		if ( ! HSPSC_Settings::get( 'cdn_enabled' ) || ! HSPSC_Settings::get( 'cdn_url' ) || ! HSPSC_Static_Assets::is_cacheable_asset_url( $url ) ) {
			return $url;
		}
		if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
			return $url;
		}

		$config = self::get_config();

		if ( ! $config['enabled'] ) {
			return $url;
		}

		if ( empty( $config['cdn_url'] ) ) {
			return $url;
		}

		if ( ! HSPSC_Static_Assets::is_cacheable_asset_url( $url ) ) {
			return $url;
		}

		if ( strpos( $url, $config['content_url'] ) === 0 ) {
			return $config['cdn_url'] . substr( $url, strlen( $config['content_url'] ) );
		}

		if ( strpos( $url, $config['site_url'] ) === 0 ) {
			return $config['cdn_url'] . substr( $url, strlen( $config['site_url'] ) );
		}

		return $url;
	}

	/**
	 * Memoizes CDN configuration by settings and current site origins.
	 *
	 * @return array Resolved CDN enablement and origin URLs.
	 */
	protected static function get_config() {
		$settings  = HSPSC_Settings::get_all();
		$signature = md5(
			wp_json_encode(
				array(
					! empty( $settings['cdn_enabled'] ),
					isset( $settings['cdn_url'] ) ? $settings['cdn_url'] : '',
					site_url(),
					content_url(),
				)
			)
		);

		if ( isset( self::$config_cache[ $signature ] ) ) {
			return self::$config_cache[ $signature ];
		}

		self::$config_cache[ $signature ] = array(
			'enabled'     => ! empty( $settings['cdn_enabled'] ),
			'cdn_url'     => isset( $settings['cdn_url'] ) ? rtrim( $settings['cdn_url'], '/' ) : '',
			'site_url'    => site_url(),
			'content_url' => content_url(),
		);

		return self::$config_cache[ $signature ];
	}
}
