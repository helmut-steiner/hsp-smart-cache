<?php
/**
 * Administrative controls, cache diagnostics, and maintenance feedback.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers privileged actions and renders the plugin's administration UI.
 */
class HSPSC_Admin {
	/**
	 * Registers settings, action handlers, toolbar controls, and scoped assets.
	 */
	public static function init() {
		add_filter( 'plugin_action_links_' . HSPSC_BASENAME, array( __CLASS__, 'add_settings_link' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_settings_assets' ) );
		add_action( 'admin_post_hspsc_clear', array( __CLASS__, 'handle_clear_cache' ) );
		add_action( 'admin_post_hspsc_run_tests', array( __CLASS__, 'handle_run_tests' ) );
		add_action( 'admin_post_hspsc_clear_current', array( __CLASS__, 'handle_clear_current' ) );
		add_action( 'admin_post_hspsc_clear_all', array( __CLASS__, 'handle_clear_all' ) );
		add_action( 'admin_post_hspsc_rebuild_current', array( __CLASS__, 'handle_rebuild_current' ) );
		add_action( 'admin_post_hspsc_rebuild_all', array( __CLASS__, 'handle_rebuild_all' ) );
		add_action( 'admin_post_hspsc_restore_defaults', array( __CLASS__, 'handle_restore_defaults' ) );
		add_action( 'admin_post_hspsc_run_preload', array( __CLASS__, 'handle_run_preload' ) );
		add_action( 'admin_post_hspsc_analyze_db', array( __CLASS__, 'handle_analyze_db' ) );
		add_action( 'admin_post_hspsc_run_db_cleanup', array( __CLASS__, 'handle_run_db_cleanup' ) );
		add_action( 'admin_post_hspsc_create_db_backup', array( __CLASS__, 'handle_create_db_backup' ) );
		add_action( 'admin_post_hspsc_optimize_db', array( __CLASS__, 'handle_optimize_db' ) );
		add_action( 'admin_post_hspsc_restore_db_backup', array( __CLASS__, 'handle_restore_db_backup' ) );
		add_action( 'admin_post_hspsc_delete_db_backup', array( __CLASS__, 'handle_delete_db_backup' ) );
		add_action( 'admin_post_hspsc_apply_bricks_preset', array( __CLASS__, 'handle_apply_bricks_preset' ) );
		add_action( 'wp_ajax_hspsc_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_hspsc_clear', array( __CLASS__, 'ajax_clear_cache' ) );
		add_action( 'wp_ajax_hspsc_run_tests', array( __CLASS__, 'ajax_run_tests' ) );
		add_action( 'wp_ajax_hspsc_restore_defaults', array( __CLASS__, 'ajax_restore_defaults' ) );
		add_action( 'wp_ajax_hspsc_apply_bricks_preset', array( __CLASS__, 'ajax_apply_bricks_preset' ) );
		add_action( 'wp_ajax_hspsc_run_preload', array( __CLASS__, 'ajax_run_preload' ) );
		add_action( 'wp_ajax_hspsc_analyze_db', array( __CLASS__, 'ajax_analyze_db' ) );
		add_action( 'wp_ajax_hspsc_run_db_cleanup', array( __CLASS__, 'ajax_run_db_cleanup' ) );
		add_action( 'wp_ajax_hspsc_create_db_backup', array( __CLASS__, 'ajax_create_db_backup' ) );
		add_action( 'wp_ajax_hspsc_optimize_db', array( __CLASS__, 'ajax_optimize_db' ) );
		add_action( 'wp_ajax_hspsc_restore_db_backup', array( __CLASS__, 'ajax_restore_db_backup' ) );
		add_action( 'wp_ajax_hspsc_delete_db_backup', array( __CLASS__, 'ajax_delete_db_backup' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar' ), 100 );
		add_action( 'update_option_' . HSPSC_Settings::OPTION_KEY, array( __CLASS__, 'handle_settings_update' ), 10, 2 );
	}

	/**
	 * Loads settings assets only for administrators viewing this plugin's screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue_settings_assets( $hook_suffix ) {
		if ( 'settings_page_hsp-smart-cache' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$plugin_file = dirname( __DIR__ ) . '/hsp-smart-cache.php';
		wp_enqueue_style( 'hspsc-admin', plugins_url( 'assets/css/admin.css', $plugin_file ), array(), HSPSC_VERSION );
		wp_enqueue_script( 'hspsc-admin', plugins_url( 'assets/js/admin.js', $plugin_file ), array(), HSPSC_VERSION, true );
		wp_localize_script(
			'hspsc-admin',
			'HSPSCAdmin',
			array(
				'optionKey'       => HSPSC_Settings::OPTION_KEY,
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'actionFailed'    => __( 'Action failed. Please try again.', 'hsp-smart-cache' ),
				'actionCompleted' => __( 'Action completed.', 'hsp-smart-cache' ),
				'savedEnabled'    => __( 'Enabled', 'hsp-smart-cache' ),
				'savedDisabled'   => __( 'Disabled', 'hsp-smart-cache' ),
				'unsavedChanges'  => __( 'You have unsaved settings changes.', 'hsp-smart-cache' ),
			)
		);
	}

	/**
	 * Adds a shortcut to the plugin's settings for users who can manage them.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Plugin action links with the settings shortcut first.
	 */
	public static function add_settings_link( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift(
				$links,
				'<a href="' . esc_url( admin_url( 'options-general.php?page=hsp-smart-cache' ) ) . '">' . esc_html__( 'Settings', 'hsp-smart-cache' ) . '</a>'
			);
		}
		return $links;
	}

	/**
	 * Adds the settings screen under the WordPress Settings menu.
	 */
	public static function register_menu() {
		add_options_page(
			__( 'HSP Smart Cache', 'hsp-smart-cache' ),
			__( 'HSP Smart Cache', 'hsp-smart-cache' ),
			'manage_options',
			'hsp-smart-cache',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Verifies the form request, clears all caches, and redirects to settings.
	 */
	public static function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_clear' );
		HSPSC_Plugin::flush_all_caches();
		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&cache=cleared#hspsc-panel-cache' ) );
	}

	/**
	 * Clears the requested URL after verifying its toolbar action nonce.
	 */
	public static function handle_clear_current() {
		self::ensure_admin_bar_access( 'hspsc_clear_current' );
		$url = self::get_target_url();
		if ( $url ) {
			HSPSC_Page::clear_cache_for_url( $url );
		}
		self::redirect_back();
	}

	/**
	 * Clears every cache after verifying the toolbar action.
	 */
	public static function handle_clear_all() {
		self::ensure_admin_bar_access( 'hspsc_clear_all' );
		HSPSC_Plugin::flush_all_caches();
		self::redirect_back();
	}

	/**
	 * Queues a rebuild for the current page's public cache.
	 */
	public static function handle_rebuild_current() {
		self::ensure_admin_bar_access( 'hspsc_rebuild_current' );
		$url    = self::get_target_url();
		$result = $url ? HSPSC_Rebuild::start( $url ) : new WP_Error( 'hspsc_rebuild_invalid_url' );
		self::safe_redirect( HSPSC_Rebuild_Admin::start_redirect_url( $result, $url ) );
	}

	/**
	 * Queues a site-wide rebuild of public page cache entries.
	 */
	public static function handle_rebuild_all() {
		self::ensure_admin_bar_access( 'hspsc_rebuild_all' );
		self::safe_redirect( HSPSC_Rebuild_Admin::start_redirect_url( HSPSC_Rebuild::start(), self::get_target_url() ) );
	}

	/**
	 * Adds cache status and nonce-protected actions to the admin toolbar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Toolbar instance to populate.
	 */
	public static function register_admin_bar( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = self::get_admin_bar_cache_status();

		$wp_admin_bar->add_node(
			array(
				'id'    => 'hspsc',
				'title' => sprintf(
					/* translators: %s is the current page cache status label. */
					__( 'HSP Cache: %s', 'hsp-smart-cache' ),
					$status['label']
				),
				'href'  => admin_url( 'options-general.php?page=hsp-smart-cache' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'hspsc-status',
				'parent' => 'hspsc',
				'title'  => sprintf(
					/* translators: %s is the current page cache status label. */
					__( 'Status: %s', 'hsp-smart-cache' ),
					$status['label']
				),
				'href'   => admin_url( 'options-general.php?page=hsp-smart-cache' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'hspsc-status-reason',
				'parent' => 'hspsc',
				'title'  => sprintf(
					/* translators: %s is a short reason explaining the current cache status. */
					__( 'Reason: %s', 'hsp-smart-cache' ),
					$status['reason']
				),
				'href'   => admin_url( 'options-general.php?page=hsp-smart-cache' ),
			)
		);

		if ( ! empty( $status['details'] ) ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'hspsc-status-details',
					'parent' => 'hspsc',
					'title'  => $status['details'],
					'href'   => admin_url( 'options-general.php?page=hsp-smart-cache' ),
				)
			);
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'hspsc-clear-all',
				'parent' => 'hspsc',
				'title'  => __( 'Clear all caches', 'hsp-smart-cache' ),
				'href'   => self::action_url( 'hspsc_clear_all' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'hspsc-rebuild-all',
				'parent' => 'hspsc',
				'title'  => __( 'Rebuild all public page caches', 'hsp-smart-cache' ),
				'href'   => self::action_url( 'hspsc_rebuild_all' ),
			)
		);

		if ( ! is_admin() ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'hspsc-rebuild-current',
					'parent' => 'hspsc',
					'title'  => __( "Rebuild this page's public cache", 'hsp-smart-cache' ),
					'href'   => self::action_url( 'hspsc_rebuild_current' ),
				)
			);
		}
	}

	/**
	 * Describes request eligibility and the separate anonymous cache state.
	 *
	 * @return array Status label, reason, and optional file details.
	 */
	protected static function get_admin_bar_cache_status() {
		if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
			return array(
				'label'   => __( 'OFF', 'hsp-smart-cache' ),
				'reason'  => __( 'Page cache is disabled.', 'hsp-smart-cache' ),
				'details' => '',
			);
		}

		if ( is_admin() ) {
			return array(
				'label'   => __( 'BYPASS', 'hsp-smart-cache' ),
				'reason'  => __( 'WordPress admin requests are not cached.', 'hsp-smart-cache' ),
				'details' => '',
			);
		}

		if ( HSPSC_Utils::is_page_cache_path_excluded() ) {
			return array(
				'label'   => __( 'BYPASS', 'hsp-smart-cache' ),
				'reason'  => __( 'This page path is excluded from page caching.', 'hsp-smart-cache' ),
				'details' => '',
			);
		}

		$logged_in = is_user_logged_in();
		if ( $logged_in && ! HSPSC_Settings::get( 'cache_logged_in' ) ) {
			$url           = self::get_current_url();
			$info          = HSPSC_Page::get_cache_info_for_url( $url ? $url : home_url( '/' ) );
			$public_status = __( 'MISS', 'hsp-smart-cache' );
			if ( ! empty( $info['fresh'] ) ) {
				$public_status = __( 'HIT', 'hsp-smart-cache' );
			} elseif ( ! empty( $info['exists'] ) ) {
				$public_status = __( 'STALE', 'hsp-smart-cache' );
			}

			return array(
				'label'   => __( 'BYPASS', 'hsp-smart-cache' ),
				'reason'  => __( 'Logged-in page caching is disabled.', 'hsp-smart-cache' ),
				'details' => sprintf(
					/* translators: 1: public cache status, 2: cache file age, TTL and size details. */
					__( 'Public cache: %1$s | %2$s', 'hsp-smart-cache' ),
					$public_status,
					self::format_cache_info_details( $info )
				),
			);
		}

		if ( ! HSPSC_Utils::is_request_cacheable() ) {
			return array(
				'label'   => __( 'BYPASS', 'hsp-smart-cache' ),
				'reason'  => __( 'This request is excluded from page caching.', 'hsp-smart-cache' ),
				'details' => '',
			);
		}

		$info    = HSPSC_Page::get_cache_info_for_request();
		$details = self::format_cache_info_details( $info );
		if ( $logged_in ) {
			$details .= ' | ' . __( 'Status recorded when this page was generated; response headers show the current request.', 'hsp-smart-cache' );
		}
		if ( ! empty( $info['fresh'] ) ) {
			return array(
				'label'   => __( 'HIT', 'hsp-smart-cache' ),
				'reason'  => __( 'Fresh cache file exists for this URL.', 'hsp-smart-cache' ),
				'details' => $details,
			);
		}

		if ( ! empty( $info['exists'] ) ) {
			return array(
				'label'   => __( 'STALE', 'hsp-smart-cache' ),
				'reason'  => __( 'Cache file exists but is older than the page cache TTL.', 'hsp-smart-cache' ),
				'details' => $details,
			);
		}

		return array(
			'label'   => __( 'MISS', 'hsp-smart-cache' ),
			'reason'  => __( 'No cache file exists for this URL.', 'hsp-smart-cache' ),
			'details' => $details,
		);
	}

	/**
	 * Formats cache age, TTL, and size for the toolbar status details.
	 *
	 * @param array $info Cache file metadata returned by the page-cache module.
	 * @return string Translated cache metadata summary.
	 */
	protected static function format_cache_info_details( $info ) {
		$ttl  = isset( $info['ttl'] ) ? intval( $info['ttl'] ) : 0;
		$size = ! empty( $info['size'] ) ? size_format( intval( $info['size'] ) ) : __( '0 B', 'hsp-smart-cache' );

		if ( isset( $info['age'] ) && null !== $info['age'] ) {
			$age = human_time_diff( time() - intval( $info['age'] ), time() );
			return sprintf(
				/* translators: 1: cache file age, 2: cache TTL in seconds, 3: cache file size. */
				__( 'Age: %1$s | TTL: %2$ds | Size: %3$s', 'hsp-smart-cache' ),
				$age,
				$ttl,
				$size
			);
		}

		return sprintf(
			/* translators: 1: cache TTL in seconds, 2: cache file size. */
			__( 'TTL: %1$ds | Size: %2$s', 'hsp-smart-cache' ),
			$ttl,
			$size
		);
	}

	/**
	 * Builds a nonce-protected admin action URL with the current return URL.
	 *
	 * @param string $action Registered admin action and nonce action name.
	 * @return string Action URL with return context.
	 */
	protected static function action_url( $action ) {
		$args = array(
			'action'   => $action,
			'_wpnonce' => wp_create_nonce( $action ),
		);

		$url    = admin_url( 'admin-post.php' );
		$return = self::get_current_url();
		if ( ! $return ) {
			$return = wp_get_referer();
		}
		if ( $return ) {
			$args['hspsc_return'] = rawurlencode( $return );
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * Builds a sanitized current URL without plugin action parameters.
	 *
	 * @return string Current request URL, or an empty string without request context.
	 */
	protected static function get_current_url() {
		if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$scheme = is_ssl() ? 'https://' : 'http://';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve the URI until the complete URL is sanitized with esc_url_raw() below.
		$url         = $scheme . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . wp_unslash( $_SERVER['REQUEST_URI'] );
		$action_args = array( 'hspsc_notice', 'hspsc_rebuild_error', 'hspsc_rebuild_page', '_wpnonce', 'hspsc_return' );
		if ( is_admin() ) {
			$action_args[] = 'action';
		}
		$url = remove_query_arg( $action_args, $url );

		return esc_url_raw( $url );
	}

	/**
	 * Resolves the sanitized return URL for an authenticated admin action.
	 *
	 * @return string Return URL, referer, or configured home URL.
	 */
	protected static function get_target_url() {
		$return = filter_input( INPUT_GET, 'hspsc_return', FILTER_UNSAFE_RAW );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Callers verified their action-specific nonce before resolving the redirect.
		if ( null === $return && isset( $_GET['hspsc_return'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by the caller; esc_url_raw() sanitizes the complete return URL below.
			$return = wp_unslash( $_GET['hspsc_return'] );
		}
		if ( is_string( $return ) && '' !== $return ) {
			// PHP normally decodes the outer query value already. Preserve the
			// encoded characters that belong to the page's own query string.
			if ( preg_match( '~\Ahttps?%3a%2f%2f~i', $return ) ) {
				$return = rawurldecode( $return );
			}
			return esc_url_raw( $return );
		}
		$referer = wp_get_referer();
		if ( $referer ) {
			return esc_url_raw( $referer );
		}
		return home_url( '/' );
	}

	/**
	 * Redirects to the requested return URL after a completed action.
	 */
	protected static function redirect_back() {
		$url = self::get_target_url();
		self::safe_redirect( $url );
	}

	/**
	 * Requires the settings capability and an action-specific toolbar nonce.
	 *
	 * @param string $nonce_action Action name used to verify the request nonce.
	 */
	protected static function ensure_admin_bar_access( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Runs cache diagnostics after verifying the form and stores their results.
	 */
	public static function handle_run_tests() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_run_tests' );
		$results = HSPSC_Tests::run();
		set_transient( 'hspsc_test_results', $results, 300 );
		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&tests=done#hspsc-panel-advanced' ) );
	}

	/**
	 * Restores default settings and synchronizes cache state after verification.
	 */
	public static function handle_restore_defaults() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_restore_defaults' );
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		HSPSC_Object::sync_dropin();
		HSPSC_Page::clear_cache();
		HSPSC_Minify::clear_cache();
		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&settings=restored#hspsc-panel-advanced' ) );
	}

	/**
	 * Applies the Bricks compatibility preset after verifying the form.
	 */
	public static function handle_apply_bricks_preset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_apply_bricks_preset' );

		self::apply_bricks_compatibility_preset();
		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&settings=bricks-preset#hspsc-panel-advanced' ) );
	}

	/**
	 * Routes the settings form and its legacy action name to the rebuild job.
	 */
	public static function handle_run_preload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_run_preload' );
		self::safe_redirect( HSPSC_Rebuild_Admin::start_redirect_url( HSPSC_Rebuild::start(), HSPSC_Rebuild_Admin::results_url() ) );
	}

	/**
	 * Runs verified database cleanup and redirects with its completion status.
	 */
	public static function handle_run_db_cleanup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_run_db_cleanup' );
		$result = HSPSC_Maintenance::run_db_cleanup();
		if ( empty( $result['ok'] ) ) {
			self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=cleanupfailed#hspsc-panel-maintenance' ) );
		}

		$analysis = HSPSC_Maintenance::analyze_optimization();
		set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );
		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=cleaned#hspsc-panel-maintenance' ) );
	}

	/**
	 * Stores a database optimization analysis after verifying the request.
	 */
	public static function handle_analyze_db() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_analyze_db' );

		$analysis = HSPSC_Maintenance::analyze_optimization();
		set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );

		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=analyzed#hspsc-panel-maintenance' ) );
	}

	/**
	 * Creates a database backup after verification and records its result.
	 */
	public static function handle_create_db_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_create_db_backup' );

		$backup = HSPSC_Maintenance::create_backup();
		if ( empty( $backup['ok'] ) ) {
			self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=backupfailed#hspsc-panel-maintenance' ) );
		}

		set_transient( 'hspsc_db_last_backup', $backup, 15 * MINUTE_IN_SECONDS );

		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=backupcreated#hspsc-panel-maintenance' ) );
	}

	/**
	 * Creates a backup before running verified table optimization.
	 */
	public static function handle_optimize_db() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_optimize_db' );

		$backup = HSPSC_Maintenance::create_backup();
		if ( empty( $backup['ok'] ) ) {
			self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=backupfailed#hspsc-panel-maintenance' ) );
		}

		set_transient( 'hspsc_db_last_backup', $backup, 15 * MINUTE_IN_SECONDS );

		$optimize = HSPSC_Maintenance::optimize_tables();
		if ( empty( $optimize['ok'] ) ) {
			self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=optimizefailed#hspsc-panel-maintenance' ) );
		}

		$analysis = HSPSC_Maintenance::analyze_optimization();
		set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );

		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=optimized#hspsc-panel-maintenance' ) );
	}

	/**
	 * Restores an authenticated backup after verifying the posted form.
	 */
	public static function handle_restore_db_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_restore_db_backup' );

		$backup_file = filter_input( INPUT_POST, 'backup_file', FILTER_UNSAFE_RAW );
		if ( null === $backup_file && isset( $_POST['backup_file'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalize the basename below; restore_backup() enforces the exact filename, path, and signature.
			$backup_file = wp_unslash( $_POST['backup_file'] );
		}
		$backup_file = self::normalize_posted_backup_file( $backup_file );

		$result = HSPSC_Maintenance::restore_backup( $backup_file );
		if ( empty( $result['ok'] ) ) {
			set_transient( 'hspsc_db_restore_error', $result, 15 * MINUTE_IN_SECONDS );
			self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=restorefailed#hspsc-panel-maintenance' ) );
		}

		set_transient( 'hspsc_db_restore_result', $result, 15 * MINUTE_IN_SECONDS );
		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=restored#hspsc-panel-maintenance' ) );
	}

	/**
	 * Deletes a validated backup after verifying the posted form.
	 */
	public static function handle_delete_db_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny_access();
		}
		check_admin_referer( 'hspsc_delete_db_backup' );

		$backup_file = filter_input( INPUT_POST, 'backup_file', FILTER_UNSAFE_RAW );
		if ( null === $backup_file && isset( $_POST['backup_file'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalize the basename below; delete_backup() enforces the exact filename and allowed backup path.
			$backup_file = wp_unslash( $_POST['backup_file'] );
		}
		$backup_file = self::normalize_posted_backup_file( $backup_file );

		$delete_error = null;
		$deleted      = HSPSC_Maintenance::delete_backup( $backup_file, $delete_error );
		if ( ! $deleted ) {
			set_transient( 'hspsc_db_delete_error', $delete_error, 5 * MINUTE_IN_SECONDS );
		}
		self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=' . ( $deleted ? 'backupdeleted' : 'deletefailed' ) . '#hspsc-panel-maintenance' ) );
	}

	/**
	 * Validates the AJAX request and saves the shared sanitized settings option.
	 */
	public static function ajax_save_settings() {
		self::ensure_ajax_access( 'hspsc_settings_group-options' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ensure_ajax_access() verified the nonce; HSPSC_Settings::sanitize() validates every supported field immediately below.
		$raw_options = isset( $_POST[ HSPSC_Settings::OPTION_KEY ] ) ? wp_unslash( $_POST[ HSPSC_Settings::OPTION_KEY ] ) : array();
		$options     = HSPSC_Settings::sanitize( is_array( $raw_options ) ? $raw_options : array() );

		update_option( HSPSC_Settings::OPTION_KEY, $options );

		self::send_ajax_success(
			__( 'Settings saved. Related caches were refreshed.', 'hsp-smart-cache' ),
			array( 'options' => $options )
		);
	}

	/**
	 * Clears all caches after AJAX capability and nonce verification.
	 */
	public static function ajax_clear_cache() {
		self::ensure_ajax_access( 'hspsc_clear' );

		HSPSC_Plugin::flush_all_caches();

		self::send_ajax_success( __( 'All caches cleared.', 'hsp-smart-cache' ) );
	}

	/**
	 * Runs verified cache diagnostics and returns their rendered result panel.
	 */
	public static function ajax_run_tests() {
		self::ensure_ajax_access( 'hspsc_run_tests' );

		$results = HSPSC_Tests::run();
		set_transient( 'hspsc_test_results', $results, 300 );

		self::send_ajax_success(
			__( 'Cache tests completed.', 'hsp-smart-cache' ),
			array(
				'html' => self::get_cache_tests_html( $results ),
			)
		);
	}

	/**
	 * Restores defaults through AJAX and returns the resulting settings.
	 */
	public static function ajax_restore_defaults() {
		self::ensure_ajax_access( 'hspsc_restore_defaults' );

		$defaults = HSPSC_Settings::defaults();
		update_option( HSPSC_Settings::OPTION_KEY, $defaults );
		HSPSC_Object::sync_dropin();
		HSPSC_Page::clear_cache();
		HSPSC_Minify::clear_cache();

		self::send_ajax_success(
			__( 'Defaults restored.', 'hsp-smart-cache' ),
			array( 'options' => $defaults )
		);
	}

	/**
	 * Applies the verified Bricks preset and returns its updated settings.
	 */
	public static function ajax_apply_bricks_preset() {
		self::ensure_ajax_access( 'hspsc_apply_bricks_preset' );

		$options = self::apply_bricks_compatibility_preset();

		self::send_ajax_success(
			self::get_bricks_preset_message(),
			array( 'options' => $options )
		);
	}

	/**
	 * Queues the same verified rebuild through the legacy settings AJAX action.
	 */
	public static function ajax_run_preload() {
		self::ensure_ajax_access( 'hspsc_run_preload' );

		$accept = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action-specific nonce was verified above.
		if ( array_key_exists( 'navigation_accept', $_POST ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate ASCII transport before decoding and validate exact header bytes in the rebuild service.
			$encoded = is_string( $_POST['navigation_accept'] ) ? wp_unslash( $_POST['navigation_accept'] ) : null;
			$accept  = is_string( $encoded ) && strlen( $encoded ) <= 16384 && 0 === strlen( $encoded ) % 2
				&& preg_match( '/\A[a-f0-9]*\z/', $encoded ) ? hex2bin( $encoded ) : false;
		}
		HSPSC_Rebuild_Admin::send_start_result( HSPSC_Rebuild::start( '', $accept ) );
	}

	/**
	 * Analyzes database maintenance needs after AJAX verification.
	 */
	public static function ajax_analyze_db() {
		self::ensure_ajax_access( 'hspsc_analyze_db' );

		$analysis = HSPSC_Maintenance::analyze_optimization();
		set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );

		self::send_ajax_success(
			__( 'Database analysis completed.', 'hsp-smart-cache' ),
			array( 'analysis_html' => self::get_db_analysis_html( $analysis ) )
		);
	}

	/**
	 * Runs verified database cleanup and returns a refreshed analysis panel.
	 */
	public static function ajax_run_db_cleanup() {
		self::ensure_ajax_access( 'hspsc_run_db_cleanup' );

		$result = HSPSC_Maintenance::run_db_cleanup();
		if ( empty( $result['ok'] ) ) {
			self::send_ajax_error( __( 'Database cleanup failed. Some queries did not complete.', 'hsp-smart-cache' ) );
		}

		$analysis = HSPSC_Maintenance::analyze_optimization();
		set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );

		self::send_ajax_success(
			__( 'Database cleanup completed.', 'hsp-smart-cache' ),
			array(
				'analysis_html' => self::get_db_analysis_html( $analysis ),
				'result'        => $result,
			)
		);
	}

	/**
	 * Creates a verified database backup and returns the refreshed backup list.
	 */
	public static function ajax_create_db_backup() {
		self::ensure_ajax_access( 'hspsc_create_db_backup' );

		$backup = HSPSC_Maintenance::create_backup();
		if ( empty( $backup['ok'] ) ) {
			self::send_ajax_error( __( 'Database backup failed.', 'hsp-smart-cache' ) );
		}

		set_transient( 'hspsc_db_last_backup', $backup, 15 * MINUTE_IN_SECONDS );

		self::send_ajax_success(
			__( 'Database backup created.', 'hsp-smart-cache' ),
			array(
				'backups_html' => self::get_db_backups_html( $backup, HSPSC_Maintenance::list_backups() ),
				'result'       => $backup,
			)
		);
	}

	/**
	 * Creates a backup before verified AJAX table optimization and refreshes panels.
	 */
	public static function ajax_optimize_db() {
		self::ensure_ajax_access( 'hspsc_optimize_db' );

		$backup = HSPSC_Maintenance::create_backup();
		if ( empty( $backup['ok'] ) ) {
			self::send_ajax_error( __( 'Database backup failed. Optimization was not executed.', 'hsp-smart-cache' ) );
		}

		set_transient( 'hspsc_db_last_backup', $backup, 15 * MINUTE_IN_SECONDS );

		$optimize = HSPSC_Maintenance::optimize_tables();
		if ( empty( $optimize['ok'] ) ) {
			self::send_ajax_error( __( 'Database optimization failed. Some tables were not optimized.', 'hsp-smart-cache' ) );
		}

		$analysis = HSPSC_Maintenance::analyze_optimization();
		set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );

		self::send_ajax_success(
			__( 'Database optimization completed.', 'hsp-smart-cache' ),
			array(
				'analysis_html' => self::get_db_analysis_html( $analysis ),
				'backups_html'  => self::get_db_backups_html( $backup, HSPSC_Maintenance::list_backups() ),
				'result'        => $optimize,
			)
		);
	}

	/**
	 * Restores a validated backup through AJAX and reports detailed results.
	 */
	public static function ajax_restore_db_backup() {
		self::ensure_ajax_access( 'hspsc_restore_db_backup' );

		$backup_file = self::get_posted_backup_file();
		$result      = HSPSC_Maintenance::restore_backup( $backup_file );
		if ( empty( $result['ok'] ) ) {
			self::send_ajax_error(
				self::get_restore_backup_error_message( $result ),
				400,
				array(
					'details' => self::get_restore_backup_error_details( $result ),
					'result'  => $result,
				)
			);
		}

		self::send_ajax_success(
			self::get_restore_backup_success_message( $result ),
			array(
				'details' => self::get_restore_backup_success_details( $result ),
				'result'  => $result,
			)
		);
	}

	/**
	 * Deletes a validated backup through AJAX and refreshes the backup list.
	 */
	public static function ajax_delete_db_backup() {
		self::ensure_ajax_access( 'hspsc_delete_db_backup' );

		$delete_error = null;
		$deleted      = HSPSC_Maintenance::delete_backup( self::get_posted_backup_file(), $delete_error );
		if ( ! $deleted ) {
			self::send_ajax_error( self::get_delete_backup_error_message( $delete_error ) );
		}

		self::send_ajax_success(
			__( 'Database backup deleted.', 'hsp-smart-cache' ),
			array(
				'backups_html' => self::get_db_backups_html( get_transient( 'hspsc_db_last_backup' ), HSPSC_Maintenance::list_backups() ),
			)
		);
	}

	/**
	 * Requires the settings capability and an action-specific AJAX nonce.
	 *
	 * @param string $nonce_action Action name used to verify the request nonce.
	 */
	protected static function ensure_ajax_access( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::send_ajax_error( __( 'Unauthorized', 'hsp-smart-cache' ), 403 );
		}

		if ( ! check_ajax_referer( $nonce_action, '_wpnonce', false ) ) {
			self::send_ajax_error( __( 'Security check failed. Refresh the page and try again.', 'hsp-smart-cache' ), 403 );
		}
	}

	/**
	 * Extracts the backup filename after the calling action verifies access.
	 *
	 * @return string Normalized basename for downstream filename and path validation.
	 */
	protected static function get_posted_backup_file() {
		$backup_file = filter_input( INPUT_POST, 'backup_file', FILTER_UNSAFE_RAW );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only verified AJAX backup actions call this extractor.
		if ( null === $backup_file && isset( $_POST['backup_file'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The calling AJAX action verifies its nonce; normalize here, then enforce filename/path rules in the maintenance module.
			$backup_file = wp_unslash( $_POST['backup_file'] );
		}

		return self::normalize_posted_backup_file( $backup_file );
	}

	/**
	 * Reduces a scalar posted backup path to a basename for strict validation.
	 *
	 * @param mixed $backup_file Posted filename or path, possibly malformed.
	 * @return string Unslashed basename, or an empty string for non-scalar input.
	 */
	protected static function normalize_posted_backup_file( $backup_file ) {
		if ( ! is_scalar( $backup_file ) ) {
			return '';
		}

		$backup_file = str_replace( '\\', '/', (string) wp_unslash( $backup_file ) );

		return basename( $backup_file );
	}

	/**
	 * Summarizes successful restore counts and the presence of warnings.
	 *
	 * @param mixed $result Restore result returned by the maintenance module.
	 * @return string Translated restore summary.
	 */
	protected static function get_restore_backup_success_message( $result ) {
		if ( ! is_array( $result ) ) {
			return __( 'Database backup restored.', 'hsp-smart-cache' );
		}

		$tables   = isset( $result['tables'] ) && is_array( $result['tables'] ) ? count( $result['tables'] ) : 0;
		$executed = isset( $result['executed'] ) ? intval( $result['executed'] ) : 0;

		if ( ! empty( $result['warnings'] ) || ! empty( $result['skipped'] ) ) {
			return sprintf(
				/* translators: 1: number of restored tables, 2: number of executed SQL statements. */
				__( 'Database backup restored with warnings. Tables: %1$d. SQL statements: %2$d.', 'hsp-smart-cache' ),
				$tables,
				$executed
			);
		}

		return sprintf(
			/* translators: 1: number of restored tables, 2: number of executed SQL statements. */
			__( 'Database backup restored. Tables: %1$d. SQL statements: %2$d.', 'hsp-smart-cache' ),
			$tables,
			$executed
		);
	}

	/**
	 * Collects restore metadata and warnings for a successful action.
	 *
	 * @param mixed $result Restore result returned by the maintenance module.
	 * @return array Unescaped detail strings to escape when rendered.
	 */
	protected static function get_restore_backup_success_details( $result ) {
		$details = self::get_restore_backup_common_details( $result );
		if ( ! is_array( $result ) ) {
			return $details;
		}

		if ( ! empty( $result['warnings'] ) && is_array( $result['warnings'] ) ) {
			foreach ( $result['warnings'] as $warning ) {
				$details[] = sprintf(
					/* translators: %s is a restore warning. */
					__( 'Warning: %s', 'hsp-smart-cache' ),
					$warning
				);
			}
		}

		return $details;
	}

	/**
	 * Maps a restore failure code to a translated diagnostic summary.
	 *
	 * @param mixed $result Restore result returned by the maintenance module.
	 * @return string Unescaped translated failure summary.
	 */
	protected static function get_restore_backup_error_message( $result ) {
		if ( ! is_array( $result ) ) {
			return __( 'Database backup restore failed. No diagnostic details were returned.', 'hsp-smart-cache' );
		}

		$code     = isset( $result['error'] ) ? sanitize_key( $result['error'] ) : 'unknown';
		$messages = array(
			'missing_file'            => __( 'The selected backup file was not found or did not match the allowed backup filename format.', 'hsp-smart-cache' ),
			'read_failed'             => __( 'The backup file could not be read or decompressed.', 'hsp-smart-cache' ),
			'invalid_backup'          => __( 'The backup file did not contain executable SQL statements.', 'hsp-smart-cache' ),
			'missing_signature'       => __( 'This backup has no integrity signature. Create a new backup with this version before restoring.', 'hsp-smart-cache' ),
			'integrity_failed'        => __( 'Backup integrity verification failed. The file, site, or signing keys have changed.', 'hsp-smart-cache' ),
			'unsafe_statement'        => __( 'The backup contains unsupported SQL. No statements were executed.', 'hsp-smart-cache' ),
			'path_outside_backup_dir' => __( 'The backup must be a regular file in this site\'s backup directory.', 'hsp-smart-cache' ),
			'query_failed'            => __( 'A database query failed while replaying the backup.', 'hsp-smart-cache' ),
			'no_tables_restored'      => __( 'No WordPress tables were restored. The backup may use a different table prefix than this site.', 'hsp-smart-cache' ),
		);

		$detail = isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Unknown restore failure.', 'hsp-smart-cache' );

		return sprintf(
			/* translators: 1: internal diagnostic code, 2: human-readable diagnostic detail. */
			__( 'Database backup restore failed. Code: %1$s. %2$s', 'hsp-smart-cache' ),
			$code,
			$detail
		);
	}

	/**
	 * Collects failed-statement details, restore counts, and warnings.
	 *
	 * @param mixed $result Restore result returned by the maintenance module.
	 * @return array Unescaped detail strings to escape when rendered.
	 */
	protected static function get_restore_backup_error_details( $result ) {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$details = self::get_restore_backup_common_details( $result );

		if ( ! empty( $result['statement'] ) ) {
			$details[] = sprintf(
				/* translators: %d is the failed SQL statement number. */
				__( 'Failed statement number: %d', 'hsp-smart-cache' ),
				intval( $result['statement'] )
			);
		}

		if ( ! empty( $result['failed_statement'] ) ) {
			$details[] = sprintf(
				/* translators: %s is a shortened SQL statement. */
				__( 'Failed SQL: %s', 'hsp-smart-cache' ),
				$result['failed_statement']
			);
		}

		if ( ! empty( $result['warnings'] ) && is_array( $result['warnings'] ) ) {
			foreach ( $result['warnings'] as $warning ) {
				$details[] = sprintf(
					/* translators: %s is a restore warning. */
					__( 'Warning: %s', 'hsp-smart-cache' ),
					$warning
				);
			}
		}

		return $details;
	}

	/**
	 * Formats backup metadata and per-table execution counts for feedback.
	 *
	 * @param mixed $result Restore result returned by the maintenance module.
	 * @return array Unescaped detail strings to escape when rendered.
	 */
	protected static function get_restore_backup_common_details( $result ) {
		$details = array();
		if ( ! is_array( $result ) ) {
			return $details;
		}

		if ( ! empty( $result['file'] ) ) {
			$details[] = sprintf(
				/* translators: %s is a backup file name. */
				__( 'File: %s', 'hsp-smart-cache' ),
				$result['file']
			);
		}

		if ( ! empty( $result['path'] ) ) {
			$details[] = sprintf(
				/* translators: %s is an absolute filesystem path. */
				__( 'Path: %s', 'hsp-smart-cache' ),
				$result['path']
			);
		}

		$details[] = sprintf(
			/* translators: 1: executed statements, 2: total parsed statements, 3: skipped statements. */
			__( 'SQL statements: %1$d executed, %2$d parsed, %3$d skipped.', 'hsp-smart-cache' ),
			isset( $result['executed'] ) ? intval( $result['executed'] ) : 0,
			isset( $result['total_statements'] ) ? intval( $result['total_statements'] ) : 0,
			isset( $result['skipped'] ) ? intval( $result['skipped'] ) : 0
		);

		if ( ! empty( $result['tables'] ) && is_array( $result['tables'] ) ) {
			foreach ( $result['tables'] as $table => $summary ) {
				$details[] = sprintf(
					/* translators: 1: table name, 2: drop count, 3: create count, 4: insert count. */
					__( 'Table %1$s: DROP=%2$d CREATE=%3$d INSERT=%4$d.', 'hsp-smart-cache' ),
					$table,
					isset( $summary['drop'] ) ? intval( $summary['drop'] ) : 0,
					isset( $summary['create'] ) ? intval( $summary['create'] ) : 0,
					isset( $summary['insert'] ) ? intval( $summary['insert'] ) : 0
				);
			}
		}

		if ( ! empty( $result['skipped_statements'] ) && is_array( $result['skipped_statements'] ) ) {
			foreach ( array_slice( $result['skipped_statements'], 0, 5 ) as $skipped ) {
				if ( ! is_array( $skipped ) ) {
					continue;
				}
				$details[] = sprintf(
					/* translators: 1: skip reason, 2: SQL preview. */
					__( 'Skipped SQL (%1$s): %2$s', 'hsp-smart-cache' ),
					isset( $skipped['reason'] ) ? $skipped['reason'] : 'unknown',
					isset( $skipped['statement'] ) ? $skipped['statement'] : ''
				);
			}
		}

		return $details;
	}

	/**
	 * Renders escaped restore diagnostic items as a list.
	 *
	 * @param array $details Unescaped diagnostic messages.
	 */
	protected static function render_restore_backup_details( $details ) {
		if ( empty( $details ) || ! is_array( $details ) ) {
			return;
		}
		?>
		<ul class="hspsc-notice-details">
			<?php foreach ( $details as $detail ) : ?>
				<li><?php echo esc_html( $detail ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Formats backup deletion failure diagnostics for privileged feedback.
	 *
	 * @param mixed $error Deletion error data returned by the maintenance module.
	 * @return string Unescaped translated deletion diagnostics.
	 */
	protected static function get_delete_backup_error_message( $error ) {
		if ( ! is_array( $error ) ) {
			return __( 'Deleting database backup failed. No diagnostic details were returned.', 'hsp-smart-cache' );
		}

		$code     = isset( $error['code'] ) ? sanitize_key( $error['code'] ) : 'unknown';
		$messages = array(
			'invalid_file'            => __( 'The posted backup filename did not match the allowed backup filename format.', 'hsp-smart-cache' ),
			'missing_file'            => __( 'The backup file was not found at the expected path.', 'hsp-smart-cache' ),
			'path_outside_backup_dir' => __( 'The resolved backup path is outside the configured backup directory.', 'hsp-smart-cache' ),
			'delete_failed'           => __( 'WordPress filesystem deletion, wp_delete_file(), and unlink() did not remove the file.', 'hsp-smart-cache' ),
		);

		$detail = isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Unknown backup deletion failure.', 'hsp-smart-cache' );
		$parts  = array(
			__( 'Deleting database backup failed.', 'hsp-smart-cache' ),
			sprintf(
				/* translators: %s is an internal diagnostic code. */
				__( 'Code: %s', 'hsp-smart-cache' ),
				$code
			),
			$detail,
		);

		if ( ! empty( $error['file'] ) ) {
			$parts[] = sprintf(
				/* translators: %s is a backup file name. */
				__( 'File: %s', 'hsp-smart-cache' ),
				$error['file']
			);
		}

		if ( ! empty( $error['path'] ) ) {
			$parts[] = sprintf(
				/* translators: %s is an absolute filesystem path. */
				__( 'Path: %s', 'hsp-smart-cache' ),
				$error['path']
			);
		}

		if ( ! empty( $error['backup_dir'] ) ) {
			$parts[] = sprintf(
				/* translators: %s is an absolute filesystem path. */
				__( 'Backup directory: %s', 'hsp-smart-cache' ),
				$error['backup_dir']
			);
		}

		if ( ! empty( $error['path_check'] ) ) {
			$parts[] = sprintf(
				/* translators: %s is an absolute filesystem path used for validation. */
				__( 'Validated path: %s', 'hsp-smart-cache' ),
				$error['path_check']
			);
		}

		if ( ! empty( $error['backup_dir_check'] ) ) {
			$parts[] = sprintf(
				/* translators: %s is an absolute filesystem path used for validation. */
				__( 'Validated backup directory: %s', 'hsp-smart-cache' ),
				$error['backup_dir_check']
			);
		}

		if ( array_key_exists( 'backup_dir_writable', $error ) ) {
			$parts[] = sprintf(
				/* translators: %s is yes or no. */
				__( 'Backup directory writable: %s', 'hsp-smart-cache' ),
				$error['backup_dir_writable'] ? __( 'yes', 'hsp-smart-cache' ) : __( 'no', 'hsp-smart-cache' )
			);
		}

		if ( array_key_exists( 'file_writable', $error ) ) {
			$parts[] = sprintf(
				/* translators: %s is yes or no. */
				__( 'File writable: %s', 'hsp-smart-cache' ),
				$error['file_writable'] ? __( 'yes', 'hsp-smart-cache' ) : __( 'no', 'hsp-smart-cache' )
			);
		}

		if ( ! empty( $error['file_permissions'] ) ) {
			$parts[] = sprintf(
				/* translators: %s is a four-digit octal permissions value. */
				__( 'Permissions: %s', 'hsp-smart-cache' ),
				$error['file_permissions']
			);
		}

		if ( ! empty( $error['attempts'] ) && is_array( $error['attempts'] ) ) {
			$attempts = array();
			foreach ( $error['attempts'] as $attempt ) {
				if ( ! is_array( $attempt ) || empty( $attempt['method'] ) ) {
					continue;
				}
				$attempt_text = $attempt['method'] . '=' . ( ! empty( $attempt['ok'] ) ? 'ok' : 'failed' );
				if ( ! empty( $attempt['error'] ) ) {
					$attempt_text .= ' (' . $attempt['error'] . ')';
				}
				$attempts[] = $attempt_text;
			}
			if ( ! empty( $attempts ) ) {
				$parts[] = sprintf(
					/* translators: %s is a comma-separated list of deletion attempts. */
					__( 'Attempts: %s', 'hsp-smart-cache' ),
					implode( ', ', $attempts )
				);
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Returns a JSON success response containing a message and optional data.
	 *
	 * @param string $message Feedback message for the administrator.
	 * @param array  $data Additional response fields.
	 */
	protected static function send_ajax_success( $message, $data = array() ) {
		wp_send_json_success(
			array_merge(
				array(
					'message' => $message,
				),
				$data
			)
		);
	}

	/**
	 * Returns a JSON error response with an HTTP status and optional data.
	 *
	 * @param string $message Feedback message for the administrator.
	 * @param int    $status_code HTTP status code for the failed action.
	 * @param array  $data Additional response fields.
	 */
	protected static function send_ajax_error( $message, $status_code = 400, $data = array() ) {
		wp_send_json_error(
			array_merge(
				array(
					'message' => $message,
				),
				$data
			),
			$status_code
		);
	}

	/**
	 * Builds an escaped diagnostic panel in the viewing administrator's locale.
	 *
	 * @param mixed $test_results Stored diagnostic results or a missing transient.
	 * @return string Escaped panel markup, or an empty string without results.
	 */
	protected static function get_cache_tests_html( $test_results ) {
		if ( ! is_array( $test_results ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="hspsc-result-list">
			<h3><?php echo esc_html__( 'Latest Cache Test Results', 'hsp-smart-cache' ); ?></h3>
			<ul>
				<?php foreach ( $test_results as $result ) : ?>
					<?php $result = HSPSC_Tests::translate_result( $result ); ?>
					<li>
						<span class="<?php echo ! empty( $result['status'] ) ? 'hspsc-status-ok' : 'hspsc-status-error'; ?>">
							<?php echo ! empty( $result['status'] ) ? esc_html__( 'Passed', 'hsp-smart-cache' ) : esc_html__( 'Failed', 'hsp-smart-cache' ); ?>
						</span>
						<?php echo esc_html( $result['label'] ); ?>
						<?php if ( ! empty( $result['details'] ) ) : ?>
							<span class="description"><?php echo esc_html( $result['details'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Builds the escaped database-analysis panel.
	 *
	 * @param mixed $db_analysis Stored optimization analysis or a missing transient.
	 * @return string Escaped analysis panel markup.
	 */
	protected static function get_db_analysis_html( $db_analysis ) {
		ob_start();

		if ( is_array( $db_analysis ) && ! empty( $db_analysis['ok'] ) ) :
			?>
			<div class="hspsc-db-analysis">
				<h3><?php echo esc_html__( 'Latest Optimization Analysis', 'hsp-smart-cache' ); ?></h3>
				<div class="hspsc-metric-row">
					<span>
						<strong><?php echo esc_html( intval( $db_analysis['table_count'] ) ); ?></strong>
						<?php echo esc_html__( 'Tables', 'hsp-smart-cache' ); ?>
					</span>
					<span>
						<strong><?php echo esc_html( intval( $db_analysis['optimizable_tables'] ) ); ?></strong>
						<?php echo esc_html__( 'Optimizable', 'hsp-smart-cache' ); ?>
					</span>
					<span>
						<strong><?php echo esc_html( size_format( intval( $db_analysis['total_size_bytes'] ) ) ); ?></strong>
						<?php echo esc_html__( 'Total size', 'hsp-smart-cache' ); ?>
					</span>
					<span>
						<strong><?php echo esc_html( size_format( intval( $db_analysis['total_overhead_bytes'] ) ) ); ?></strong>
						<?php echo esc_html__( 'Estimated reclaimable', 'hsp-smart-cache' ); ?>
					</span>
				</div>
				<?php if ( ! empty( $db_analysis['cleanup'] ) && is_array( $db_analysis['cleanup'] ) ) : ?>
					<h3><?php echo esc_html__( 'Cleanup Candidates', 'hsp-smart-cache' ); ?></h3>
					<div class="hspsc-metric-row hspsc-cleanup-row">
						<span>
							<strong><?php echo esc_html( intval( $db_analysis['cleanup']['revisions'] ) ); ?></strong>
							<?php echo esc_html__( 'Revisions', 'hsp-smart-cache' ); ?>
						</span>
						<span>
							<strong><?php echo esc_html( intval( $db_analysis['cleanup']['auto_drafts'] ) ); ?></strong>
							<?php echo esc_html__( 'Auto drafts', 'hsp-smart-cache' ); ?>
						</span>
						<span>
							<strong><?php echo esc_html( intval( $db_analysis['cleanup']['trashed_posts'] ) ); ?></strong>
							<?php echo esc_html__( 'Trashed posts', 'hsp-smart-cache' ); ?>
						</span>
						<span>
							<strong><?php echo esc_html( intval( $db_analysis['cleanup']['spam_trash_comments'] ) ); ?></strong>
							<?php echo esc_html__( 'Spam or trashed comments', 'hsp-smart-cache' ); ?>
						</span>
						<span>
							<strong><?php echo esc_html( intval( $db_analysis['cleanup']['expired_transient_timeouts'] ) ); ?></strong>
							<?php echo esc_html__( 'Expired transient timeouts', 'hsp-smart-cache' ); ?>
						</span>
						<span>
							<strong><?php echo esc_html( intval( $db_analysis['cleanup']['expired_transient_values'] ) ); ?></strong>
							<?php echo esc_html__( 'Expired transient values', 'hsp-smart-cache' ); ?>
						</span>
					</div>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d is the number of database rows that cleanup can remove. */
								_n( 'Clean Database can remove about %d row based on this analysis.', 'Clean Database can remove about %d rows based on this analysis.', intval( $db_analysis['cleanup']['total_items'] ), 'hsp-smart-cache' ),
								intval( $db_analysis['cleanup']['total_items'] )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>
			<?php
		endif;

		return ob_get_clean();
	}

	/**
	 * Builds escaped backup metadata and nonce-protected action forms.
	 *
	 * @param mixed $last_backup Last backup result or a missing transient.
	 * @param array $db_backups Available backup records from the maintenance module.
	 * @return string Escaped backup list and action markup.
	 */
	protected static function get_db_backups_html( $last_backup, $db_backups ) {
		ob_start();
		?>
		<h3><?php echo esc_html__( 'Database Backups', 'hsp-smart-cache' ); ?></h3>
		<?php if ( ! empty( $last_backup ) && is_array( $last_backup ) && ! empty( $last_backup['ok'] ) ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s is a backup file name. */
						__( 'Latest created backup: %s', 'hsp-smart-cache' ),
						$last_backup['file']
					)
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( empty( $db_backups ) ) : ?>
			<p class="description"><?php echo esc_html__( 'No backups available yet.', 'hsp-smart-cache' ); ?></p>
		<?php else : ?>
			<div class="hspsc-table-scroll">
				<table class="widefat striped hspsc-backups-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Backup', 'hsp-smart-cache' ); ?></th>
							<th><?php echo esc_html__( 'Timestamp', 'hsp-smart-cache' ); ?></th>
							<th><?php echo esc_html__( 'Size', 'hsp-smart-cache' ); ?></th>
							<th><?php echo esc_html__( 'Actions', 'hsp-smart-cache' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $db_backups as $backup ) : ?>
							<tr>
								<td><?php echo esc_html( $backup['file'] ); ?></td>
								<td><?php echo esc_html( $backup['timestamp'] ? gmdate( 'Y-m-d H:i:s', intval( $backup['timestamp'] ) ) . ' GMT' : '-' ); ?></td>
								<td><?php echo esc_html( size_format( intval( $backup['size_bytes'] ) ) ); ?></td>
								<td class="hspsc-row-actions">
									<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Restore this database backup?', 'hsp-smart-cache' ); ?>">
										<input type="hidden" name="action" value="hspsc_restore_db_backup" />
										<input type="hidden" name="backup_file" value="<?php echo esc_attr( $backup['file'] ); ?>" />
										<?php self::render_action_nonce( 'hspsc_restore_db_backup' ); ?>
										<button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Restoring...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Restore', 'hsp-smart-cache' ); ?></button>
									</form>
									<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Delete this database backup?', 'hsp-smart-cache' ); ?>">
										<input type="hidden" name="action" value="hspsc_delete_db_backup" />
										<input type="hidden" name="backup_file" value="<?php echo esc_attr( $backup['file'] ); ?>" />
										<?php self::render_action_nonce( 'hspsc_delete_db_backup' ); ?>
										<button type="submit" class="button hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Deleting...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Delete', 'hsp-smart-cache' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * Stops an unauthorized administrative action with an escaped error.
	 */
	protected static function deny_access() {
		wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
	}

	/**
	 * Performs a WordPress-safe redirect and normally terminates the request.
	 *
	 * @param string $url Return URL validated by WordPress before redirecting.
	 */
	protected static function safe_redirect( $url ) {
		wp_safe_redirect( $url );

		if ( ! self::should_skip_exit() ) {
			exit;
		}
	}

	/**
	 * Allows test harnesses to observe redirects without terminating PHP.
	 *
	 * @return bool Whether the exit behavior is disabled by the test filter.
	 */
	protected static function should_skip_exit() {
		$skip = apply_filters( 'hspsc_skip_admin_exit', false );
		return (bool) apply_filters( 'hspsc_skip_admin_exit', $skip );
	}

	/**
	 * Synchronizes the drop-in and invalidates all caches when settings change.
	 *
	 * @param mixed $old_value Previous option value supplied by the WordPress action.
	 * @param mixed $new_value New option value supplied by the WordPress action.
	 */
	public static function handle_settings_update( $old_value, $new_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Retains the WordPress update_option callback signature.
		HSPSC_Object::sync_dropin();
		HSPSC_Object::flush_cache();
		HSPSC_Page::clear_cache();
		HSPSC_Minify::clear_cache();
	}

	/**
	 * Describes the native Bricks settings to review after applying the preset.
	 *
	 * @return string Translated confirmation shared by form and AJAX responses.
	 */
	protected static function get_bricks_preset_message() {
		return __( 'Bricks compatibility preset applied. In Bricks > Settings > Performance, leave "Disable lazy loading" unchecked. Enable "Disable emojis" and "Disable embed" if those features are not needed. The preset also turns off HSP Smart Cache iframe lazy loading and script defer/async.', 'hsp-smart-cache' );
	}

	/**
	 * Disables overlapping frontend features while preserving other settings.
	 *
	 * @return array Sanitized settings saved by the compatibility preset.
	 */
	public static function apply_bricks_compatibility_preset() {
		$options = HSPSC_Settings::get_all();

		$options['perf_disable_emojis'] = false;
		$options['perf_disable_embeds'] = false;
		$options['perf_lazy_images']    = false;
		$options['perf_lazy_iframes']   = false;
		$options['render_defer_js']     = false;
		$options['render_async_js']     = false;

		$options = HSPSC_Settings::sanitize( $options );
		update_option( HSPSC_Settings::OPTION_KEY, $options );

		return $options;
	}

	/**
	 * Detects an available Bricks API or a Bricks parent or child theme.
	 *
	 * @return bool Whether the site appears to use Bricks.
	 */
	public static function is_bricks_detected() {
		if ( defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Theme', false ) || class_exists( 'Bricks\Theme', false ) ) {
			return true;
		}

		$theme  = wp_get_theme();
		$themes = array_filter(
			array(
				$theme ? $theme->get_stylesheet() : '',
				$theme ? $theme->get_template() : '',
				$theme ? $theme->get( 'Name' ) : '',
				$theme && $theme->parent() ? $theme->parent()->get( 'Name' ) : '',
			)
		);

		foreach ( $themes as $theme_value ) {
			if ( stripos( (string) $theme_value, 'bricks' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders all settings panels and one shared form for their controls.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = wp_parse_args( get_option( HSPSC_Settings::OPTION_KEY ), HSPSC_Settings::defaults() );
		$tabs    = array(
			'cache'        => __( 'Cache', 'hsp-smart-cache' ),
			'optimization' => __( 'Optimization', 'hsp-smart-cache' ),
			'maintenance'  => __( 'Maintenance', 'hsp-smart-cache' ),
			'advanced'     => __( 'Advanced', 'hsp-smart-cache' ),
		);
		?>
		<div class="wrap hspsc-admin">
			<h1><?php esc_html_e( 'HSP Smart Cache', 'hsp-smart-cache' ); ?></h1>
			<nav class="hspsc-tabs" aria-label="<?php esc_attr_e( 'HSP Smart Cache settings', 'hsp-smart-cache' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a id="hspsc-tab-<?php echo esc_attr( $key ); ?>" href="#hspsc-panel-<?php echo esc_attr( $key ); ?>" data-hspsc-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div id="hspsc-feedback" class="hspsc-feedback" role="status" aria-live="polite" aria-atomic="true"></div>
			<form id="hspsc-settings-form" class="hspsc-settings-form" method="post" action="options.php">
				<?php settings_fields( 'hspsc_settings_group' ); ?>
			</form>
			<section id="hspsc-panel-cache" class="hspsc-tab-panel" data-hspsc-panel="cache" aria-labelledby="hspsc-tab-cache">
				<?php self::render_cache_tab( $options ); ?>
			</section>
			<section id="hspsc-panel-optimization" class="hspsc-tab-panel" data-hspsc-panel="optimization" aria-labelledby="hspsc-tab-optimization">
				<?php self::render_optimization_tab( $options ); ?>
			</section>
			<section id="hspsc-panel-maintenance" class="hspsc-tab-panel" data-hspsc-panel="maintenance" aria-labelledby="hspsc-tab-maintenance">
				<?php self::render_maintenance_tab(); ?>
			</section>
			<section id="hspsc-panel-advanced" class="hspsc-tab-panel" data-hspsc-panel="advanced" aria-labelledby="hspsc-tab-advanced">
				<?php self::render_advanced_tab( $options ); ?>
			</section>
			<p class="description hspsc-credit">
				<?php esc_html_e( 'Made by Helmut Steiner Productions with ❤️ in Vienna', 'hsp-smart-cache' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Reads a display-only redirect code for a settings panel.
	 *
	 * @param string $key Notice query parameter.
	 * @return string Sanitized notice code.
	 */
	protected static function get_settings_notice( $key ) {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
		return is_string( $value ) ? sanitize_key( $value ) : '';
	}

	/**
	 * Shows saved cache settings, routine actions, and rebuild results.
	 *
	 * @param array $options Saved plugin settings with defaults applied.
	 */
	protected static function render_cache_tab( $options ) {
		$cache_notice = self::get_settings_notice( 'cache' );
		$statuses     = array(
			'page_cache'      => __( 'Public page cache', 'hsp-smart-cache' ),
			'cache_logged_in' => __( 'Logged-in page cache', 'hsp-smart-cache' ),
			'browser_cache'   => __( 'Browser cache headers', 'hsp-smart-cache' ),
			'object_cache'    => __( 'Persistent object cache', 'hsp-smart-cache' ),
		);
		?>
		<div class="hspsc-card">
			<h2 class="hspsc-section-title"><?php esc_html_e( 'Cache Operations', 'hsp-smart-cache' ); ?></h2>
			<p class="hspsc-section-lede"><?php esc_html_e( 'Rebuild the public page cache in the background, or clear the page, asset, and object caches.', 'hsp-smart-cache' ); ?></p>
			<?php if ( 'cleared' === $cache_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Cache cleared.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Saved cache settings', 'hsp-smart-cache' ); ?></h3>
			<dl class="hspsc-cache-summary">
				<?php foreach ( $statuses as $key => $label ) : ?>
					<?php $enabled = ! empty( $options[ $key ] ); ?>
					<div class="hspsc-cache-status">
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd data-hspsc-saved-option="<?php echo esc_attr( $key ); ?>" data-enabled="<?php echo esc_attr( $enabled ? '1' : '0' ); ?>"><?php echo esc_html( $enabled ? __( 'Enabled', 'hsp-smart-cache' ) : __( 'Disabled', 'hsp-smart-cache' ) ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
			<div class="hspsc-cache-actions">
				<form class="hspsc-rebuild-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hspsc_run_preload" />
					<?php self::render_action_nonce( 'hspsc_run_preload' ); ?>
					<button type="submit" class="button button-primary hspsc-action-button"><?php esc_html_e( 'Rebuild all public page caches', 'hsp-smart-cache' ); ?></button>
				</form>
				<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hspsc_clear" />
					<?php self::render_action_nonce( 'hspsc_clear' ); ?>
					<button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php esc_attr_e( 'Clearing...', 'hsp-smart-cache' ); ?>"><?php esc_html_e( 'Clear all caches', 'hsp-smart-cache' ); ?></button>
				</form>
			</div>
			<?php HSPSC_Rebuild_Admin::render_report(); ?>
		</div>
		<div class="hspsc-card hspsc-settings-section">
			<h2 class="hspsc-section-title"><?php esc_html_e( 'Cache settings', 'hsp-smart-cache' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Page Cache', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[page_cache]" value="1" <?php checked( $options['page_cache'] ); ?> title="<?php echo esc_attr__( 'Cache full HTML pages for visitors.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Enable page caching for anonymous visitors', 'hsp-smart-cache' ); ?>
						</label>
						<p>
							<label>
								<?php echo esc_html__( 'Cache TTL (seconds)', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="number" min="60" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[page_cache_ttl]" value="<?php echo esc_attr( $options['page_cache_ttl'] ); ?>" title="<?php echo esc_attr__( 'How long a page stays cached before regeneration.', 'hsp-smart-cache' ); ?>" />
							</label>
						</p>
						<p>
							<label for="hspsc-page-cache-excluded-paths"><?php echo esc_html__( 'Excluded page paths', 'hsp-smart-cache' ); ?></label>
							<textarea form="hspsc-settings-form" id="hspsc-page-cache-excluded-paths" class="large-text ltr" rows="4" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[page_cache_excluded_paths]" placeholder="/booking/&#10;/member-area/" aria-describedby="hspsc-page-cache-excluded-paths-help"><?php echo esc_textarea( $options['page_cache_excluded_paths'] ); ?></textarea>
						</p>
						<p id="hspsc-page-cache-excluded-paths-help" class="description">
							<?php echo esc_html__( 'One exact URL path per line, starting with /. Include the installation subdirectory and translated paths when needed. Queries and trailing slashes do not affect matching. Subpages, full URLs, and wildcards are not included. Asset optimizations remain enabled.', 'hsp-smart-cache' ); ?>
						</p>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[cache_logged_in]" value="1" <?php checked( $options['cache_logged_in'] ); ?> title="<?php echo esc_attr__( 'Enable only if logged-in users see cache-safe content.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Cache eligible frontend pages for logged-in users', 'hsp-smart-cache' ); ?>
						</label>
						<p class="description">
							<?php echo esc_html__( 'WordPress admin and settings pages always bypass the page cache.', 'hsp-smart-cache' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Browser Cache Headers', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[browser_cache]" value="1" <?php checked( $options['browser_cache'] ); ?> title="<?php echo esc_attr__( 'Send Cache-Control and ETag headers for cached pages.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Send Cache-Control headers for cacheable pages', 'hsp-smart-cache' ); ?>
						</label>
						<p>
							<label>
								<?php echo esc_html__( 'HTML cache TTL (seconds)', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="number" min="60" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[browser_cache_html_ttl]" value="<?php echo esc_attr( $options['browser_cache_html_ttl'] ); ?>" title="<?php echo esc_attr__( 'Browser cache lifetime for HTML pages.', 'hsp-smart-cache' ); ?>" />
							</label>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Asset cache TTL (seconds)', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="number" min="60" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[browser_cache_asset_ttl]" value="<?php echo esc_attr( $options['browser_cache_asset_ttl'] ); ?>" title="<?php echo esc_attr__( 'Browser cache lifetime for static assets.', 'hsp-smart-cache' ); ?>" />
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Static Asset Caching', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_cache]" value="1" <?php checked( $options['static_asset_cache'] ); ?> title="<?php echo esc_attr__( 'Cache generated CSS/JS for longer and revalidate other static files.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Enable safe browser cache rules for static assets', 'hsp-smart-cache' ); ?>
						</label>
						<p>
							<label>
								<?php echo esc_html__( 'Generated CSS/JS TTL (seconds)', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="number" min="60" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_ttl]" value="<?php echo esc_attr( $options['static_asset_ttl'] ); ?>" title="<?php echo esc_attr__( 'Cache lifetime for HSP-generated CSS/JS via web server rules.', 'hsp-smart-cache' ); ?>" />
							</label>
						</p>
						<p>
							<label>
								<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_immutable]" value="1" <?php checked( $options['static_asset_immutable'] ); ?> title="<?php echo esc_attr__( 'Add immutable only to HSP-generated CSS/JS.', 'hsp-smart-cache' ); ?>" />
								<?php echo esc_html__( 'Add immutable to generated CSS/JS', 'hsp-smart-cache' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_auto_write]" value="1" <?php checked( $options['static_asset_auto_write'] ); ?> title="<?php echo esc_attr__( 'Automatically update .htaccess if writable.', 'hsp-smart-cache' ); ?>" />
								<?php echo esc_html__( 'Auto-write .htaccess rules when possible', 'hsp-smart-cache' ); ?>
							</label>
						</p>
						<p class="description" style="color:#b32d2e;">
							<?php echo esc_html__( 'Warning: Incorrect .htaccess rules can break site access. Use with care.', 'hsp-smart-cache' ); ?>
						</p>
						<p>
							<label>
								<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_compression]" value="1" <?php checked( $options['static_asset_compression'] ); ?> title="<?php echo esc_attr__( 'Enable gzip/deflate compression via web server rules.', 'hsp-smart-cache' ); ?>" />
								<?php echo esc_html__( 'Enable gzip/deflate compression', 'hsp-smart-cache' ); ?>
							</label>
						</p>
						<p class="description" style="color:#b32d2e;">
							<?php echo esc_html__( 'Warning: If your server already manages compression, double-compression can cause issues.', 'hsp-smart-cache' ); ?>
						</p>
						<p>
							<label for="hsp-htaccess-preview"><?php echo esc_html__( 'Apache .htaccess snippet', 'hsp-smart-cache' ); ?></label><br />
							<textarea form="hspsc-settings-form"
								class="large-text ltr"
								rows="8"
								readonly
								id="hsp-htaccess-preview"
								data-template-cache="<?php echo esc_attr( HSPSC_Static_Assets::get_htaccess_rules( 0, false, false ) ); ?>"
								data-template-cache-immutable="<?php echo esc_attr( HSPSC_Static_Assets::get_htaccess_rules( 0, true, false ) ); ?>"
								data-template-cache-compress="<?php echo esc_attr( HSPSC_Static_Assets::get_htaccess_rules( 0, false, true ) ); ?>"
								data-template-cache-immutable-compress="<?php echo esc_attr( HSPSC_Static_Assets::get_htaccess_rules( 0, true, true ) ); ?>"
							><?php echo esc_textarea( HSPSC_Static_Assets::get_htaccess_rules( $options['static_asset_ttl'], $options['static_asset_immutable'], $options['static_asset_compression'] ) ); ?></textarea>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Object Cache', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[object_cache]" value="1" <?php checked( $options['object_cache'] ); ?> title="<?php echo esc_attr__( 'Use file-based persistent object cache drop-in.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Enable file-based persistent object cache (drop-in)', 'hsp-smart-cache' ); ?>
						</label>
						<p>
							<label>
								<?php echo esc_html__( 'Default TTL for uncached entries (seconds)', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="number" min="0" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[object_cache_default_ttl]" value="<?php echo esc_attr( $options['object_cache_default_ttl'] ); ?>" title="<?php echo esc_attr__( 'Used when an object cache write does not provide an expiration.', 'hsp-smart-cache' ); ?>" />
							</label>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Maximum TTL cap (seconds)', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="number" min="0" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[object_cache_max_ttl]" value="<?php echo esc_attr( $options['object_cache_max_ttl'] ); ?>" title="<?php echo esc_attr__( 'Caps any longer object cache expiration and bounds legacy unlimited entries.', 'hsp-smart-cache' ); ?>" />
							</label>
						</p>
						<p class="description" style="color:#b32d2e;">
							<?php echo esc_html__( 'Warning: Object cache drop-ins can conflict with other cache plugins. Entries without an expiration are now capped by the maximum TTL and periodically cleaned up.', 'hsp-smart-cache' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Public page cache rebuild', 'hsp-smart-cache' ); ?></th>
					<td>
						<p class="description"><?php esc_html_e( 'Rebuilds start only when you request them and continue in the background. An all-pages rebuild discovers public URLs from the sitemap.', 'hsp-smart-cache' ); ?></p>
						<p>
							<label>
								<?php echo esc_html__( 'Sitemap URL', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="url" class="regular-text" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[preload_sitemap_url]" value="<?php echo esc_attr( $options['preload_sitemap_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-sitemap.xml' ) ); ?>" />
							</label>
							<span class="description"><?php esc_html_e( 'Leave blank to use the site’s default sitemap.', 'hsp-smart-cache' ); ?></span>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Request timeout (seconds)', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="number" min="3" max="<?php echo esc_attr( HSPSC_Rebuild::MAX_TIMEOUT ); ?>" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[preload_timeout]" value="<?php echo esc_attr( max( 3, min( HSPSC_Rebuild::MAX_TIMEOUT, (int) $options['preload_timeout'] ) ) ); ?>" aria-describedby="hspsc-rebuild-timeout-description" />
							</label>
							<span id="hspsc-rebuild-timeout-description" class="description"><?php esc_html_e( 'Maximum time to wait for each page or sitemap request during a rebuild.', 'hsp-smart-cache' ); ?></span>
						</p>
					</td>
				</tr>
			</table>
			<?php self::render_settings_save_button(); ?>
		</div>
		<?php
	}

	/**
	 * Shows controls for asset loading, minification, and CDN rewriting.
	 *
	 * @param array $options Saved plugin settings with defaults applied.
	 */
	protected static function render_optimization_tab( $options ) {
		?>
		<div class="hspsc-card hspsc-settings-section">
			<h2 class="hspsc-section-title"><?php esc_html_e( 'Frontend optimization', 'hsp-smart-cache' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Minification', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[minify_html]" value="1" <?php checked( $options['minify_html'] ); ?> title="<?php echo esc_attr__( 'Remove whitespace/comments from HTML.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Minify HTML', 'hsp-smart-cache' ); ?>
						</label><br />
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[minify_css]" value="1" <?php checked( $options['minify_css'] ); ?> title="<?php echo esc_attr__( 'Create minified CSS files in the cache.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Minify CSS files', 'hsp-smart-cache' ); ?>
						</label><br />
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[minify_js]" value="1" <?php checked( $options['minify_js'] ); ?> title="<?php echo esc_attr__( 'Create minified JS files in the cache.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Minify JS files', 'hsp-smart-cache' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Render Blocking Optimization', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_defer_js]" value="1" <?php checked( $options['render_defer_js'] ); ?> title="<?php echo esc_attr__( 'Add defer to scripts to reduce render blocking.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Add defer to enqueued scripts', 'hsp-smart-cache' ); ?>
						</label>
						<p class="description" style="color:#b32d2e;">
							<?php echo esc_html__( 'Warning: Deferring scripts may break plugins that expect blocking execution.', 'hsp-smart-cache' ); ?>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Defer exclusions (script handles, one per line)', 'hsp-smart-cache' ); ?>
								<textarea form="hspsc-settings-form" class="large-text ltr" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_defer_exclusions]" placeholder="jquery\nwp-embed" title="<?php echo esc_attr__( 'Script handles that must not be deferred.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_defer_exclusions'] ); ?></textarea>
							</label>
						</p>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_async_js]" value="1" <?php checked( $options['render_async_js'] ); ?> title="<?php echo esc_attr__( 'Use async when defer is disabled.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Add async to enqueued scripts (if defer is off)', 'hsp-smart-cache' ); ?>
						</label>
						<p class="description" style="color:#b32d2e;">
							<?php echo esc_html__( 'Warning: Async can change execution order and break dependencies.', 'hsp-smart-cache' ); ?>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Async exclusions (script handles, one per line)', 'hsp-smart-cache' ); ?>
								<textarea form="hspsc-settings-form" class="large-text ltr" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_async_exclusions]" placeholder="jquery\nwp-embed" title="<?php echo esc_attr__( 'Script handles that must not be async.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_async_exclusions'] ); ?></textarea>
							</label>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Preconnect URLs (one per line)', 'hsp-smart-cache' ); ?>
								<textarea form="hspsc-settings-form" class="large-text ltr" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_preconnect_urls]" placeholder="https://fonts.gstatic.com" title="<?php echo esc_attr__( 'Origins to preconnect for faster TLS/handshake.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preconnect_urls'] ); ?></textarea>
							</label>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Preload font URLs (one per line)', 'hsp-smart-cache' ); ?>
								<textarea form="hspsc-settings-form" class="large-text ltr" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_preload_fonts]" placeholder="https://example.com/fonts/myfont.woff2" title="<?php echo esc_attr__( 'Font files to preload early.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preload_fonts'] ); ?></textarea>
							</label>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Preload CSS URLs (one per line)', 'hsp-smart-cache' ); ?>
								<textarea form="hspsc-settings-form" class="large-text ltr" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_preload_css]" placeholder="https://example.com/style.css" title="<?php echo esc_attr__( 'Stylesheets to preload before render.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preload_css'] ); ?></textarea>
							</label>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'Inline critical CSS', 'hsp-smart-cache' ); ?>
								<textarea form="hspsc-settings-form" class="large-text ltr" rows="5" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_critical_css]" placeholder="/* Critical CSS */" title="<?php echo esc_attr__( 'CSS injected inline to speed up first render.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_critical_css'] ); ?></textarea>
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Additional Performance', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_lazy_images]" value="1" <?php checked( $options['perf_lazy_images'] ); ?> title="<?php echo esc_attr__( 'Adds loading="lazy" to images where safe.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Enable native lazy-loading for images', 'hsp-smart-cache' ); ?>
						</label><br />
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_lazy_iframes]" value="1" <?php checked( $options['perf_lazy_iframes'] ); ?> title="<?php echo esc_attr__( 'Adds loading="lazy" to iframes where safe.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Enable native lazy-loading for iframes', 'hsp-smart-cache' ); ?>
						</label><br />
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_decoding_async]" value="1" <?php checked( $options['perf_decoding_async'] ); ?> title="<?php echo esc_attr__( 'Hints browser to decode images asynchronously.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Add decoding="async" to images', 'hsp-smart-cache' ); ?>
						</label><br />
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_disable_emojis]" value="1" <?php checked( $options['perf_disable_emojis'] ); ?> title="<?php echo esc_attr__( 'Remove emoji scripts and styles for faster loads.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Disable emoji scripts and styles', 'hsp-smart-cache' ); ?>
						</label><br />
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_disable_embeds]" value="1" <?php checked( $options['perf_disable_embeds'] ); ?> title="<?php echo esc_attr__( 'Disable oEmbed discovery and scripts.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Disable WordPress embeds', 'hsp-smart-cache' ); ?>
						</label><br />
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_disable_dashicons]" value="1" <?php checked( $options['perf_disable_dashicons'] ); ?> title="<?php echo esc_attr__( 'Remove Dashicons for non-logged-in visitors.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Disable Dashicons for guests', 'hsp-smart-cache' ); ?>
						</label>
						<p class="description" style="color:#b32d2e;">
							<?php echo esc_html__( 'Warning: Disabling embeds or Dashicons can affect themes or plugins that rely on them.', 'hsp-smart-cache' ); ?>
						</p>
						<p>
							<label>
								<?php echo esc_html__( 'DNS prefetch URLs (one per line)', 'hsp-smart-cache' ); ?>
								<textarea form="hspsc-settings-form" class="large-text ltr" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_dns_prefetch_urls]" placeholder="//fonts.googleapis.com" title="<?php echo esc_attr__( 'Hostnames to DNS-prefetch.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['perf_dns_prefetch_urls'] ); ?></textarea>
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'CDN', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[cdn_enabled]" value="1" <?php checked( $options['cdn_enabled'] ); ?> title="<?php echo esc_attr__( 'Rewrite static asset URLs to your CDN.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Enable CDN URL rewriting for static assets', 'hsp-smart-cache' ); ?>
						</label>
						<p>
							<label>
								<?php echo esc_html__( 'CDN Base URL', 'hsp-smart-cache' ); ?>
								<input form="hspsc-settings-form" type="url" class="regular-text" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[cdn_url]" value="<?php echo esc_attr( $options['cdn_url'] ); ?>" placeholder="https://cdn.example.com" title="<?php echo esc_attr__( 'Base URL used for CDN rewriting.', 'hsp-smart-cache' ); ?>" />
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logged-in visitors', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[optimize_logged_in]" value="1" <?php checked( $options['optimize_logged_in'] ); ?> title="<?php echo esc_attr__( 'Apply frontend optimizations for logged-in users.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Allow frontend optimizations for logged-in users', 'hsp-smart-cache' ); ?>
						</label>
						<p class="description">
							<?php echo esc_html__( 'Recommended off for editors/builders. Controls minify, script defer/async, and frontend performance tweaks.', 'hsp-smart-cache' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php self::render_settings_save_button(); ?>
		</div>
		<?php
	}

	/**
	 * Shows database maintenance, analysis, and backup controls.
	 */
	protected static function render_maintenance_tab() {
		$db_notice         = self::get_settings_notice( 'db' );
		$db_analysis       = get_transient( 'hspsc_db_analysis' );
		$last_backup       = get_transient( 'hspsc_db_last_backup' );
		$db_restore_result = get_transient( 'hspsc_db_restore_result' );
		if ( $db_restore_result ) {
			delete_transient( 'hspsc_db_restore_result' );
		}
		$db_restore_error = get_transient( 'hspsc_db_restore_error' );
		if ( $db_restore_error ) {
			delete_transient( 'hspsc_db_restore_error' );
		}
		$db_delete_error = get_transient( 'hspsc_db_delete_error' );
		if ( $db_delete_error ) {
			delete_transient( 'hspsc_db_delete_error' );
		}
		$db_backups = HSPSC_Maintenance::list_backups();
		?>
		<div class="hspsc-card hspsc-maintenance-panel" aria-labelledby="hspsc-maintenance-title">
			<h2 id="hspsc-maintenance-title" class="hspsc-section-title"><?php echo esc_html__( 'Database Maintenance', 'hsp-smart-cache' ); ?></h2>
			<p class="hspsc-section-lede"><?php echo esc_html__( 'Inspect, clean, optimize, and manage generated backups from one workspace.', 'hsp-smart-cache' ); ?></p>

			<?php if ( 'cleaned' === $db_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Database cleanup completed.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'cleanupfailed' === $db_notice ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'Database cleanup failed. Some queries did not complete.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'optimized' === $db_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Database optimization completed.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'optimizefailed' === $db_notice ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'Database optimization failed. Some tables were not optimized.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'analyzed' === $db_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Database analysis completed.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'backupfailed' === $db_notice ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'Database backup failed. Optimization was not executed.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'backupcreated' === $db_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Database backup created.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'restored' === $db_notice ) : ?>
				<div class="notice notice-success">
					<p><?php echo esc_html( self::get_restore_backup_success_message( $db_restore_result ) ); ?></p>
					<?php self::render_restore_backup_details( self::get_restore_backup_success_details( $db_restore_result ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( 'restorefailed' === $db_notice ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html( self::get_restore_backup_error_message( $db_restore_error ) ); ?></p>
					<?php self::render_restore_backup_details( self::get_restore_backup_error_details( $db_restore_error ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( 'backupdeleted' === $db_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Database backup deleted.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'deletefailed' === $db_notice ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( self::get_delete_backup_error_message( $db_delete_error ) ); ?></p></div>
			<?php endif; ?>

			<div class="hspsc-maintenance-actions">
				<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hspsc_analyze_db" />
					<?php self::render_action_nonce( 'hspsc_analyze_db' ); ?>
					<button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Analyzing...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Analyze', 'hsp-smart-cache' ); ?></button>
				</form>
				<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Run database cleanup now?', 'hsp-smart-cache' ); ?>">
					<input type="hidden" name="action" value="hspsc_run_db_cleanup" />
					<?php self::render_action_nonce( 'hspsc_run_db_cleanup' ); ?>
					<button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Cleaning...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Clean Database', 'hsp-smart-cache' ); ?></button>
				</form>
				<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hspsc_create_db_backup" />
					<?php self::render_action_nonce( 'hspsc_create_db_backup' ); ?>
					<button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Creating...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Create Backup', 'hsp-smart-cache' ); ?></button>
				</form>
				<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Create a backup and optimize database tables now?', 'hsp-smart-cache' ); ?>">
					<input type="hidden" name="action" value="hspsc_optimize_db" />
					<?php self::render_action_nonce( 'hspsc_optimize_db' ); ?>
					<button type="submit" class="button button-primary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Optimizing...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Optimize Tables', 'hsp-smart-cache' ); ?></button>
				</form>
			</div>

			<p class="hspsc-note"><?php echo esc_html__( 'A timestamped backup is created automatically before optimization starts.', 'hsp-smart-cache' ); ?></p>
			<p class="hspsc-callout"><?php echo esc_html__( 'Database cleanup deletes revisions, trashed posts, spam comments, and expired transients. Table optimization can be slow on large databases.', 'hsp-smart-cache' ); ?></p>

			<div id="hspsc-db-analysis-panel" class="hspsc-dynamic-panel">
				<?php echo self::get_db_analysis_html( $db_analysis ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The rendering helper escapes each dynamic value in its appropriate HTML context. ?>
			</div>

			<div id="hspsc-db-backups-panel" class="hspsc-dynamic-panel hspsc-backups">
				<?php echo self::get_db_backups_html( $last_backup, $db_backups ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The rendering helper escapes each dynamic value in its appropriate HTML context. ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Shows diagnostics, compatibility controls, and site-wide options.
	 *
	 * @param array $options Saved plugin settings with defaults applied.
	 */
	protected static function render_advanced_tab( $options ) {
		$test_results    = get_transient( 'hspsc_test_results' );
		$tests_notice    = self::get_settings_notice( 'tests' );
		$settings_notice = self::get_settings_notice( 'settings' );
		$bricks_detected = self::is_bricks_detected();
		?>
		<div class="hspsc-card">
			<h2 class="hspsc-section-title"><?php esc_html_e( 'Diagnostics', 'hsp-smart-cache' ); ?></h2>
			<?php if ( 'done' === $tests_notice && is_array( $test_results ) ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'Cache tests completed.', 'hsp-smart-cache' ); ?></p>
				</div>
			<?php endif; ?>
			<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hspsc_run_tests" />
				<?php self::render_action_nonce( 'hspsc_run_tests' ); ?>
				<button type="submit" class="button button-secondary" data-loading-text="<?php esc_attr_e( 'Testing...', 'hsp-smart-cache' ); ?>"><?php esc_html_e( 'Run Cache Tests', 'hsp-smart-cache' ); ?></button>
			</form>
			<div id="hspsc-tests-panel" class="hspsc-dynamic-panel">
				<?php echo self::get_cache_tests_html( $test_results ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The rendering helper escapes each dynamic value in its appropriate HTML context. ?>
			</div>
		</div>
		<div class="hspsc-card">
			<h2 class="hspsc-section-title"><?php esc_html_e( 'Compatibility and defaults', 'hsp-smart-cache' ); ?></h2>
			<?php if ( 'restored' === $settings_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Defaults restored.', 'hsp-smart-cache' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'bricks-preset' === $settings_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( self::get_bricks_preset_message() ); ?></p></div>
			<?php endif; ?>
			<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php esc_attr_e( 'Apply the Bricks compatibility preset? This disables overlapping HSP Smart Cache optimizations so Bricks can own them.', 'hsp-smart-cache' ); ?>">
				<input type="hidden" name="action" value="hspsc_apply_bricks_preset" />
				<?php self::render_action_nonce( 'hspsc_apply_bricks_preset' ); ?>
				<button type="submit" class="button button-secondary" data-loading-text="<?php esc_attr_e( 'Applying...', 'hsp-smart-cache' ); ?>"><?php esc_html_e( 'Apply Bricks Preset', 'hsp-smart-cache' ); ?></button>
			</form>
			<p class="hspsc-compat-note">
				<?php
				echo esc_html(
					$bricks_detected
						? __( 'Bricks detected. This preset turns off duplicate HSP lazy-load, emoji/embed cleanup, and script defer/async.', 'hsp-smart-cache' )
						: __( 'Use this on Bricks sites to let Bricks handle its native performance toggles.', 'hsp-smart-cache' )
				);
				?>
			</p>
			<form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php esc_attr_e( 'Restore all plugin settings to defaults?', 'hsp-smart-cache' ); ?>">
				<input type="hidden" name="action" value="hspsc_restore_defaults" />
				<?php self::render_action_nonce( 'hspsc_restore_defaults' ); ?>
				<button type="submit" class="button" data-loading-text="<?php esc_attr_e( 'Restoring...', 'hsp-smart-cache' ); ?>"><?php esc_html_e( 'Restore Defaults', 'hsp-smart-cache' ); ?></button>
			</form>
		</div>
		<div class="hspsc-card hspsc-settings-section">
			<h2 class="hspsc-section-title"><?php esc_html_e( 'Site settings', 'hsp-smart-cache' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Robots.txt', 'hsp-smart-cache' ); ?></th>
					<td>
						<label>
							<input form="hspsc-settings-form" type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[robots_disallow_ai]" value="1" <?php checked( $options['robots_disallow_ai'] ); ?> title="<?php echo esc_attr__( 'Add disallow rules for known AI crawlers.', 'hsp-smart-cache' ); ?>" />
							<?php echo esc_html__( 'Disallow common AI crawlers', 'hsp-smart-cache' ); ?>
						</label>
						<p class="description">
							<?php echo esc_html__( 'Adds rules via WordPress robots.txt output (no file write).', 'hsp-smart-cache' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php self::render_settings_save_button(); ?>
		</div>
		<?php
	}

	/**
	 * Renders a submit control associated with the shared settings form.
	 */
	protected static function render_settings_save_button() {
		?>
		<div class="hspsc-settings-actions">
			<button type="submit" form="hspsc-settings-form" class="button button-primary" data-loading-text="<?php esc_attr_e( 'Saving...', 'hsp-smart-cache' ); ?>"><?php esc_html_e( 'Save all settings', 'hsp-smart-cache' ); ?></button>
			<span data-hspsc-unsaved-settings role="status" hidden></span>
		</div>
		<?php
	}

	/**
	 * Prints an action nonce without repeating the shared settings form's ID.
	 *
	 * @param string $action Registered nonce action.
	 */
	protected static function render_action_nonce( $action ) {
		?>
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( $action ) ); ?>" />
		<?php wp_referer_field(); ?>
		<?php
	}
}
