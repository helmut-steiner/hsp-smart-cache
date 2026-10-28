<?php

// These API-shaped fixtures keep integration checks deterministic without installing a store.
if ( ! function_exists( 'wc_get_page_id' ) ) {
    function wc_get_page_id( $page ) {
        return (int) get_option( 'woocommerce_' . $page . '_page_id', -1 );
    }
}

if ( ! class_exists( '\\FluentCart\\Api\\StoreSettings' ) ) {
    class HSPSC_Commerce_Settings_Fixture {
        public function getPagesSettings() {
            return get_option( 'fluent_cart_store_settings', array() );
        }
    }
    class_alias( 'HSPSC_Commerce_Settings_Fixture', 'FluentCart\\Api\\StoreSettings' );
}

class HSPSC_Commerce_Test extends WP_UnitTestCase {
    private $server;
    private $cookies;
    private $query;
    private $pagenow;

    public function set_up(): void {
        parent::set_up();
        $this->server = $_SERVER;
        $this->cookies = $_COOKIE;
        $this->query = $_GET;
        $this->pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
        $_COOKIE = array();
        $_GET = array();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['QUERY_STRING'] = '';
        $GLOBALS['pagenow'] = 'index.php';
        wp_set_current_user( 0 );
        $this->go_to( home_url( '/' ) );
        HSPSC_Settings::reset_cache();
        HSPSC_Commerce::init();
        HSPSC_Utils::ensure_cache_dirs();
    }

    public function tear_down(): void {
        $_SERVER = $this->server;
        $_COOKIE = $this->cookies;
        $_GET = $this->query;
        $GLOBALS['pagenow'] = $this->pagenow;
        HSPSC_Utils::reset_request_cache();
        parent::tear_down();
    }

    public function test_two_guest_carts_bypass_the_same_public_url() {
        $this->assertTrue( HSPSC_Utils::is_request_cacheable() );
        $_COOKIE['fct_cart_hash'] = 'shopper-a';
        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
        $this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
        $_COOKIE['fct_cart_hash'] = 'shopper-b';
        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
        unset( $_COOKIE['fct_cart_hash'] );
        $this->assertTrue( HSPSC_Utils::is_request_cacheable() );
    }

    public function test_currency_cookie_bypasses_before_later_store_callbacks() {
        $_COOKIE['fct_currency'] = 'EUR';
        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
        $this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
    }

    public function test_dynamic_cookie_changes_are_not_memoized() {
        foreach ( array( 'woocommerce_cart_hash', 'wp_woocommerce_session_test', 'woocommerce_recently_viewed', 'PHPSESSID', 'edd_session_test', 'edd_cart_messages', 'edd_purchase', 'cart_discounts', 'comment_author_email_test' ) as $name ) {
            $_COOKIE = array();
            $this->assertTrue( HSPSC_Utils::is_request_cacheable() );
            $_COOKIE[ $name ] = 'private';
            $this->assertFalse( HSPSC_Utils::is_request_cacheable(), $name );
        }
    }

    public function test_personalized_query_urls_bypass_even_with_custom_slugs() {
        foreach ( array( 'fct_cart_hash=private', '%66ct_cart_hash=private', 'fluent-cart=order-review', 'action=fluent_cart_checkout_routes', 'wc-api=payment', 'key=order-secret', 'rest_route=%2Fcustom%2Fcart' ) as $query ) {
            $_SERVER['REQUEST_URI'] = '/bezahlung/?' . $query;
            $_SERVER['QUERY_STRING'] = $query;
            $this->assertFalse( HSPSC_Utils::is_request_cacheable(), $query );
        }
    }

    public function test_authorization_header_added_late_bypasses_the_cache() {
        $this->assertTrue( HSPSC_Utils::is_request_cacheable() );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer private-token';
        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
        $this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
    }

    public function test_changed_query_conditionals_are_not_memoized() {
        $this->assertTrue( HSPSC_Utils::is_request_cacheable() );
        $GLOBALS['wp_query']->is_search = true;
        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
        $GLOBALS['wp_query']->is_search = false;
    }

    public function test_configured_woocommerce_pages_are_private_with_localized_slugs() {
        foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
            $id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'privat-' . $page ) );
            update_option( 'woocommerce_' . $page . '_page_id', $id );
            $this->go_to( get_permalink( $id ) );
            $this->assertFalse( HSPSC_Utils::is_request_cacheable(), $page );
            $this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations(), $page );
        }
    }

    public function test_configured_fluentcart_private_pages_bypass_but_shop_remains_public() {
        $pages = array();
        foreach ( array( 'cart', 'checkout', 'receipt', 'customer_profile', 'login', 'registration', 'custom_payment', 'order_review', 'shop' ) as $page ) {
            $pages[ $page . '_page_id' ] = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'bereich-' . $page ) );
        }
        update_option( 'fluent_cart_store_settings', $pages );
        if ( is_callable( array( '\\FluentCart\\Api\\StoreSettings', 'clearCache' ) ) ) {
            \FluentCart\Api\StoreSettings::clearCache();
        }
        foreach ( $pages as $key => $id ) {
            $this->go_to( get_permalink( $id ) );
            $this->assertSame( $key === 'shop_page_id', HSPSC_Utils::is_request_cacheable(), $key );
        }
    }

    public function test_wpml_translated_woocommerce_private_pages_bypass_without_switching_language() {
        $translations = array();
        foreach ( array( 'cart', 'checkout', 'myaccount' ) as $role ) {
            $base = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'original-' . $role ) );
            $translated = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'uebersetzung-' . $role ) );
            update_option( 'woocommerce_' . $role . '_page_id', $base );
            $translations[ $base ] = $translated;
        }
        foreach ( $translations as $translated ) {
            $this->go_to( get_permalink( $translated ) );
            $this->assertTrue( HSPSC_Utils::is_request_cacheable() );
        }
        $lookups = array();
        $current_language = static function () {
            return 'de';
        };
        $translate = static function ( $id, $type, $fallback, $language ) use ( $translations, &$lookups ) {
            $lookups[] = array( $type, $fallback, $language );
            return isset( $translations[ $id ] ) ? $translations[ $id ] : $id;
        };
        $switches = did_action( 'wpml_switch_language' );
        add_filter( 'wpml_current_language', $current_language );
        add_filter( 'wpml_object_id', $translate, 0, 4 );
        try {
            foreach ( $translations as $base => $translated ) {
                $this->go_to( get_permalink( $translated ) );
                $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
                $this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
                $this->go_to( get_permalink( $base ) );
                $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
            }
            $this->assertNotEmpty( $lookups );
            foreach ( $lookups as $lookup ) {
                $this->assertSame( array( 'page', true, 'de' ), $lookup );
            }
            $this->assertSame( $switches, did_action( 'wpml_switch_language' ) );
        } finally {
            remove_filter( 'wpml_current_language', $current_language );
            remove_filter( 'wpml_object_id', $translate, 0 );
        }
    }

	/**
	 * WCML may translate page settings before HSP Smart Cache reads them.
	 */
	public function test_wpml_localized_cart_option_preserves_both_source_and_translated_pages() {
		$source     = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'source-private' ) );
		$translated = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'ziel-private' ) );
		$public     = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'public-catalog' ) );
		update_option( 'woocommerce_cart_page_id', $source );

		$localized_option = static function ( $id ) use ( $source, $translated ) {
			return (int) $id === $source ? $translated : $id;
		};
		$current_language = static function () {
			return 'de';
		};
		$default_language = static function () {
			return 'en';
		};
		$translate = static function ( $id, $type, $fallback, $language ) use ( $source, $translated ) {
			if ( 'page' === $type && in_array( (int) $id, array( $source, $translated ), true ) ) {
				return 'en' === $language ? $source : $translated;
			}
			return $fallback ? $id : null;
		};
		add_filter( 'option_woocommerce_cart_page_id', $localized_option );
		add_filter( 'wpml_current_language', $current_language );
		add_filter( 'wpml_default_language', $default_language );
		add_filter( 'wpml_object_id', $translate, 10, 4 );
		try {
			$this->assertSame( $translated, wc_get_page_id( 'cart' ) );
			foreach ( array( $source, $translated ) as $id ) {
				$this->go_to( get_permalink( $id ) );
				$this->assertFalse( HSPSC_Utils::is_request_cacheable(), (string) $id );
				$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations(), (string) $id );
			}
			$this->go_to( get_permalink( $public ) );
			$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
			$this->assertTrue( HSPSC_Utils::should_apply_frontend_optimizations() );
		} finally {
			remove_filter( 'option_woocommerce_cart_page_id', $localized_option );
			remove_filter( 'wpml_current_language', $current_language );
			remove_filter( 'wpml_default_language', $default_language );
			remove_filter( 'wpml_object_id', $translate, 10 );
		}
	}

    public function test_wpml_translated_fluentcart_private_pages_bypass_and_shop_remains_public() {
        $pages = array();
        $translations = array();
        foreach ( array( 'cart', 'checkout', 'receipt', 'customer_profile', 'login', 'registration', 'shop' ) as $role ) {
            $base = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'ursprung-' . $role ) );
            $translated = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'sprache-' . $role ) );
            $pages[ $role . '_page_id' ] = $base;
            $translations[ $base ] = $translated;
        }
        update_option( 'fluent_cart_store_settings', $pages );
        if ( is_callable( array( '\\FluentCart\\Api\\StoreSettings', 'clearCache' ) ) ) {
            \FluentCart\Api\StoreSettings::clearCache();
        }
        $current_language = static function () {
            return 'de';
        };
        $translate = static function ( $id, $type, $fallback, $language ) use ( $translations ) {
            if ( 'page' === $type && 'de' === $language && isset( $translations[ $id ] ) ) {
                return $translations[ $id ];
            }
            return $fallback ? $id : null;
        };
        add_filter( 'wpml_current_language', $current_language );
        add_filter( 'wpml_object_id', $translate, 10, 4 );
        try {
            foreach ( $pages as $key => $base ) {
                $this->go_to( get_permalink( $translations[ $base ] ) );
                $this->assertSame( 'shop_page_id' === $key, HSPSC_Utils::is_request_cacheable(), $key );
                $this->assertSame( 'shop_page_id' === $key, HSPSC_Utils::should_apply_frontend_optimizations(), $key );
            }
        } finally {
            remove_filter( 'wpml_current_language', $current_language );
            remove_filter( 'wpml_object_id', $translate, 10 );
        }
    }

    public function test_wpml_missing_private_page_translation_keeps_the_original_excluded() {
        $base = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'private-original' ) );
        update_option( 'woocommerce_checkout_page_id', $base );
        $translate = static function () {
            return null;
        };
        $current_language = static function () {
            return 'de';
        };
        add_filter( 'wpml_current_language', $current_language );
        add_filter( 'wpml_object_id', $translate );
        try {
            $this->go_to( get_permalink( $base ) );
            $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
            $this->go_to( home_url( '/' ) );
            $this->assertTrue( HSPSC_Utils::is_request_cacheable() );
        } finally {
            remove_filter( 'wpml_current_language', $current_language );
            remove_filter( 'wpml_object_id', $translate );
        }
    }

    public function test_embedded_cart_block_is_private_on_an_ordinary_page() {
        $id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:woocommerce/cart /-->' ) );
        $this->go_to( get_permalink( $id ) );
        $this->assertFalse( HSPSC_Utils::is_request_cacheable() );
    }

    public function test_stock_and_fluentcart_catalog_events_clear_pages_without_flushing_assets() {
        $page = HSPSC_PATH . '/pages/commerce-stock.html';
        $asset = HSPSC_PATH . '/assets/commerce-asset.min.js';
        file_put_contents( $asset, 'asset' );
        try {
            foreach ( array( 'woocommerce_updated_product_stock', 'woocommerce_update_product', 'fluent_cart/product_stock_changed', 'fluent_cart/product_updated', 'fluent_cart/product/variants_updated', 'fluent_cart/order_paid' ) as $hook ) {
                file_put_contents( $page, 'old stock' );
                do_action( $hook, array() );
                $this->assertFileDoesNotExist( $page, $hook );
                $this->assertFileExists( $asset );
            }
        } finally {
            wp_delete_file( $page );
            wp_delete_file( $asset );
        }
    }

    public function test_direct_product_price_meta_updates_invalidate_public_pages() {
        $registered = post_type_exists( 'product' );
        if ( ! $registered ) {
            register_post_type( 'product', array( 'public' => true ) );
        }
        $id = self::factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'publish' ) );
        $page = HSPSC_PATH . '/pages/commerce-price.html';
        file_put_contents( $page, 'old price' );
        try {
            update_post_meta( $id, '_price', '19.95' );
            $this->assertFileDoesNotExist( $page );
        } finally {
            wp_delete_file( $page );
            if ( ! $registered ) {
                unregister_post_type( 'product' );
            }
        }
    }
}
