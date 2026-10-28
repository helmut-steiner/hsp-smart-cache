<?php

/**
 * Supplies HTTP response data that PHP's CLI SAPI does not retain.
 */
class HSPSC_Page_Buffer_Fixture extends HSPSC_Page {

	public static $response_headers = array();
	public static $response_status = 200;

	protected static function should_cache_response( $status = null, $headers = null ) {
		return parent::should_cache_response( self::$response_status, self::$response_headers );
	}
}

class HSPSC_Page_Buffer_Test extends WP_UnitTestCase {

	private $server;
	private $cookies;
	private $query;
	private $buffer_level;
	private $cache_file;

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		$this->cookies = $_COOKIE;
		$this->query = $_GET;
		$this->buffer_level = ob_get_level();
		$_COOKIE = array();
		$_GET = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_ACCEPT'] );
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/' ) );
		$home = wp_parse_url( home_url() );
		$_SERVER['HTTP_HOST'] = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$_SERVER['HTTPS'] = 'https' === $home['scheme'] ? 'on' : 'off';
		$_SERVER['SERVER_PORT'] = isset( $home['port'] ) ? $home['port'] : ( 'https' === $home['scheme'] ? 443 : 80 );
		$_SERVER['REQUEST_URI'] = '/buffer-regression/';
		update_option(
			HSPSC_Settings::OPTION_KEY,
			array_merge( HSPSC_Settings::defaults(), array( 'minify_html' => false, 'browser_cache' => false ) )
		);
		HSPSC_Utils::reset_request_cache();
		HSPSC_Utils::ensure_cache_dirs();
		HSPSC_Page_Buffer_Fixture::$response_status = 200;
		HSPSC_Page_Buffer_Fixture::$response_headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$this->cache_file = HSPSC_Page::get_cache_file_path_for_url( home_url( '/buffer-regression/' ) );
		wp_delete_file( $this->cache_file );
	}

	public function tear_down(): void {
		while ( ob_get_level() > $this->buffer_level ) {
			ob_end_clean();
		}
		wp_delete_file( $this->cache_file );
		HSPSC_Page::clear_cache_for_url( home_url( '/buffer-changed/' ) );
		$_SERVER = $this->server;
		$_COOKIE = $this->cookies;
		$_GET = $this->query;
		HSPSC_Utils::reset_request_cache();
		parent::tear_down();
	}

	/**
	 * A complete normal response is returned and stored without duplicate output.
	 */
	public function test_owned_buffer_stores_the_complete_response() {
		ob_start();
		HSPSC_Page_Buffer_Fixture::start_buffer();
		echo '<html><body>complete response</body></html>';
		HSPSC_Page_Buffer_Fixture::save_buffer();
		$output = ob_get_clean();

		$this->assertSame( '<html><body>complete response</body></html>', $output );
		$this->assertFileExists( $this->cache_file );
		$this->assertSame( $output, file_get_contents( $this->cache_file ) );
	}

	/**
	 * Foreign callbacks retain ownership and their final output becomes the cache.
	 */
	public function test_nested_transformations_are_captured_after_their_final_flush() {
		$calls = 0;
		ob_start();
		HSPSC_Page_Buffer_Fixture::start_buffer();
		echo '<html><body>prefix ';
		ob_start(
			static function ( $html ) use ( &$calls ) {
				++$calls;
				return str_replace( 'original.jpg', 'converted.webp', $html );
			}
		);
		echo '<img src="original.jpg"></body></html>';
		$level = ob_get_level();
		HSPSC_Page_Buffer_Fixture::save_buffer();
		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( 0, $calls );
		$this->assertFileDoesNotExist( $this->cache_file );
		ob_end_flush();
		ob_end_flush();
		$output = ob_get_clean();

		$this->assertSame( 1, $calls );
		$this->assertSame( '<html><body>prefix <img src="converted.webp"></body></html>', $output );
		$this->assertSame( $output, file_get_contents( $this->cache_file ) );
	}

	/**
	 * WordPress 6.9+'s nonflushable enhancement buffer is drained by core normally.
	 */
	public function test_core_template_enhancement_output_is_cached() {
		if ( ! function_exists( 'wp_start_template_enhancement_output_buffer' ) ) {
			$this->markTestSkipped( 'Template enhancement output buffering requires WordPress 6.9 or later.' );
		}
		$enhance = static function ( $html ) {
			return str_replace( 'BEFORE_ENHANCEMENT', 'AFTER_ENHANCEMENT', $html );
		};
		add_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_true' );
		add_filter( 'wp_template_enhancement_output_buffer', $enhance );
		try {
			ob_start();
			HSPSC_Page_Buffer_Fixture::start_buffer();
			$this->assertTrue( wp_start_template_enhancement_output_buffer() );
			echo '<!doctype html><html><head></head><body>BEFORE_ENHANCEMENT</body></html>';
			$level = ob_get_level();
			HSPSC_Page_Buffer_Fixture::save_buffer();
			$this->assertSame( $level, ob_get_level() );
			$this->assertFileDoesNotExist( $this->cache_file );
			ob_end_flush();
			ob_end_flush();
			$output = ob_get_clean();
		} finally {
			remove_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_true' );
			remove_filter( 'wp_template_enhancement_output_buffer', $enhance );
		}

		$this->assertStringContainsString( 'AFTER_ENHANCEMENT', $output );
		$this->assertStringNotContainsString( 'BEFORE_ENHANCEMENT', $output );
		$this->assertSame( $output, file_get_contents( $this->cache_file ) );
	}

	/**
	 * Late integration changes still prevent caching when capture was deferred.
	 *
	 * @dataProvider late_changes
	 */
	public function test_nested_finalization_rechecks_privacy_and_representation( $change ) {
		ob_start();
		HSPSC_Page_Buffer_Fixture::start_buffer();
		ob_start(
			static function ( $html ) use ( $change ) {
				switch ( $change ) {
					case 'cart':
						$_COOKIE['fct_cart_hash'] = 'late-private-cart';
						break;
					case 'authorization':
						$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer private-token';
						break;
					case 'uri':
						$_SERVER['REQUEST_URI'] = '/buffer-changed/';
						break;
					case 'accept':
						$_SERVER['HTTP_ACCEPT'] = 'text/html,image/webp';
						break;
					case 'status':
						HSPSC_Page_Buffer_Fixture::$response_status = 500;
						break;
					case 'cookie-header':
						HSPSC_Page_Buffer_Fixture::$response_headers[] = 'Set-Cookie: session=private';
						break;
					case 'private-header':
						HSPSC_Page_Buffer_Fixture::$response_headers[] = 'Cache-Control: private';
						break;
					case 'markdown':
						HSPSC_Page_Buffer_Fixture::$response_headers = array( 'Content-Type: text/markdown' );
						break;
					case 'gzip':
						HSPSC_Page_Buffer_Fixture::$response_headers[] = 'Content-Encoding: gzip';
						break;
				}
				return $html;
			}
		);
		echo '<html><body>late private response</body></html>';
		HSPSC_Page_Buffer_Fixture::save_buffer();
		ob_end_flush();
		ob_end_flush();
		$output = ob_get_clean();

		$this->assertSame( '<html><body>late private response</body></html>', $output );
		$this->assertFileDoesNotExist( $this->cache_file );
		$this->assertFileDoesNotExist( HSPSC_Page::get_cache_file_path_for_url( home_url( $_SERVER['REQUEST_URI'] ) ) );
	}

	public function late_changes() {
		return array(
			'cart session'       => array( 'cart' ),
			'authorization'      => array( 'authorization' ),
			'changed URL'        => array( 'uri' ),
			'changed Accept'     => array( 'accept' ),
			'error status'       => array( 'status' ),
			'response cookie'    => array( 'cookie-header' ),
			'private response'   => array( 'private-header' ),
			'Markdown response'  => array( 'markdown' ),
			'compressed body'    => array( 'gzip' ),
		);
	}

	/**
	 * A manual flush or clean prevents a later fragment from becoming a full page.
	 *
	 * @dataProvider interruptions
	 */
	public function test_interrupted_buffers_preserve_output_without_caching( $clean ) {
		ob_start();
		HSPSC_Page_Buffer_Fixture::start_buffer();
		echo 'first fragment';
		if ( $clean ) {
			ob_clean();
		} else {
			ob_flush();
		}
		echo 'last fragment';
		HSPSC_Page_Buffer_Fixture::save_buffer();
		$output = ob_get_clean();

		$this->assertSame( $clean ? 'last fragment' : 'first fragmentlast fragment', $output );
		$this->assertFileDoesNotExist( $this->cache_file );
	}

	public function interruptions() {
		return array( 'clean' => array( true ), 'flush' => array( false ) );
	}

	/**
	 * Closing the owned buffer early cannot authorize closing its replacement.
	 */
	public function test_same_depth_replacement_buffer_is_left_intact() {
		ob_start();
		HSPSC_Page_Buffer_Fixture::start_buffer();
		echo 'early output';
		ob_end_flush();
		ob_start();
		echo 'replacement output';
		$level = ob_get_level();
		HSPSC_Page_Buffer_Fixture::save_buffer();
		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( 'replacement output', ob_get_clean() );
		$this->assertSame( 'early output', ob_get_clean() );
		$this->assertFileDoesNotExist( $this->cache_file );
	}

	/**
	 * An owner discarding its response after shutdown readiness cannot save it.
	 */
	public function test_clean_final_callback_is_not_cached() {
		ob_start();
		HSPSC_Page_Buffer_Fixture::start_buffer();
		ob_start();
		echo 'discarded response';
		HSPSC_Page_Buffer_Fixture::save_buffer();
		ob_end_flush();
		ob_end_clean();
		$this->assertSame( '', ob_get_clean() );
		$this->assertFileDoesNotExist( $this->cache_file );
	}
}
