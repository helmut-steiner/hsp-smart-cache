<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
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
        add_action( 'wp_ajax_hspsc_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_hspsc_clear', array( __CLASS__, 'ajax_clear_cache' ) );
        add_action( 'wp_ajax_hspsc_run_tests', array( __CLASS__, 'ajax_run_tests' ) );
        add_action( 'wp_ajax_hspsc_restore_defaults', array( __CLASS__, 'ajax_restore_defaults' ) );
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

    public static function register_menu() {
        add_options_page(
            __( 'HSP Smart Cache', 'hsp-smart-cache' ),
            __( 'HSP Smart Cache', 'hsp-smart-cache' ),
            'manage_options',
            'hsp-smart-cache',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function handle_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_clear' );
        HSPSC_Plugin::flush_all_caches();
        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&cache=cleared' ) );
    }

    public static function handle_clear_current() {
        self::ensure_admin_bar_access( 'hspsc_clear_current' );
        $url = self::get_target_url();
        if ( $url ) {
            HSPSC_Page::clear_cache_for_url( $url );
        }
        self::redirect_back();
    }

    public static function handle_clear_all() {
        self::ensure_admin_bar_access( 'hspsc_clear_all' );
        HSPSC_Plugin::flush_all_caches();
        self::redirect_back();
    }

    public static function handle_rebuild_current() {
        self::ensure_admin_bar_access( 'hspsc_rebuild_current' );
        $url = self::get_target_url();
        if ( $url ) {
            HSPSC_Page::clear_cache_for_url( $url );
            HSPSC_Page::warm_url( $url );
        }
        self::redirect_back();
    }

    public static function handle_rebuild_all() {
        self::ensure_admin_bar_access( 'hspsc_rebuild_all' );
        HSPSC_Plugin::flush_all_caches();
        HSPSC_Page::warm_urls( array( home_url( '/' ) ) );
        if ( HSPSC_Settings::get( 'preload_enabled' ) ) {
            HSPSC_Preload::run();
        }
        self::redirect_back();
    }

    public static function register_admin_bar( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $wp_admin_bar->add_node(
            array(
                'id'    => 'hspsc',
                'title' => __( 'HSP Cache', 'hsp-smart-cache' ),
                'href'  => admin_url( 'options-general.php?page=hsp-smart-cache' ),
            )
        );

        $is_frontend = ! is_admin();

        if ( $is_frontend ) {
            $wp_admin_bar->add_node(
                array(
                    'id'     => 'hspsc-clear-current',
                    'parent' => 'hspsc',
                    'title'  => __( 'Delete cache of current page', 'hsp-smart-cache' ),
                    'href'   => self::action_url( 'hspsc_clear_current' ),
                )
            );
            $wp_admin_bar->add_node(
                array(
                    'id'     => 'hspsc-rebuild-current',
                    'parent' => 'hspsc',
                    'title'  => __( 'Rebuild current page cache', 'hsp-smart-cache' ),
                    'href'   => self::action_url( 'hspsc_rebuild_current' ),
                )
            );
        }

        $wp_admin_bar->add_node(
            array(
                'id'     => 'hspsc-clear-all',
                'parent' => 'hspsc',
                'title'  => __( 'Delete all cache files', 'hsp-smart-cache' ),
                'href'   => self::action_url( 'hspsc_clear_all' ),
            )
        );

        $wp_admin_bar->add_node(
            array(
                'id'     => 'hspsc-rebuild-all',
                'parent' => 'hspsc',
                'title'  => __( 'Rebuild all caches', 'hsp-smart-cache' ),
                'href'   => self::action_url( 'hspsc_rebuild_all' ),
            )
        );
    }

    protected static function action_url( $action ) {
        $args = array(
            'action' => $action,
            '_wpnonce' => wp_create_nonce( $action ),
        );

        $url = admin_url( 'admin-post.php' );
        $referer = wp_get_referer();
        if ( $referer ) {
            $args['hspsc_return'] = rawurlencode( $referer );
        }

        return add_query_arg( $args, $url );
    }

    protected static function get_target_url() {
        $return = filter_input( INPUT_GET, 'hspsc_return', FILTER_UNSAFE_RAW );
        if ( $return === null && isset( $_GET['hspsc_return'] ) ) {
            $return = wp_unslash( $_GET['hspsc_return'] );
        }
        if ( ! empty( $return ) ) {
            $return = rawurldecode( $return );
            return esc_url_raw( $return );
        }
        $referer = wp_get_referer();
        if ( $referer ) {
            return esc_url_raw( $referer );
        }
        return home_url( '/' );
    }

    protected static function redirect_back() {
        $url = self::get_target_url();
        self::safe_redirect( $url );
    }

    protected static function ensure_admin_bar_access( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( $nonce_action );
    }

    public static function handle_run_tests() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_run_tests' );
        $results = HSPSC_Tests::run();
        set_transient( 'hspsc_test_results', $results, 300 );
        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&tests=done' ) );
    }

    public static function handle_restore_defaults() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_restore_defaults' );
        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
        HSPSC_Object::sync_dropin();
        HSPSC_Page::clear_cache();
        HSPSC_Minify::clear_cache();
        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&settings=restored' ) );
    }

    public static function handle_run_preload() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_run_preload' );
        $result = HSPSC_Preload::run();
        set_transient( 'hspsc_preload_result', $result, 300 );
        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&preload=done' ) );
    }

    public static function handle_run_db_cleanup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_run_db_cleanup' );
        $result = HSPSC_Maintenance::run_db_cleanup();
        if ( empty( $result['ok'] ) ) {
            self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=cleanupfailed' ) );
        }

        $analysis = HSPSC_Maintenance::analyze_optimization();
        set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );
        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=cleaned' ) );
    }

    public static function handle_analyze_db() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_analyze_db' );

        $analysis = HSPSC_Maintenance::analyze_optimization();
        set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );

        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=analyzed' ) );
    }

    public static function handle_create_db_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_create_db_backup' );

        $backup = HSPSC_Maintenance::create_backup();
        if ( empty( $backup['ok'] ) ) {
            self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=backupfailed' ) );
        }

        set_transient( 'hspsc_db_last_backup', $backup, 15 * MINUTE_IN_SECONDS );

        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=backupcreated' ) );
    }

    public static function handle_optimize_db() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_optimize_db' );

        $backup = HSPSC_Maintenance::create_backup();
        if ( empty( $backup['ok'] ) ) {
            self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=backupfailed' ) );
        }

        set_transient( 'hspsc_db_last_backup', $backup, 15 * MINUTE_IN_SECONDS );

        $optimize = HSPSC_Maintenance::optimize_tables();
        if ( empty( $optimize['ok'] ) ) {
            self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=optimizefailed' ) );
        }

        $analysis = HSPSC_Maintenance::analyze_optimization();
        set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );

        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=optimized' ) );
    }

    public static function handle_restore_db_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_restore_db_backup' );

        $backup_file = filter_input( INPUT_POST, 'backup_file', FILTER_UNSAFE_RAW );
        if ( $backup_file === null && isset( $_POST['backup_file'] ) ) {
            $backup_file = wp_unslash( $_POST['backup_file'] );
        }
        $backup_file = $backup_file ? sanitize_file_name( wp_unslash( $backup_file ) ) : '';

        $result = HSPSC_Maintenance::restore_backup( $backup_file );
        if ( empty( $result['ok'] ) ) {
            self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=restorefailed' ) );
        }

        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=restored' ) );
    }

    public static function handle_delete_db_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::deny_access();
        }
        check_admin_referer( 'hspsc_delete_db_backup' );

        $backup_file = filter_input( INPUT_POST, 'backup_file', FILTER_UNSAFE_RAW );
        if ( $backup_file === null && isset( $_POST['backup_file'] ) ) {
            $backup_file = wp_unslash( $_POST['backup_file'] );
        }
        $backup_file = $backup_file ? sanitize_file_name( wp_unslash( $backup_file ) ) : '';

        $deleted = HSPSC_Maintenance::delete_backup( $backup_file );
        self::safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=' . ( $deleted ? 'backupdeleted' : 'deletefailed' ) ) );
    }

    public static function ajax_save_settings() {
        self::ensure_ajax_access( 'hspsc_settings_group-options' );

        $raw_options = isset( $_POST[ HSPSC_Settings::OPTION_KEY ] ) ? wp_unslash( $_POST[ HSPSC_Settings::OPTION_KEY ] ) : array();
        $options = HSPSC_Settings::sanitize( is_array( $raw_options ) ? $raw_options : array() );

        update_option( HSPSC_Settings::OPTION_KEY, $options );

        self::send_ajax_success(
            __( 'Settings saved. Related caches were refreshed.', 'hsp-smart-cache' ),
            array( 'options' => $options )
        );
    }

    public static function ajax_clear_cache() {
        self::ensure_ajax_access( 'hspsc_clear' );

        HSPSC_Plugin::flush_all_caches();

        self::send_ajax_success( __( 'All caches cleared.', 'hsp-smart-cache' ) );
    }

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

    public static function ajax_run_preload() {
        self::ensure_ajax_access( 'hspsc_run_preload' );

        $result = HSPSC_Preload::run();
        set_transient( 'hspsc_preload_result', $result, 300 );

        $count = is_array( $result ) && isset( $result['count'] ) ? intval( $result['count'] ) : 0;
        self::send_ajax_success(
            sprintf(
                /* translators: %d is the number of warmed URLs. */
                __( 'Preload completed. %d URLs warmed.', 'hsp-smart-cache' ),
                $count
            ),
            array( 'result' => $result )
        );
    }

    public static function ajax_analyze_db() {
        self::ensure_ajax_access( 'hspsc_analyze_db' );

        $analysis = HSPSC_Maintenance::analyze_optimization();
        set_transient( 'hspsc_db_analysis', $analysis, 15 * MINUTE_IN_SECONDS );

        self::send_ajax_success(
            __( 'Database analysis completed.', 'hsp-smart-cache' ),
            array( 'analysis_html' => self::get_db_analysis_html( $analysis ) )
        );
    }

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
                'result' => $result,
            )
        );
    }

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
                'result' => $backup,
            )
        );
    }

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

    public static function ajax_restore_db_backup() {
        self::ensure_ajax_access( 'hspsc_restore_db_backup' );

        $backup_file = self::get_posted_backup_file();
        $result = HSPSC_Maintenance::restore_backup( $backup_file );
        if ( empty( $result['ok'] ) ) {
            self::send_ajax_error( __( 'Database backup restore failed.', 'hsp-smart-cache' ) );
        }

        self::send_ajax_success( __( 'Database backup restored.', 'hsp-smart-cache' ) );
    }

    public static function ajax_delete_db_backup() {
        self::ensure_ajax_access( 'hspsc_delete_db_backup' );

        $deleted = HSPSC_Maintenance::delete_backup( self::get_posted_backup_file() );
        if ( ! $deleted ) {
            self::send_ajax_error( __( 'Deleting database backup failed.', 'hsp-smart-cache' ) );
        }

        self::send_ajax_success(
            __( 'Database backup deleted.', 'hsp-smart-cache' ),
            array(
                'backups_html' => self::get_db_backups_html( get_transient( 'hspsc_db_last_backup' ), HSPSC_Maintenance::list_backups() ),
            )
        );
    }

    protected static function ensure_ajax_access( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::send_ajax_error( __( 'Unauthorized', 'hsp-smart-cache' ), 403 );
        }

        if ( ! check_ajax_referer( $nonce_action, '_wpnonce', false ) ) {
            self::send_ajax_error( __( 'Security check failed. Refresh the page and try again.', 'hsp-smart-cache' ), 403 );
        }
    }

    protected static function get_posted_backup_file() {
        $backup_file = filter_input( INPUT_POST, 'backup_file', FILTER_UNSAFE_RAW );
        if ( $backup_file === null && isset( $_POST['backup_file'] ) ) {
            $backup_file = wp_unslash( $_POST['backup_file'] );
        }

        return $backup_file ? sanitize_file_name( wp_unslash( $backup_file ) ) : '';
    }

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

    protected static function send_ajax_error( $message, $status_code = 400 ) {
        wp_send_json_error(
            array(
                'message' => $message,
            ),
            $status_code
        );
    }

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
                        <?php echo esc_html__( 'Reclaimable', 'hsp-smart-cache' ); ?>
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
                                __( 'Clean Database can remove about %d rows based on this analysis.', 'hsp-smart-cache' ),
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
                                        <?php wp_nonce_field( 'hspsc_restore_db_backup' ); ?>
                                        <button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Restoring...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Restore', 'hsp-smart-cache' ); ?></button>
                                    </form>
                                    <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Delete this database backup?', 'hsp-smart-cache' ); ?>">
                                        <input type="hidden" name="action" value="hspsc_delete_db_backup" />
                                        <input type="hidden" name="backup_file" value="<?php echo esc_attr( $backup['file'] ); ?>" />
                                        <?php wp_nonce_field( 'hspsc_delete_db_backup' ); ?>
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

    protected static function deny_access() {
        wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
    }

    protected static function safe_redirect( $url ) {
        wp_safe_redirect( $url );

        if ( ! self::should_skip_exit() ) {
            exit;
        }
    }

    protected static function should_skip_exit() {
        $skip = apply_filters( 'hspsc_skip_admin_exit', false );
        return (bool) apply_filters( 'hspsc_skip_admin_exit', $skip );
    }

    public static function handle_settings_update( $old_value, $new_value ) {
        HSPSC_Object::sync_dropin();
        HSPSC_Page::clear_cache();
        HSPSC_Minify::clear_cache();
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options = get_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
        $options = wp_parse_args( $options, HSPSC_Settings::defaults() );
        $test_results = get_transient( 'hspsc_test_results' );
        $cache_notice = filter_input( INPUT_GET, 'cache', FILTER_UNSAFE_RAW );
        $settings_notice = filter_input( INPUT_GET, 'settings', FILTER_UNSAFE_RAW );
        $preload_notice = filter_input( INPUT_GET, 'preload', FILTER_UNSAFE_RAW );
        $db_notice = filter_input( INPUT_GET, 'db', FILTER_UNSAFE_RAW );
        $tests_notice = filter_input( INPUT_GET, 'tests', FILTER_UNSAFE_RAW );
        $db_analysis = get_transient( 'hspsc_db_analysis' );
        $last_backup = get_transient( 'hspsc_db_last_backup' );
        $db_backups = HSPSC_Maintenance::list_backups();

        $cache_notice = $cache_notice ? sanitize_key( $cache_notice ) : '';
        $settings_notice = $settings_notice ? sanitize_key( $settings_notice ) : '';
        $preload_notice = $preload_notice ? sanitize_key( $preload_notice ) : '';
        $db_notice = $db_notice ? sanitize_key( $db_notice ) : '';
        $tests_notice = $tests_notice ? sanitize_key( $tests_notice ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'HSP Smart Cache', 'hsp-smart-cache' ); ?></h1>
            <?php if ( $cache_notice === 'cleared' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Cache cleared.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $settings_notice === 'restored' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Defaults restored.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $preload_notice === 'done' ) : ?>
                <?php $preload_result = get_transient( 'hspsc_preload_result' ); ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Preload completed.', 'hsp-smart-cache' ); ?> <?php if ( is_array( $preload_result ) ) { /* translators: %d is the number of warmed URLs. */ echo esc_html( sprintf( __( '%d URLs warmed.', 'hsp-smart-cache' ), $preload_result['count'] ) ); } ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'cleaned' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Database cleanup completed.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'cleanupfailed' ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'Database cleanup failed. Some queries did not complete.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'optimized' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Database optimization completed.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'optimizefailed' ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'Database optimization failed. Some tables were not optimized.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'analyzed' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Database analysis completed.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'backupfailed' ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'Database backup failed. Optimization was not executed.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'backupcreated' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Database backup created.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'restored' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Database backup restored.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'restorefailed' ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'Database backup restore failed.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'backupdeleted' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Database backup deleted.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'deletefailed' ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'Deleting database backup failed.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $tests_notice === 'done' && is_array( $test_results ) ) : ?>
                <div class="notice notice-info">
                    <p><?php echo esc_html__( 'Cache tests completed.', 'hsp-smart-cache' ); ?></p>
                    <ul>
                        <?php foreach ( $test_results as $result ) : ?>
                            <li>
                                <?php echo $result['status'] ? '✅' : '❌'; ?>
                                <?php echo esc_html( $result['label'] ); ?>
                                <?php if ( ! empty( $result['details'] ) ) : ?>
                                    - <?php echo esc_html( $result['details'] ); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="hspsc-settings-form" class="hspsc-settings-form" method="post" action="options.php">
                <?php settings_fields( 'hspsc_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Page Cache', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[page_cache]" value="1" <?php checked( $options['page_cache'] ); ?> title="<?php echo esc_attr__( 'Cache full HTML pages for visitors.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable page caching for anonymous visitors', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Cache TTL (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="60" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[page_cache_ttl]" value="<?php echo esc_attr( $options['page_cache_ttl'] ); ?>" title="<?php echo esc_attr__( 'How long a page stays cached before regeneration.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[cache_logged_in]" value="1" <?php checked( $options['cache_logged_in'] ); ?> title="<?php echo esc_attr__( 'Enable only if logged-in users see cache-safe content.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Allow page caching for logged-in users', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Can serve private data if enabled on sites with personalized content.', 'hsp-smart-cache' ); ?>
                            </p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[optimize_logged_in]" value="1" <?php checked( $options['optimize_logged_in'] ); ?> title="<?php echo esc_attr__( 'Apply frontend optimizations for logged-in users.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Allow frontend optimizations for logged-in users', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__( 'Recommended off for editors/builders. Controls minify, script defer/async, and frontend performance tweaks.', 'hsp-smart-cache' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Robots.txt', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[robots_disallow_ai]" value="1" <?php checked( $options['robots_disallow_ai'] ); ?> title="<?php echo esc_attr__( 'Add disallow rules for known AI crawlers.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Disallow common AI crawlers', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__( 'Adds rules via WordPress robots.txt output (no file write).', 'hsp-smart-cache' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Browser Cache Headers', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[browser_cache]" value="1" <?php checked( $options['browser_cache'] ); ?> title="<?php echo esc_attr__( 'Send Cache-Control and ETag headers for cached pages.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Send Cache-Control headers for cacheable pages', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'HTML cache TTL (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="60" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[browser_cache_html_ttl]" value="<?php echo esc_attr( $options['browser_cache_html_ttl'] ); ?>" title="<?php echo esc_attr__( 'Browser cache lifetime for HTML pages.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Asset cache TTL (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="60" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[browser_cache_asset_ttl]" value="<?php echo esc_attr( $options['browser_cache_asset_ttl'] ); ?>" title="<?php echo esc_attr__( 'Browser cache lifetime for static assets.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Static Asset Caching', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_cache]" value="1" <?php checked( $options['static_asset_cache'] ); ?> title="<?php echo esc_attr__( 'Enable long-lived cache headers for CSS/JS/fonts/images.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable browser caching for WordPress/theme/plugin assets', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Static asset TTL (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="60" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_ttl]" value="<?php echo esc_attr( $options['static_asset_ttl'] ); ?>" title="<?php echo esc_attr__( 'Cache lifetime for assets via web server rules.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_immutable]" value="1" <?php checked( $options['static_asset_immutable'] ); ?> title="<?php echo esc_attr__( 'Add immutable directive to encourage long-term caching.', 'hsp-smart-cache' ); ?>" />
                                    <?php echo esc_html__( 'Add immutable directive where supported', 'hsp-smart-cache' ); ?>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_auto_write]" value="1" <?php checked( $options['static_asset_auto_write'] ); ?> title="<?php echo esc_attr__( 'Automatically update .htaccess if writable.', 'hsp-smart-cache' ); ?>" />
                                    <?php echo esc_html__( 'Auto-write .htaccess rules when possible', 'hsp-smart-cache' ); ?>
                                </label>
                            </p>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Incorrect .htaccess rules can break site access. Use with care.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_compression]" value="1" <?php checked( $options['static_asset_compression'] ); ?> title="<?php echo esc_attr__( 'Enable gzip/deflate compression via web server rules.', 'hsp-smart-cache' ); ?>" />
                                    <?php echo esc_html__( 'Enable gzip/deflate compression', 'hsp-smart-cache' ); ?>
                                </label>
                            </p>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: If your server already manages compression, double-compression can cause issues.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label><?php echo esc_html__( 'Apache .htaccess snippet', 'hsp-smart-cache' ); ?></label><br />
                                <textarea
                                    class="large-text"
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
                        <th scope="row"><?php echo esc_html__( 'Render Blocking Optimization', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_defer_js]" value="1" <?php checked( $options['render_defer_js'] ); ?> title="<?php echo esc_attr__( 'Add defer to scripts to reduce render blocking.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Add defer to enqueued scripts', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Deferring scripts may break plugins that expect blocking execution.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Defer exclusions (script handles, one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_defer_exclusions]" placeholder="jquery\nwp-embed" title="<?php echo esc_attr__( 'Script handles that must not be deferred.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_defer_exclusions'] ); ?></textarea>
                                </label>
                            </p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_async_js]" value="1" <?php checked( $options['render_async_js'] ); ?> title="<?php echo esc_attr__( 'Use async when defer is disabled.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Add async to enqueued scripts (if defer is off)', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Async can change execution order and break dependencies.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Async exclusions (script handles, one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_async_exclusions]" placeholder="jquery\nwp-embed" title="<?php echo esc_attr__( 'Script handles that must not be async.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_async_exclusions'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Preconnect URLs (one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_preconnect_urls]" placeholder="https://fonts.gstatic.com" title="<?php echo esc_attr__( 'Origins to preconnect for faster TLS/handshake.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preconnect_urls'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Preload font URLs (one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_preload_fonts]" placeholder="https://example.com/fonts/myfont.woff2" title="<?php echo esc_attr__( 'Font files to preload early.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preload_fonts'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Preload CSS URLs (one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_preload_css]" placeholder="https://example.com/style.css" title="<?php echo esc_attr__( 'Stylesheets to preload before render.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preload_css'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Inline critical CSS', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="5" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[render_critical_css]" placeholder="/* Critical CSS */" title="<?php echo esc_attr__( 'CSS injected inline to speed up first render.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_critical_css'] ); ?></textarea>
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Additional Performance', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_lazy_images]" value="1" <?php checked( $options['perf_lazy_images'] ); ?> title="<?php echo esc_attr__( 'Adds loading="lazy" to images where safe.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable native lazy-loading for images', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_lazy_iframes]" value="1" <?php checked( $options['perf_lazy_iframes'] ); ?> title="<?php echo esc_attr__( 'Adds loading="lazy" to iframes where safe.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable native lazy-loading for iframes', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_decoding_async]" value="1" <?php checked( $options['perf_decoding_async'] ); ?> title="<?php echo esc_attr__( 'Hints browser to decode images asynchronously.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Add decoding="async" to images', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_disable_emojis]" value="1" <?php checked( $options['perf_disable_emojis'] ); ?> title="<?php echo esc_attr__( 'Remove emoji scripts and styles for faster loads.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Disable emoji scripts and styles', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_disable_embeds]" value="1" <?php checked( $options['perf_disable_embeds'] ); ?> title="<?php echo esc_attr__( 'Disable oEmbed discovery and scripts.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Disable WordPress embeds', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_disable_dashicons]" value="1" <?php checked( $options['perf_disable_dashicons'] ); ?> title="<?php echo esc_attr__( 'Remove Dashicons for non-logged-in visitors.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Disable Dashicons for guests', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Disabling embeds or Dashicons can affect themes or plugins that rely on them.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'DNS prefetch URLs (one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[perf_dns_prefetch_urls]" placeholder="//fonts.googleapis.com" title="<?php echo esc_attr__( 'Hostnames to DNS-prefetch.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['perf_dns_prefetch_urls'] ); ?></textarea>
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Cache Preload', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[preload_enabled]" value="1" <?php checked( $options['preload_enabled'] ); ?> title="<?php echo esc_attr__( 'Allow manual preload of URLs from sitemap.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable cache preloading', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Sitemap URL', 'hsp-smart-cache' ); ?>
                                    <input type="url" class="regular-text" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[preload_sitemap_url]" value="<?php echo esc_attr( $options['preload_sitemap_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/sitemap.xml' ) ); ?>" title="<?php echo esc_attr__( 'Sitemap to pull URLs from for warming.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Max URLs to warm', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="1" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[preload_limit]" value="<?php echo esc_attr( $options['preload_limit'] ); ?>" title="<?php echo esc_attr__( 'Limit number of URLs warmed per run.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Request timeout (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="3" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[preload_timeout]" value="<?php echo esc_attr( $options['preload_timeout'] ); ?>" title="<?php echo esc_attr__( 'Timeout per warmed URL.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Minification', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[minify_html]" value="1" <?php checked( $options['minify_html'] ); ?> title="<?php echo esc_attr__( 'Remove whitespace/comments from HTML.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Minify HTML', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[minify_css]" value="1" <?php checked( $options['minify_css'] ); ?> title="<?php echo esc_attr__( 'Create minified CSS files in the cache.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Minify CSS files', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[minify_js]" value="1" <?php checked( $options['minify_js'] ); ?> title="<?php echo esc_attr__( 'Create minified JS files in the cache.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Minify JS files', 'hsp-smart-cache' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Object Cache', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[object_cache]" value="1" <?php checked( $options['object_cache'] ); ?> title="<?php echo esc_attr__( 'Use file-based persistent object cache drop-in.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable file-based persistent object cache (drop-in)', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Object cache drop-ins can conflict with other cache plugins.', 'hsp-smart-cache' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'CDN', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[cdn_enabled]" value="1" <?php checked( $options['cdn_enabled'] ); ?> title="<?php echo esc_attr__( 'Rewrite static asset URLs to your CDN.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable CDN URL rewriting for static assets', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'CDN Base URL', 'hsp-smart-cache' ); ?>
                                    <input type="url" class="regular-text" name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[cdn_url]" value="<?php echo esc_attr( $options['cdn_url'] ); ?>" placeholder="https://cdn.example.com" title="<?php echo esc_attr__( 'Base URL used for CDN rewriting.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary" data-loading-text="<?php echo esc_attr__( 'Saving...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Save Changes', 'hsp-smart-cache' ); ?></button>
                </p>
            </form>

            <div id="hspsc-feedback" class="hspsc-feedback" role="status" aria-live="polite" aria-atomic="true"></div>

            <style>
                .hspsc-settings-form {
                    max-width: 1180px;
                }
                .hspsc-feedback {
                    margin: 18px 0;
                    min-height: 1px;
                }
                .hspsc-feedback .notice {
                    margin: 0;
                }
                .hspsc-action-area {
                    display: grid;
                    grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
                    gap: 20px;
                    margin-top: 22px;
                    max-width: 1180px;
                }
                .hspsc-action-stack,
                .hspsc-maintenance-panel {
                    background: #fff;
                    border: 1px solid #d0d7de;
                    border-radius: 8px;
                    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
                }
                .hspsc-action-stack {
                    padding: 18px;
                }
                .hspsc-maintenance-panel {
                    padding: 20px;
                }
                .hspsc-section-title {
                    margin: 0 0 6px;
                    color: #1d2327;
                    font-size: 18px;
                    line-height: 1.3;
                }
                .hspsc-section-lede {
                    margin: 0 0 16px;
                    color: #646970;
                }
                .hspsc-action-group {
                    border-top: 1px solid #eef0f2;
                    padding-top: 16px;
                    margin-top: 16px;
                }
                .hspsc-action-group:first-of-type {
                    border-top: 0;
                    margin-top: 0;
                    padding-top: 0;
                }
                .hspsc-action-group h3,
                .hspsc-db-analysis h3,
                .hspsc-backups h3,
                .hspsc-result-list h3 {
                    margin: 0 0 10px;
                    font-size: 13px;
                    font-weight: 600;
                    color: #1d2327;
                    text-transform: uppercase;
                }
                .hspsc-button-list {
                    display: grid;
                    gap: 10px;
                }
                .hspsc-action-button {
                    display: inline-flex !important;
                    align-items: center;
                    justify-content: center;
                    min-height: 36px;
                    width: 100%;
                    margin: 0 !important;
                    text-align: center;
                }
                .hspsc-action-button[disabled] {
                    cursor: wait;
                    opacity: 0.72;
                }
                .hspsc-maintenance-actions {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(170px, 1fr));
                    gap: 12px;
                    margin-bottom: 16px;
                }
                .hspsc-callout {
                    margin: 12px 0 0;
                    padding: 12px 14px;
                    border-left: 4px solid #d63638;
                    background: #fcf0f1;
                    color: #5f2120;
                }
                .hspsc-note {
                    margin: 12px 0 0;
                    padding: 12px 14px;
                    border-left: 4px solid #72aee6;
                    background: #f0f6fc;
                    color: #1d3557;
                }
                .hspsc-dynamic-panel {
                    border-top: 1px solid #eef0f2;
                    margin-top: 18px;
                    padding-top: 18px;
                }
                .hspsc-metric-row {
                    display: grid;
                    grid-template-columns: repeat(4, minmax(120px, 1fr));
                    gap: 12px;
                }
                .hspsc-cleanup-row {
                    grid-template-columns: repeat(3, minmax(150px, 1fr));
                    margin-top: 10px;
                }
                .hspsc-metric-row span {
                    border: 1px solid #dcdcde;
                    border-radius: 8px;
                    padding: 12px;
                    background: #f6f7f7;
                    color: #646970;
                }
                .hspsc-metric-row strong {
                    display: block;
                    color: #1d2327;
                    font-size: 20px;
                    line-height: 1.2;
                }
                .hspsc-table-scroll {
                    overflow-x: auto;
                }
                .hspsc-backups-table {
                    margin-top: 8px;
                }
                .hspsc-row-actions {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                }
                .hspsc-row-actions .hspsc-action-button {
                    width: auto;
                    min-height: 30px;
                }
                .hspsc-result-list ul {
                    margin: 0;
                }
                .hspsc-result-list li {
                    margin-bottom: 8px;
                }
                .hspsc-status-ok,
                .hspsc-status-error {
                    display: inline-block;
                    min-width: 54px;
                    margin-right: 6px;
                    font-weight: 600;
                }
                .hspsc-status-ok {
                    color: #008a20;
                }
                .hspsc-status-error {
                    color: #b32d2e;
                }
                @media (max-width: 960px) {
                    .hspsc-action-area,
                    .hspsc-maintenance-actions,
                    .hspsc-metric-row {
                        grid-template-columns: 1fr;
                    }
                }
            </style>

            <div class="hspsc-action-area">
                <div class="hspsc-action-stack" aria-labelledby="hspsc-quick-actions-title">
                    <h2 id="hspsc-quick-actions-title" class="hspsc-section-title"><?php echo esc_html__( 'Cache Operations', 'hsp-smart-cache' ); ?></h2>
                    <p class="hspsc-section-lede"><?php echo esc_html__( 'Run routine cache tasks without leaving this screen.', 'hsp-smart-cache' ); ?></p>

                    <div class="hspsc-action-group">
                        <h3><?php echo esc_html__( 'Cache', 'hsp-smart-cache' ); ?></h3>
                        <div class="hspsc-button-list">
                            <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="hspsc_clear" />
                                <?php wp_nonce_field( 'hspsc_clear' ); ?>
                                <button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Clearing...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Clear All Caches', 'hsp-smart-cache' ); ?></button>
                            </form>
                            <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="hspsc_run_tests" />
                                <?php wp_nonce_field( 'hspsc_run_tests' ); ?>
                                <button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Testing...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Run Cache Tests', 'hsp-smart-cache' ); ?></button>
                            </form>
                        </div>
                    </div>

                    <div class="hspsc-action-group">
                        <h3><?php echo esc_html__( 'Preload', 'hsp-smart-cache' ); ?></h3>
                        <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="hspsc_run_preload" />
                            <?php wp_nonce_field( 'hspsc_run_preload' ); ?>
                            <button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Preloading...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Run Cache Preload', 'hsp-smart-cache' ); ?></button>
                        </form>
                    </div>

                    <div class="hspsc-action-group">
                        <h3><?php echo esc_html__( 'Settings', 'hsp-smart-cache' ); ?></h3>
                        <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Restore all plugin settings to defaults?', 'hsp-smart-cache' ); ?>">
                            <input type="hidden" name="action" value="hspsc_restore_defaults" />
                            <?php wp_nonce_field( 'hspsc_restore_defaults' ); ?>
                            <button type="submit" class="button hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Restoring...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Restore Defaults', 'hsp-smart-cache' ); ?></button>
                        </form>
                    </div>
                </div>

                <div class="hspsc-maintenance-panel" aria-labelledby="hspsc-maintenance-title">
                    <h2 id="hspsc-maintenance-title" class="hspsc-section-title"><?php echo esc_html__( 'Database Maintenance', 'hsp-smart-cache' ); ?></h2>
                    <p class="hspsc-section-lede"><?php echo esc_html__( 'Inspect, clean, optimize, and manage generated backups from one workspace.', 'hsp-smart-cache' ); ?></p>

                    <div class="hspsc-maintenance-actions">
                        <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="hspsc_analyze_db" />
                            <?php wp_nonce_field( 'hspsc_analyze_db' ); ?>
                            <button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Analyzing...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Analyze', 'hsp-smart-cache' ); ?></button>
                        </form>
                        <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Run database cleanup now?', 'hsp-smart-cache' ); ?>">
                            <input type="hidden" name="action" value="hspsc_run_db_cleanup" />
                            <?php wp_nonce_field( 'hspsc_run_db_cleanup' ); ?>
                            <button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Cleaning...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Clean Database', 'hsp-smart-cache' ); ?></button>
                        </form>
                        <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="hspsc_create_db_backup" />
                            <?php wp_nonce_field( 'hspsc_create_db_backup' ); ?>
                            <button type="submit" class="button button-secondary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Creating...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Create Backup', 'hsp-smart-cache' ); ?></button>
                        </form>
                        <form class="hspsc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Create a backup and optimize database tables now?', 'hsp-smart-cache' ); ?>">
                            <input type="hidden" name="action" value="hspsc_optimize_db" />
                            <?php wp_nonce_field( 'hspsc_optimize_db' ); ?>
                            <button type="submit" class="button button-primary hspsc-action-button" data-loading-text="<?php echo esc_attr__( 'Optimizing...', 'hsp-smart-cache' ); ?>"><?php echo esc_html__( 'Optimize Tables', 'hsp-smart-cache' ); ?></button>
                        </form>
                    </div>

                    <p class="hspsc-note"><?php echo esc_html__( 'A timestamped backup is created automatically before optimization starts.', 'hsp-smart-cache' ); ?></p>
                    <p class="hspsc-callout"><?php echo esc_html__( 'Database cleanup deletes revisions, trashed posts, spam comments, and expired transients. Table optimization can be slow on large databases.', 'hsp-smart-cache' ); ?></p>

                    <div id="hspsc-tests-panel" class="hspsc-dynamic-panel">
                        <?php echo self::get_cache_tests_html( $test_results ); ?>
                    </div>

                    <div id="hspsc-db-analysis-panel" class="hspsc-dynamic-panel">
                        <?php echo self::get_db_analysis_html( $db_analysis ); ?>
                    </div>

                    <div id="hspsc-db-backups-panel" class="hspsc-dynamic-panel hspsc-backups">
                        <?php echo self::get_db_backups_html( $last_backup, $db_backups ); ?>
                    </div>
                </div>
            </div>

            <script>
                (function() {
                    var preview = document.getElementById('hsp-htaccess-preview');

                    function getVal(name) {
                        var el = document.querySelector('[name="<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[' + name + ']"]');
                        if (!el) { return null; }
                        if (el.type === 'checkbox') { return el.checked; }
                        return el.value;
                    }

                    function updatePreview() {
                        if (!preview) { return; }
                        var ttl = parseInt(getVal('static_asset_ttl'), 10);
                        if (isNaN(ttl) || ttl < 60) { ttl = 60; }

                        var immutable = !!getVal('static_asset_immutable');
                        var compress = !!getVal('static_asset_compression');
                        var enabled = !!getVal('static_asset_cache');

                        if (!enabled) {
                            preview.value = '';
                            return;
                        }

                        var template = preview.dataset.templateCache;
                        if (immutable && compress) {
                            template = preview.dataset.templateCacheImmutableCompress;
                        } else if (immutable) {
                            template = preview.dataset.templateCacheImmutable;
                        } else if (compress) {
                            template = preview.dataset.templateCacheCompress;
                        }

                        preview.value = template.replace('max-age=0', 'max-age=' + ttl);
                    }

                    document.addEventListener('input', function(e) {
                        if (!e.target || !e.target.name) { return; }
                        if (e.target.name.indexOf('<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_') === 0) {
                            updatePreview();
                        }
                    });

                    document.addEventListener('change', function(e) {
                        if (!e.target || !e.target.name) { return; }
                        if (e.target.name.indexOf('<?php echo esc_attr( HSPSC_Settings::OPTION_KEY ); ?>[static_asset_') === 0) {
                            updatePreview();
                        }
                    });

                    updatePreview();

                    var feedback = document.getElementById('hspsc-feedback');
                    var settingsForm = document.getElementById('hspsc-settings-form');

                    function showFeedback(message, type) {
                        if (!feedback) { return; }
                        feedback.innerHTML = '<div class="notice notice-' + type + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>';
                    }

                    function escapeHtml(value) {
                        var div = document.createElement('div');
                        div.textContent = value || '';
                        return div.innerHTML;
                    }

                    function setFormBusy(form, busy) {
                        var buttons = form.querySelectorAll('button, input[type="submit"]');
                        form.setAttribute('aria-busy', busy ? 'true' : 'false');

                        buttons.forEach(function(button) {
                            if (busy) {
                                button.dataset.originalText = button.textContent || button.value || '';
                                if (button.dataset.loadingText) {
                                    if (button.tagName === 'INPUT') {
                                        button.value = button.dataset.loadingText;
                                    } else {
                                        button.textContent = button.dataset.loadingText;
                                    }
                                }
                                button.disabled = true;
                            } else {
                                if (button.dataset.originalText) {
                                    if (button.tagName === 'INPUT') {
                                        button.value = button.dataset.originalText;
                                    } else {
                                        button.textContent = button.dataset.originalText;
                                    }
                                }
                                button.disabled = false;
                            }
                        });
                    }

                    function updatePanels(data) {
                        var testsPanel = document.getElementById('hspsc-tests-panel');
                        var analysisPanel = document.getElementById('hspsc-db-analysis-panel');
                        var backupsPanel = document.getElementById('hspsc-db-backups-panel');

                        if (data.html && testsPanel) {
                            testsPanel.innerHTML = data.html;
                        }
                        if (data.analysis_html && analysisPanel) {
                            analysisPanel.innerHTML = data.analysis_html;
                        }
                        if (data.backups_html && backupsPanel) {
                            backupsPanel.innerHTML = data.backups_html;
                        }
                    }

                    function applyOptions(options) {
                        if (!options) { return; }

                        Object.keys(options).forEach(function(key) {
                            var field = document.querySelector('[name="<?php echo esc_js( HSPSC_Settings::OPTION_KEY ); ?>[' + key + ']"]');
                            if (!field) { return; }

                            if (field.type === 'checkbox') {
                                field.checked = !!options[key];
                                return;
                            }

                            field.value = options[key] == null ? '' : options[key];
                        });

                        updatePreview();
                    }

                    function submitAjaxForm(form, ajaxAction) {
                        var formData = new FormData(form);
                        formData.set('action', ajaxAction || formData.get('action'));

                        setFormBusy(form, true);

                        fetch(ajaxurl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        })
                            .then(function(response) {
                                return response.json();
                            })
                            .then(function(response) {
                                var data = response && response.data ? response.data : {};
                                if (!response || !response.success) {
                                    throw new Error(data.message || '<?php echo esc_js( __( 'Action failed. Please try again.', 'hsp-smart-cache' ) ); ?>');
                                }

                                showFeedback(data.message || '<?php echo esc_js( __( 'Action completed.', 'hsp-smart-cache' ) ); ?>', 'success');
                                applyOptions(data.options);
                                updatePanels(data);
                            })
                            .catch(function(error) {
                                showFeedback(error.message || '<?php echo esc_js( __( 'Action failed. Please try again.', 'hsp-smart-cache' ) ); ?>', 'error');
                            })
                            .finally(function() {
                                setFormBusy(form, false);
                            });
                    }

                    document.addEventListener('submit', function(event) {
                        var form = event.target;
                        if (!form || !form.classList) { return; }

                        if (form.classList.contains('hspsc-ajax-form')) {
                            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                                event.preventDefault();
                                return;
                            }

                            event.preventDefault();
                            submitAjaxForm(form);
                        }
                    });

                    if (settingsForm) {
                        settingsForm.addEventListener('submit', function(event) {
                            event.preventDefault();
                            submitAjaxForm(settingsForm, 'hspsc_save_settings');
                        });
                    }
                })();
            </script>

            <p class="description" style="margin-top:16px;">
                <?php echo esc_html__( 'Made by Helmut Steiner Productions with ❤️ in Vienna', 'hsp-smart-cache' ); ?>
            </p>
        </div>
        <?php
    }
}
