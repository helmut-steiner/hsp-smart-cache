<?php
/**
 * Plugin Name: HSP Smart Cache
 * Description: Page caching, minification, CDN rewriting, and file-based object cache with settings UI.
 * Version: 0.9.1
 * Requires at least: 7.1.3
 * Requires PHP: 8.4
 * Update URI: https://github.com/helmut-steiner/hsp-smart-cache
 * Author: Helmut Steiner
 * License: HSP Personal Noncommercial Contribution License 1.0
 * License URI: https://github.com/helmut-steiner/hsp-smart-cache/blob/main/LICENSE
 * Text Domain: hsp-smart-cache
 * Domain Path: /languages
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HSPSC_VERSION', '0.9.1' );
define( 'HSPSC_BASENAME', plugin_basename( __FILE__ ) );
define( 'HSPSC_PATH', WP_CONTENT_DIR . '/cache/hspsc' );
define( 'HSPSC_URL', content_url( '/cache/hspsc' ) );

require_once __DIR__ . '/includes/class-hspsc-settings.php';
require_once __DIR__ . '/includes/class-hspsc-utils.php';
require_once __DIR__ . '/includes/class-hspsc-wpml.php';
require_once __DIR__ . '/includes/class-hspsc-commerce.php';
require_once __DIR__ . '/includes/class-hspsc-analytics.php';
require_once __DIR__ . '/includes/class-hspsc-seo.php';
require_once __DIR__ . '/includes/class-hspsc-images.php';
require_once __DIR__ . '/includes/class-hspsc-bricks.php';
require_once __DIR__ . '/includes/class-hspsc-admin.php';
require_once __DIR__ . '/includes/class-hspsc-minify.php';
require_once __DIR__ . '/includes/class-hspsc-page.php';
require_once __DIR__ . '/includes/class-hspsc-cdn.php';
require_once __DIR__ . '/includes/class-hspsc-object.php';
require_once __DIR__ . '/includes/class-hspsc-tests.php';
require_once __DIR__ . '/includes/class-hspsc-static-assets.php';
require_once __DIR__ . '/includes/class-hspsc-render.php';
require_once __DIR__ . '/includes/class-hspsc-performance.php';
require_once __DIR__ . '/includes/class-hspsc-maintenance.php';
require_once __DIR__ . '/includes/class-hspsc-preload.php';
require_once __DIR__ . '/includes/class-hspsc-updater.php';

/**
 * Bootstraps plugin modules and shared cache invalidation.
 */
class HSPSC_Plugin {
	const CACHE_SCHEMA = 5;

	/**
	 * Initializes modules and registers lifecycle invalidation hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		self::maybe_upgrade_cache_schema();
		HSPSC_Settings::init();
		HSPSC_WPML::init();
		HSPSC_Commerce::init();
		HSPSC_Analytics::init();
		HSPSC_SEO::init();
		HSPSC_Images::init();
		HSPSC_Bricks::init();
		HSPSC_Admin::init();
		HSPSC_Minify::init();
		HSPSC_Page::init();
		HSPSC_CDN::init();
		HSPSC_Object::init();
		HSPSC_Static_Assets::init();
		HSPSC_Render::init();
		HSPSC_Performance::init();
		HSPSC_Updater::init();

		add_action( 'send_headers', array( 'HSPSC_Minify', 'maybe_send_asset_headers' ), 0 );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 10, 2 );

		add_action( 'save_post', array( __CLASS__, 'handle_post_change' ), 10, 3 );
		// REST saves can persist additional fields after save_post has fired.
		add_action( 'wp_after_insert_post', array( __CLASS__, 'handle_post_change' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'handle_post_delete' ), 10, 2 );
		add_action( 'edit_terms', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'edited_term', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'delete_term', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'comment_post', array( __CLASS__, 'handle_comment_change' ) );
		add_action( 'edit_comment', array( __CLASS__, 'handle_comment_change' ) );
		add_action( 'deleted_comment', array( __CLASS__, 'handle_comment_change' ) );
		add_action( 'switch_theme', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'flush_render_caches' ), 10, 0 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush_render_caches' ), 10, 0 );
		add_action( 'customize_save_after', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'handle_upgrader_process_complete' ), 10, 2 );
		add_action( 'core_updated_successfully', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'updated_option', array( __CLASS__, 'handle_updated_option' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'handle_added_option' ), 10, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'handle_deleted_option' ), 10, 1 );
	}

	/**
	 * Registers bundled translations after WordPress initializes locale handling.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'hsp-smart-cache', false, dirname( HSPSC_BASENAME ) . '/languages' );
	}

	/**
	 * Purges incompatible render caches and refreshes opted-in server rules.
	 */
	public static function maybe_upgrade_cache_schema() {
		if ( (int) get_option( 'hspsc_cache_schema', 0 ) >= self::CACHE_SCHEMA ) {
			return;
		}

		self::flush_render_caches();
		// Refresh only installations that already opted into managed server rules.
		HSPSC_Static_Assets::apply_rules( null, HSPSC_Settings::get_all() );
		update_option( 'hspsc_cache_schema', self::CACHE_SCHEMA, false );
	}

	/**
	 * Creates cache directories, settings defaults, and the owned drop-in.
	 */
	public static function activate() {
		HSPSC_Utils::ensure_cache_dirs();
		HSPSC_Settings::ensure_defaults();
		HSPSC_Object::sync_dropin();
	}

	/**
	 * Removes the owned object-cache drop-in on deactivation.
	 */
	public static function deactivate() {
		HSPSC_Object::remove_dropin();
	}

	/**
	 * Clears page, generated-asset, and persistent object caches.
	 */
	public static function flush_all_caches() {
		HSPSC_Page::clear_cache();
		HSPSC_Minify::clear_cache();
		HSPSC_Object::flush_cache();
	}

	/**
	 * Clears page and generated-asset caches without flushing objects.
	 */
	public static function flush_render_caches() {
		HSPSC_Page::clear_cache();
		HSPSC_Minify::clear_cache();
	}

	/**
	 * Invalidates pages after public content or shared render data changes.
	 *
	 * @param int          $post_id Saved post ID.
	 * @param WP_Post|null $post Saved post object.
	 * @param bool         $update Whether the post already existed.
	 */
	public static function handle_post_change( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Keep the existing public callback signature and named-argument compatibility.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( self::is_shared_render_post_type( $post->post_type ) ) {
			self::flush_render_caches();
			return;
		}
		HSPSC_Page::clear_cache_for_post( $post_id );
	}

	/**
	 * Invalidates consumers of deleted content using its original post object.
	 *
	 * @param int          $post_id Deleted post ID.
	 * @param WP_Post|null $post Original post object, when supplied by WordPress.
	 */
	public static function handle_post_delete( $post_id, $post = null ) {
		if ( ! $post_id ) {
			return;
		}
		// deleted_post supplies the original object after its database row is gone.
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( $post && self::is_shared_render_post_type( $post->post_type ) ) {
			self::flush_render_caches();
			return;
		}
		HSPSC_Page::clear_cache_for_post( $post_id );
	}

	/**
	 * Identifies content types whose output can be consumed by other pages.
	 *
	 * @param string $post_type Registered post type.
	 * @return bool Whether changes require render-wide invalidation.
	 */
	protected static function is_shared_render_post_type( $post_type ) {
		return in_array( $post_type, array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_global_styles', 'wp_navigation', 'custom_css' ), true )
			|| HSPSC_Bricks::is_shared_post_type( $post_type );
	}

	/**
	 * Invalidates pages affected by a changed comment.
	 *
	 * @param int $comment_id Changed comment ID.
	 */
	public static function handle_comment_change( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment || empty( $comment->comment_post_ID ) ) {
			return;
		}
		HSPSC_Page::clear_cache_for_post( (int) $comment->comment_post_ID );
	}

	/**
	 * Applies the configured crawler policy to generated robots.txt output.
	 *
	 * @param string   $output Existing robots.txt contents.
	 * @param bool|int $public Whether the site is publicly visible.
	 * @return string Filtered robots.txt contents.
	 */
	public static function filter_robots_txt( $output, $public ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.publicFound -- Keep the existing public callback signature and named-argument compatibility.
		return HSPSC_Utils::apply_robots_rules( $output, (bool) $public );
	}

	/**
	 * Flushes caches after completed plugin, theme, or core updates.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance supplied by the hook.
	 * @param array       $hook_extra Update operation metadata.
	 */
	public static function handle_upgrader_process_complete( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return;
		}

		$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';
		$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';

		if ( 'update' !== $action ) {
			return;
		}

		if ( in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			self::flush_all_caches();
		}
	}

	/**
	 * Invalidates render caches when an output-affecting option changes.
	 *
	 * @param string $option Updated option name.
	 * @param mixed  $old_value Previous value supplied by the hook.
	 * @param mixed  $value New value supplied by the hook.
	 */
	public static function handle_updated_option( $option, $old_value, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Keep the existing public callback signature and named-argument compatibility.
		if ( self::is_render_affecting_option( $option ) ) {
			self::flush_render_caches();
		}
	}

	/**
	 * Invalidates render caches when an output-affecting option is added.
	 *
	 * @param string $option Added option name.
	 * @param mixed  $value Value supplied by the hook.
	 */
	public static function handle_added_option( $option, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Keep the existing public callback signature and named-argument compatibility.
		if ( self::is_render_affecting_option( $option ) ) {
			self::flush_render_caches();
		}
	}

	/**
	 * Invalidates render caches when an output-affecting option is deleted.
	 *
	 * @param string $option Deleted option name.
	 */
	public static function handle_deleted_option( $option ) {
		if ( self::is_render_affecting_option( $option ) ) {
			self::flush_render_caches();
		}
	}

	/**
	 * Recognizes commerce and builder options that can change rendered output.
	 *
	 * @param string $option Option name to inspect.
	 * @return bool Whether the option affects render caches.
	 */
	protected static function is_render_affecting_option( $option ) {
		$option = (string) $option;
		if ( '' === $option || HSPSC_Settings::OPTION_KEY === $option ) {
			return false;
		}

		if ( in_array( $option, array( 'fluent_cart_store_settings', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_currency', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop' ), true ) ) {
			return true;
		}

		$prefixes = array(
			'bricks_',
			'brx_',
			'_bricks_',
		);

		foreach ( $prefixes as $prefix ) {
			if ( strpos( $option, $prefix ) === 0 ) {
				return (bool) apply_filters( 'hspsc_is_render_affecting_option', true, $option );
			}
		}

		return (bool) apply_filters( 'hspsc_is_render_affecting_option', false, $option );
	}
}

register_activation_hook( __FILE__, array( 'HSPSC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HSPSC_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'HSPSC_Plugin', 'init' ) );
