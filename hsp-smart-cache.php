<?php
/**
 * Plugin Name: HSP Smart Cache
 * Description: Page caching, minification, CDN rewriting, and file-based object cache with settings UI.
 * Version: 0.5.1
 * Update URI: https://github.com/helmut-steiner/hsp-smart-cache
 * Author: Helmut Steiner
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: hsp-smart-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HSPSC_VERSION', '0.5.1' );
define( 'HSPSC_BASENAME', plugin_basename( __FILE__ ) );
define( 'HSPSC_PATH', WP_CONTENT_DIR . '/cache/hspsc' );
define( 'HSPSC_URL', content_url( '/cache/hspsc' ) );

require_once __DIR__ . '/includes/class-hspsc-settings.php';
require_once __DIR__ . '/includes/class-hspsc-utils.php';
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

class HSPSC_Plugin {
    public static function init() {
        HSPSC_Settings::init();
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
        add_action( 'deleted_post', array( __CLASS__, 'handle_post_delete' ) );
        add_action( 'edit_terms', array( __CLASS__, 'flush_all_caches' ) );
        add_action( 'edited_term', array( __CLASS__, 'flush_all_caches' ) );
        add_action( 'delete_term', array( __CLASS__, 'flush_all_caches' ) );
        add_action( 'comment_post', array( __CLASS__, 'handle_comment_change' ) );
        add_action( 'edit_comment', array( __CLASS__, 'handle_comment_change' ) );
        add_action( 'deleted_comment', array( __CLASS__, 'handle_comment_change' ) );
        add_action( 'switch_theme', array( __CLASS__, 'flush_all_caches' ) );
        add_action( 'customize_save_after', array( __CLASS__, 'flush_all_caches' ) );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'handle_upgrader_process_complete' ), 10, 2 );
        add_action( 'core_updated_successfully', array( __CLASS__, 'flush_all_caches' ) );
    }

    public static function activate() {
        HSPSC_Utils::ensure_cache_dirs();
        HSPSC_Settings::ensure_defaults();
        HSPSC_Object::sync_dropin();
    }

    public static function deactivate() {
        HSPSC_Object::remove_dropin();
    }

    public static function flush_all_caches() {
        HSPSC_Page::clear_cache();
        HSPSC_Minify::clear_cache();
        HSPSC_Object::flush_cache();
    }

    public static function handle_post_change( $post_id, $post, $update ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! $post || $post->post_status === 'auto-draft' ) {
            return;
        }
        HSPSC_Page::clear_cache_for_post( $post_id );
    }

    public static function handle_post_delete( $post_id ) {
        if ( ! $post_id ) {
            return;
        }
        HSPSC_Page::clear_cache_for_post( $post_id );
    }

    public static function handle_comment_change( $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( ! $comment || empty( $comment->comment_post_ID ) ) {
            return;
        }
        HSPSC_Page::clear_cache_for_post( (int) $comment->comment_post_ID );
    }

    public static function filter_robots_txt( $output, $public ) {
        return HSPSC_Utils::apply_robots_rules( $output, (bool) $public );
    }

    public static function handle_upgrader_process_complete( $upgrader, $hook_extra ) {
        if ( ! is_array( $hook_extra ) ) {
            return;
        }

        $action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';
        $type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';

        if ( $action !== 'update' ) {
            return;
        }

        if ( in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
            self::flush_all_caches();
        }
    }
}

register_activation_hook( __FILE__, array( 'HSPSC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HSPSC_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'HSPSC_Plugin', 'init' ) );
