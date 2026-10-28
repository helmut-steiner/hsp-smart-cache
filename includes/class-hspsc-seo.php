<?php
/**
 * Optional Slim SEO and Slim SEO Pro page-cache invalidation.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invalidates published output after SEO settings or source data changes.
 *
 * This integration does not require either SEO plugin to be installed. Exact
 * option names, metadata keys and mutation routes exclude scanner statistics,
 * link-status caches and analytics credentials from page-cache invalidation.
 */
class HSPSC_SEO {
	/**
	 * Options that change metadata, schema, links or redirect behavior.
	 *
	 * @var string[]
	 */
	private const OUTPUT_OPTIONS = array(
		'slim_seo',
		'ss_redirects',
		'slim_seo_pro',
		'slim_seo_schemas',
		'slim_seo_link_manager_settings',
		'ssp_auto_link_settings',
		'ssp_entities_settings',
	);

	/**
	 * Post metadata consumed by the free and premium output renderers.
	 *
	 * @var string[]
	 */
	private const OUTPUT_POST_META = array(
		'slim_seo',
		'slim_seo_schema',
		'sss_allow_global',
		'slim_seo_pro',
		'slim_seo_pro_auto_link',
		'slim_seo_pro_entities',
	);

	/**
	 * Premium mutations stored in custom tables without WordPress metadata events.
	 *
	 * @var string[]
	 */
	private const OUTPUT_REST_ROUTES = array(
		'/slim-seo-pro/auto_link/update_keyword',
		'/slim-seo-pro/auto_link/delete_keywords',
		'/slim-seo-pro/entities/update',
		'/slim-seo-pro/entities/delete',
	);

	/**
	 * Registers mutations without loading or configuring a third-party plugin.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'handle_option_change' ), 10, 1 );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'handle_post_meta_change' ), 10, 3 );
		}
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'handle_term_meta_change' ), 10, 3 );
		}
		foreach ( array( 'slim_seo_link_manager_update_link_url', 'slim_seo_link_manager_unlink' ) as $hook ) {
			// Builder integrations update their source data during the same action.
			add_action( $hook, array( __CLASS__, 'clear_pages' ), PHP_INT_MAX, 0 );
		}
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'handle_rest_mutation' ), 10, 3 );
	}

	/**
	 * Clears published output after an output-affecting option was changed.
	 *
	 * @param string $option Changed option name.
	 * @return void
	 */
	public static function handle_option_change( $option ) {
		if ( in_array( $option, self::OUTPUT_OPTIONS, true ) ) {
			self::clear_pages();
		}
	}

	/**
	 * Invalidates SEO values changed independently of a post save.
	 *
	 * @param int|int[] $meta_id   Metadata ID, or IDs when deleting metadata.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Changed metadata key.
	 * @return void
	 */
	public static function handle_post_meta_change( $meta_id, $object_id, $meta_key ) {
		$primary_term = is_string( $meta_key )
			&& 0 === strpos( $meta_key, '_slim_seo_primary_term_' )
			&& strlen( $meta_key ) > strlen( '_slim_seo_primary_term_' );
		if ( $primary_term || in_array( $meta_key, self::OUTPUT_POST_META, true ) ) {
			self::clear_pages();
		}
	}

	/**
	 * Invalidates term SEO edits made through the editor, REST or abilities APIs.
	 *
	 * @param int|int[] $meta_id   Metadata ID, or IDs when deleting metadata.
	 * @param int       $object_id Term ID.
	 * @param string    $meta_key  Changed metadata key.
	 * @return void
	 */
	public static function handle_term_meta_change( $meta_id, $object_id, $meta_key ) {
		if ( 'slim_seo' === $meta_key ) {
			self::clear_pages();
		}
	}

	/**
	 * Invalidates successful custom-table edits without changing their response.
	 *
	 * @param mixed           $response Original REST callback response.
	 * @param array           $handler  Matched REST handler.
	 * @param WP_REST_Request $request  Current REST request.
	 * @return mixed The original response, including errors and failure values.
	 */
	public static function handle_rest_mutation( $response, $handler, $request ) {
		if ( ! ( $request instanceof WP_REST_Request ) || is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true )
			|| ! in_array( untrailingslashit( $request->get_route() ), self::OUTPUT_REST_ROUTES, true ) ) {
			return $response;
		}
		$result = rest_ensure_response( $response );
		if ( is_wp_error( $result ) || $result->get_status() < 200 || $result->get_status() >= 300 || ! $result->get_data() ) {
			return $response;
		}
		self::clear_pages();
		return $response;
	}

	/**
	 * Clears page output while preserving generated assets and object-cache data.
	 *
	 * SEO output and link changes can affect archives and embedded site content.
	 *
	 * @return void
	 */
	public static function clear_pages() {
		HSPSC_Page::clear_cache();
	}
}
