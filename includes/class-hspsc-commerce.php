<?php
/**
 * Optional commerce request exclusions and catalog cache invalidation.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates optional storefront integrations without requiring a commerce plugin.
 */
class HSPSC_Commerce {
	/**
	 * Registers catalog invalidation callbacks for the available integrations.
	 *
	 * @return void
	 */
	public static function init() {
		if ( function_exists( 'wc_get_page_id' ) ) {
			$hooks = array(
				'woocommerce_updated_product_stock',
				'woocommerce_product_set_stock',
				'woocommerce_variation_set_stock',
				'woocommerce_product_set_stock_status',
				'woocommerce_variation_set_stock_status',
				'woocommerce_update_product',
				'woocommerce_update_product_variation',
				'woocommerce_scheduled_sales',
			);
			foreach ( $hooks as $hook ) {
				add_action( $hook, array( __CLASS__, 'clear_catalog_cache' ), 100, 0 );
			}
		}

		if ( class_exists( '\\FluentCart\\Api\\StoreSettings' ) || function_exists( 'fluentCart' ) ) {
			$hooks = array(
				'fluent_cart/product_stock_changed',
				'fluent_cart/product_updated',
				'fluent_cart/product/variants_updated',
				'fluent_cart/product_duplicated',
				'fluent_cart/order_created',
				'fluent_cart/order_paid',
				'fluent_cart/order_updated',
				'fluent_cart/order_status_changed',
				'fluent_cart/shipping_status_changed',
				'fluent_cart/order_refunded',
			);
			foreach ( $hooks as $hook ) {
				add_action( $hook, array( __CLASS__, 'clear_catalog_cache' ), 100, 0 );
			}
		}

		add_action( 'added_post_meta', array( __CLASS__, 'handle_product_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'handle_product_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'handle_product_meta_change' ), 10, 3 );
	}

	/**
	 * Clears page output after catalog or order data changes.
	 *
	 * Product listings and widgets can appear on any public page.
	 *
	 * @return void
	 */
	public static function clear_catalog_cache() {
		// Listings and embedded product widgets can occur on any public page.
		HSPSC_Page::clear_cache();
	}

	/**
	 * Invalidates catalog output when a product's stock or price metadata changes.
	 *
	 * @param int|int[] $meta_id  Metadata ID, or IDs when metadata is deleted.
	 * @param int       $post_id  Product or variation post ID.
	 * @param string    $meta_key Metadata key that changed.
	 * @return void
	 */
	public static function handle_product_meta_change( $meta_id, $post_id, $meta_key ) {
		$keys = array(
			'_stock',
			'_stock_status',
			'_price',
			'_regular_price',
			'_sale_price',
			'_sale_price_dates_from',
			'_sale_price_dates_to',
		);
		if (
			in_array( $meta_key, $keys, true )
			&& in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true )
		) {
			self::clear_catalog_cache();
		}
	}

	/**
	 * Determines whether the current commerce request requires dynamic output.
	 *
	 * An integration that cannot supply its private page settings fails closed.
	 *
	 * @return bool Whether page caching and frontend transformations must be skipped.
	 */
	public static function is_dynamic_request() {
		// WCML can choose a visitor's currency by location before any cookie exists.
		if ( self::has_dynamic_wcml_currency() ) {
			return true;
		}
		$conditionals = array(
			'is_cart',
			'is_checkout',
			'is_account_page',
			'is_wc_endpoint_url',
		);
		foreach ( $conditionals as $conditional ) {
			if ( function_exists( $conditional ) && call_user_func( $conditional ) ) {
				return true;
			}
		}

		$page_ids = self::get_private_page_ids();
		if ( null === $page_ids ) {
			return true;
		}
		if ( ! empty( $page_ids ) && is_page( $page_ids ) ) {
			return true;
		}

		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$shortcodes = array(
				'woocommerce_cart',
				'woocommerce_checkout',
				'woocommerce_my_account',
				'fluent_cart_checkout',
				'fluent_cart_receipt',
				'fluent_cart_order_review',
			);
			foreach ( $shortcodes as $shortcode ) {
				if ( has_shortcode( $post->post_content, $shortcode ) ) {
					return true;
				}
			}
			foreach ( array( 'woocommerce/cart', 'woocommerce/checkout' ) as $block ) {
				if ( has_block( $block, $post ) ) {
					return true;
				}
			}
		}

		/**
		 * Flags additional commerce requests that require private, dynamic output.
		 *
		 * Built-in private contexts return before this filter is applied.
		 *
		 * @param bool $is_dynamic Whether the request requires dynamic output.
		 */
		return (bool) apply_filters( 'hspsc_is_dynamic_commerce_request', false );
	}

	/**
	 * Determines whether WCML may personalize an uncookied visitor's currency.
	 *
	 * The currency API is installed when multicurrency initializes. If WCML is
	 * present without that API, only its explicit disabled setting proves safety.
	 *
	 * @return bool Whether currency handling requires dynamic output.
	 */
	private static function has_dynamic_wcml_currency() {
		if ( false !== has_filter( 'wcml_price_currency' ) ) {
			return true;
		}
		if ( ! defined( 'WCML_VERSION' ) ) {
			return false;
		}
		global $woocommerce_wpml;
		if ( ! is_object( $woocommerce_wpml ) || ! is_callable( array( $woocommerce_wpml, 'get_setting' ) ) ) {
			return true;
		}
		try {
			$setting = $woocommerce_wpml->get_setting( 'enable_multi_currency', null );
		} catch ( Throwable $error ) {
			return true;
		}
		return ! in_array( $setting, array( 0, '0' ), true );
	}

	/**
	 * Gets configured private pages from the available commerce integrations.
	 *
	 * @return int[]|null Unique positive page IDs, or null if integration settings
	 *                    cannot be read reliably.
	 */
	protected static function get_private_page_ids() {
		$ids = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$ids[] = wc_get_page_id( $page );
			}
		}

		if ( class_exists( '\\FluentCart\\Api\\StoreSettings' ) ) {
			try {
				$settings = new \FluentCart\Api\StoreSettings();
				if ( is_callable( array( $settings, 'getPagesSettings' ) ) ) {
					$pages = $settings->getPagesSettings();
					if ( ! is_array( $pages ) ) {
						return null;
					}
					$page_keys = array(
						'cart_page_id',
						'checkout_page_id',
						'receipt_page_id',
						'customer_profile_page_id',
						'login_page_id',
						'registration_page_id',
						'custom_payment_page_id',
						'order_review_page_id',
					);
					foreach ( $page_keys as $key ) {
						if ( isset( $pages[ $key ] ) && is_scalar( $pages[ $key ] ) ) {
							$ids[] = $pages[ $key ];
						}
					}
				} else {
					return null;
				}
			} catch ( Throwable $error ) {
				// An unavailable integration must not turn private pages into shared output.
				return null;
			}
		}

		/**
		 * Filters candidate commerce page IDs that must remain private.
		 *
		 * Candidates are normalized to unique positive integers after filtering.
		 *
		 * @param array $ids Candidate private commerce page IDs.
		 */
		$ids = (array) apply_filters( 'hspsc_private_commerce_page_ids', $ids );
		$ids = array_map( 'intval', array_filter( $ids, 'is_scalar' ) );
		$ids = array_values(
			array_unique(
				array_filter(
					$ids,
					static function ( $id ) {
						return 0 < $id;
					}
				)
			)
		);
		return self::include_wpml_private_page_translations( $ids );
	}

	/**
	 * Includes current and default language versions of configured private pages.
	 *
	 * Some integrations localize page settings before they are read. Mapping back
	 * to the default language keeps the source page private alongside that result.
	 * Missing translations preserve the supplied IDs. WPML owns the lookup.
	 *
	 * @param int[] $ids Normalized private page IDs.
	 * @return int[]|null Base and translated IDs, or null if WPML lookup fails.
	 */
	protected static function include_wpml_private_page_translations( $ids ) {
		if ( false === has_filter( 'wpml_object_id' ) || empty( $ids ) ) {
			return $ids;
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented language API.
			$language = apply_filters( 'wpml_current_language', null );
			if ( ! is_string( $language ) || '' === $language ) {
				$language = null;
			}
			$languages = array( $language );
			if ( false !== has_filter( 'wpml_default_language' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented default language API.
				$default_language = apply_filters( 'wpml_default_language', null );
				if ( is_string( $default_language ) && '' !== $default_language && ! in_array( $default_language, $languages, true ) ) {
					$languages[] = $default_language;
				}
			}
			foreach ( $ids as $id ) {
				foreach ( $languages as $target_language ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented page translation API.
					$translated_id = apply_filters( 'wpml_object_id', $id, 'page', true, $target_language );
					if ( is_scalar( $translated_id ) && 0 < (int) $translated_id ) {
						$ids[] = (int) $translated_id;
					}
				}
			}
		} catch ( Throwable $error ) {
			// A failed language lookup must not expose an unrecognized private page.
			return null;
		}

		return array_values( array_unique( $ids ) );
	}
}
