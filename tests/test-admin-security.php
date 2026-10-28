<?php

class HSPSC_Admin_Security_Die_Exception extends RuntimeException {
}

class HSPSC_Admin_Security_Test extends WP_UnitTestCase {
    private $original_get;
    private $original_post;
    private $original_request;
    private $original_server;
    private $original_user;
    private $backup_dir;
    private $backup_name = 'hsp-db-backup-2026-10-27_12-00-00-AbCdEf123456.sql';
    private $page_file;
    private $asset_file;
    private $settings;

    public function set_up(): void {
        parent::set_up();
        $this->original_get = $_GET;
        $this->original_post = $_POST;
        $this->original_request = $_REQUEST;
        $this->original_server = $_SERVER;
        $this->original_user = get_current_user_id();

        $this->settings = array_merge( HSPSC_Settings::defaults(), array( 'page_cache_ttl' => 1234 ) );
        update_option( HSPSC_Settings::OPTION_KEY, $this->settings );
        HSPSC_Utils::ensure_cache_dirs();
        $this->page_file = HSPSC_PATH . '/pages/admin-security.html';
        $this->asset_file = HSPSC_PATH . '/assets/admin-security.min.js';
        file_put_contents( $this->page_file, 'public page sentinel' );
        file_put_contents( $this->asset_file, 'asset sentinel' );

        $this->backup_dir = trailingslashit( get_temp_dir() ) . 'hspsc-admin-security-' . wp_generate_uuid4();
        wp_mkdir_p( $this->backup_dir );
        file_put_contents( $this->backup_dir . '/' . $this->backup_name, '-- backup sentinel' );
        add_filter( 'hspsc_db_backup_dir', array( $this, 'get_backup_dir' ) );
        add_filter( 'wp_die_handler', array( $this, 'get_die_handler' ), PHP_INT_MAX );
        add_filter( 'wp_die_ajax_handler', array( $this, 'get_die_handler' ), PHP_INT_MAX );
    }

    public function tear_down(): void {
        remove_filter( 'hspsc_db_backup_dir', array( $this, 'get_backup_dir' ) );
        remove_filter( 'wp_die_handler', array( $this, 'get_die_handler' ), PHP_INT_MAX );
        remove_filter( 'wp_die_ajax_handler', array( $this, 'get_die_handler' ), PHP_INT_MAX );
        wp_delete_file( $this->page_file );
        wp_delete_file( $this->asset_file );
        wp_delete_file( $this->backup_dir . '/' . $this->backup_name );
        @rmdir( $this->backup_dir );
        $_GET = $this->original_get;
        $_POST = $this->original_post;
        $_REQUEST = $this->original_request;
        $_SERVER = $this->original_server;
        wp_set_current_user( $this->original_user );
        HSPSC_Settings::reset_cache();
        parent::tear_down();
    }

    public function get_backup_dir( $default ) {
        return $this->backup_dir;
    }

    public function get_die_handler( $handler ) {
        return array( $this, 'stop_request' );
    }

    public function stop_request( $message = '', $title = '', $args = array() ) {
        throw new HSPSC_Admin_Security_Die_Exception( 'Request terminated by WordPress.' );
    }

    public static function denied_form_actions() {
        return self::build_cases( array(
            array( 'handle_clear_cache', 'hspsc_clear' ),
            array( 'handle_restore_defaults', 'hspsc_restore_defaults' ),
            array( 'handle_delete_db_backup', 'hspsc_delete_db_backup' ),
        ) );
    }

    public static function denied_ajax_actions() {
        return self::build_cases( array(
            array( 'ajax_clear_cache', 'hspsc_clear' ),
            array( 'ajax_save_settings', 'hspsc_settings_group-options' ),
            array( 'ajax_delete_db_backup', 'hspsc_delete_db_backup' ),
        ) );
    }

    private static function build_cases( $actions ) {
        $cases = array();
        foreach ( $actions as $action ) {
            foreach ( array( 'capability', 'missing_nonce', 'wrong_nonce' ) as $failure ) {
                $cases[ $action[0] . '-' . $failure ] = array( $action[0], $action[1], $failure );
            }
        }
        return $cases;
    }

    /**
     * @dataProvider denied_form_actions
     */
    public function test_form_actions_deny_access_before_mutating_state( $method, $nonce_action, $failure ) {
        $this->prepare_denied_request( $nonce_action, $failure );
        $this->invoke_denied_action( $method );
        $this->assert_state_unchanged();
    }

    /**
     * @dataProvider denied_ajax_actions
     */
    public function test_ajax_actions_deny_access_before_mutating_state( $method, $nonce_action, $failure ) {
        $this->prepare_denied_request( $nonce_action, $failure );
        $output = $this->invoke_denied_action( $method, true );
        $response = json_decode( $output, true );
        $this->assertIsArray( $response );
        $this->assertFalse( $response['success'] );
        $this->assertSame(
            $failure === 'capability' ? __( 'Unauthorized', 'hsp-smart-cache' ) : __( 'Security check failed. Refresh the page and try again.', 'hsp-smart-cache' ),
            $response['data']['message']
        );
        $this->assert_state_unchanged();
    }

    private function prepare_denied_request( $nonce_action, $failure ) {
        $role = $failure === 'capability' ? 'subscriber' : 'administrator';
        wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
        $_GET = array();
        $_POST = array(
            'backup_file' => $this->backup_name,
            HSPSC_Settings::OPTION_KEY => array( 'page_cache' => false, 'page_cache_ttl' => 888 ),
        );
        $_REQUEST = $_POST;
        unset( $_SERVER['HTTP_REFERER'] );
        if ( $failure !== 'missing_nonce' ) {
            $nonce = wp_create_nonce( $failure === 'wrong_nonce' ? 'hspsc_different_action' : $nonce_action );
            $_GET['_wpnonce'] = $nonce;
            $_POST['_wpnonce'] = $nonce;
            $_REQUEST['_wpnonce'] = $nonce;
        }
    }

    private function invoke_denied_action( $method, $ajax = false ) {
        $level = ob_get_level();
        ob_start();
        if ( $ajax ) {
            add_filter( 'wp_doing_ajax', '__return_true', PHP_INT_MAX );
        }
        try {
            call_user_func( array( 'HSPSC_Admin', $method ) );
            $this->fail( 'The action did not terminate at its access check.' );
        } catch ( HSPSC_Admin_Security_Die_Exception $error ) {
            return ob_get_contents();
        } finally {
            if ( $ajax ) {
                remove_filter( 'wp_doing_ajax', '__return_true', PHP_INT_MAX );
            }
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
        }
    }

    private function assert_state_unchanged() {
        $this->assertFileExists( $this->page_file );
        $this->assertSame( 'public page sentinel', file_get_contents( $this->page_file ) );
        $this->assertFileExists( $this->asset_file );
        $this->assertSame( 'asset sentinel', file_get_contents( $this->asset_file ) );
        $this->assertSame( $this->settings, get_option( HSPSC_Settings::OPTION_KEY ) );
        $backup = $this->backup_dir . '/' . $this->backup_name;
        $this->assertFileExists( $backup );
        $this->assertSame( '-- backup sentinel', file_get_contents( $backup ) );
    }
}
