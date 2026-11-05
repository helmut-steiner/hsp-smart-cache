<?php
/**
 * Page-path configuration must guard both old cache hits and new writes.
 */
class HSPSC_Page_Exclusions_Fixture extends HSPSC_Page {
	protected static function should_cache_response( $status = null, $headers = null ) {
		return parent::should_cache_response( 200, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}

class HSPSC_Page_Exclusions_Test extends WP_UnitTestCase {
	private $server;
	private $cookies;
	private $query;
	private $buffer_level;
	private $cache_file;

	public function set_up(): void {
		parent::set_up();
		$this->server       = $_SERVER;
		$this->cookies      = $_COOKIE;
		$this->query        = $_GET;
		$this->buffer_level = ob_get_level();
		$_COOKIE           = array();
		$_GET              = array();
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/' ) );
		$home = wp_parse_url( home_url() );
		$_SERVER['HTTP_HOST']      = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$_SERVER['HTTPS']          = 'https' === $home['scheme'] ? 'on' : 'off';
		$_SERVER['SERVER_PORT']    = $home['port'] ?? ( 'https' === $home['scheme'] ? 443 : 80 );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/booking/';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_ACCEPT'], $_SERVER['QUERY_STRING'] );
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'page_cache_excluded_paths' => '/booking/', 'minify_html' => false, 'browser_cache' => false ) ) );
		HSPSC_Utils::ensure_cache_dirs();
		HSPSC_Utils::reset_request_cache();
		$this->cache_file = HSPSC_Page::get_cache_file_path_for_url( home_url( '/booking/' ) );
		wp_delete_file( $this->cache_file );
	}

	public function tear_down(): void {
		while ( ob_get_level() > $this->buffer_level ) {
			ob_end_clean();
		}
		wp_delete_file( $this->cache_file );
		$_SERVER = $this->server;
		$_COOKIE = $this->cookies;
		$_GET    = $this->query;
		HSPSC_Utils::reset_request_cache();
		parent::tear_down();
	}

	/** @dataProvider matching_paths */
	public function test_exact_paths_bypass_page_cache_with_query_and_encoding_variations( $uri ) {
		$_SERVER['REQUEST_URI'] = $uri;
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
	}

	public function matching_paths() {
		return array(
			array( '/booking' ),
			array( '/booking/' ),
			array( '/booking/?service=1&lang=de' ),
			array( '/book%69ng/' ),
			array( '/booking//' ),
			array( '/other/../booking/' ),
		);
	}

	public function test_exclusions_do_not_match_subpages_or_similar_names() {
		foreach ( array( '/booking/guide/', '/booking-next/', '/normal/' ) as $path ) {
			$_SERVER['REQUEST_URI'] = $path;
			$this->assertTrue( HSPSC_Utils::is_request_cacheable(), $path );
		}
		$_SERVER['REQUEST_URI'] = '/booking/';
		$this->assertTrue( HSPSC_Utils::should_apply_frontend_optimizations() );
	}

	/**
	 * Literal percent escapes remain excluded after saving the settings again.
	 *
	 * @dataProvider literal_percent_paths
	 */
	public function test_saved_literal_percent_paths_remain_excluded( $path, $other_path ) {
		$options = HSPSC_Settings::get_all();
		$options['page_cache_excluded_paths'] = HSPSC_Settings::sanitize_page_cache_excluded_paths( $path );
		update_option( HSPSC_Settings::OPTION_KEY, $options );
		$_SERVER['REQUEST_URI'] = $path . '?service=1';
		$this->assertTrue( HSPSC_Utils::is_page_cache_path_excluded() );
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );

		$options = HSPSC_Settings::get_all();
		$options['page_cache_excluded_paths'] = HSPSC_Settings::sanitize_page_cache_excluded_paths( $options['page_cache_excluded_paths'] );
		update_option( HSPSC_Settings::OPTION_KEY, $options );
		$this->assertTrue( HSPSC_Utils::is_page_cache_path_excluded() );
		$this->assertFalse( HSPSC_Utils::is_request_cacheable() );

		$_SERVER['REQUEST_URI'] = $other_path;
		$this->assertFalse( HSPSC_Utils::is_page_cache_path_excluded() );
		$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
	}

	public function literal_percent_paths() {
		return array(
			'space text' => array( '/offers%2520private/', '/offers%20private/' ),
			'question mark text' => array( '/raw%253Fvalue/', '/raw-value/' ),
			'hex text' => array( '/sale%25de/', '/sale-de/' ),
			'nested escapes' => array( '/nested%252520text/', '/nested%2520text/' ),
			'slash text' => array( '/literal%252Fslash/', '/literal/slash/' ),
			'dot text' => array( '/%252e%252e/booking/', '/booking/' ),
		);
	}

	public function test_existing_cache_file_is_bypassed_before_any_hit_can_be_served() {
		file_put_contents( $this->cache_file, '<html>STALE PRIVATE FORM</html>' );
		$eligibility_calls = 0;
		// Without the configured-path guard this sentinel is reached before lookup.
		$sentinel = static function () use ( &$eligibility_calls ) {
			++$eligibility_calls;
			return false;
		};
		add_filter( 'hspsc_is_request_cacheable', $sentinel );
		try {
			ob_start();
			HSPSC_Page::maybe_serve_cache();
			$output = ob_get_clean();
		} finally {
			remove_filter( 'hspsc_is_request_cacheable', $sentinel );
		}
		$this->assertSame( '', $output );
		$this->assertSame( 0, $eligibility_calls );
		$this->assertSame( '<html>STALE PRIVATE FORM</html>', file_get_contents( $this->cache_file ) );
	}

	public function test_excluded_page_cannot_start_a_cache_buffer() {
		$level = ob_get_level();
		HSPSC_Page::start_buffer();
		$this->assertSame( $level, ob_get_level() );
		$this->assertFileDoesNotExist( $this->cache_file );
	}

	public function test_late_exclusion_is_rechecked_before_writing_html() {
		$options = HSPSC_Settings::get_all();
		$options['page_cache_excluded_paths'] = '';
		update_option( HSPSC_Settings::OPTION_KEY, $options );
		ob_start();
		HSPSC_Page_Exclusions_Fixture::start_buffer();
		echo '<html>fresh booking policy</html>';
		$options['page_cache_excluded_paths'] = '/booking/';
		update_option( HSPSC_Settings::OPTION_KEY, $options );
		HSPSC_Page_Exclusions_Fixture::save_buffer();
		$output = ob_get_clean();
		$this->assertSame( '<html>fresh booking policy</html>', $output );
		$this->assertFileDoesNotExist( $this->cache_file );
	}

	public function test_normal_page_is_still_stored_when_another_path_is_excluded() {
		$_SERVER['REQUEST_URI'] = '/normal/';
		$this->cache_file = HSPSC_Page::get_cache_file_path_for_url( home_url( '/normal/' ) );
		ob_start();
		HSPSC_Page_Exclusions_Fixture::start_buffer();
		echo '<html>ordinary public page</html>';
		HSPSC_Page_Exclusions_Fixture::save_buffer();
		$output = ob_get_clean();
		$this->assertSame( $output, file_get_contents( $this->cache_file ) );
	}
}
