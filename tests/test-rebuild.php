<?php

class HSPSC_Rebuild_Test extends WP_UnitTestCase {
	private $original_server;
	private $sitemaps = array();
	private $failing_pages = array();
	private $requests = array();
	private $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

	public function set_up(): void {
		parent::set_up();
		$this->original_server = $_SERVER;
		$_SERVER['HTTP_ACCEPT'] = $this->accept;
		$this->assertTrue( HSPSC_Rebuild::reset() );
		HSPSC_Utils::ensure_cache_dirs();
		$this->configure_sitemap( home_url( '/rebuild-sitemap.xml' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		HSPSC_Rebuild::reset();
		HSPSC_Page::clear_public_cache();
		$_SERVER = $this->original_server;
		HSPSC_Settings::reset_cache();
		parent::tear_down();
	}

	private function configure_sitemap( $url ) {
		update_option(
			HSPSC_Settings::OPTION_KEY,
			array_merge(
				HSPSC_Settings::defaults(),
				array( 'page_cache' => true, 'preload_sitemap_url' => $url )
			)
		);
	}

	public function test_full_job_discovers_more_than_fifty_pages_and_survives_object_cache_flush() {
		$root = home_url( '/rebuild-sitemap.xml' );
		$leaf_one = home_url( '/rebuild-pages-one.xml' );
		$leaf_two = home_url( '/rebuild-pages-two.xml' );
		$one = array( untrailingslashit( home_url( '/' ) ) );
		$two = array();
		for ( $index = 0; $index < 60; ++$index ) {
			if ( $index < 30 ) {
				$one[] = home_url( '/rebuild-page-' . $index . '/' );
			} else {
				$two[] = home_url( '/rebuild-page-' . $index . '/' );
			}
		}
		$two[] = $one[1];
		$this->sitemaps = array(
			$root => $this->xml( 'sitemapindex', array( $leaf_one, $leaf_two, $root ) ),
			$leaf_one => $this->xml( 'urlset', $one ),
			$leaf_two => $this->xml( 'urlset', $two ),
		);

		$job = HSPSC_Rebuild::start();
		$this->assertIsArray( $job );
		$this->assertSame( 'discovering', $job['state'] );
		$this->assertSame( 1, $job['total'] );
		$this->assertSame( array(), $this->requests );
		$this->assertArrayNotHasKey( HSPSC_Rebuild::OPTION, wp_load_alloptions() );
		$this->assertNotFalse( wp_next_scheduled( HSPSC_Rebuild::HOOK, array( $job['id'] ) ) );

		$_SERVER['HTTP_ACCEPT'] = '*/*';
		$first = HSPSC_Rebuild::run_batch( $job['id'] );
		$this->assertIsArray( $first );
		$this->assertLessThanOrEqual( HSPSC_Rebuild::BATCH_SIZE, count( $this->requests ) );
		wp_cache_flush();
		$this->assertSame( $job['id'], HSPSC_Rebuild::get_status()['id'] );

		$complete = $this->drain( $job['id'] );
		$this->assertSame( 'completed', $complete['state'] );
		$this->assertSame( 61, $complete['total'] );
		$this->assertSame( 61, $complete['processed'] );
		$this->assertSame( 61, $complete['cached'] );
		$this->assertTrue( $complete['discovery_complete'] );
		$this->assertCount( 25, $complete['results'] );
		$this->assertSame( 3, $complete['pages'] );
		$last = HSPSC_Rebuild::get_status( 99 );
		$this->assertSame( 3, $last['page'] );
		$this->assertCount( 11, $last['results'] );
		$this->assertFalse( wp_next_scheduled( HSPSC_Rebuild::HOOK, array( $job['id'] ) ) );
		$this->assertCount( 64, $this->requests );
		foreach ( array_slice( $this->requests, 3 ) as $request ) {
			$this->assertSame( $this->accept, $request['args']['headers']['Accept'] );
			$this->assertSame( array(), $request['args']['cookies'] );
			$this->assertSame( 0, $request['args']['redirection'] );
		}
		$this->assertArrayNotHasKey( 'accept', $complete );
	}

	public function test_retry_processes_failed_sitemap_and_page_only_and_preserves_public_files() {
		$root = home_url( '/rebuild-sitemap.xml' );
		$good_leaf = home_url( '/rebuild-good-leaf.xml' );
		$bad_leaf = add_query_arg( array( 'paged' => 1, 'sitemap' => 'posts', 'sitemap-subtype' => 'post' ), home_url( '/' ) );
		$good_page = add_query_arg( 'cat', 7, home_url( '/' ) );
		$bad_page = add_query_arg( 'author', 8, home_url( '/' ) );
		$new_page = home_url( '/rebuild-newly-discovered-page/' );
		$private = add_query_arg( array( 'token' => 'mock-secret-value', 'cat' => 'not-a-number', 'sitemap' => 'invalid/value' ), home_url( '/private-link/' ) );
		$this->sitemaps = array(
			$root => $this->xml( 'sitemapindex', array( $good_leaf, $bad_leaf ) ),
			$good_leaf => $this->xml( 'urlset', array( $good_page, $bad_page, $private ) ),
			$bad_leaf => new WP_Error( 'fixture_http_error', 'internal HTTP error text' ),
		);
		$this->failing_pages[ $bad_page ] = true;

		$job = HSPSC_Rebuild::start();
		$this->assertIsArray( $job );
		$failed = $this->drain( $job['id'] );
		$this->assertSame( 'failed', $failed['state'] );
		$this->assertSame( 2, $failed['cached'] );
		$this->assertSame( 1, $failed['failed'] );
		$this->assertSame( 1, $failed['skipped'] );
		$this->assertFalse( $failed['discovery_complete'] );
		$this->assertSame( 1, $failed['discovery_failed'] );
		$this->assertTrue( $failed['retryable'] );
		$this->assertNotContains( $private, array_column( $this->requests, 'url' ) );
		$this->assertStringNotContainsString( 'mock-secret-value', wp_json_encode( $failed ) );
		$this->assertStringNotContainsString( 'internal HTTP error text', wp_json_encode( $failed ) );
		$reported = array_column( $failed['results'], 'url' );
		$this->assertContains( $good_page, $reported );
		$this->assertContains( $bad_page, $reported );
		$this->assertContains( $bad_leaf, $reported );
		$this->assertContains( home_url( '/private-link/' ), $reported );
		$good_file = HSPSC_Page::get_cache_file_path_for_url( $good_page );
		$this->assertFileExists( $good_file );
		file_put_contents( $good_file, 'already successful public page' );

		$this->requests = array();
		$this->sitemaps[ $bad_leaf ] = $this->xml( 'urlset', array( $bad_page, $new_page ) );
		$this->failing_pages = array();
		$this->assertTrue( wp_schedule_single_event( time() + 60, HSPSC_Rebuild::HOOK, array( $job['id'] ) ) );
		$retry = HSPSC_Rebuild::retry( $job['id'] );
		$this->assertIsArray( $retry );
		$this->assertNotSame( $job['id'], $retry['id'] );
		$this->assertSame( 'discovering', $retry['state'] );
		$this->assertSame( 2, $retry['cached'] );
		$this->assertFalse( wp_next_scheduled( HSPSC_Rebuild::HOOK, array( $job['id'] ) ) );
		$this->assertNotFalse( wp_next_scheduled( HSPSC_Rebuild::HOOK, array( $retry['id'] ) ) );
		$this->assertSame( 'hspsc_rebuild_stale_job', HSPSC_Rebuild::run_batch( $job['id'] )->get_error_code() );
		$this->assertSame( 'hspsc_rebuild_stale_job', HSPSC_Rebuild::retry( $job['id'] )->get_error_code() );
		$this->assertSame( array(), $this->requests );
		$complete = $this->drain( $retry['id'] );

		$this->assertSame( 'completed', $complete['state'] );
		$this->assertTrue( $complete['discovery_complete'] );
		$this->assertSame( 4, $complete['cached'] );
		$this->assertSame( 1, $complete['skipped'] );
		$this->assertSame( array( $bad_leaf, $bad_page, $new_page ), array_column( $this->requests, 'url' ) );
		$this->assertSame( 'already successful public page', file_get_contents( $good_file ) );
	}

	public function test_explicit_navigation_accept_is_validated_and_used_without_changing_request_state() {
		$url = home_url( '/rebuild-ajax-navigation-accept/' );
		$_SERVER['HTTP_ACCEPT'] = '*/*';
		foreach ( array( array( 'text/html' ), "text/html\r\nInjected: value" ) as $invalid ) {
			$result = HSPSC_Rebuild::start( $url, $invalid );
			$this->assertWPError( $result );
			$this->assertSame( 'hspsc_rebuild_invalid_accept', $result->get_error_code() );
		}
		$this->assertSame( array(), HSPSC_Rebuild::get_status() );
		$job = HSPSC_Rebuild::start( $url, $this->accept );
		$this->assertIsArray( $job );
		$this->assertSame( '*/*', $_SERVER['HTTP_ACCEPT'] );
		$this->assertSame( 'completed', $this->drain( $job['id'] )['state'] );
		$this->assertSame( $this->accept, $this->requests[0]['args']['headers']['Accept'] );
		$this->assertSame( '*/*', $_SERVER['HTTP_ACCEPT'] );
	}

	public function test_targeted_job_removes_all_public_variants_of_only_the_target() {
		$url = home_url( '/rebuild-target/' );
		$file = HSPSC_Page::get_cache_file_path_for_url( $url );
		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/webp';
		$other_variant = HSPSC_Page::get_cache_file_path_for_url( $url );
		$_SERVER['HTTP_ACCEPT'] = $this->accept;
		$unrelated = HSPSC_Page::get_cache_file_path_for_url( home_url( '/unrelated-rebuild-page/' ) );
		$private = HSPSC_PATH . '/pages/private/rebuild-private-marker.php';
		$asset = HSPSC_PATH . '/assets/rebuild-preserve.min.js';
		$object = HSPSC_PATH . '/object/rebuild-preserve.php';
		wp_mkdir_p( dirname( $private ) );
		foreach ( array( $file, $other_variant, $unrelated, $private, $asset, $object ) as $marker ) {
			file_put_contents( $marker, '<?php exit; // existing file' );
		}

		try {
			$this->assertWPError( HSPSC_Rebuild::start( admin_url( 'options-general.php' ) ) );
			$this->assertWPError( HSPSC_Rebuild::start( 'https://outside.example/rebuild/' ) );
			$this->assertSame( array(), HSPSC_Rebuild::get_status() );
			$this->assertFileExists( $file );
			$job = HSPSC_Rebuild::start( $url );
			$this->assertIsArray( $job );
			$this->assertSame( 'running', $job['state'] );
			$this->assertSame( 'hspsc_rebuild_active', HSPSC_Rebuild::start()->get_error_code() );
			$stale = HSPSC_Rebuild::run_batch( wp_generate_uuid4() );
			$this->assertWPError( $stale );
			$this->assertSame( 'hspsc_rebuild_stale_job', $stale->get_error_code() );
			$this->assertFileExists( $other_variant );
			$this->assertSame( array(), $this->requests );

			$complete = $this->drain( $job['id'] );
			$this->assertSame( 'completed', $complete['state'] );
			$this->assertSame( 1, $complete['cached'] );
			$this->assertSame( array( $url ), array_column( $this->requests, 'url' ) );
			$this->assertFileDoesNotExist( $other_variant );
			$this->assertSame( $this->html(), file_get_contents( $file ) );
			foreach ( array( $unrelated, $private, $asset, $object ) as $marker ) {
				$this->assertFileExists( $marker );
			}
		} finally {
			foreach ( array( $file, $other_variant, $unrelated, $private, $asset, $object ) as $marker ) {
				wp_delete_file( $marker );
			}
		}
	}

	public function test_interrupted_worker_leaves_a_checkpoint_and_scheduled_recovery() {
		$url = home_url( '/rebuild-interrupted/' );
		$job = HSPSC_Rebuild::start( $url );
		$this->assertIsArray( $job );
		$args = array( $job['id'] );
		wp_clear_scheduled_hook( HSPSC_Rebuild::HOOK, $args );
		$abort = function () use ( $args ) {
			$this->assertNotFalse( wp_next_scheduled( HSPSC_Rebuild::HOOK, $args ) );
			$this->assertNotNull( get_option( HSPSC_Rebuild::OPTION )['in_flight'] );
			throw new RuntimeException( 'worker interrupted' );
		};
		add_filter( 'pre_http_request', $abort, 5 );
		try {
			HSPSC_Rebuild::run_batch( $job['id'] );
			$this->fail( 'The simulated interruption was not observed.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'worker interrupted', $error->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $abort, 5 );
		}

		$recovered = HSPSC_Rebuild::run_batch( $job['id'] );
		$this->assertIsArray( $recovered );
		$this->assertSame( 'failed', $recovered['state'] );
		$this->assertSame( 'worker-interrupted', $recovered['results'][0]['reason'] );
		$this->assertSame( array(), $this->requests );
		$this->assertTrue( $recovered['retryable'] );

		$retry = HSPSC_Rebuild::retry( $job['id'] );
		$this->assertIsArray( $retry );
		$this->assertSame( 'completed', $this->drain( $retry['id'] )['state'] );
		$this->assertSame( array( $url ), array_column( $this->requests, 'url' ) );
	}

	public function test_worker_bypasses_a_stale_negative_option_cache_entry() {
		$url = home_url( '/rebuild-option-refresh/' );
		$job = HSPSC_Rebuild::start( $url );
		$this->assertIsArray( $job );
		$missing = wp_cache_get( 'notoptions', 'options' );
		$missing = is_array( $missing ) ? $missing : array();
		wp_cache_set( 'notoptions', array( HSPSC_Rebuild::OPTION => true ) + $missing, 'options' );
		$this->assertSame( array(), HSPSC_Rebuild::get_status() );
		try {
			$this->assertSame( 'completed', $this->drain( $job['id'] )['state'] );
			$this->assertSame( array( $url ), array_column( $this->requests, 'url' ) );
		} finally {
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	public function test_sitemap_depth_bound_is_reported_as_incomplete_discovery() {
		$root = home_url( '/rebuild-depth-0.xml' );
		$this->configure_sitemap( $root );
		for ( $level = 0; $level <= HSPSC_Rebuild::MAX_SITEMAP_DEPTH; ++$level ) {
			$this->sitemaps[ home_url( '/rebuild-depth-' . $level . '.xml' ) ] = $this->xml(
				'sitemapindex',
				array( home_url( '/rebuild-depth-' . ( $level + 1 ) . '.xml' ) )
			);
		}
		$job = HSPSC_Rebuild::start();
		$this->assertIsArray( $job );
		$result = $this->drain( $job['id'] );
		$this->assertSame( 'failed', $result['state'] );
		$this->assertSame( 1, $result['discovery_failed'] );
		$this->assertFalse( $result['discovery_complete'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( 'sitemap-depth-limit', $result['results'][0]['reason'] );
		$this->assertSame( 'sitemap', $result['results'][0]['type'] );
		$this->assertCount( HSPSC_Rebuild::MAX_SITEMAP_DEPTH + 2, $this->requests );
	}

	public function test_sitemap_document_cap_reports_omitted_discovery() {
		$root = home_url( '/rebuild-sitemap.xml' );
		$children = array();
		for ( $index = 0; $index <= HSPSC_Rebuild::MAX_SITEMAPS; ++$index ) {
			$url = home_url( '/rebuild-child-' . $index . '.xml' );
			$children[] = $url;
			$this->sitemaps[ $url ] = '<urlset/>';
		}
		$this->sitemaps[ $root ] = $this->xml( 'sitemapindex', $children );
		$job = HSPSC_Rebuild::start();
		$this->assertIsArray( $job );
		$result = HSPSC_Rebuild::run_batch( $job['id'] );
		$this->assertIsArray( $result );
		$this->assertSame( 'discovering', $result['state'] );
		$this->assertSame( 1, $result['discovery_failed'] );
		$this->assertSame( 'sitemap-count-limit', $result['results'][0]['reason'] );
		$this->assertSame( HSPSC_Rebuild::MAX_SITEMAPS, count( get_option( HSPSC_Rebuild::OPTION )['sitemaps'] ) );
	}

	public function test_busy_worker_keeps_a_cron_continuation_without_duplicate_requests() {
		$job = HSPSC_Rebuild::start( home_url( '/rebuild-lock-target/' ) );
		$this->assertIsArray( $job );
		$args = array( $job['id'] );
		wp_clear_scheduled_hook( HSPSC_Rebuild::HOOK, $args );
		$handle = fopen( HSPSC_PATH . '/.rebuild-lock', 'c+b' );
		$this->assertIsResource( $handle );
		try {
			$this->assertTrue( flock( $handle, LOCK_EX ) );
			$busy = HSPSC_Rebuild::run_batch( $job['id'] );
			$this->assertWPError( $busy );
			$this->assertSame( 'hspsc_rebuild_busy', $busy->get_error_code() );
			$this->assertSame( array(), $this->requests );
			$this->assertNotFalse( wp_next_scheduled( HSPSC_Rebuild::HOOK, $args ) );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
		$this->assertSame( 'completed', $this->drain( $job['id'] )['state'] );
	}

	public function test_start_saves_the_job_before_scheduling_and_records_scheduler_failure() {
		$url = home_url( '/rebuild-schedule-failure/' );
		$file = HSPSC_Page::get_cache_file_path_for_url( $url );
		file_put_contents( $file, 'existing cached page' );
		$scheduled_id = '';
		$filter = function ( $preempt, $event ) use ( &$scheduled_id ) {
			if ( HSPSC_Rebuild::HOOK !== $event->hook ) {
				return $preempt;
			}
			$scheduled_id = $event->args[0];
			$this->assertSame( $scheduled_id, HSPSC_Rebuild::get_status()['id'] );
			return false;
		};
		add_filter( 'pre_schedule_event', $filter, 10, 2 );
		try {
			$result = HSPSC_Rebuild::start( $url );
			$this->assertWPError( $result );
			$this->assertSame( 'hspsc_rebuild_schedule', $result->get_error_code() );
			$status = HSPSC_Rebuild::get_status();
			$this->assertSame( $scheduled_id, $status['id'] );
			$this->assertSame( 'failed', $status['state'] );
			$this->assertSame( 'schedule-failed', $status['failure'] );
			$this->assertGreaterThan( 0, $status['finished_at'] );
			$this->assertSame( 'existing cached page', file_get_contents( $file ) );
			$this->assertSame( array(), $this->requests );
		} finally {
			remove_filter( 'pre_schedule_event', $filter, 10 );
		}
	}

	public function test_consumed_event_with_failed_successor_preserves_progress_and_releases_the_job() {
		$root = home_url( '/rebuild-sitemap.xml' );
		$pages = array();
		for ( $index = 0; $index < HSPSC_Rebuild::BATCH_SIZE + 1; ++$index ) {
			$pages[] = home_url( '/rebuild-schedule-interrupted-' . $index . '/' );
		}
		$this->sitemaps[ $root ] = $this->xml( 'urlset', $pages );
		$job = HSPSC_Rebuild::start();
		$this->assertIsArray( $job );
		$before = HSPSC_Rebuild::run_batch( $job['id'] );
		$this->assertIsArray( $before );
		$this->assertSame( 'running', $before['state'] );
		$this->assertGreaterThan( 0, $before['cached'] );
		$requests = $this->requests;
		wp_clear_scheduled_hook( HSPSC_Rebuild::HOOK, array( $job['id'] ) );
		$filter = static function ( $preempt, $event ) {
			return HSPSC_Rebuild::HOOK === $event->hook ? false : $preempt;
		};
		add_filter( 'pre_schedule_event', $filter, 10, 2 );
		try {
			$result = HSPSC_Rebuild::run_batch( $job['id'] );
			$this->assertWPError( $result );
			$this->assertSame( 'hspsc_rebuild_schedule', $result->get_error_code() );
			$status = HSPSC_Rebuild::get_status();
			$this->assertSame( 'failed', $status['state'] );
			$this->assertSame( 'schedule-failed', $status['failure'] );
			$this->assertSame( $before['cached'], $status['cached'] );
			$this->assertSame( $before['results'], $status['results'] );
			$this->assertSame( $requests, $this->requests );
			$this->assertFalse( wp_next_scheduled( HSPSC_Rebuild::HOOK, array( $job['id'] ) ) );
		} finally {
			remove_filter( 'pre_schedule_event', $filter, 10 );
		}
		$replacement = HSPSC_Rebuild::start( $pages[0] );
		$this->assertIsArray( $replacement );
		$this->assertNotSame( $job['id'], $replacement['id'] );
	}

	private function drain( $id ) {
		for ( $attempt = 0; $attempt < 100; ++$attempt ) {
			$result = HSPSC_Rebuild::run_batch( $id );
			$this->assertIsArray( $result );
			if ( in_array( $result['state'], array( 'completed', 'failed' ), true ) ) {
				return $result;
			}
		}
		$this->fail( 'The fixture rebuild did not complete within the batch bound.' );
	}

	private function xml( $kind, $urls ) {
		$entry = 'sitemapindex' === $kind ? 'sitemap' : 'url';
		$body = '<' . $kind . '>';
		foreach ( $urls as $url ) {
			$body .= '<' . $entry . '><loc>' . htmlspecialchars( $url, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</loc></' . $entry . '>';
		}
		return $body . '</' . $kind . '>';
	}

	private function html() {
		return '<!doctype html><html><body><p>Verified rebuilt page</p></body></html>';
	}

	public function mock_http( $preempt, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		if ( isset( $this->sitemaps[ $url ] ) ) {
			$fixture = $this->sitemaps[ $url ];
			return is_wp_error( $fixture ) ? $fixture : array( 'response' => array( 'code' => 200 ), 'body' => $fixture );
		}
		if ( isset( $this->failing_pages[ $url ] ) ) {
			return new WP_Error( 'fixture_page_error', 'internal HTTP error text' );
		}
		$original_accept = $_SERVER['HTTP_ACCEPT'];
		$_SERVER['HTTP_ACCEPT'] = $args['headers']['Accept'];
		try {
			$file = HSPSC_Page::get_cache_file_path_for_url( $url );
		} finally {
			$_SERVER['HTTP_ACCEPT'] = $original_accept;
		}
		$this->assertIsString( $file );
		$this->assertTrue( HSPSC_Utils::atomic_write( $file, $this->html() ) );
		return array(
			'response' => array( 'code' => 200 ),
			'headers' => array( 'content-type' => 'text/html; charset=UTF-8', 'x-hspsc-cache' => 'MISS' ),
			'body' => $this->html(),
			'cookies' => array(),
		);
	}
}
