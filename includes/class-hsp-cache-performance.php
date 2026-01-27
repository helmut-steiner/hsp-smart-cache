<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Smart_Cache_Performance {
    public static function init() {
        add_filter( 'wp_lazy_loading_enabled', array( __CLASS__, 'filter_lazy_loading' ), 10, 2 );
        add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_image_attributes' ), 10, 3 );
        add_filter( 'wp_resource_hints', array( __CLASS__, 'add_dns_prefetch_hints' ), 10, 2 );
        add_action( 'init', array( __CLASS__, 'maybe_disable_emojis' ), 1 );
        add_action( 'init', array( __CLASS__, 'maybe_disable_embeds' ), 1 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_disable_dashicons' ), 100 );
    }

    public static function filter_lazy_loading( $default, $tag_name ) {
        if ( is_admin() ) {
            return $default;
        }
        if ( $tag_name === 'img' ) {
            return HSP_Cache_Settings::get( 'perf_lazy_images', true );
        }
        if ( $tag_name === 'iframe' ) {
            return HSP_Cache_Settings::get( 'perf_lazy_iframes', true );
        }
        return $default;
    }

    public static function filter_image_attributes( $attr, $attachment, $size ) {
        if ( is_admin() ) {
            return $attr;
        }
        if ( HSP_Cache_Settings::get( 'perf_decoding_async', true ) && empty( $attr['decoding'] ) ) {
            $attr['decoding'] = 'async';
        }
        return $attr;
    }

    public static function add_dns_prefetch_hints( $hints, $relation_type ) {
        if ( $relation_type !== 'dns-prefetch' ) {
            return $hints;
        }
        $list = self::parse_list( HSP_Cache_Settings::get( 'perf_dns_prefetch_urls', '' ) );
        foreach ( $list as $url ) {
            $hints[] = $url;
        }
        return array_unique( $hints );
    }

    public static function maybe_disable_emojis() {
        if ( ! HSP_Cache_Settings::get( 'perf_disable_emojis' ) ) {
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

    public static function disable_emojis_tinymce( $plugins ) {
        if ( is_array( $plugins ) ) {
            return array_diff( $plugins, array( 'wpemoji' ) );
        }
        return array();
    }

    public static function disable_emojis_dns_prefetch( $urls, $relation_type ) {
        if ( $relation_type === 'dns-prefetch' ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            $emoji_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' );
            $urls = array_diff( $urls, array( $emoji_url ) );
        }
        return $urls;
    }

    public static function maybe_disable_embeds() {
        if ( ! HSP_Cache_Settings::get( 'perf_disable_embeds' ) ) {
            return;
        }
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );
        remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
        remove_action( 'rest_api_init', 'wp_oembed_register_route' );
        add_filter( 'embed_oembed_discover', '__return_false' );
    }

    public static function maybe_disable_dashicons() {
        if ( ! HSP_Cache_Settings::get( 'perf_disable_dashicons' ) ) {
            return;
        }
        if ( is_user_logged_in() ) {
            return;
        }
        wp_dequeue_style( 'dashicons' );
    }

    protected static function parse_list( $value ) {
        if ( empty( $value ) ) {
            return array();
        }
        $parts = preg_split( '/[\r\n,]+/', $value );
        $clean = array();
        foreach ( (array) $parts as $part ) {
            $part = trim( $part );
            if ( $part !== '' ) {
                $clean[] = $part;
            }
        }
        return array_unique( $clean );
    }
}
