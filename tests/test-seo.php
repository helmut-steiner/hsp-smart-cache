<?php
/**
 * Regression coverage for optional Slim SEO integrations.
 *
 * @package HSPSC
 */

/**
 * Exercises source-backed WordPress mutation contracts without SEO packages.
 */
class HSPSC_SEO_Test extends WP_UnitTestCase {
	private $page_file;
	private $asset_file;
	private $object_file;
	private $post_id;
	private $term_id;

	/**
	 * Creates independent cache sentinels and ordinary WordPress content.
	 */
	public function set_up(): void {
		parent::set_up();
		HSPSC_SEO::init();
		HSPSC_Utils::ensure_cache_dirs();
		$this->post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->term_id     = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->page_file   = HSPSC_PATH . '/pages/seo-regression.html';
		$this->asset_file  = HSPSC_PATH . '/assets/seo-regression.css';
		$this->object_file = HSPSC_PATH . '/object/seo-regression.txt';
		$this->seed_caches();
	}

	/**
	 * Removes only owned test files; WordPress restores hooks and metadata.
	 */
	public function tear_down(): void {
		foreach ( array( $this->page_file, $this->asset_file, $this->object_file ) as $file ) {
			wp_delete_file( $file );
		}
		parent::tear_down();
	}

	private function seed_caches() {
		file_put_contents( $this->page_file, 'Cached SEO output' );
		file_put_contents( $this->asset_file, 'body { color: black; }' );
		file_put_contents( $this->object_file, 'Shared object sentinel' );
	}

	private function assert_only_pages_cleared() {
		$this->assertFileDoesNotExist( $this->page_file );
		$this->assertFileExists( $this->asset_file );
		$this->assertFileExists( $this->object_file );
	}

	public function test_global_settings_and_redirect_option_lifecycle_clear_pages_only() {
		$options = array( 'slim_seo', 'ss_redirects', 'slim_seo_pro', 'slim_seo_schemas', 'slim_seo_link_manager_settings', 'ssp_auto_link_settings', 'ssp_entities_settings' );
		foreach ( $options as $option ) {
			delete_option( $option );
			$this->seed_caches();
			add_option( $option, array( 'fixture' => 'initial' ) );
			$this->assert_only_pages_cleared();

			$this->seed_caches();
			update_option( $option, array( 'fixture' => 'changed' ) );
			$this->assert_only_pages_cleared();

			$this->seed_caches();
			delete_option( $option );
			$this->assert_only_pages_cleared();
		}
	}

	public function test_direct_post_meta_changes_invalidate_cached_metadata_and_schema() {
		$keys = array( 'slim_seo', 'slim_seo_schema', 'sss_allow_global', 'slim_seo_pro', 'slim_seo_pro_auto_link', 'slim_seo_pro_entities', '_slim_seo_primary_term_category' );
		foreach ( $keys as $key ) {
			$this->seed_caches();
			add_post_meta( $this->post_id, $key, array( 'fixture' => 'initial' ), true );
			$this->assert_only_pages_cleared();

			$this->seed_caches();
			update_post_meta( $this->post_id, $key, array( 'fixture' => 'changed' ) );
			$this->assert_only_pages_cleared();

			$this->seed_caches();
			delete_post_meta( $this->post_id, $key );
			$this->assert_only_pages_cleared();
		}
	}

	public function test_direct_term_meta_lifecycle_invalidates_cached_archive_metadata() {
		add_term_meta( $this->term_id, 'slim_seo', array( 'title' => 'Initial archive title' ) );
		$this->assert_only_pages_cleared();
		$this->seed_caches();
		update_term_meta( $this->term_id, 'slim_seo', array( 'title' => 'Changed archive title' ) );
		$this->assert_only_pages_cleared();
		$this->seed_caches();
		delete_term_meta( $this->term_id, 'slim_seo' );
		$this->assert_only_pages_cleared();
	}

	public function test_scanner_counters_analytics_and_unrelated_meta_keep_cached_pages() {
		foreach ( array( 'sslm_links_cache', 'ssp_auto_link_db_version', 'ssp_entities_db_version', 'slim_seo_link_manager_total_links', 'slim_seo_link_manager_scan_log', 'slim_seo_pro_gsc_token', 'unrelated_seo_setting' ) as $option ) {
			update_option( $option, array( 'fixture' => 'changed' ) );
		}
		update_post_meta( $this->post_id, '_edit_lock', '123:456' );
		update_post_meta( $this->post_id, '_slim_seo_primary_term_', 123 );
		update_term_meta( $this->term_id, 'unrelated_seo_setting', 'changed' );
		$this->assertFileExists( $this->page_file );
		$this->assertFileExists( $this->asset_file );
		$this->assertFileExists( $this->object_file );
	}

	public function test_link_manager_builder_mutations_run_after_source_updates() {
		foreach ( array( 'slim_seo_link_manager_update_link_url', 'slim_seo_link_manager_unlink' ) as $hook ) {
			$this->assertSame( PHP_INT_MAX, has_action( $hook, array( 'HSPSC_SEO', 'clear_pages' ) ) );
			$this->seed_caches();
			do_action( $hook, array( 'source_id' => $this->post_id, 'location' => 'bricks' ), 'https://example.org/old', 'https://example.org/new' );
			$this->assert_only_pages_cleared();
		}
	}

	public function test_successful_custom_table_rest_mutations_preserve_the_original_response() {
		$routes = array( '/slim-seo-pro/auto_link/update_keyword', '/slim-seo-pro/auto_link/delete_keywords', '/slim-seo-pro/entities/update', '/slim-seo-pro/entities/delete/' );
		foreach ( $routes as $route ) {
			$this->seed_caches();
			$request  = new WP_REST_Request( 'POST', $route );
			$response = new WP_REST_Response( 123, 200 );
			$result   = apply_filters( 'rest_request_after_callbacks', $response, array(), $request );
			$this->assertSame( $response, $result );
			$this->assert_only_pages_cleared();
		}
	}

	public function test_failed_readonly_and_unrelated_rest_callbacks_preserve_cached_pages() {
		$cases = array(
			array( 'POST', '/slim-seo-pro/entities/update', new WP_Error( 'forbidden' ) ),
			array( 'POST', '/slim-seo-pro/entities/update', new WP_REST_Response( true, 403 ) ),
			array( 'POST', '/slim-seo-pro/entities/update', new WP_REST_Response( 0, 200 ) ),
			array( 'POST', '/slim-seo-pro/entities/update', new WP_REST_Response( false, 200 ) ),
			array( 'GET', '/slim-seo-pro/entities/update', new WP_REST_Response( true, 200 ) ),
			array( 'OPTIONS', '/slim-seo-pro/entities/update', new WP_REST_Response( true, 200 ) ),
			array( 'POST', '/slim-seo-pro/entities/list', new WP_REST_Response( true, 200 ) ),
			array( 'POST', '/slim-seo-pro/entities/update-extra', new WP_REST_Response( true, 200 ) ),
			array( 'POST', '/slim-seo-pro/analytics/update', new WP_REST_Response( true, 200 ) ),
		);
		foreach ( $cases as $case ) {
			$request = new WP_REST_Request( $case[0], $case[1] );
			$this->assertSame( $case[2], apply_filters( 'rest_request_after_callbacks', $case[2], array(), $request ) );
			$this->assertFileExists( $this->page_file );
		}
	}

	public function test_slim_seo_json_ld_and_inline_payloads_survive_html_optimization() {
		$json = '<script type="application/ld+json">{"@context":"https://schema.org","name":"A <!-- keep --> > < B","description":"/* not a comment */"}</script>';
		$this->assertSame( $json, HSPSC_Minify::minify_html( $json ) );
	}
}
