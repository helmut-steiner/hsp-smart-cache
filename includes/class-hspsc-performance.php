<?php
/**
 * Applies optional frontend loading and resource-hint preferences.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies optional frontend loading and resource-hint preferences.
 */
class HSPSC_Performance {
	/**
	 * Registers optional media, resource-hint, and frontend feature hooks.
	 */
	public static function init() {
		add_filter( 'wp_lazy_loading_enabled', array( __CLASS__, 'filter_lazy_loading' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_image_attributes' ), 10, 3 );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'add_dns_prefetch_hints' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_disable_emojis' ), 1 );
		add_action( 'init', array( __CLASS__, 'maybe_disable_embeds' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_disable_dashicons' ), 100 );
	}

	/**
	 * Applies configured image and iframe lazy-loading preferences.
	 *
	 * @param bool   $default Existing WordPress lazy-loading decision.
	 * @param string $tag_name HTML element name.
	 * @return bool Whether lazy loading is enabled for the element.
	 */
	public static function filter_lazy_loading( $default, $tag_name ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Keep the existing public callback signature and named-argument compatibility.
		if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
			return $default;
		}
		if ( 'img' === $tag_name ) {
			return HSPSC_Settings::get( 'perf_lazy_images', true );
		}
		if ( 'iframe' === $tag_name ) {
			return HSPSC_Settings::get( 'perf_lazy_iframes', true );
		}
		return $default;
	}

	/**
	 * Adds asynchronous decoding to eligible images without overriding an attribute.
	 *
	 * @param array        $attr Existing image attributes.
	 * @param WP_Post      $attachment Attachment object supplied by WordPress.
	 * @param string|int[] $size Requested image size supplied by WordPress.
	 * @return array Filtered image attributes.
	 */
	public static function filter_image_attributes( $attr, $attachment, $size ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Keep the existing public callback signature and named-argument compatibility.
		if ( ! HSPSC_Settings::get( 'perf_decoding_async', true ) || ! empty( $attr['decoding'] ) ) {
			return $attr;
		}
		if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
			return $attr;
		}
		if ( HSPSC_Settings::get( 'perf_decoding_async', true ) && empty( $attr['decoding'] ) ) {
			$attr['decoding'] = 'async';
		}
		return $attr;
	}

	/**
	 * Adds configured DNS-prefetch origins to the corresponding hint list.
	 *
	 * @param array  $hints Existing resource hints.
	 * @param string $relation_type Resource-hint relation.
	 * @return array Unique resource hints.
	 */
	public static function add_dns_prefetch_hints( $hints, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type ) {
			return $hints;
		}
		$list = self::parse_list( HSPSC_Settings::get( 'perf_dns_prefetch_urls', '' ) );
		foreach ( $list as $url ) {
			$hints[] = $url;
		}
		return array_unique( $hints );
	}

	/**
	 * Removes optional emoji support for eligible frontend requests.
	 */
	public static function maybe_disable_emojis() {
		if ( ! HSPSC_Settings::get( 'perf_disable_emojis' ) ) {
			return;
		}
		if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
			return;
		}

		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( __CLASS__, 'disable_emojis_tinymce' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'disable_emojis_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Removes the emoji editor plugin from the supplied plugin list.
	 *
	 * @param array $plugins Existing editor plugins.
	 * @return array Editor plugins without wpemoji.
	 */
	public static function disable_emojis_tinymce( $plugins ) {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpemoji' ) );
		}
		return array();
	}

	/**
	 * Removes the emoji origin from DNS-prefetch hints.
	 *
	 * @param array  $urls Existing resource hints.
	 * @param string $relation_type Resource-hint relation.
	 * @return array Filtered resource hints.
	 */
	public static function disable_emojis_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$emoji_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' );
			$urls      = array_diff( $urls, array( $emoji_url ) );
		}
		return $urls;
	}

	/**
	 * Removes optional oEmbed hooks for eligible frontend requests.
	 */
	public static function maybe_disable_embeds() {
		if ( ! HSPSC_Settings::get( 'perf_disable_embeds' ) ) {
			return;
		}
		if ( ! HSPSC_Utils::should_apply_frontend_optimizations() ) {
			return;
		}

		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
	}

	/**
	 * Removes Dashicons from guest-facing script queues when configured.
	 */
	public static function maybe_disable_dashicons() {
		if ( ! HSPSC_Settings::get( 'perf_disable_dashicons' ) ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}
		wp_dequeue_style( 'dashicons' );
		wp_deregister_style( 'dashicons' );

		global $wp_styles;
		if ( $wp_styles instanceof WP_Styles ) {
			$wp_styles->queue = array_values( array_diff( $wp_styles->queue, array( 'dashicons' ) ) );
			$wp_styles->to_do = array_values( array_diff( $wp_styles->to_do, array( 'dashicons' ) ) );
			$wp_styles->done  = array_values( array_diff( $wp_styles->done, array( 'dashicons' ) ) );
		}
	}

	/**
	 * Parses unique nonempty values from a comma- or newline-separated list.
	 *
	 * @param string $value Raw setting value.
	 * @return string[] Unique trimmed entries.
	 */
	protected static function parse_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		$parts = preg_split( '/[\r\n,]+/', $value );
		$clean = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$clean[] = $part;
			}
		}
		return array_unique( $clean );
	}
}
