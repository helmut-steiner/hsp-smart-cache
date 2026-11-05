<?php
/**
 * Optional Bricks publishing observers.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observes optional Bricks publishing and generated-CSS changes.
 */
class HSPSC_Bricks {
	/**
	 * Themes load after plugins_loaded, including Bricks' constants and APIs.
	 */
	public static function init() {
		add_action( 'after_setup_theme', array( __CLASS__, 'register_hooks' ), 20 );
		if ( did_action( 'after_setup_theme' ) ) {
			self::register_hooks();
		}
	}

	/**
	 * Registers Bricks invalidation observers only when Bricks is active.
	 */
	public static function register_hooks() {
		if ( ! self::is_active() ) {
			return;
		}

		add_action( 'bricks/generate_css_file', array( 'HSPSC_Plugin', 'flush_render_caches' ), 10, 0 );
		add_action( 'added_post_meta', array( __CLASS__, 'handle_post_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'handle_post_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'handle_post_meta_change' ), 10, 3 );
	}

	/**
	 * Detects the Bricks theme or its loaded APIs.
	 *
	 * @return bool Whether Bricks integration is available.
	 */
	protected static function is_active() {
		return defined( 'BRICKS_VERSION' ) || class_exists( 'Bricks\\Database', false ) || 'bricks' === get_template();
	}

	/**
	 * Recognizes reusable Bricks templates without assuming Bricks is installed.
	 *
	 * @param string $post_type Post type to inspect.
	 * @return bool Whether this is an active Bricks template type.
	 */
	public static function is_shared_post_type( $post_type ) {
		$template_type = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
		return $template_type === $post_type && self::is_active();
	}

	/**
	 * Observe persisted data for inline CSS and metadata-only Builder saves too.
	 *
	 * Content/settings keys are documented in the Bricks data-model schema.
	 * Editor-mode writes and save ordering were checked against Bricks 2.4.4.
	 *
	 * @param int|int[] $meta_id Added/updated metadata ID or deleted metadata IDs.
	 * @param int       $post_id Post whose metadata changed.
	 * @param string    $meta_key Changed metadata key.
	 */
	public static function handle_post_meta_change( $meta_id, $post_id, $meta_key ) {
		$keys    = array(
			'BRICKS_DB_PAGE_HEADER'       => '_bricks_page_header_2',
			'BRICKS_DB_PAGE_CONTENT'      => '_bricks_page_content_2',
			'BRICKS_DB_PAGE_FOOTER'       => '_bricks_page_footer_2',
			'BRICKS_DB_PAGE_SETTINGS'     => '_bricks_page_settings',
			'BRICKS_DB_TEMPLATE_SETTINGS' => '_bricks_template_settings',
			'BRICKS_DB_TEMPLATE_TYPE'     => '_bricks_template_type',
			'BRICKS_DB_EDITOR_MODE'       => '_bricks_editor_mode',
		);
		$matches = false;
		foreach ( $keys as $constant => $fallback ) {
			if ( ( defined( $constant ) ? constant( $constant ) : $fallback ) === $meta_key ) {
				$matches = true;
				break;
			}
		}

		if ( ! $matches || ! self::is_active() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( $post && 'auto-draft' !== $post->post_status ) {
			HSPSC_Plugin::flush_render_caches();
		}
	}
}
