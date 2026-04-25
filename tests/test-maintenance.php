<?php

class HSPSC_Maintenance_WPDB_Mock {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $comments = 'wp_comments';
    public $commentmeta = 'wp_commentmeta';
    public $options = 'wp_options';
    public $postmeta = 'wp_postmeta';
    public $term_relationships = 'wp_term_relationships';
    public $queries = array();
    public $tables = array( 'wp_posts', 'wp_comments', 'wp_options', 'other_logs' );
    public $status_rows = array();
    public $table_rows = array();
    public $create_sql = array();
    public $inserted = array();
    public $counts = array();
    public $query_fail_patterns = array();

    public function query( $sql ) {
        $this->queries[] = $sql;
        foreach ( $this->query_fail_patterns as $pattern ) {
            if ( stripos( $sql, $pattern ) !== false ) {
                return false;
            }
        }

        return 1;
    }

    public function esc_like( $text ) {
        return addcslashes( $text, '_%\\' );
    }

    public function _escape( $data ) {
        if ( is_array( $data ) ) {
            return array_map( array( $this, '_escape' ), $data );
        }

        return addslashes( (string) $data );
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
        $this->queries[] = $sql;
        if ( stripos( $sql, 'SHOW TABLES' ) !== false ) {
            return $this->tables;
        }
        return array();
    }

    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( stripos( $sql, "post_type = 'revision'" ) !== false ) {
            return isset( $this->counts['revisions'] ) ? $this->counts['revisions'] : 0;
        }

        if ( stripos( $sql, "post_status = 'auto-draft'" ) !== false ) {
            return isset( $this->counts['auto_drafts'] ) ? $this->counts['auto_drafts'] : 0;
        }

        if ( stripos( $sql, "post_status = 'trash'" ) !== false ) {
            return isset( $this->counts['trashed_posts'] ) ? $this->counts['trashed_posts'] : 0;
        }

        if ( stripos( $sql, "comment_approved IN ('spam','trash')" ) !== false ) {
            return isset( $this->counts['spam_trash_comments'] ) ? $this->counts['spam_trash_comments'] : 0;
        }

        if ( stripos( $sql, 'COUNT(o.option_id)' ) !== false && stripos( $sql, 'site' ) !== false && stripos( $sql, 'transient' ) !== false ) {
            return isset( $this->counts['expired_site_transient_values'] ) ? $this->counts['expired_site_transient_values'] : 0;
        }

        if ( stripos( $sql, 'COUNT(o.option_id)' ) !== false ) {
            return isset( $this->counts['expired_transient_values'] ) ? $this->counts['expired_transient_values'] : 0;
        }

        if ( stripos( $sql, 'option_name LIKE' ) !== false && stripos( $sql, 'option_value <' ) !== false && stripos( $sql, 'site' ) !== false && stripos( $sql, 'transient' ) !== false ) {
            return isset( $this->counts['expired_site_transient_timeouts'] ) ? $this->counts['expired_site_transient_timeouts'] : 0;
        }

        if ( stripos( $sql, 'option_name LIKE' ) !== false && stripos( $sql, 'option_value <' ) !== false ) {
            return isset( $this->counts['expired_transient_timeouts'] ) ? $this->counts['expired_transient_timeouts'] : 0;
        }

        return 0;
    }

    public function get_results( $sql, $output = OBJECT ) {
        $this->queries[] = $sql;
        if ( stripos( $sql, 'SHOW TABLE STATUS' ) !== false ) {
            return $this->status_rows;
        }

        if ( preg_match( '/SELECT\s+\*\s+FROM\s+`([^`]+)`(?:\s+LIMIT\s+(\d+)\s*,\s*(\d+))?/i', $sql, $matches ) ) {
            $table = $matches[1];
            $rows = isset( $this->table_rows[ $table ] ) ? $this->table_rows[ $table ] : array();
            if ( isset( $matches[2], $matches[3] ) ) {
                return array_slice( $rows, intval( $matches[2] ), intval( $matches[3] ) );
            }

            return $rows;
        }

        return array();
    }

    public function get_row( $sql, $output = OBJECT ) {
        $this->queries[] = $sql;
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

    public function suppress_errors( $suppress = null ) {
        return false;
    }
}

class HSPSC_Maintenance_Test extends WP_UnitTestCase {
    private $wpdb_original;
    private $backup_dir;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->wpdb_original = $wpdb;
        $this->backup_dir = HSPSC_PATH . '/db-backups';

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
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();

        $result = HSPSC_Maintenance::run_db_cleanup();

        $this->assertIsArray( $result );
        $this->assertTrue( $result['ok'] );
        $this->assertGreaterThanOrEqual( 11, count( $wpdb->queries ) );
        $this->assertStringContainsString( 'wp_postmeta', implode( "\n", $wpdb->queries ) );
        $this->assertStringContainsString( 'wp_term_relationships', implode( "\n", $wpdb->queries ) );
        $this->assertStringContainsString( 'site\\_transient\\_timeout', implode( "\n", $wpdb->queries ) );
    }

    public function test_optimize_tables_returns_true_and_runs_optimize_per_table() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_posts', 'wp_options', 'other_logs' );

        $result = HSPSC_Maintenance::optimize_tables();

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 2, $result['optimized_tables'] );
        $this->assertContains( 'OPTIMIZE TABLE `wp_posts`', $wpdb->queries );
        $this->assertContains( 'OPTIMIZE TABLE `wp_options`', $wpdb->queries );
        $this->assertNotContains( 'OPTIMIZE TABLE `other_logs`', $wpdb->queries );
    }

    public function test_optimize_tables_returns_false_when_no_tables_found() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->tables = array();

        $result = HSPSC_Maintenance::optimize_tables();

        $this->assertFalse( $result['ok'] );
        $this->assertSame( array( 'no_tables' ), $result['errors'] );
    }

    public function test_optimize_tables_reports_query_failures() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_posts', 'wp_options' );
        $wpdb->query_fail_patterns = array( 'OPTIMIZE TABLE `wp_options`' );

        $result = HSPSC_Maintenance::optimize_tables();

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 1, $result['optimized_tables'] );
        $this->assertSame( array( 'wp_options' ), $result['errors'] );
    }

    public function test_analyze_optimization_returns_summary() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->counts = array(
            'revisions' => 7,
            'auto_drafts' => 2,
            'trashed_posts' => 3,
            'spam_trash_comments' => 5,
            'expired_transient_timeouts' => 11,
            'expired_transient_values' => 10,
            'expired_site_transient_timeouts' => 4,
            'expired_site_transient_values' => 3,
        );
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
            array(
                'Name' => 'other_logs',
                'Rows' => 500,
                'Data_length' => 10000,
                'Index_length' => 1000,
                'Data_free' => 1000,
            ),
        );

        $analysis = HSPSC_Maintenance::analyze_optimization();

        $this->assertTrue( $analysis['ok'] );
        $this->assertSame( 2, $analysis['table_count'] );
        $this->assertSame( 1, $analysis['optimizable_tables'] );
        $this->assertSame( 1800, $analysis['total_size_bytes'] );
        $this->assertSame( 250, $analysis['total_overhead_bytes'] );
        $this->assertSame( 7, $analysis['cleanup']['revisions'] );
        $this->assertSame( 15, $analysis['cleanup']['expired_transient_timeouts'] );
        $this->assertSame( 45, $analysis['cleanup']['total_items'] );
    }

    public function test_create_backup_generates_timestamped_file() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_posts' );
        $wpdb->table_rows = array(
            'wp_posts' => array(
                array( 'ID' => 1, 'post_title' => 'Hello' ),
            ),
        );
        $wpdb->create_sql = array(
            'wp_posts' => 'CREATE TABLE `wp_posts` (`ID` int(11) NOT NULL, `post_title` text)',
        );

        $backup = HSPSC_Maintenance::create_backup();

        $this->assertTrue( $backup['ok'] );
        $this->assertMatchesRegularExpression( '/^hsp-db-backup-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql(?:\.gz)?$/', $backup['file'] );
        $this->assertFileExists( $backup['path'] );
        $this->assertSame( 1, $backup['tables'] );
        $this->assertSame( 1, $backup['rows'] );

        $contents = $this->read_backup_contents( $backup['path'] );
        $this->assertStringContainsString( 'CREATE TABLE `wp_posts`', $contents );
        $this->assertStringContainsString( 'INSERT INTO `wp_posts`', $contents );
        $this->assertStringContainsString( "'Hello'", $contents );
    }

    public function test_create_backup_streams_rows_in_batches_and_escapes_semicolons() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_posts' );
        $wpdb->table_rows = array( 'wp_posts' => array() );
        $wpdb->create_sql = array(
            'wp_posts' => 'CREATE TABLE `wp_posts` (`ID` int(11) NOT NULL, `post_title` text, `post_content` text)',
        );

        for ( $i = 1; $i <= 501; $i++ ) {
            $wpdb->table_rows['wp_posts'][] = array(
                'ID' => $i,
                'post_title' => 'Post ' . $i,
                'post_content' => $i === 501 ? "Semi; colon and 'quote'" : 'Body ' . $i,
            );
        }

        $backup = HSPSC_Maintenance::create_backup();
        $contents = $this->read_backup_contents( $backup['path'] );

        $this->assertTrue( $backup['ok'] );
        $this->assertSame( 501, $backup['rows'] );
        $this->assertStringContainsString( 'LIMIT 0, 500', implode( "\n", $wpdb->queries ) );
        $this->assertStringContainsString( 'LIMIT 500, 500', implode( "\n", $wpdb->queries ) );
        $this->assertStringContainsString( "Semi; colon and \\'quote\\'", $contents );
    }

    public function test_restore_backup_reinserts_rows() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
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

        $backup = HSPSC_Maintenance::create_backup();
        $this->assertTrue( $backup['ok'] );

        $restore = HSPSC_Maintenance::restore_backup( $backup['file'] );

        $this->assertTrue( $restore['ok'] );
        $this->assertGreaterThanOrEqual( 5, $restore['statements'] );
        $this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_posts`', implode( "\n", $wpdb->queries ) );
        $this->assertStringContainsString( 'INSERT INTO `wp_posts`', implode( "\n", $wpdb->queries ) );
    }

    public function test_restore_backup_handles_semicolons_inside_values() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();

        $file = 'hsp-db-backup-2026-04-25_10-20-30.sql';
        $path = $this->backup_dir . '/' . $file;
        wp_mkdir_p( $this->backup_dir );
        file_put_contents(
            $path,
            "SET FOREIGN_KEY_CHECKS=0;\n"
            . "INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES ('1', 'A title; with semicolon');\n"
            . "SET FOREIGN_KEY_CHECKS=1;\n"
        );

        $restore = HSPSC_Maintenance::restore_backup( $file );

        $this->assertTrue( $restore['ok'] );
        $this->assertSame( 3, $restore['statements'] );
        $this->assertContains( "INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES ('1', 'A title; with semicolon');", $wpdb->queries );
    }

    public function test_restore_backup_skips_non_wordpress_table_statements() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();

        $file = 'hsp-db-backup-2026-04-25_10-20-31.sql';
        $path = $this->backup_dir . '/' . $file;
        wp_mkdir_p( $this->backup_dir );
        file_put_contents(
            $path,
            "DROP TABLE IF EXISTS `other_logs`;\n"
            . "CREATE TABLE `other_logs` (`id` int(11) NOT NULL);\n"
            . "INSERT INTO `other_logs` (`id`) VALUES ('1');\n"
            . "INSERT INTO `wp_posts` (`ID`) VALUES ('2');\n"
        );

        $restore = HSPSC_Maintenance::restore_backup( $file );

        $this->assertTrue( $restore['ok'] );
        $this->assertSame( 1, $restore['statements'] );
        $this->assertSame( array( "INSERT INTO `wp_posts` (`ID`) VALUES ('2');" ), $wpdb->queries );
    }

    public function test_restore_backup_reports_query_failures() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->query_fail_patterns = array( 'INSERT INTO `wp_posts`' );

        $file = 'hsp-db-backup-2026-04-25_10-20-32.sql';
        $path = $this->backup_dir . '/' . $file;
        wp_mkdir_p( $this->backup_dir );
        file_put_contents( $path, "INSERT INTO `wp_posts` (`ID`) VALUES ('1');\n" );

        $restore = HSPSC_Maintenance::restore_backup( $file );

        $this->assertFalse( $restore['ok'] );
        $this->assertSame( 'query_failed', $restore['error'] );
        $this->assertSame( 1, $restore['statement'] );
    }

    public function test_list_and_delete_backups_work() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_options' );
        $wpdb->table_rows = array( 'wp_options' => array() );
        $wpdb->create_sql = array( 'wp_options' => 'CREATE TABLE `wp_options` (`option_id` int(11) NOT NULL)' );

        $backup = HSPSC_Maintenance::create_backup();
        $this->assertTrue( $backup['ok'] );

        $backups = HSPSC_Maintenance::list_backups();
        $this->assertNotEmpty( $backups );
        $this->assertSame( $backup['file'], $backups[0]['file'] );

        $deleted = HSPSC_Maintenance::delete_backup( $backup['file'] );
        $this->assertTrue( $deleted );
        $this->assertFileDoesNotExist( $backup['path'] );
    }

    public function test_delete_backup_falls_back_when_wp_delete_file_is_blocked() {
        global $wpdb;
        $wpdb = new HSPSC_Maintenance_WPDB_Mock();
        $wpdb->tables = array( 'wp_options' );
        $wpdb->table_rows = array( 'wp_options' => array() );
        $wpdb->create_sql = array( 'wp_options' => 'CREATE TABLE `wp_options` (`option_id` int(11) NOT NULL)' );

        $backup = HSPSC_Maintenance::create_backup();
        $this->assertTrue( $backup['ok'] );

        add_filter(
            'wp_delete_file',
            static function () {
                return '';
            }
        );

        $deleted = HSPSC_Maintenance::delete_backup( $backup['file'] );

        remove_all_filters( 'wp_delete_file' );

        $this->assertTrue( $deleted );
        $this->assertFileDoesNotExist( $backup['path'] );
    }

    public function test_list_and_delete_hspsc_prefixed_backup_files() {
        wp_mkdir_p( $this->backup_dir );

        $file = 'hspsc-db-backup-2026-04-25_10-20-33.sql';
        $path = $this->backup_dir . '/' . $file;
        file_put_contents( $path, '-- backup' );

        $backups = HSPSC_Maintenance::list_backups();
        $this->assertNotEmpty( $backups );
        $this->assertSame( $file, $backups[0]['file'] );

        $this->assertTrue( HSPSC_Maintenance::delete_backup( $file ) );
        $this->assertFileDoesNotExist( $path );
    }

    private function read_backup_contents( $path ) {
        $contents = file_get_contents( $path );
        if ( substr( $path, -3 ) === '.gz' ) {
            $decoded = gzdecode( $contents );
            return is_string( $decoded ) ? $decoded : '';
        }

        return (string) $contents;
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
