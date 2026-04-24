<?php

class HSP_Smart_Cache_Maintenance_WPDB_Mock {
    public $posts = 'wp_posts';
    public $comments = 'wp_comments';
    public $options = 'wp_options';
    public $queries = array();
    public $tables = array( 'wp_posts', 'wp_comments', 'wp_options' );
    public $status_rows = array();
    public $table_rows = array();
    public $create_sql = array();
    public $inserted = array();

    public function query( $sql ) {
        $this->queries[] = $sql;
        return 1;
    }

    public function esc_like( $text ) {
        return addcslashes( $text, '_%\\' );
    }

    public function prepare( $query, ...$args ) {
        $parts = explode( '%', $query );
        $built = array_shift( $parts );

        foreach ( $parts as $index => $part ) {
            $specifier = substr( $part, 0, 1 );
            $rest = substr( $part, 1 );
            $value = isset( $args[ $index ] ) ? $args[ $index ] : '';

            if ( $specifier === 'd' ) {
                $built .= (string) intval( $value );
            } else {
                $built .= "'" . str_replace( "'", "''", (string) $value ) . "'";
            }

            $built .= $rest;
        }

        return $built;
    }

    public function get_col( $sql ) {
        if ( stripos( $sql, 'SHOW TABLES' ) !== false ) {
            return $this->tables;
        }
        return array();
    }

    public function get_results( $sql, $output = OBJECT ) {
        if ( stripos( $sql, 'SHOW TABLE STATUS' ) !== false ) {
            return $this->status_rows;
        }

        if ( preg_match( '/SELECT\s+\*\s+FROM\s+`([^`]+)`/i', $sql, $matches ) ) {
            $table = $matches[1];
            return isset( $this->table_rows[ $table ] ) ? $this->table_rows[ $table ] : array();
        }

        return array();
    }

    public function get_row( $sql, $output = OBJECT ) {
        if ( preg_match( '/SHOW\s+CREATE\s+TABLE\s+`([^`]+)`/i', $sql, $matches ) ) {
            $table = $matches[1];
            return array(
                'Table' => $table,
                'Create Table' => isset( $this->create_sql[ $table ] )
                    ? $this->create_sql[ $table ]
                    : 'CREATE TABLE `' . $table . '` (`id` int(11) NOT NULL)',
            );
        }

        return null;
    }

    public function insert( $table, $data ) {
        if ( ! isset( $this->inserted[ $table ] ) ) {
            $this->inserted[ $table ] = array();
        }

        $this->inserted[ $table ][] = $data;
        return true;
    }
}

class HSP_Smart_Cache_Maintenance_Test extends WP_UnitTestCase {
    private $wpdb_original;
    private $backup_dir;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->wpdb_original = $wpdb;
        $this->backup_dir = HSP_SMART_CACHE_PATH . '/db-backups';

        $this->delete_dir_recursively( $this->backup_dir );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb = $this->wpdb_original;

        $this->delete_dir_recursively( $this->backup_dir );

        parent::tear_down();
    }

    public function test_run_db_cleanup_executes_expected_cleanup_queries() {
        global $wpdb;
        $wpdb = new HSP_Smart_Cache_Maintenance_WPDB_Mock();

        $result = HSP_Smart_Cache_Maintenance::run_db_cleanup();

        $this->assertTrue( $result );
        $this->assertGreaterThanOrEqual( 6, count( $wpdb->queries ) );
        $this->assertStringContainsString( "post_type = 'revision'", $wpdb->queries[0] );
        $this->assertStringContainsString( "comment_approved IN ('spam','trash')", $wpdb->queries[3] );
    }

    public function test_optimize_tables_returns_true_and_runs_optimize_per_table() {
        global $wpdb;
        $wpdb = new HSP_Smart_Cache_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_posts', 'wp_options' );

        $result = HSP_Smart_Cache_Maintenance::optimize_tables();

        $this->assertTrue( $result );
        $this->assertContains( 'OPTIMIZE TABLE wp_posts', $wpdb->queries );
        $this->assertContains( 'OPTIMIZE TABLE wp_options', $wpdb->queries );
    }

    public function test_optimize_tables_returns_false_when_no_tables_found() {
        global $wpdb;
        $wpdb = new HSP_Smart_Cache_Maintenance_WPDB_Mock();
        $wpdb->tables = array();

        $result = HSP_Smart_Cache_Maintenance::optimize_tables();

        $this->assertFalse( $result );
    }

    public function test_analyze_optimization_returns_summary() {
        global $wpdb;
        $wpdb = new HSP_Smart_Cache_Maintenance_WPDB_Mock();
        $wpdb->status_rows = array(
            array(
                'Name' => 'wp_posts',
                'Rows' => 120,
                'Data_length' => 1000,
                'Index_length' => 500,
                'Data_free' => 250,
            ),
            array(
                'Name' => 'wp_options',
                'Rows' => 35,
                'Data_length' => 200,
                'Index_length' => 100,
                'Data_free' => 0,
            ),
        );

        $analysis = HSP_Smart_Cache_Maintenance::analyze_optimization();

        $this->assertTrue( $analysis['ok'] );
        $this->assertSame( 2, $analysis['table_count'] );
        $this->assertSame( 1, $analysis['optimizable_tables'] );
        $this->assertSame( 1800, $analysis['total_size_bytes'] );
        $this->assertSame( 250, $analysis['total_overhead_bytes'] );
    }

    public function test_create_backup_generates_timestamped_file() {
        global $wpdb;
        $wpdb = new HSP_Smart_Cache_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_posts' );
        $wpdb->table_rows = array(
            'wp_posts' => array(
                array( 'ID' => 1, 'post_title' => 'Hello' ),
            ),
        );
        $wpdb->create_sql = array(
            'wp_posts' => 'CREATE TABLE `wp_posts` (`ID` int(11) NOT NULL, `post_title` text)',
        );

        $backup = HSP_Smart_Cache_Maintenance::create_backup();

        $this->assertTrue( $backup['ok'] );
        $this->assertMatchesRegularExpression( '/^hsp-db-backup-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.json$/', $backup['file'] );
        $this->assertFileExists( $backup['path'] );
    }

    public function test_restore_backup_reinserts_rows() {
        global $wpdb;
        $wpdb = new HSP_Smart_Cache_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_posts' );
        $wpdb->table_rows = array(
            'wp_posts' => array(
                array( 'ID' => 1, 'post_title' => 'Hello' ),
                array( 'ID' => 2, 'post_title' => 'World' ),
            ),
        );
        $wpdb->create_sql = array(
            'wp_posts' => 'CREATE TABLE `wp_posts` (`ID` int(11) NOT NULL, `post_title` text)',
        );

        $backup = HSP_Smart_Cache_Maintenance::create_backup();
        $this->assertTrue( $backup['ok'] );

        $restore = HSP_Smart_Cache_Maintenance::restore_backup( $backup['file'] );

        $this->assertTrue( $restore['ok'] );
        $this->assertArrayHasKey( 'wp_posts', $wpdb->inserted );
        $this->assertCount( 2, $wpdb->inserted['wp_posts'] );
    }

    public function test_list_and_delete_backups_work() {
        global $wpdb;
        $wpdb = new HSP_Smart_Cache_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_options' );
        $wpdb->table_rows = array( 'wp_options' => array() );
        $wpdb->create_sql = array( 'wp_options' => 'CREATE TABLE `wp_options` (`option_id` int(11) NOT NULL)' );

        $backup = HSP_Smart_Cache_Maintenance::create_backup();
        $this->assertTrue( $backup['ok'] );

        $backups = HSP_Smart_Cache_Maintenance::list_backups();
        $this->assertNotEmpty( $backups );
        $this->assertSame( $backup['file'], $backups[0]['file'] );

        $deleted = HSP_Smart_Cache_Maintenance::delete_backup( $backup['file'] );
        $this->assertTrue( $deleted );
        $this->assertFileDoesNotExist( $backup['path'] );
    }

    private function delete_dir_recursively( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = scandir( $dir );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $path = $dir . '/' . $item;
            if ( is_dir( $path ) ) {
                $this->delete_dir_recursively( $path );
                @rmdir( $path );
            } else {
                @unlink( $path );
            }
        }

        @rmdir( $dir );
    }
}
