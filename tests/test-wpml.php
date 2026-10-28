<?php
/**
 * Regression coverage for optional WPML language contexts.
 *
 * @package HSPSC
 */

/**
 * Exercises WPML's public filter contract without installing a language plugin.
 */
class HSPSC_WPML_Test extends WP_UnitTestCase {
	private $server;
	private $cookies;
	private $query;
	private $actions;
	private $buffer_level;
	private $current = 'en';
	private $mode = 'directories';
	private $active_calls = 0;
	private $permalink_calls = 0;
	private $broken = false;
	private $alias = false;
	private $home;
	private $pagenow;

	/**
	 * Installs request-scoped, API-shaped WPML fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->server       = $_SERVER;
		$this->cookies      = $_COOKIE;
		$this->query        = $_GET;
		$this->actions      = $GLOBALS['wp_actions'];
		$this->buffer_level = ob_get_level();
		$this->home         = trailingslashit( get_option( 'home' ) );
		$this->pagenow      = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		$_COOKIE            = array();
		$_GET               = array();
		wp_set_current_user( 0 );
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		$this->go_to( $this->home );
		$this->request( $this->home );
		$this->add_api_filters();
		HSPSC_WPML::init();
		HSPSC_Utils::ensure_cache_dirs();
	}

	/**
	 * Restores language hooks, buffers and request state for unrelated test suites.
	 */
	public function tear_down(): void {
		HSPSC_WPML::purge_queued_pages();
		$this->remove_api_filters();
		remove_action( 'wpml_loaded', array( 'HSPSC_WPML', 'register_hooks' ) );
		foreach ( array_keys( $GLOBALS['wp_filter'] ) as $hook ) {
			remove_action( $hook, array( 'HSPSC_WPML', 'queue_page_purge' ), PHP_INT_MAX );
		}
		while ( ob_get_level() > $this->buffer_level ) {
			ob_end_clean();
		}
		foreach ( array( 'buffer_started' => false, 'buffer_level' => 0, 'buffer_cache_file' => null ) as $name => $value ) {
			$property = new ReflectionProperty( 'HSPSC_Page', $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
		$_SERVER               = $this->server;
		$_COOKIE               = $this->cookies;
		$_GET                  = $this->query;
		$GLOBALS['wp_actions'] = $this->actions;
		$GLOBALS['pagenow']    = $this->pagenow;
		HSPSC_Utils::reset_request_cache();
		parent::tear_down();
	}

	private function add_api_filters() {
		add_filter( 'wpml_current_language', array( $this, 'current_language' ) );
		add_filter( 'wpml_default_language', array( $this, 'default_language' ) );
		add_filter( 'wpml_active_languages', array( $this, 'active_languages' ) );
		add_filter( 'wpml_permalink', array( $this, 'language_permalink' ), 10, 2 );
	}

	private function remove_api_filters() {
		remove_filter( 'wpml_current_language', array( $this, 'current_language' ) );
		remove_filter( 'wpml_default_language', array( $this, 'default_language' ) );
		remove_filter( 'wpml_active_languages', array( $this, 'active_languages' ) );
		remove_filter( 'wpml_permalink', array( $this, 'language_permalink' ), 10 );
	}

	public function current_language() {
		return $this->current;
	}

	public function default_language() {
		return 'en';
	}

	public function active_languages() {
		++$this->active_calls;
		return $this->broken ? null : array( 'en' => array(), 'de' => array() );
	}

	public function language_permalink( $url, $language ) {
		++$this->permalink_calls;
		if ( 'invalid' === $this->mode ) {
			return 'https://user:password@example.org/';
		}
		if ( 'query' === $this->mode ) {
			return add_query_arg( 'lang', $this->alias && 'de' === $language ? 'de-de' : $language, $this->home );
		}
		if ( 'domains' === $this->mode && 'de' === $language ) {
			return 'https://de.example.org:8443/';
		}
		return 'de' === $language ? $this->home . ( $this->alias ? 'de-de/' : 'de/' ) : $this->home;
	}

	private function request( $url ) {
		$parts                        = wp_parse_url( $url );
		$_SERVER['HTTP_HOST']          = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$_SERVER['HTTPS']              = 'https' === $parts['scheme'] ? 'on' : 'off';
		$_SERVER['SERVER_PORT']        = isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $parts['scheme'] ? 443 : 80 );
		$_SERVER['REQUEST_METHOD']     = 'GET';
		$_SERVER['QUERY_STRING']       = isset( $parts['query'] ) ? $parts['query'] : '';
		$_SERVER['REQUEST_URI']        = $parts['path'] . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$GLOBALS['wp_actions']['wp']   = max( 1, did_action( 'wp' ) );
		$GLOBALS['pagenow']            = 'index.php';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );
		HSPSC_Utils::reset_request_cache();
	}

	private function invoke_page( $method, ...$args ) {
		$reflection = new ReflectionMethod( 'HSPSC_Page', $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	public function test_partial_compatibility_filters_do_not_activate_wpml() {
		$this->remove_api_filters();
		add_filter( 'wpml_current_language', array( $this, 'current_language' ) );
		$this->assertSame( '', HSPSC_WPML::request_context() );
		$this->assertSame( '', HSPSC_WPML::url_context( $this->home ) );
		$this->assertSame( array(), HSPSC_WPML::language_urls() );
	}

	/**
	 * A privacy check does not construct WPML's language switcher catalog twice.
	 */
	public function test_request_context_reads_one_fresh_catalog_per_check() {
		$this->active_calls    = 0;
		$this->permalink_calls = 0;
		$this->assertSame( 'en', HSPSC_WPML::request_context() );
		$this->assertSame( 1, $this->active_calls );
		$this->assertSame( 2, $this->permalink_calls );

		// A later API failure must still bypass an earlier successful check.
		$this->broken = true;
		$this->assertNull( HSPSC_WPML::request_context() );
		$this->assertSame( 2, $this->active_calls );
	}

	public function test_directory_urls_match_runtime_and_explicit_url_keys() {
		$this->current = 'de';
		$url           = $this->home . 'de/products/';
		$this->request( $url );
		$this->assertSame( 'de', HSPSC_WPML::request_context() );
		$this->assertSame( 'de', HSPSC_WPML::url_context( $url ) );
		$this->assertSame( $this->invoke_page( 'get_cache_file_path' ), HSPSC_Page::get_cache_file_path_for_url( $url ) );
		$this->assertNotSame( HSPSC_Page::get_cache_file_path_for_url( $url ), HSPSC_Page::get_cache_file_path_for_url( $this->home . 'products/' ) );
		$this->assertNotSame( $this->invoke_page( 'get_url_cache_key', $url, '', 'en' ), $this->invoke_page( 'get_url_cache_key', $url, '', 'de' ) );
	}

	public function test_custom_directory_display_code_preserves_internal_language_context() {
		$this->alias   = true;
		$this->current = 'de';
		$url           = $this->home . 'de-de/products/';
		$this->request( $url );
		$this->assertSame( 'de', HSPSC_WPML::request_context() );
		$this->assertSame( 'de', HSPSC_WPML::url_context( $url ) );
		$this->assertSame( $this->home . 'de-de/', HSPSC_WPML::language_urls()['de'] );
		$this->assertSame( $this->invoke_page( 'get_cache_file_path' ), HSPSC_Page::get_cache_file_path_for_url( $url ) );
	}

	public function test_matching_language_cookies_keep_public_pages_cacheable() {
		$this->current = 'de';
		$this->request( $this->home . 'de/products/' );
		$_COOKIE['wp-wpml_current_language'] = 'de';
		$_COOKIE['_icl_visitor_chosen_lang'] = 'de';
		$this->assertSame( 'de', HSPSC_WPML::request_context() );
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
		$_COOKIE['wp-wpml_current_language'] = 'en';
		$this->assertNull( HSPSC_WPML::request_context() );
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
	}

	public function test_returning_visitor_preference_can_differ_from_explicit_url() {
		$this->current = 'de';
		$this->request( $this->home . 'de/products/' );
		foreach ( array( '_icl_visitor_chosen_lang', '_icl_visitor_chosen_lang_2', '_icl_visitor_lang_js', '_icl_visitor_lang_js_2' ) as $name ) {
			$_COOKIE = array( $name => 'en' );
			$this->assertSame( 'de', HSPSC_WPML::request_context(), $name );
		}
		$_COOKIE['_icl_visitor_chosen_lang_2'] = array( 'de' );
		$this->assertNull( HSPSC_WPML::request_context() );
		$_COOKIE = array( '_icl_visitor_chosen_lang' => 'unknown' );
		$this->assertNull( HSPSC_WPML::request_context() );
	}

	public function test_language_domains_preserve_exact_translated_warm_origins() {
		$this->mode    = 'domains';
		$this->current = 'de';
		$this->request( 'https://de.example.org:8443/products/' );
		$this->assertSame( 'de', HSPSC_WPML::request_context() );
		$this->assertSame( 'https://de.example.org:8443/', HSPSC_WPML::language_urls()['de'] );
		$this->assertTrue( HSPSC_Page::is_warmable_url( 'https://de.example.org:8443/products/' ) );
		$this->assertFalse( HSPSC_Page::is_warmable_url( 'https://de.example.org/products/' ) );
		$this->assertNull( HSPSC_WPML::url_context( 'https://unconfigured.example.org/products/' ) );
	}

	public function test_browser_language_preferences_keep_one_public_landing_page_key() {
		$this->request( $this->home );
		$file = $this->invoke_page( 'get_cache_file_path' );
		$this->assertSame( 'en', HSPSC_WPML::request_context() );
		$_SERVER['HTTP_ACCEPT_LANGUAGE']      = 'de-DE,de;q=0.9';
		$_COOKIE['wpml_browser_redirect_test'] = '1';
		$_COOKIE['_icl_visitor_chosen_lang_2'] = 'de';
		$this->assertSame( 'en', HSPSC_WPML::request_context() );
		$this->assertSame( $file, $this->invoke_page( 'get_cache_file_path' ) );
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
	}

	public function test_query_language_and_url_code_aliases_are_isolated() {
		$this->mode    = 'query';
		$this->current = 'de';
		$url           = $this->home . 'products/?lang=de';
		$this->request( $url );
		$this->assertSame( 'de', HSPSC_WPML::request_context() );
		$this->assertSame( 'en', HSPSC_WPML::url_context( $this->home . 'products/' ) );
		$this->assertNull( HSPSC_WPML::url_context( $this->home . 'products/?lang[]=de' ) );
		$this->assertNull( HSPSC_WPML::url_context( $this->home . 'products/?lang=unknown' ) );
		$this->assertSame( 'de', HSPSC_WPML::url_context( $this->home . 'products/?%6cang=de' ) );
		$this->alias = true;
		$this->request( $this->home . 'products/?lang=de-de' );
		$this->assertSame( 'de', HSPSC_WPML::request_context() );
		$this->assertNull( HSPSC_WPML::url_context( $url ) );
	}

	public function test_conflicting_url_query_and_display_languages_bypass() {
		$this->current = 'de';
		$this->request( $this->home . 'de/products/?lang=en' );
		$this->assertNull( HSPSC_WPML::request_context() );
		$this->assertNull( HSPSC_WPML::url_context( $this->home . 'de/products/?lang=en' ) );
		$this->request( $this->home . 'products/' );
		$this->assertNull( HSPSC_WPML::request_context() );
	}

	public function test_early_api_fallback_does_not_call_active_languages_or_guess_paths() {
		$this->current                  = 'de';
		$GLOBALS['wp_actions']['wp']    = 0;
		$this->active_calls             = 0;
		$this->assertSame( 'de', HSPSC_WPML::url_context( $this->home . 'de/products/' ) );
		$this->assertNull( HSPSC_WPML::url_context( $this->home . 'unknown/products/' ) );
		$this->assertSame( 'en', HSPSC_WPML::url_context( $this->home ) );
		$this->assertSame( 0, $this->active_calls );
		$this->assertArrayHasKey( 'de', HSPSC_WPML::language_urls() );
	}

	public function test_unknown_pre_wp_url_invalidation_clears_all_pages_and_keeps_assets() {
		$GLOBALS['wp_actions']['wp'] = 0;
		$first                       = HSPSC_PATH . '/pages/wpml-first.html';
		$second                      = HSPSC_PATH . '/pages/wpml-second.html';
		$asset                       = HSPSC_PATH . '/assets/wpml-invalidation.js';
		file_put_contents( $first, 'English page' );
		file_put_contents( $second, 'German page' );
		file_put_contents( $asset, 'generated asset' );
		$url = $this->home . 'de/products/';
		$this->assertNull( HSPSC_WPML::url_context( $url ) );
		HSPSC_Page::clear_cache_for_url( $url );
		$this->assertFileDoesNotExist( $first );
		$this->assertFileDoesNotExist( $second );
		$this->assertFileExists( $asset );
		unlink( $asset );
	}

	public function test_unresolved_apis_and_credentialed_roots_fail_closed() {
		$this->broken = true;
		$this->assertNull( HSPSC_WPML::request_context() );
		$this->assertNull( HSPSC_WPML::url_context( $this->home ) );
		$this->assertSame( array(), HSPSC_WPML::language_urls() );
		$this->broken = false;
		$this->mode   = 'invalid';
		$this->assertSame( array(), HSPSC_WPML::language_urls() );
		$this->assertNull( HSPSC_WPML::request_context() );
	}

	public function test_late_language_change_does_not_save_an_earlier_buffer() {
		$url = $this->home . 'products/';
		$this->request( $url );
		$file = $this->invoke_page( 'get_cache_file_path' );
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		ob_start();
		HSPSC_Page::start_buffer();
		echo '<html>English output</html>';
		$this->current = 'de';
		HSPSC_Page::save_buffer();
		ob_end_clean();
		$this->assertFileDoesNotExist( $file );
		$this->assertNull( $this->invoke_page( 'get_cache_file_path' ) );
	}

	public function test_wcml_currency_cookies_bypass_instead_of_sharing_language_cache() {
		foreach ( array( 'client_currency', 'client_currency_language', 'wcml_client_currency', 'wcml_client_currency_language', 'wcml_client_country', '_wcml_order_currency' ) as $name ) {
			$_COOKIE = array();
			$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
			$_COOKIE[ $name ] = 'EUR';
			$this->assertFalse( HSPSC_Utils::is_request_cacheable(), $name );
			$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations(), $name );
		}
	}

	public function test_wcml_uncookied_first_visitors_do_not_share_geolocated_prices() {
		$currency = 'EUR';
		$price    = static function () use ( &$currency ) {
			return $currency;
		};
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
		add_filter( 'wcml_price_currency', $price );
		try {
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
			$currency = 'USD';
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			$currency = null;
			$this->assertFalse( HSPSC_Utils::is_request_cacheable(), 'The documented default-currency null response is not proof multicurrency is disabled.' );
		} finally {
			remove_filter( 'wcml_price_currency', $price );
		}
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
	}

	/**
	 * An active WCML marker requires explicit proof that multicurrency is disabled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wcml_marker_requires_an_explicit_disabled_setting_without_currency_filter() {
		if ( ! defined( 'WCML_VERSION' ) ) {
			define( 'WCML_VERSION', 'test-fixture' );
		}
		$had_instance = array_key_exists( 'woocommerce_wpml', $GLOBALS );
		$instance     = $had_instance ? $GLOBALS['woocommerce_wpml'] : null;
		$filter       = isset( $GLOBALS['wp_filter']['wcml_price_currency'] ) ? clone $GLOBALS['wp_filter']['wcml_price_currency'] : null;
		remove_all_filters( 'wcml_price_currency' );
		$fixture = new class() {
			public $setting;
			public $failure = false;
			public $calls   = array();

			public function get_setting( $key, $fallback = null ) {
				$this->calls[] = array( $key, $fallback );
				if ( $this->failure ) {
					throw new RuntimeException( 'Unavailable WCML settings.' );
				}
				return $this->setting;
			}
		};
		$GLOBALS['woocommerce_wpml'] = $fixture;
		try {
			$this->assertFalse( has_filter( 'wcml_price_currency' ) );
			foreach ( array( 0, '0' ) as $disabled ) {
				$fixture->setting = $disabled;
				$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
				$this->assertTrue( HSPSC_Utils::should_apply_frontend_optimizations() );
			}
			$this->assertSame( array( 'enable_multi_currency', null ), $fixture->calls[0] );
			foreach ( array( 2, null, 'invalid', false, '', array() ) as $uncertain ) {
				$fixture->setting = $uncertain;
				$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
				$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
			}
			$fixture->setting = 0;
			$fixture->failure = true;
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			$GLOBALS['woocommerce_wpml'] = new stdClass();
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			$GLOBALS['woocommerce_wpml'] = null;
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			unset( $GLOBALS['woocommerce_wpml'] );
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
		} finally {
			if ( $had_instance ) {
				$GLOBALS['woocommerce_wpml'] = $instance;
			} else {
				unset( $GLOBALS['woocommerce_wpml'] );
			}
			if ( null !== $filter ) {
				$GLOBALS['wp_filter']['wcml_price_currency'] = $filter;
			} else {
				unset( $GLOBALS['wp_filter']['wcml_price_currency'] );
			}
		}
	}

	public function test_translation_changes_queue_one_page_only_purge() {
		$page  = HSPSC_PATH . '/pages/wpml-regression.html';
		$asset = HSPSC_PATH . '/assets/wpml-regression.js';
		file_put_contents( $page, 'translated page' );
		file_put_contents( $asset, 'translated asset' );
		do_action( 'wpml_st_add_string_translation' );
		do_action( 'wpml_translation_job_saved' );
		$this->assertFileExists( $page );
		HSPSC_WPML::purge_queued_pages();
		$this->assertFileDoesNotExist( $page );
		$this->assertFileExists( $asset );
		file_put_contents( $page, 'new page' );
		HSPSC_WPML::purge_queued_pages();
		$this->assertFileExists( $page );
		unlink( $page );
		unlink( $asset );
	}

	/**
	 * String removals and source mutations invalidate rendered translations.
	 */
	public function test_string_mutations_queue_a_page_only_purge() {
		$page   = HSPSC_PATH . '/pages/wpml-string-mutation.html';
		$asset  = HSPSC_PATH . '/assets/wpml-string-mutation.js';
		$object = HSPSC_PATH . '/object/wpml-string-mutation.txt';
		$events = array(
			'wpml_st_before_remove_strings'       => array( array( 123 ) ),
			'wpml_st_string_unregistered'         => array(),
			'wpml_st_language_of_strings_changed' => array( array( 123 ), array( 'en', 'de' ) ),
			'wpml_st_update_string'               => array( 'hspsc-testing', 'label', 'Old', 'New', false, (object) array( 'id' => 123 ) ),
		);
		file_put_contents( $asset, 'static asset' );
		file_put_contents( $object, 'shared object' );
		try {
			foreach ( $events as $hook => $args ) {
				file_put_contents( $page, 'stale translated page' );
				do_action_ref_array( $hook, $args );
				$this->assertFileExists( $page, $hook . ' queues the purge until shutdown.' );
				HSPSC_WPML::purge_queued_pages();
				$this->assertFileDoesNotExist( $page, $hook );
				$this->assertFileExists( $asset, $hook );
				$this->assertFileExists( $object, $hook );
			}
		} finally {
			wp_delete_file( $page );
			wp_delete_file( $asset );
			wp_delete_file( $object );
		}
	}

	/**
	 * Ordinary frontend string registration and refresh preserve shared output.
	 */
	public function test_string_registration_does_not_purge_cached_pages() {
		$page = HSPSC_PATH . '/pages/wpml-string-registration.html';
		file_put_contents( $page, 'cached page' );
		try {
			do_action( 'wpml_st_string_registered' );
			do_action( 'wpml_register_single_string', 'hspsc-testing', 'label', 'Public value' );
			do_action( 'wpml_st_string_updated' );
			HSPSC_WPML::purge_queued_pages();
			$this->assertFileExists( $page );
		} finally {
			wp_delete_file( $page );
		}
	}

	public function test_language_settings_invalidate_translated_pages() {
		$page = HSPSC_PATH . '/pages/wpml-settings.html';
		foreach ( array( 'update_option_icl_sitepress_settings', 'update_option_icl_st_settings', 'wpml_update_active_languages' ) as $hook ) {
			file_put_contents( $page, 'old settings' );
			do_action( $hook );
			HSPSC_WPML::purge_queued_pages();
			$this->assertFileDoesNotExist( $page, $hook );
		}
	}
}
