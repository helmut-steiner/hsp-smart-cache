<?php

class HSP_Smart_Cache_Maintenance_WPDB_Mock {
    public $posts = 'wp_posts';
    public $comments = 'wp_comments';
    public $options = 'wp_options';
    public $queries = array();
    public $tables = array( 'wp_posts', 'wp_comments', 'wp_options' );

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
}

class HSP_Smart_Cache_Maintenance_Test extends WP_UnitTestCase {
    private $wpdb_original;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->wpdb_original = $wpdb;
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb = $this->wpdb_original;
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
}
