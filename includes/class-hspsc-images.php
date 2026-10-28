<?php
/**
 * Page-cache invalidation for optional image representation settings.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks configuration changes that alter WebP Express HTML output.
 */
class HSPSC_Images {
	/**
	 * WebP Express's autoloaded HTML settings, independent of runtime state.
	 *
	 * @var string[]
	 */
	private const WEBP_HTML_OPTIONS = array(
		'webp-express-alter-html',
		'webp-express-alter-html-hooks',
		'webp-express-alter-html-replacement',
		'webp-express-alter-html-add-picturefill-js',
		'webp-express-alter-html-options',
	);

	/**
	 * Registers ordinary and network option invalidation without a plugin dependency.
	 *
	 * @return void
	 */
	public static function init() {
		$hooks = array(
			'added_option',
			'updated_option',
			'deleted_option',
			'add_site_option',
			'update_site_option',
			'delete_site_option',
		);
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'handle_option_change' ), 10, 1 );
		}
	}

	/**
	 * Invalidates all negotiated HTML variants after representation settings change.
	 *
	 * @param string $option Changed option name.
	 * @return void
	 */
	public static function handle_option_change( $option ) {
		if ( is_string( $option ) && in_array( $option, self::WEBP_HTML_OPTIONS, true ) ) {
			HSPSC_Page::clear_cache();
		}
	}
}
