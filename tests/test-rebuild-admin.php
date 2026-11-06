<?php
/**
 * Authorization, progress, and report coverage for the public rebuild UI.
 *
 * @package HSPSC
 */

class HSPSC_Rebuild_Admin_Test_Exit extends RuntimeException {
}

class HSPSC_Rebuild_Admin_Test extends WP_UnitTestCase {
	private $original_post;
	private $original_request;
	private $original_server;
	private $original_user;

	public function set_up(): void {
		parent::set_up();
		$this->original_post    = $_POST;
		$this->original_request = $_REQUEST;
		$this->original_server  = $_SERVER;
		$this->original_user    = get_current_user_id();
		HSPSC_Rebuild::reset();
	}

	public function tear_down(): void {
		HSPSC_Rebuild::reset();
		$_POST    = $this->original_post;
		$_REQUEST = $this->original_request;
		$_SERVER  = $this->original_server;
		wp_set_current_user( $this->original_user );
		parent::tear_down();
	}

	/**
	 * @dataProvider unauthorized_actions
	 */
	public function test_rebuild_ajax_requires_capability_and_action_nonce( $method, $nonce_action ) {
		$id = wp_generate_uuid4();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->call_ajax( $method, array( 'job_id' => $id, '_wpnonce' => wp_create_nonce( $nonce_action . ( 'ajax_retry' === $method ? $id : '' ) ) ) );
		$this->assertFalse( $response['success'] );
		$this->assertSame( __( 'Unauthorized', 'hsp-smart-cache' ), $response['data']['message'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = $this->call_ajax( $method, array( 'job_id' => $id, '_wpnonce' => wp_create_nonce( $nonce_action . '-different-job-or-action' ) ) );
		$this->assertFalse( $response['success'] );
		$this->assertSame( __( 'Security check failed. Refresh the page and try again.', 'hsp-smart-cache' ), $response['data']['message'] );
		$this->assertEmpty( HSPSC_Rebuild::get_status() );
	}

	public static function unauthorized_actions() {
		return array(
			'status' => array( 'ajax_status', 'hspsc_rebuild_status' ),
			'tick'   => array( 'ajax_tick', 'hspsc_rebuild_tick' ),
			'retry'  => array( 'ajax_retry', 'hspsc_rebuild_retry_' ),
		);
	}

	/** A progress read neither warms a URL nor returns table rows to the frontend. */
	public function test_status_is_read_only_and_returns_only_notice_data_by_default() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'page_cache' => true ) ) );
		$job = HSPSC_Rebuild::start( home_url( '/rebuild-status-target/' ) );
		$this->assertNotWPError( $job );
		$http_requests = 0;
		$count_http    = static function ( $preempt ) use ( &$http_requests ) {
			++$http_requests;
			return new WP_Error( 'unexpected_http' );
		};
		add_filter( 'pre_http_request', $count_http );
		try {
			$response = $this->call_ajax( 'ajax_status', array( '_wpnonce' => wp_create_nonce( 'hspsc_rebuild_status' ) ) );
		} finally {
			remove_filter( 'pre_http_request', $count_http );
		}
		$this->assertTrue( $response['success'] );
		$this->assertSame( $job['id'], $response['data']['job']['id'] );
		$this->assertTrue( $response['data']['job']['active'] );
		$this->assertArrayNotHasKey( 'report', $response['data'] );
		$this->assertArrayNotHasKey( 'results', $response['data']['job'] );
		$this->assertSame( 0, $http_requests );
		$this->assertSame( 0, HSPSC_Rebuild::get_status()['processed'] );
	}

	/** A valid nonce for an old job cannot retry the current job. */
	public function test_retry_rejects_stale_job_even_with_its_valid_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'page_cache' => true ) ) );
		$job = HSPSC_Rebuild::start( home_url( '/rebuild-retry-target/' ) );
		$this->assertNotWPError( $job );
		$stale = wp_generate_uuid4();
		$response = $this->call_ajax( 'ajax_retry', array( 'job_id' => $stale, '_wpnonce' => wp_create_nonce( 'hspsc_rebuild_retry_' . $stale ) ) );
		$this->assertFalse( $response['success'] );
		$this->assertSame( __( 'This rebuild is no longer the latest job. View the current results and try again.', 'hsp-smart-cache' ), $response['data']['message'] );
		$this->assertSame( $job['id'], HSPSC_Rebuild::get_status()['id'] );
	}

	/** A settings AJAX start warms and verifies the original navigation variant. */
	public function test_settings_ajax_warms_the_document_accept_variant() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$sitemap = home_url( '/rebuild-admin-sitemap.xml' );
		update_option(
			HSPSC_Settings::OPTION_KEY,
			array_merge(
				HSPSC_Settings::defaults(),
				array(
					'page_cache'          => true,
					'preload_sitemap_url' => $sitemap,
				)
			)
		);
		$accept                = 'text/html;example="a&amp;b",application/xhtml+xml,image/webp,*/*;q=0.8';
		$_SERVER['HTTP_ACCEPT'] = '*/*';
		$started               = $this->call_ajax(
			'ajax_run_preload',
			array(
				'_wpnonce'          => wp_create_nonce( 'hspsc_run_preload' ),
				'navigation_accept' => bin2hex( $accept ),
			),
			'HSPSC_Admin'
		);
		$this->assertTrue( $started['success'] );
		$this->assertSame( $accept, get_option( HSPSC_Rebuild::OPTION )['accept'] );

		$home        = home_url( '/' );
		$body        = '<html><body>Verified navigation variant</body></html>';
		$key         = ( new ReflectionMethod( 'HSPSC_Page', 'get_url_cache_key' ) )->invoke( null, $home );
		$cache_file  = ( new ReflectionMethod( 'HSPSC_Page', 'get_variant_cache_file_path' ) )->invoke( null, $key, $accept );
		$sent_accept = null;
		$mock_http   = static function ( $preempt, $args, $url ) use ( $sitemap, $home, $body, $cache_file, &$sent_accept ) {
			if ( $sitemap === $url ) {
				return array(
					'headers'  => array( 'content-type' => 'application/xml' ),
					'body'     => '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>',
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			}
			if ( $home !== $url ) {
				return new WP_Error( 'unexpected_http' );
			}
			$sent_accept = $args['headers']['Accept'];
			file_put_contents( $cache_file, $body );
			return array(
				'headers'  => array( 'content-type' => 'text/html', 'x-hspsc-cache' => 'MISS' ),
				'body'     => $body,
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $mock_http, 10, 3 );
		try {
			$finished = HSPSC_Rebuild::run_batch( $started['data']['job']['id'] );
			$this->assertNotWPError( $finished );
			$this->assertSame( $accept, $sent_accept );
			$this->assertSame( 1, $finished['cached'] );
			$report = $this->call_ajax( 'ajax_status', array( '_wpnonce' => wp_create_nonce( 'hspsc_rebuild_status' ), 'include_report' => '1' ) );
			$this->assertStringContainsString( '1 cached, 0 skipped, 0 failed.', $report['data']['report'] );
			$this->assertStringContainsString( 'Verified public cache entry.', $report['data']['report'] );
		} finally {
			remove_filter( 'pre_http_request', $mock_http, 10 );
			wp_delete_file( $cache_file );
		}
	}

	/** Reports escape URLs, translate fixed reasons, and bind retry to the job ID. */
	public function test_result_table_is_escaped_paginated_and_job_bound() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = wp_generate_uuid4();
		$html = ( new ReflectionMethod( 'HSPSC_Rebuild_Admin', 'get_report_html' ) )->invoke(
			null,
			array(
				'id'               => $id,
				'state'            => 'completed',
				'total'            => 60,
				'processed'        => 60,
				'cached'           => 59,
				'failed'           => 1,
				'discovery_failed' => 1,
				'retryable'        => true,
				'page'             => 2,
				'pages'            => 3,
				'results'          => array(
					array( 'url' => home_url( '/test/?value=<script>alert(1)</script>' ), 'status' => 'failed', 'reason' => 'write-failed', 'type' => 'page' ),
					array( 'url' => '/srv/secret/database.sql', 'status' => 'failed', 'reason' => '/srv/private/debug', 'type' => 'sitemap' ),
				),
			)
		);
		$this->assertStringContainsString( '59 cached, 0 skipped, 1 failed.', $html );
		$this->assertStringContainsString( '1 sitemap could not be fully read.', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '/srv/', $html );
		$this->assertStringContainsString( 'The cache entry could not be saved.', $html );
		$this->assertStringContainsString( 'hspsc_rebuild_page=1#hspsc-rebuild-report', $html );
		$this->assertStringContainsString( 'hspsc_rebuild_page=3#hspsc-rebuild-report', $html );
		$this->assertStringContainsString( 'name="job_id" value="' . $id . '"', $html );
		$this->assertStringContainsString( wp_create_nonce( 'hspsc_rebuild_retry_' . $id ), $html );
	}

	/** The frontend notice receives a job-bound nonce only for retryable completion. */
	public function test_notice_retry_nonce_is_limited_to_retryable_terminal_jobs() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$view = new ReflectionMethod( 'HSPSC_Rebuild_Admin', 'get_notice_summary' );
		$id   = wp_generate_uuid4();
		$job  = array( 'id' => $id, 'state' => 'failed', 'failed' => 1, 'retryable' => true );
		$data = $view->invoke( null, $job );
		$this->assertNotFalse( wp_verify_nonce( $data['retryNonce'], 'hspsc_rebuild_retry_' . $id ) );
		$this->assertFalse( wp_verify_nonce( $data['retryNonce'], 'hspsc_rebuild_retry_' . wp_generate_uuid4() ) );
		$job['state'] = 'running';
		$this->assertSame( '', $view->invoke( null, $job )['retryNonce'] );
		$job['state'] = 'completed';
		$job['retryable'] = false;
		$this->assertSame( '', $view->invoke( null, $job )['retryNonce'] );
	}

	/** Unknown engine errors cannot put internal diagnostics in an action redirect. */
	public function test_error_redirect_exposes_only_allowlisted_feedback_code() {
		$url = HSPSC_Rebuild_Admin::start_redirect_url( new WP_Error( 'unexpected_error', 'Could not read /srv/private/diagnostic' ), home_url( '/' ) );
		$this->assertStringContainsString( 'hspsc_rebuild_error=hspsc_rebuild_error#hspsc-rebuild-report', $url );
		$this->assertStringNotContainsString( '/srv/', $url );
		$this->assertStringNotContainsString( 'unexpected_error', $url );
	}

	private function call_ajax( $method, $fields, $class = 'HSPSC_Rebuild_Admin' ) {
		$_POST    = $fields;
		$_REQUEST = $fields;
		$handler  = static function () {
			return static function () {
				throw new HSPSC_Rebuild_Admin_Test_Exit();
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $handler );
		ob_start();
		try {
			call_user_func( array( $class, $method ) );
			$this->fail( 'Expected a terminating JSON response.' );
		} catch ( HSPSC_Rebuild_Admin_Test_Exit $exit ) {
			return json_decode( ob_get_contents(), true );
		} finally {
			ob_end_clean();
			remove_filter( 'wp_die_ajax_handler', $handler );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
	}
}
