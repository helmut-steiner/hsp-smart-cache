<?php

/** Frontend-only eligibility with realistic authentication cookies. */
class HSPSC_Logged_In_Cache_Test extends WP_UnitTestCase {
	private $server;
	private $cookies;
	private $query;
	private $pagenow;
	private $user_id;
	private $settings;

	public function set_up(): void {
		parent::set_up();
		$this->server   = $_SERVER;
		$this->cookies  = $_COOKIE;
		$this->query    = $_GET;
		$this->pagenow  = $GLOBALS['pagenow'] ?? null;
		$this->user_id  = get_current_user_id();
		$this->settings = get_option( HSPSC_Settings::OPTION_KEY );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_COOKIE = array( 'wordpress_logged_in_audit' => 'authenticated-session' );
		$_GET = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = '/public-page/';
		$_SERVER['QUERY_STRING'] = '';
		$GLOBALS['pagenow'] = 'index.php';
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'cache_logged_in' => true ) ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_doing_cron', '__return_true' );
		set_current_screen( 'front' );
		$_SERVER = $this->server;
		$_COOKIE = $this->cookies;
		$_GET = $this->query;
		$GLOBALS['pagenow'] = $this->pagenow;
		wp_set_current_user( $this->user_id );
		if ( false === $this->settings ) {
			delete_option( HSPSC_Settings::OPTION_KEY );
		} else {
			update_option( HSPSC_Settings::OPTION_KEY, $this->settings );
		}
		HSPSC_Settings::reset_cache();
		parent::tear_down();
	}

	public function test_authenticated_frontend_requires_opt_in_and_keeps_optimization_separate() {
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
		$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
	}

	public function test_unverified_login_cookie_never_enables_caching() {
		wp_set_current_user( 0 );
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
	}

	public function test_backend_settings_login_api_and_builders_never_start_a_page_buffer() {
		foreach ( array( '/wp-admin', '/wp-admin/', '/wp-admin/options-general.php?page=hsp-smart-cache', '/subsite/wp-admin/network/settings.php', '/wp%2dadmin/options.php', '/public/../wp-admin/options.php', '/wp-login.php', '/wp-json/wp/v2/settings', '/?rest_route=/wp/v2/settings', '/?bricks=run', '/?bricks=preview', '/?customize_changeset_uuid=test' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertFalse( HSPSC_Utils::is_request_cacheable(), $uri );
			$level = ob_get_level();
			HSPSC_Page::start_buffer();
			$this->assertSame( $level, ob_get_level(), $uri );
		}
	}

	public function test_admin_screen_ajax_and_cron_remain_dynamic_on_any_url() {
		set_current_screen( 'options-general' );
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
		set_current_screen( 'front' );
		foreach ( array( 'wp_doing_ajax', 'wp_doing_cron' ) as $hook ) {
			add_filter( $hook, '__return_true' );
			$this->assertFalse( HSPSC_Utils::is_request_cacheable(), $hook );
			remove_filter( $hook, '__return_true' );
		}
	}

	public function test_private_cookies_and_non_get_requests_still_bypass() {
		foreach ( array( 'PHPSESSID', 'woocommerce_items_in_cart', 'iawp_ignore_visitor', 'wcml_client_currency' ) as $name ) {
			$_COOKIE[ $name ] = '';
			$this->assertFalse( HSPSC_Utils::is_request_cacheable(), $name );
			unset( $_COOKIE[ $name ] );
		}
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
	}
}
