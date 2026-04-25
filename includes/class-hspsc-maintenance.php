<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Maintenance {
    const BACKUP_DIR = 'db-backups';

    public static function run_db_cleanup() {
        global $wpdb;
        $before = self::analyze_cleanup_candidates();
        $errors = array();
        $affected = array();

        $stale_post_where = "p.post_type = 'revision' OR p.post_status IN ('auto-draft','trash')";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        self::run_counted_query(
            "DELETE cm FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID WHERE {$stale_post_where}",
            'commentmeta_for_posts',
            $affected,
            $errors
        );
        self::run_counted_query(
            "DELETE c FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID WHERE {$stale_post_where}",
            'comments_for_posts',
            $affected,
            $errors
        );
        self::run_counted_query(
            "DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE {$stale_post_where}",
            'postmeta',
            $affected,
            $errors
        );
        self::run_counted_query(
            "DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID WHERE {$stale_post_where}",
            'term_relationships',
            $affected,
            $errors
        );
        self::run_counted_query(
            "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' OR post_status IN ('auto-draft','trash')",
            'posts',
            $affected,
            $errors
        );

        self::run_counted_query(
            "DELETE cm FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_approved IN ('spam','trash')",
            'spam_trash_commentmeta',
            $affected,
            $errors
        );
        self::run_counted_query(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')",
            'spam_trash_comments',
            $affected,
            $errors
        );

        // Delete expired transients
        $now = time();
        foreach ( self::get_transient_timeout_prefixes() as $prefix ) {
            $like = $wpdb->esc_like( $prefix ) . '%';
            self::run_counted_query(
                $wpdb->prepare(
                    "DELETE o FROM {$wpdb->options} o INNER JOIN {$wpdb->options} t ON o.option_name = REPLACE(t.option_name, '_timeout', '') WHERE t.option_name LIKE %s AND t.option_value < %d",
                    $like,
                    $now
                ),
                $prefix . 'values',
                $affected,
                $errors
            );
            self::run_counted_query(
                $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $like, $now ),
                $prefix . 'timeouts',
                $affected,
                $errors
            );
        }

        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

        return array(
            'ok' => empty( $errors ),
            'cleanup' => $before,
            'affected' => $affected,
            'errors' => $errors,
        );
    }

    public static function analyze_optimization() {
        global $wpdb;
        $cleanup = self::analyze_cleanup_candidates();

        $wp_tables = self::get_tables();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        $status_rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ( empty( $status_rows ) || ! is_array( $status_rows ) ) {
            return array(
                'ok' => false,
                'table_count' => 0,
                'total_size_bytes' => 0,
                'total_overhead_bytes' => 0,
                'optimizable_tables' => 0,
                'cleanup' => $cleanup,
                'tables' => array(),
            );
        }

        $tables = array();
        $total_size = 0;
        $total_overhead = 0;
        $optimizable = 0;

        foreach ( $status_rows as $row ) {
            $name = isset( $row['Name'] ) ? (string) $row['Name'] : '';
            if ( $name === '' ) {
                continue;
            }
            if ( ! in_array( $name, $wp_tables, true ) ) {
                continue;
            }

            $data_length = isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0;
            $index_length = isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0;
            $overhead = isset( $row['Data_free'] ) ? (int) $row['Data_free'] : 0;
            $rows = isset( $row['Rows'] ) ? (int) $row['Rows'] : 0;

            $size = $data_length + $index_length;
            $is_optimizable = $overhead > 0;

            $total_size += $size;
            $total_overhead += $overhead;

            if ( $is_optimizable ) {
                $optimizable++;
            }

            $tables[] = array(
                'name' => $name,
                'rows' => $rows,
                'size_bytes' => $size,
                'overhead_bytes' => $overhead,
                'optimizable' => $is_optimizable,
            );
        }

        return array(
            'ok' => true,
            'table_count' => count( $tables ),
            'total_size_bytes' => $total_size,
            'total_overhead_bytes' => $total_overhead,
            'optimizable_tables' => $optimizable,
            'cleanup' => $cleanup,
            'tables' => $tables,
            'generated_at' => gmdate( 'c' ),
        );
    }

    public static function analyze_cleanup_candidates() {
        global $wpdb;

        $now = time();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        $revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        $auto_drafts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
        $trashed_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
        $spam_trash_comments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" );
        $expired_transient_timeouts = 0;
        $expired_transient_values = 0;
        foreach ( self::get_transient_timeout_prefixes() as $prefix ) {
            $like = $wpdb->esc_like( $prefix ) . '%';
            $expired_transient_timeouts += (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
                    $like,
                    $now
                )
            );
            $expired_transient_values += (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(o.option_id) FROM {$wpdb->options} o INNER JOIN {$wpdb->options} t ON o.option_name = REPLACE(t.option_name, '_timeout', '') WHERE t.option_name LIKE %s AND t.option_value < %d",
                    $like,
                    $now
                )
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

        return array(
            'revisions' => $revisions,
            'auto_drafts' => $auto_drafts,
            'trashed_posts' => $trashed_posts,
            'spam_trash_comments' => $spam_trash_comments,
            'expired_transient_timeouts' => $expired_transient_timeouts,
            'expired_transient_values' => $expired_transient_values,
            'total_items' => $revisions + $auto_drafts + $trashed_posts + $spam_trash_comments + $expired_transient_timeouts + $expired_transient_values,
        );
    }

    public static function create_backup() {
        global $wpdb;

        $tables = self::get_tables();
        if ( empty( $tables ) ) {
            return array( 'ok' => false, 'error' => 'no_tables' );
        }

        $payload = array(
            'created_at_gmt' => gmdate( 'c' ),
            'site_url' => site_url(),
            'tables' => array(),
        );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ( $tables as $table ) {
            $table = (string) $table;
            $create_row = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::quote_identifier( $table ), ARRAY_A );
            $rows = $wpdb->get_results( 'SELECT * FROM ' . self::quote_identifier( $table ), ARRAY_A );

            $create_sql = '';
            if ( is_array( $create_row ) ) {
                foreach ( $create_row as $value ) {
                    if ( is_string( $value ) && stripos( $value, 'CREATE TABLE' ) === 0 ) {
                        $create_sql = $value;
                        break;
                    }
                }
            }

            $payload['tables'][] = array(
                'name' => $table,
                'create_sql' => $create_sql,
                'rows' => is_array( $rows ) ? $rows : array(),
            );
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

        $json = wp_json_encode( $payload );
        if ( ! is_string( $json ) || $json === '' ) {
            return array( 'ok' => false, 'error' => 'encode_failed' );
        }

        $dir = self::get_backup_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return array( 'ok' => false, 'error' => 'mkdir_failed' );
        }

        $filename = 'hsp-db-backup-' . gmdate( 'Y-m-d_H-i-s' ) . '.json';
        $path = trailingslashit( $dir ) . $filename;

        $written = file_put_contents( $path, $json );
        if ( $written === false ) {
            return array( 'ok' => false, 'error' => 'write_failed' );
        }

        return array(
            'ok' => true,
            'file' => $filename,
            'path' => $path,
            'size_bytes' => (int) $written,
            'created_at_gmt' => $payload['created_at_gmt'],
        );
    }

    public static function list_backups() {
        $dir = self::get_backup_dir();
        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $files = glob( trailingslashit( $dir ) . 'hsp-db-backup-*.json' );
        if ( empty( $files ) || ! is_array( $files ) ) {
            return array();
        }

        usort(
            $files,
            static function ( $a, $b ) {
                $mtime_a = (int) @filemtime( $a );
                $mtime_b = (int) @filemtime( $b );
                return $mtime_b <=> $mtime_a;
            }
        );

        $backups = array();
        foreach ( $files as $path ) {
            $name = basename( $path );
            $mtime = @filemtime( $path );
            $backups[] = array(
                'file' => $name,
                'path' => $path,
                'size_bytes' => (int) @filesize( $path ),
                'timestamp' => $mtime ? (int) $mtime : 0,
            );
        }

        return $backups;
    }

    public static function delete_backup( $file ) {
        $path = self::get_backup_file_path( $file );
        if ( ! $path || ! file_exists( $path ) ) {
            return false;
        }

        return (bool) wp_delete_file( $path );
    }

    public static function restore_backup( $file ) {
        global $wpdb;

        $path = self::get_backup_file_path( $file );
        if ( ! $path || ! file_exists( $path ) ) {
            return array( 'ok' => false, 'error' => 'missing_file' );
        }

        $json = file_get_contents( $path );
        if ( $json === false || $json === '' ) {
            return array( 'ok' => false, 'error' => 'read_failed' );
        }

        $payload = json_decode( $json, true );
        if ( ! is_array( $payload ) || empty( $payload['tables'] ) || ! is_array( $payload['tables'] ) ) {
            return array( 'ok' => false, 'error' => 'invalid_backup' );
        }

        $restored_tables = 0;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

        foreach ( $payload['tables'] as $table_payload ) {
            if ( empty( $table_payload['name'] ) ) {
                continue;
            }

            $table = (string) $table_payload['name'];
            if ( ! self::is_wp_table( $table ) ) {
                continue;
            }

            $create_sql = isset( $table_payload['create_sql'] ) ? (string) $table_payload['create_sql'] : '';

            if ( $create_sql !== '' ) {
                $wpdb->query( $create_sql );
            }

            $wpdb->query( 'DELETE FROM ' . self::quote_identifier( $table ) );

            $rows = isset( $table_payload['rows'] ) && is_array( $table_payload['rows'] ) ? $table_payload['rows'] : array();
            foreach ( $rows as $row ) {
                if ( is_array( $row ) ) {
                    $wpdb->insert( $table, $row );
                }
            }

            $restored_tables++;
        }

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

        return array( 'ok' => true, 'tables' => $restored_tables );
    }

    public static function optimize_tables() {
        global $wpdb;
        $errors = array();
        $optimized = array();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $tables = self::get_tables();
        if ( empty( $tables ) ) {
            return array(
                'ok' => false,
                'optimized_tables' => 0,
                'tables' => array(),
                'errors' => array( 'no_tables' ),
            );
        }
        foreach ( $tables as $table ) {
            $result = $wpdb->query( 'OPTIMIZE TABLE ' . self::quote_identifier( $table ) );
            if ( $result === false ) {
                $errors[] = $table;
                continue;
            }

            $optimized[] = $table;
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

        return array(
            'ok' => empty( $errors ),
            'optimized_tables' => count( $optimized ),
            'tables' => $optimized,
            'errors' => $errors,
        );
    }

    protected static function get_tables() {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ( ! is_array( $tables ) ) {
            return array();
        }

        $prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
        if ( $prefix === '' ) {
            return $tables;
        }

        return array_values(
            array_filter(
                $tables,
                static function ( $table ) {
                    return self::is_wp_table( $table );
                }
            )
        );
    }

    protected static function is_wp_table( $table ) {
        global $wpdb;

        $prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
        return $prefix === '' || strpos( (string) $table, $prefix ) === 0;
    }

    protected static function run_counted_query( $sql, $key, &$affected, &$errors ) {
        global $wpdb;

        $result = $wpdb->query( $sql );
        if ( $result === false ) {
            $errors[] = $key;
            $affected[ $key ] = 0;
            return false;
        }

        $affected[ $key ] = (int) $result;
        return true;
    }

    protected static function get_transient_timeout_prefixes() {
        return array(
            '_transient_timeout_',
            '_site_transient_timeout_',
        );
    }

    protected static function get_backup_dir() {
        return trailingslashit( HSPSC_PATH ) . self::BACKUP_DIR;
    }

    protected static function get_backup_file_path( $file ) {
        $file = basename( (string) $file );
        if ( $file === '' || ! preg_match( '/^hsp-db-backup-[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.json$/', $file ) ) {
            return null;
        }

        return trailingslashit( self::get_backup_dir() ) . $file;
    }

    protected static function quote_identifier( $name ) {
        return '`' . str_replace( '`', '``', (string) $name ) . '`';
    }
}
