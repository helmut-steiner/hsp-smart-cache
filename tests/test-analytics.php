<?php
/**
 * Independent Analytics compatibility contracts without a paid plugin dependency.
 *
 * @package HSPSC
 */

/**
 * Exercises shared tracking markup, privacy exclusions and option invalidation.
 */
class HSPSC_Analytics_Test extends WP_UnitTestCase {
	private $server;
	private $cookies;
	private $query;
	private $pagenow;
	private $user_id;
	private $buffer_level;
	private $page;
	private $asset;
	private $object;

	/**
	 * Creates an ordinary public request and disposable cache sentinels.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->server       = $_SERVER;
		$this->cookies      = $_COOKIE;
		$this->query        = $_GET;
		$this->pagenow      = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		$this->user_id      = get_current_user_id();
		$this->buffer_level = ob_get_level();
		$_COOKIE           = array();
		$_GET              = array();
		wp_set_current_user( 0 );
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		$this->go_to( home_url( '/' ) );
		$home = wp_parse_url( home_url() );
		$_SERVER['HTTP_HOST']      = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$_SERVER['HTTPS']          = 'https' === $home['scheme'] ? 'on' : 'off';
		$_SERVER['SERVER_PORT']    = isset( $home['port'] ) ? $home['port'] : ( 'https' === $home['scheme'] ? 443 : 80 );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = '';
		$GLOBALS['pagenow']        = 'index.php';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );
		HSPSC_Analytics::init();
		HSPSC_Utils::ensure_cache_dirs();
		HSPSC_Utils::reset_request_cache();
		$this->page   = HSPSC_PATH . '/pages/analytics-regression.html';
		$this->asset  = HSPSC_PATH . '/assets/analytics-regression.js';
		$this->object = HSPSC_PATH . '/object/analytics-regression.txt';
	}

	/**
	 * Restores request and output-buffer state for other integration suites.
	 */
	public function tear_down(): void {
		while ( ob_get_level() > $this->buffer_level ) {
			ob_end_clean();
		}
		foreach ( array( 'buffer_started' => false, 'buffer_level' => 0, 'buffer_cache_file' => null ) as $name => $value ) {
			$property = new ReflectionProperty( 'HSPSC_Page', $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
		foreach ( array( $this->page, $this->asset, $this->object ) as $file ) {
			wp_delete_file( $file );
		}
		$_SERVER            = $this->server;
		$_COOKIE            = $this->cookies;
		$_GET               = $this->query;
		$GLOBALS['pagenow'] = $this->pagenow;
		wp_set_current_user( $this->user_id );
		HSPSC_Utils::reset_request_cache();
		parent::tear_down();
	}

	/**
	 * Invokes a page-cache identity helper without performing a cache-hit exit.
	 *
	 * @param string $method Protected helper name.
	 * @return mixed Helper result.
	 */
	private function invoke_page( $method ) {
		$reflection = new ReflectionMethod( 'HSPSC_Page', $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null );
	}

	/**
	 * Ignored visitors must neither receive nor create shared tracking markup.
	 */
	public function test_ignore_cookie_bypasses_cached_pages_even_when_empty() {
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
		$this->assertTrue( HSPSC_Utils::should_apply_frontend_optimizations() );
		foreach ( array( '1', '0', '' ) as $value ) {
			$_COOKIE['iawp_ignore_visitor'] = $value;
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations() );
		}
		unset( $_COOKIE['iawp_ignore_visitor'] );
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
	}

	/**
	 * A cookie introduced after buffering is reevaluated before saving the page.
	 */
	public function test_late_ignore_cookie_keeps_output_and_skips_cache_write() {
		$file = $this->invoke_page( 'get_cache_file_path' );
		wp_delete_file( $file );
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
		ob_start();
		HSPSC_Page::start_buffer();
		echo '<html><body>Tracking excluded for this visitor.</body></html>';
		$_COOKIE['iawp_ignore_visitor'] = '1';
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
		HSPSC_Page::save_buffer();
		$this->assertSame( '<html><body>Tracking excluded for this visitor.</body></html>', ob_get_clean() );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * Campaigns share the HTML entry while the browser retains its actual query.
	 */
	public function test_campaign_cache_keys_preserve_browser_tracking_payloads() {
		$canonical = HSPSC_Page::get_cache_file_path_for_url( home_url( '/landing/' ) );
		$campaigns = array(
			'/landing/?utm_source=newsletter&utm_medium=email&utm_campaign=spring',
			'/landing/?utm_source=search&utm_content=button&gclid=campaign-click',
		);
		foreach ( $campaigns as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertSame( $canonical, $this->invoke_page( 'get_cache_file_path' ) );
			$this->assertSame( $uri, $_SERVER['REQUEST_URI'] );
		}
		$tracker = '<script id="independent-analytics-script">' . "\n"
			. '// Tracking payload reads the current browser URL.' . "\n"
			. 'const campaign = new URLSearchParams(window.location.search);' . "\n"
			. 'window.hspscAnalyticsFixture = { source: campaign.get("utm_source"), endpoint: "/wp-json/iawp/search" };' . "\n"
			. '</script>';
		$consent = '<script type="text/plain" data-category="statistics" data-service="independent-analytics">window.hspscConsentFixture = true;</script>';
		$output  = HSPSC_Minify::minify_html( '<html><body><!-- disposable comment -->' . $tracker . $consent . '</body></html>' );
		$this->assertStringContainsString( $tracker, $output );
		$this->assertStringContainsString( $consent, $output );
		$this->assertStringNotContainsString( 'disposable comment', $output );
	}

	/**
	 * Analytics collectors remain dynamic under normal and alternate REST routing.
	 */
	public function test_analytics_collection_routes_are_never_cached() {
		$requests = array(
			array( 'POST', '/wp-json/iawp/search' ),
			array( 'GET', '/wp-json/iawp/search' ),
			array( 'POST', '/?rest_route=%2Fiawp%2Fsearch' ),
			array( 'GET', '/?rest_route=%2Fiawp%2Fsearch' ),
			array( 'POST', '/wp-content/plugins/independent-analytics-pro/iawp-click-endpoint.php' ),
		);
		foreach ( $requests as $request ) {
			$_SERVER['REQUEST_METHOD'] = $request[0];
			$_SERVER['REQUEST_URI']    = $request[1];
			$this->assertFalse( HSPSC_Utils::is_request_cacheable(), $request[1] );
			$this->assertFalse( HSPSC_Utils::should_apply_frontend_optimizations(), $request[1] );
		}
	}

	/**
	 * Settings reflected in HTML invalidate pages for create, update and removal.
	 */
	public function test_tracking_configuration_changes_purge_only_pages() {
		$options = array(
			'iawp_link_rules'                => array( array( 'type' => 'class', 'value' => 'first-button' ), array( 'type' => 'class', 'value' => 'new-button' ) ),
			'iawp_request_payload_salt'      => array( 'fixture-old-salt', 'fixture-new-salt' ),
			'iawp_track_authenticated_users' => array( false, true ),
			'iawp_blocked_roles'             => array( array( 'administrator' ), array( 'administrator', 'editor' ) ),
			'iawp_ignore_via_cookie'         => array( false, true ),
			'complianz_options_integrations' => array( array( 'independent-analytics' => false ), array( 'independent-analytics' => true ) ),
			'iawp_view_counter_label'        => array( 'Views:', 'Reads:' ),
		);
		file_put_contents( $this->asset, 'static tracker-independent asset' );
		file_put_contents( $this->object, 'shared analytics object' );
		foreach ( $options as $option => $values ) {
			delete_option( $option );
			foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
				file_put_contents( $this->page, 'stale tracker configuration' );
				if ( 'add' === $operation ) {
					add_option( $option, $values[0] );
				} elseif ( 'update' === $operation ) {
					update_option( $option, $values[1] );
				} else {
					delete_option( $option );
				}
				$this->assertFileDoesNotExist( $this->page, $option . ' ' . $operation );
				$this->assertFileExists( $this->asset, $option );
				$this->assertFileExists( $this->object, $option );
			}
		}
	}

	/**
	 * Visitor-salt rotation and dashboard changes do not evict shared HTML.
	 */
	public function test_collection_and_dashboard_options_do_not_purge_pages() {
		file_put_contents( $this->page, 'stable public page' );
		foreach ( array( 'iawp_salt', 'iawp_appearance', 'iawp_db_version', 'iawp_last_update_viewed' ) as $option ) {
			delete_option( $option );
			add_option( $option, 'first' );
			$this->assertFileExists( $this->page );
			update_option( $option, 'second' );
			$this->assertFileExists( $this->page );
			delete_option( $option );
			$this->assertFileExists( $this->page );
		}
	}
}
