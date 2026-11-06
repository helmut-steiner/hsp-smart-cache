<?php

require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

class HSPSC_Admin_Test extends WP_UnitTestCase {
    private $original_get;
    private $original_request;
    private $original_server;

    public function set_up(): void {
        parent::set_up();
        HSPSC_Utils::ensure_cache_dirs();

        $this->original_get = $_GET;
        $this->original_request = $_REQUEST;
        $this->original_server = $_SERVER;

        add_filter( 'hspsc_skip_admin_exit', '__return_true' );
    }

    public function tear_down(): void {
        remove_filter( 'hspsc_skip_admin_exit', '__return_true' );

        $_GET = $this->original_get;
        $_REQUEST = $this->original_request;
        $_SERVER = $this->original_server;

        parent::tear_down();
    }

	/**
	 * Settings assets are absent from unrelated screens and unauthorized requests.
	 */
	public function test_settings_assets_are_restricted_to_the_authorized_screen() {
		$this->with_fresh_asset_registries(
			function () {
				wp_set_current_user( 0 );
				HSPSC_Admin::enqueue_settings_assets( 'settings_page_hsp-smart-cache' );
				$this->assertFalse( wp_style_is( 'hspsc-admin', 'enqueued' ) );
				$this->assertFalse( wp_script_is( 'hspsc-admin', 'enqueued' ) );

				wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
				HSPSC_Admin::enqueue_settings_assets( 'dashboard' );
				$this->assertFalse( wp_style_is( 'hspsc-admin', 'enqueued' ) );
				$this->assertFalse( wp_script_is( 'hspsc-admin', 'enqueued' ) );
			}
		);
	}

	/**
	 * The settings screen receives versioned files and safely encoded translations.
	 */
	public function test_settings_assets_include_versioned_files_and_localized_config() {
		$this->with_fresh_asset_registries(
			function () {
				wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
				$translation = static function ( $translated, $text, $domain ) {
					if ( 'hsp-smart-cache' === $domain && 'Action failed. Please try again.' === $text ) {
						return 'L’action a échoué. "Réessayez" <test>.';
					}
					return $translated;
				};
				add_filter( 'gettext', $translation, 10, 3 );
				try {
					HSPSC_Admin::enqueue_settings_assets( 'settings_page_hsp-smart-cache' );
				} finally {
					remove_filter( 'gettext', $translation, 10 );
				}

				$this->assertTrue( wp_style_is( 'hspsc-admin', 'enqueued' ) );
				$this->assertTrue( wp_script_is( 'hspsc-admin', 'enqueued' ) );
				$style  = wp_styles()->registered['hspsc-admin'];
				$script = wp_scripts()->registered['hspsc-admin'];
				$this->assertSame( HSPSC_VERSION, $style->ver );
				$this->assertSame( HSPSC_VERSION, $script->ver );
				$this->assertStringEndsWith( '/assets/css/admin.css', $style->src );
				$this->assertStringEndsWith( '/assets/js/admin.js', $script->src );
				$this->assertSame( 1, wp_scripts()->get_data( 'hspsc-admin', 'group' ) );
				$data = wp_scripts()->get_data( 'hspsc-admin', 'data' );
				$this->assertSame( 1, preg_match( '/^var HSPSCAdmin = (.+);$/s', $data, $matches ) );
				$config = json_decode( $matches[1], true );
				$this->assertSame( HSPSC_Settings::OPTION_KEY, $config['optionKey'] );
				$this->assertSame( admin_url( 'admin-ajax.php' ), $config['ajaxUrl'] );
				$this->assertSame( 'L’action a échoué. "Réessayez" <test>.', $config['actionFailed'] );
				$this->assertSame( 'Action completed.', $config['actionCompleted'] );

				ob_start();
				HSPSC_Admin::render_settings_page();
				$html = ob_get_clean();
				$this->assertStringNotContainsString( '<script>', $html );
				$this->assertStringNotContainsString( '<style>', $html );
				$this->assertStringContainsString( 'id="hsp-htaccess-preview"', $html );
				$this->assertStringContainsString( 'id="hspsc-settings-form"', $html );
				$this->assertStringContainsString( 'id="hspsc-rebuild-report"', $html );
				$this->assertStringContainsString( 'Rebuild all public page caches', $html );
				$this->assertStringNotContainsString( '[preload_enabled]', $html );
				$this->assertStringNotContainsString( '[preload_limit]', $html );
			}
		);
	}

	/**
	 * The notice shell and action-specific nonces are limited to administrators.
	 */
	public function test_rebuild_notice_assets_require_manage_options() {
		$this->with_fresh_asset_registries(
			function () {
				set_current_screen( 'front' );
				wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
				HSPSC_Rebuild_Admin::enqueue_assets();
				$this->assertFalse( wp_style_is( 'hspsc-rebuild', 'enqueued' ) );
				$this->assertFalse( wp_script_is( 'hspsc-rebuild', 'enqueued' ) );
				ob_start();
				HSPSC_Rebuild_Admin::render_notice();
				$this->assertSame( '', ob_get_clean() );

				wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
				HSPSC_Rebuild_Admin::enqueue_assets();
				$this->assertTrue( wp_style_is( 'hspsc-rebuild', 'enqueued' ) );
				$this->assertTrue( wp_script_is( 'hspsc-rebuild', 'enqueued' ) );
				$this->assertFalse( wp_style_is( 'hspsc-admin', 'enqueued' ) );
				$this->assertFalse( wp_script_is( 'hspsc-admin', 'enqueued' ) );
				$data = wp_scripts()->get_data( 'hspsc-rebuild', 'data' );
				$this->assertSame( 1, preg_match( '/^var HSPSCRebuild = (.+);$/s', $data, $matches ) );
				$config = json_decode( $matches[1], true );
				$this->assertNotFalse( wp_verify_nonce( $config['statusNonce'], 'hspsc_rebuild_status' ) );
				$this->assertNotFalse( wp_verify_nonce( $config['tickNonce'], 'hspsc_rebuild_tick' ) );
				$this->assertArrayNotHasKey( 'job', $config );

				ob_start();
				HSPSC_Rebuild_Admin::render_notice();
				$html = ob_get_clean();
				$this->assertStringContainsString( 'id="hspsc-rebuild-notice"', $html );
				$this->assertStringContainsString( '#hspsc-rebuild-report', $html );
				$this->assertStringContainsString( 'aria-live="polite"', $html );
				$this->assertStringContainsString( 'name="job_id" value=""', $html );
				$this->assertStringContainsString( 'name="_wpnonce" value=""', $html );
				$this->assertStringNotContainsString( '<style>', $html );
			}
		);
	}

	/** The settings document provides exact navigation bytes for AJAX starts. */
	public function test_rebuild_settings_assets_capture_the_document_accept_header() {
		$this->with_fresh_asset_registries(
			function () {
				wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
				$accept = 'text/html;example="a&amp;b",application/xhtml+xml,image/webp,*/*;q=0.8';
				$_SERVER['HTTP_ACCEPT'] = wp_slash( $accept );
				HSPSC_Rebuild_Admin::enqueue_assets( 'settings_page_hsp-smart-cache' );
				$data = wp_scripts()->get_data( 'hspsc-rebuild', 'data' );
				$this->assertSame( 1, preg_match( '/^var HSPSCRebuild = (.+);$/s', $data, $matches ) );
				$config = json_decode( $matches[1], true );
				$this->assertSame( $accept, hex2bin( $config['navigationAccept'] ) );
			}
		);
	}

	/**
	 * Isolates enqueue assertions from other tests and restores user/screen state.
	 *
	 * @param callable $callback Assertions to run with fresh WordPress registries.
	 */
	private function with_fresh_asset_registries( $callback ) {
		$original_styles  = $GLOBALS['wp_styles'] ?? null;
		$original_scripts = $GLOBALS['wp_scripts'] ?? null;
		$original_screen  = $GLOBALS['current_screen'] ?? null;
		$original_user    = get_current_user_id();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated WordPress fixture, restored in finally.
		$GLOBALS['wp_styles'] = new WP_Styles();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated WordPress fixture, restored in finally.
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		try {
			$callback();
		} finally {
			wp_set_current_user( $original_user );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original WordPress fixture.
			$GLOBALS['wp_styles'] = $original_styles;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original WordPress fixture.
			$GLOBALS['wp_scripts'] = $original_scripts;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original screen after a frontend fixture.
			$GLOBALS['current_screen'] = $original_screen;
		}
	}

    public function test_init_registers_admin_hooks() {
        HSPSC_Admin::init();

        $this->assertNotFalse( has_action( 'admin_menu', array( 'HSPSC_Admin', 'register_menu' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hspsc_clear', array( 'HSPSC_Admin', 'handle_clear_cache' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hspsc_run_tests', array( 'HSPSC_Admin', 'handle_run_tests' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hspsc_analyze_db', array( 'HSPSC_Admin', 'handle_analyze_db' ) ) );
        $this->assertNotFalse( has_action( 'admin_bar_menu', array( 'HSPSC_Admin', 'register_admin_bar' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hspsc_create_db_backup', array( 'HSPSC_Admin', 'handle_create_db_backup' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hspsc_restore_db_backup', array( 'HSPSC_Admin', 'handle_restore_db_backup' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hspsc_delete_db_backup', array( 'HSPSC_Admin', 'handle_delete_db_backup' ) ) );
        $this->assertNotFalse( has_action( 'admin_post_hspsc_apply_bricks_preset', array( 'HSPSC_Admin', 'handle_apply_bricks_preset' ) ) );
        $this->assertNotFalse( has_action( 'wp_ajax_hspsc_save_settings', array( 'HSPSC_Admin', 'ajax_save_settings' ) ) );
        $this->assertNotFalse( has_action( 'wp_ajax_hspsc_clear', array( 'HSPSC_Admin', 'ajax_clear_cache' ) ) );
        $this->assertNotFalse( has_action( 'wp_ajax_hspsc_create_db_backup', array( 'HSPSC_Admin', 'ajax_create_db_backup' ) ) );
        $this->assertNotFalse( has_action( 'wp_ajax_hspsc_optimize_db', array( 'HSPSC_Admin', 'ajax_optimize_db' ) ) );
        $this->assertNotFalse( has_action( 'wp_ajax_hspsc_apply_bricks_preset', array( 'HSPSC_Admin', 'ajax_apply_bricks_preset' ) ) );
    }

    public function test_register_admin_bar_adds_main_cache_node_for_admin_user() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $bar = new WP_Admin_Bar();
        $bar->initialize();

        HSPSC_Admin::register_admin_bar( $bar );

        $this->assertNotNull( $bar->get_node( 'hspsc' ) );
        $this->assertStringContainsString( 'HSP Cache:', $bar->get_node( 'hspsc' )->title );
        $this->assertNotNull( $bar->get_node( 'hspsc-status' ) );
        $this->assertNotNull( $bar->get_node( 'hspsc-status-reason' ) );
        $this->assertNotNull( $bar->get_node( 'hspsc-clear-all' ) );

        wp_set_current_user( 0 );
    }

	/**
	 * A public cache file must not be presented as a hit for the admin's request.
	 */
	public function test_logged_in_toolbar_shows_bypass_with_separate_public_cache_status() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$file = $this->create_public_cache_for_toolbar();

		try {
			$bar = new WP_Admin_Bar();
			$bar->initialize();
			HSPSC_Admin::register_admin_bar( $bar );

			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			$this->assertSame( 'HSP Cache: BYPASS', $bar->get_node( 'hspsc' )->title );
			$this->assertSame( 'Status: BYPASS', $bar->get_node( 'hspsc-status' )->title );
			$this->assertStringContainsString( 'Logged-in page caching is disabled.', $bar->get_node( 'hspsc-status-reason' )->title );
			$this->assertStringContainsString( 'Public cache: HIT', $bar->get_node( 'hspsc-status-details' )->title );
		} finally {
			wp_delete_file( $file );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Anonymous URL diagnostics continue to recognize a fresh public cache file.
	 */
	public function test_public_cache_status_remains_hit_for_an_anonymous_request() {
		wp_set_current_user( 0 );
		$file = $this->create_public_cache_for_toolbar();

		try {
			$method = new ReflectionMethod( 'HSPSC_Admin', 'get_admin_bar_cache_status' );
			$method->setAccessible( true );
			$status = $method->invoke( null );

			$this->assertSame( 'HIT', $status['label'] );
			$this->assertSame( 'Fresh cache file exists for this URL.', $status['reason'] );
		} finally {
			wp_delete_file( $file );
		}
	}

	/**
	 * The logged-in frontend toolbar inspects the active session's private entry.
	 */
	public function test_toolbar_distinguishes_current_private_session_from_public_cache() {
		$original_options = HSPSC_Settings::get_all();
		$cookies          = $_COOKIE;
		$user             = get_current_user_id();
		$admin_id         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$public  = $this->create_public_cache_for_toolbar();
		$private = null;
		$method  = new ReflectionMethod( 'HSPSC_Admin', 'get_admin_bar_cache_status' );
		$method->setAccessible( true );
		try {
			update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'cache_logged_in' => true ) ) );
			file_put_contents( $public, '<html>Fresh public entry</html>' );
			$_COOKIE = array( LOGGED_IN_COOKIE => wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in', 'toolbar-session' ) );
			$this->assertTrue( HSPSC_Utils::is_request_cacheable() );
			$this->assertSame( 'MISS', $method->invoke( null )['label'] );

			$private = HSPSC_Page::get_cache_info_for_request()['file'];
			wp_mkdir_p( dirname( $private ) );
			file_put_contents( $private, HSPSC_Page::PRIVATE_FILE_GUARD . '<html>Fresh private entry</html>' );
			$status = $method->invoke( null );
			$this->assertSame( 'HIT', $status['label'] );
			$this->assertStringContainsString( 'response headers show the current request', $status['details'] );

			$_COOKIE['woocommerce_cart_hash'] = 'private-cart';
			$this->assertSame( 'BYPASS', $method->invoke( null )['label'] );
			unset( $_COOKIE['woocommerce_cart_hash'] );
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in', 'other-session' );
			$this->assertSame( 'MISS', $method->invoke( null )['label'] );
		} finally {
			if ( $private ) {
				wp_delete_file( $private );
			}
			wp_delete_file( $public );
			update_option( HSPSC_Settings::OPTION_KEY, $original_options );
			$_COOKIE = $cookies;
			wp_set_current_user( $user );
		}
	}

	/**
	 * Enabling logged-in frontend caching never changes the admin-screen status.
	 */
	public function test_settings_screen_toolbar_bypasses_enabled_logged_in_cache() {
		$original_options = HSPSC_Settings::get_all();
		$screen           = get_current_screen();
		$user             = get_current_user_id();
		try {
			update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'cache_logged_in' => true ) ) );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			set_current_screen( 'settings_page_hsp-smart-cache' );
			$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php?page=hsp-smart-cache';
			$bar = new WP_Admin_Bar();
			$bar->initialize();
			HSPSC_Admin::register_admin_bar( $bar );

			$this->assertSame( 'HSP Cache: BYPASS', $bar->get_node( 'hspsc' )->title );
			$this->assertStringContainsString( 'WordPress admin requests are not cached.', $bar->get_node( 'hspsc-status-reason' )->title );
			$this->assertNull( $bar->get_node( 'hspsc-clear-current' ) );
			$this->assertNull( $bar->get_node( 'hspsc-rebuild-current' ) );
		} finally {
			set_current_screen( $screen ?: 'front' );
			update_option( HSPSC_Settings::OPTION_KEY, $original_options );
			wp_set_current_user( $user );
		}
	}

	/**
	 * Path exclusions override cache-file diagnostics for every logged-in policy.
	 *
	 * @dataProvider excluded_path_toolbar_states
	 * @param bool   $cache_logged_in Whether authenticated pages may be cached.
	 * @param string $file_state      Existing anonymous cache state.
	 */
	public function test_excluded_path_toolbar_shows_bypass_without_public_hit_details( $cache_logged_in, $file_state ) {
		$original_options = HSPSC_Settings::get_all();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$file = $this->create_public_cache_for_toolbar();

		try {
			$options = HSPSC_Settings::get_all();
			$options['cache_logged_in'] = $cache_logged_in;
			$options['page_cache_excluded_paths'] = '/';
			update_option( HSPSC_Settings::OPTION_KEY, $options );
			wp_delete_file( $file );
			if ( 'missing' !== $file_state ) {
				file_put_contents( $file, '<html>previously cached public page</html>' );
				if ( 'stale' === $file_state ) {
					touch( $file, time() - $options['page_cache_ttl'] - 1 );
				}
			}
			clearstatcache( true, $file );

			$bar = new WP_Admin_Bar();
			$bar->initialize();
			HSPSC_Admin::register_admin_bar( $bar );

			$this->assertTrue( HSPSC_Utils::is_page_cache_path_excluded() );
			$this->assertFalse( HSPSC_Utils::is_request_cacheable() );
			$this->assertSame( 'HSP Cache: BYPASS', $bar->get_node( 'hspsc' )->title );
			$this->assertSame( 'Status: BYPASS', $bar->get_node( 'hspsc-status' )->title );
			$this->assertStringContainsString( 'This page path is excluded from page caching.', $bar->get_node( 'hspsc-status-reason' )->title );
			$this->assertNull( $bar->get_node( 'hspsc-status-details' ) );
		} finally {
			wp_delete_file( $file );
			update_option( HSPSC_Settings::OPTION_KEY, $original_options );
			wp_set_current_user( 0 );
		}
	}

	public function excluded_path_toolbar_states() {
		return array(
			'logged-in caching disabled, no file'    => array( false, 'missing' ),
			'logged-in caching disabled, fresh file' => array( false, 'fresh' ),
			'logged-in caching disabled, stale file' => array( false, 'stale' ),
			'logged-in caching enabled, no file'     => array( true, 'missing' ),
			'logged-in caching enabled, fresh file'  => array( true, 'fresh' ),
			'logged-in caching enabled, stale file'  => array( true, 'stale' ),
		);
	}

	/**
	 * Creates a fresh anonymous cache file for the current frontend URL.
	 *
	 * @return string Cache filename.
	 */
	private function create_public_cache_for_toolbar() {
		$this->go_to( home_url( '/' ) );
		$home = wp_parse_url( home_url() );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$_SERVER['HTTPS']          = 'https' === $home['scheme'] ? 'on' : 'off';
		$_SERVER['SERVER_PORT']    = isset( $home['port'] ) ? $home['port'] : ( 'https' === $home['scheme'] ? 443 : 80 );
		$_SERVER['REQUEST_URI']    = '/';
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		$file = HSPSC_Page::get_cache_file_path_for_url( home_url( '/' ) );
		file_put_contents( $file, '<html>public page</html>' );
		return $file;
	}

    public function test_handle_settings_update_clears_page_and_asset_caches() {
        $page_file  = HSPSC_PATH . '/pages/admin-test-page.html';
        $asset_file = HSPSC_PATH . '/assets/admin-test.min.js';

        file_put_contents( $page_file, 'page' );
        file_put_contents( $asset_file, 'asset' );

        $this->assertFileExists( $page_file );
        $this->assertFileExists( $asset_file );

        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
        HSPSC_Admin::handle_settings_update( array(), array() );

        $this->assertFileDoesNotExist( $page_file );
        $this->assertFileDoesNotExist( $asset_file );
    }

    public function test_backup_file_normalization_preserves_sql_gz_extension() {
        $method = new ReflectionMethod( 'HSPSC_Admin', 'normalize_posted_backup_file' );
        $method->setAccessible( true );

        $this->assertSame(
            'hsp-db-backup-2026-04-25_20-09-17.sql.gz',
            $method->invoke( null, 'hsp-db-backup-2026-04-25_20-09-17.sql.gz' )
        );
    }

    public function test_backup_file_normalization_removes_paths() {
        $method = new ReflectionMethod( 'HSPSC_Admin', 'normalize_posted_backup_file' );
        $method->setAccessible( true );

        $this->assertSame(
            'hsp-db-backup-2026-04-25_20-09-17.sql.gz',
            $method->invoke( null, '../db-backups/hsp-db-backup-2026-04-25_20-09-17.sql.gz' )
        );
    }

    public function test_restore_backup_error_message_includes_diagnostics() {
        $message_method = new ReflectionMethod( 'HSPSC_Admin', 'get_restore_backup_error_message' );
        $message_method->setAccessible( true );
        $details_method = new ReflectionMethod( 'HSPSC_Admin', 'get_restore_backup_error_details' );
        $details_method->setAccessible( true );

        $result = array(
            'ok' => false,
            'error' => 'no_tables_restored',
            'file' => 'hsp-db-backup-2026-04-25_20-09-17.sql.gz',
            'path' => '/tmp/hsp-db-backup-2026-04-25_20-09-17.sql.gz',
            'executed' => 2,
            'total_statements' => 5,
            'skipped' => 3,
            'skipped_statements' => array(
                array(
                    'reason' => 'non_wordpress_table',
                    'statement' => 'DROP TABLE IF EXISTS `old_posts`;',
                ),
            ),
            'warnings' => array(
                'No WordPress tables were restored. Check whether the backup table prefix matches this site.',
            ),
            'tables' => array(),
        );

        $message = $message_method->invoke( null, $result );
        $details = $details_method->invoke( null, $result );

        $this->assertStringContainsString( 'no_tables_restored', $message );
        $this->assertContains( 'File: hsp-db-backup-2026-04-25_20-09-17.sql.gz', $details );
        $this->assertContains( 'SQL statements: 2 executed, 5 parsed, 3 skipped.', $details );
        $this->assertStringContainsString( 'Skipped SQL (non_wordpress_table)', implode( "\n", $details ) );
    }

    public function test_apply_bricks_compatibility_preset_disables_overlapping_optimizations() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'perf_disable_emojis' => true,
                    'perf_disable_embeds' => true,
                    'perf_lazy_images' => true,
                    'perf_lazy_iframes' => true,
                    'render_defer_js' => true,
                    'render_async_js' => true,
                    'page_cache' => true,
                    'minify_html' => true,
                )
            )
        );

        $options = HSPSC_Admin::apply_bricks_compatibility_preset();

        $this->assertFalse( $options['perf_disable_emojis'] );
        $this->assertFalse( $options['perf_disable_embeds'] );
        $this->assertFalse( $options['perf_lazy_images'] );
        $this->assertFalse( $options['perf_lazy_iframes'] );
        $this->assertFalse( $options['render_defer_js'] );
        $this->assertFalse( $options['render_async_js'] );
        $this->assertTrue( $options['page_cache'] );
        $this->assertTrue( $options['minify_html'] );
    }

    public function test_admin_bar_actions_use_current_url_as_return_target() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $home_parts = wp_parse_url( home_url() );
        $_SERVER['HTTP_HOST'] = $home_parts['host'] . ( isset( $home_parts['port'] ) ? ':' . $home_parts['port'] : '' );
        $_SERVER['REQUEST_URI'] = '/cache-view/?preview=true';

        $bar = new WP_Admin_Bar();
        $bar->initialize();

        HSPSC_Admin::register_admin_bar( $bar );

        $node = $bar->get_node( 'hspsc-rebuild-all' );
        $this->assertNotNull( $node );
        $this->assertSame( __( 'Rebuild all public page caches', 'hsp-smart-cache' ), $node->title );
        $this->assertSame( __( 'Clear all caches', 'hsp-smart-cache' ), $bar->get_node( 'hspsc-clear-all' )->title );
        $this->assertSame( __( "Rebuild this page's public cache", 'hsp-smart-cache' ), $bar->get_node( 'hspsc-rebuild-current' )->title );
        $this->assertNull( $bar->get_node( 'hspsc-clear-current' ) );
        $this->assertStringContainsString( rawurlencode( home_url( '/cache-view/?preview=true' ) ), $node->href );

        wp_set_current_user( 0 );
    }

    public function test_handle_clear_all_flushes_cache_and_redirects_back() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $page_file = HSPSC_PATH . '/pages/admin-clear-all-test.html';
        file_put_contents( $page_file, 'cached page' );
        $this->assertFileExists( $page_file );

        $_GET['_wpnonce'] = wp_create_nonce( 'hspsc_clear_all' );
        $_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
        $_GET['hspsc_return'] = rawurlencode( home_url( '/return-clear-all/' ) );

        $redirect_to = '';
        $capture_redirect = static function( $location ) use ( &$redirect_to ) {
            $redirect_to = $location;
            return false;
        };
        add_filter( 'wp_redirect', $capture_redirect );

        try {
            HSPSC_Admin::handle_clear_all();
        } finally {
            remove_filter( 'wp_redirect', $capture_redirect );
        }

        $this->assertFileDoesNotExist( $page_file );
        $this->assertSame( home_url( '/return-clear-all/' ), $redirect_to );

        wp_set_current_user( 0 );
    }

    public function test_handle_rebuild_all_queues_a_job_and_redirects_back() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $_GET['_wpnonce'] = wp_create_nonce( 'hspsc_rebuild_all' );
        $_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
        $_GET['hspsc_return'] = rawurlencode( home_url( '/return-rebuild-all/' ) );

        $redirect_to = '';
        $capture_redirect = static function( $location ) use ( &$redirect_to ) {
            $redirect_to = $location;
            return false;
        };
        add_filter( 'wp_redirect', $capture_redirect );

        try {
            HSPSC_Admin::handle_rebuild_all();
            $job = HSPSC_Rebuild::get_status();
            $this->assertNotEmpty( $job['id'] );
            $this->assertContains( $job['state'], array( 'discovering', 'running' ) );
            $this->assertSame( 0, $job['processed'] );
            $this->assertSame( home_url( '/return-rebuild-all/' ), $redirect_to );
        } finally {
            remove_filter( 'wp_redirect', $capture_redirect );
            HSPSC_Rebuild::reset();
            wp_set_current_user( 0 );
        }
    }

	/** The browser-decoded return parameter still contains the page's encoded query. */
	public function test_rebuild_current_keeps_encoded_query_values() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$target_url               = home_url( '/rebuild-current/?label=a%26b' );
		$_GET['hspsc_return']      = $target_url;
		$_REQUEST['_wpnonce']     = wp_create_nonce( 'hspsc_rebuild_current' );
		$redirect_to              = '';
		$capture_redirect         = static function ( $location ) use ( &$redirect_to ) {
			$redirect_to = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );
		try {
			HSPSC_Admin::handle_rebuild_current();
			$job = HSPSC_Rebuild::get_status();
			$this->assertSame( 1, $job['total'] );
			$this->assertSame( 0, $job['processed'] );
			$this->assertSame( $target_url, $redirect_to );
		} finally {
			remove_filter( 'wp_redirect', $capture_redirect );
			HSPSC_Rebuild::reset();
			wp_set_current_user( 0 );
		}
	}

    public function test_handle_clear_current_removes_requested_url_cache() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $target_url = home_url( '/clear-current-target/' );
        $ref = new ReflectionClass( 'HSPSC_Page' );
        $method = $ref->getMethod( 'get_cache_file_path_for_url' );
        $method->setAccessible( true );
        $cache_file = $method->invoke( null, $target_url );
        file_put_contents( $cache_file, 'cached' );
        $this->assertFileExists( $cache_file );
		$private_file = HSPSC_PATH . '/pages/private/' . substr( basename( $cache_file ), 0, 32 ) . '-user-' . md5( 'other-session' ) . '-' . md5( 'text/html' ) . '.php';
		wp_mkdir_p( dirname( $private_file ) );
		file_put_contents( $private_file, HSPSC_Page::PRIVATE_FILE_GUARD . 'cached private page' );
		$this->assertFileExists( $private_file );

        $_GET['_wpnonce'] = wp_create_nonce( 'hspsc_clear_current' );
        $_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
        $_GET['hspsc_return'] = rawurlencode( $target_url );

        $capture_redirect = static function() {
            return false;
        };
        add_filter( 'wp_redirect', $capture_redirect );

        try {
            HSPSC_Admin::handle_clear_current();
			$this->assertFileDoesNotExist( $cache_file );
			$this->assertFileDoesNotExist( $private_file );
        } finally {
            remove_filter( 'wp_redirect', $capture_redirect );
			wp_delete_file( $private_file );
			wp_delete_file( $cache_file );
			wp_set_current_user( 0 );
        }
    }

    public function test_legacy_preload_form_queues_the_public_rebuild_job() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $_GET['_wpnonce'] = wp_create_nonce( 'hspsc_run_preload' );
        $_REQUEST['_wpnonce'] = $_GET['_wpnonce'];

        $redirect_to = '';
        $capture_redirect = static function( $location ) use ( &$redirect_to ) {
            $redirect_to = $location;
            return false;
        };
        add_filter( 'wp_redirect', $capture_redirect );

        try {
            HSPSC_Admin::handle_run_preload();
            $job = HSPSC_Rebuild::get_status();
            $this->assertNotEmpty( $job['id'] );
            $this->assertSame( 0, $job['processed'] );
            $this->assertSame( HSPSC_Rebuild_Admin::results_url(), $redirect_to );
        } finally {
            remove_filter( 'wp_redirect', $capture_redirect );
            HSPSC_Rebuild::reset();
            wp_set_current_user( 0 );
        }
    }
}
