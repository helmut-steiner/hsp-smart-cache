<?php
/**
 * Plugin Name: HSP Smart Cache
 * Description: Page caching, minification, CDN rewriting, and file-based object cache with settings UI.
 * Version: 0.1.1
 * Author: Helmut Steiner
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: hsp-smart-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HSP_CACHE_VERSION', '0.1.1' );
define( 'HSP_CACHE_PATH', WP_CONTENT_DIR . '/cache/hsp-cache' );
define( 'HSP_CACHE_URL', content_url( '/cache/hsp-cache' ) );

require_once __DIR__ . '/includes/class-hsp-cache-settings.php';
require_once __DIR__ . '/includes/class-hsp-cache-utils.php';
require_once __DIR__ . '/includes/class-hsp-cache-admin.php';
require_once __DIR__ . '/includes/class-hsp-cache-minify.php';
require_once __DIR__ . '/includes/class-hsp-cache-page.php';
require_once __DIR__ . '/includes/class-hsp-cache-cdn.php';
require_once __DIR__ . '/includes/class-hsp-cache-object.php';
require_once __DIR__ . '/includes/class-hsp-cache-tests.php';
require_once __DIR__ . '/includes/class-hsp-cache-static-assets.php';
require_once __DIR__ . '/includes/class-hsp-cache-render.php';
require_once __DIR__ . '/includes/class-hsp-cache-performance.php';
require_once __DIR__ . '/includes/class-hsp-cache-maintenance.php';
require_once __DIR__ . '/includes/class-hsp-cache-preload.php';

class HSP_Cache_Plugin {
    public static function init() {
        HSP_Cache_Settings::init();
        HSP_Cache_Admin::init();
        HSP_Cache_Minify::init();
        HSP_Cache_Page::init();
        HSP_Cache_CDN::init();
        HSP_Cache_Object::init();
        HSP_Cache_Static_Assets::init();
        HSP_Cache_Render::init();
        HSP_Cache_Performance::init();

        add_action( 'send_headers', array( 'HSP_Cache_Minify', 'maybe_send_asset_headers' ), 0 );

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
    }

    public static function activate() {
        HSP_Cache_Utils::ensure_cache_dirs();
        HSP_Cache_Settings::ensure_defaults();
        HSP_Cache_Object::sync_dropin();
    }

    public static function deactivate() {
        HSP_Cache_Object::remove_dropin();
    }

    public static function flush_all_caches() {
        HSP_Cache_Page::clear_cache();
        HSP_Cache_Minify::clear_cache();
        HSP_Cache_Object::flush_cache();
    }

    public static function handle_post_change( $post_id, $post, $update ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! $post || $post->post_status === 'auto-draft' ) {
            return;
        }
        HSP_Cache_Page::clear_cache_for_post( $post_id );
    }

    public static function handle_post_delete( $post_id ) {
        if ( ! $post_id ) {
            return;
        }
        HSP_Cache_Page::clear_cache_for_post( $post_id );
    }

    public static function handle_comment_change( $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( ! $comment || empty( $comment->comment_post_ID ) ) {
            return;
        }
        HSP_Cache_Page::clear_cache_for_post( (int) $comment->comment_post_ID );
    }
}

register_activation_hook( __FILE__, array( 'HSP_Cache_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HSP_Cache_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'HSP_Cache_Plugin', 'init' ) );
