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
            $rows = isset( $row['Rows'] ) ? (int) $row['Rows'] : 0;

            $size = $data_length + $index_length;
            $overhead = self::estimate_reclaimable_bytes( $row, $size );
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

    protected static function estimate_reclaimable_bytes( $status_row, $table_size ) {
        $table_size = max( 0, (int) $table_size );
        $raw_overhead = isset( $status_row['Data_free'] ) ? max( 0, (int) $status_row['Data_free'] ) : 0;
        if ( $table_size <= 0 || $raw_overhead <= 0 ) {
            return 0;
        }

        $engine = isset( $status_row['Engine'] ) ? strtolower( (string) $status_row['Engine'] ) : '';
        $capped_overhead = min( $raw_overhead, $table_size );

        if ( in_array( $engine, array( 'myisam', 'aria' ), true ) ) {
            return $capped_overhead;
        }

        if ( $engine === 'innodb' ) {
            if ( $raw_overhead > $table_size ) {
                return 0;
            }

            if ( $raw_overhead < 1024 * 1024 || $raw_overhead < $table_size * 0.1 ) {
                return 0;
            }

            return $capped_overhead;
        }

        return $raw_overhead <= $table_size ? $capped_overhead : 0;
    }

    public static function create_backup() {
        global $wpdb;

        $tables = self::get_tables();
        if ( empty( $tables ) ) {
            return array( 'ok' => false, 'error' => 'no_tables' );
        }

        $dir = self::get_backup_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return array( 'ok' => false, 'error' => 'mkdir_failed' );
        }

        self::protect_backup_dir( $dir );

        $compressed = function_exists( 'gzopen' );
        $filename = 'hsp-db-backup-' . gmdate( 'Y-m-d_H-i-s' ) . '-' . self::backup_filename_token() . '.sql' . ( $compressed ? '.gz' : '' );
        $path = trailingslashit( $dir ) . $filename;
        $handle = $compressed ? gzopen( $path, 'wb' ) : fopen( $path, 'wb' );
        if ( ! $handle ) {
            return array( 'ok' => false, 'error' => 'open_failed' );
        }

        $tables_written = 0;
        $rows_written = 0;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        self::write_backup_line( $handle, '-- HSP Smart Cache database backup', $compressed );
        self::write_backup_line( $handle, '-- Created at GMT: ' . gmdate( 'c' ), $compressed );
        self::write_backup_line( $handle, '-- Site URL: ' . site_url(), $compressed );
        self::write_backup_line( $handle, 'SET FOREIGN_KEY_CHECKS=0;', $compressed );
        self::write_backup_line( $handle, '', $compressed );

        foreach ( $tables as $table ) {
            $table = (string) $table;
            $create_row = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::quote_identifier( $table ), ARRAY_A );
            $create_sql = '';
            if ( is_array( $create_row ) ) {
                foreach ( $create_row as $value ) {
                    if ( is_string( $value ) && stripos( $value, 'CREATE TABLE' ) === 0 ) {
                        $create_sql = $value;
                        break;
                    }
                }
            }

            if ( $create_sql === '' ) {
                continue;
            }

            self::write_backup_line( $handle, 'DROP TABLE IF EXISTS ' . self::quote_identifier( $table ) . ';', $compressed );
            self::write_backup_line( $handle, rtrim( $create_sql, " \t\n\r\0\x0B;" ) . ';', $compressed );

            $offset = 0;
            $batch_size = 500;
            do {
                $rows = $wpdb->get_results( 'SELECT * FROM ' . self::quote_identifier( $table ) . ' LIMIT ' . intval( $offset ) . ', ' . intval( $batch_size ), ARRAY_A );
                if ( empty( $rows ) || ! is_array( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) || empty( $row ) ) {
                        continue;
                    }

                    $columns = array_map( array( __CLASS__, 'quote_identifier' ), array_keys( $row ) );
                    $values = array_map( array( __CLASS__, 'sql_literal' ), array_values( $row ) );
                    self::write_backup_line(
                        $handle,
                        'INSERT INTO ' . self::quote_identifier( $table ) . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ');',
                        $compressed
                    );
                    $rows_written++;
                }

                $offset += $batch_size;
            } while ( count( $rows ) === $batch_size );

            self::write_backup_line( $handle, '', $compressed );
            $tables_written++;
        }

        self::write_backup_line( $handle, 'SET FOREIGN_KEY_CHECKS=1;', $compressed );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

        $closed = $compressed ? gzclose( $handle ) : fclose( $handle );
        if ( ! $closed ) {
            return array( 'ok' => false, 'error' => 'close_failed' );
        }

        return array(
            'ok' => true,
            'file' => $filename,
            'path' => $path,
            'size_bytes' => (int) @filesize( $path ),
            'created_at_gmt' => gmdate( 'c' ),
            'compressed' => $compressed,
            'tables' => $tables_written,
            'rows' => $rows_written,
        );
    }

    public static function list_backups() {
        $dir = self::get_backup_dir();
        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $files = array_merge(
            (array) glob( trailingslashit( $dir ) . 'hsp-db-backup-*.sql*' ),
            (array) glob( trailingslashit( $dir ) . 'hspsc-db-backup-*.sql*' )
        );
        $files = array_values( array_unique( array_filter( $files ) ) );
        if ( empty( $files ) ) {
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
            if ( ! self::get_backup_file_path( $name ) ) {
                continue;
            }
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

    public static function delete_backup( $file, &$error = null ) {
        $error = null;
        $path = self::get_backup_file_path( $file );
        if ( ! $path ) {
            $error = self::build_backup_delete_error( 'invalid_file', $file, $path );
            return false;
        }

        if ( ! file_exists( $path ) ) {
            $error = self::build_backup_delete_error( 'missing_file', $file, $path );
            return false;
        }

        $backup_dir_path = self::get_backup_dir();
        $backup_dir = realpath( $backup_dir_path );
        $real_path = realpath( $path );

        if ( $backup_dir && $real_path ) {
            $backup_dir_check = trailingslashit( wp_normalize_path( $backup_dir ) );
            $path_check = wp_normalize_path( $real_path );
        } else {
            $backup_dir_check = trailingslashit( wp_normalize_path( $backup_dir_path ) );
            $path_check = wp_normalize_path( $path );
        }

        if ( strpos( $path_check, $backup_dir_check ) !== 0 ) {
            $error = self::build_backup_delete_error(
                'path_outside_backup_dir',
                $file,
                $path,
                array(
                    'backup_dir_check' => $backup_dir_check,
                    'path_check' => $path_check,
                )
            );
            return false;
        }

        if ( ! wp_is_writable( $backup_dir_path ) ) {
            @chmod( $backup_dir_path, 0755 );
        }

        $deleted = false;
        $attempts = array();

        $fs = HSPSC_Utils::get_filesystem();
        if ( $fs && $fs->exists( $path ) ) {
            $deleted = (bool) $fs->delete( $path, false, 'f' );
            $attempts[] = array(
                'method' => 'WP_Filesystem::delete',
                'ok' => $deleted,
            );
        } else {
            $attempts[] = array(
                'method' => 'WP_Filesystem::exists',
                'ok' => false,
                'error' => $fs ? 'File not visible to WP_Filesystem.' : 'WP_Filesystem unavailable.',
            );
        }

        if ( ! $deleted && function_exists( 'wp_delete_file' ) ) {
            $deleted = (bool) wp_delete_file( $path );
            $attempts[] = array(
                'method' => 'wp_delete_file',
                'ok' => $deleted,
            );
        }

        if ( ! $deleted && file_exists( $path ) ) {
            if ( ! is_writable( $path ) ) {
                @chmod( $path, 0644 );
            }
            $last_error = error_get_last();
            $deleted = @unlink( $path );
            $unlink_error = error_get_last();
            if ( $last_error === $unlink_error ) {
                $unlink_error = null;
            }
            $attempts[] = array(
                'method' => 'unlink',
                'ok' => $deleted,
                'error' => $unlink_error && ! empty( $unlink_error['message'] ) ? $unlink_error['message'] : '',
            );
        }

        if ( $deleted || ! file_exists( $path ) ) {
            return true;
        }

        $error = self::build_backup_delete_error(
            'delete_failed',
            $file,
            $path,
            array(
                'backup_dir_writable' => wp_is_writable( $backup_dir_path ),
                'file_writable' => is_writable( $path ),
                'file_permissions' => self::get_file_permissions( $path ),
                'attempts' => $attempts,
            )
        );

        return false;
    }

    public static function restore_backup( $file ) {
        global $wpdb;

        $file = basename( (string) $file );
        $path = self::get_backup_file_path( $file );
        if ( ! $path || ! file_exists( $path ) ) {
            return self::build_restore_result(
                false,
                'missing_file',
                array(
                    'file' => $file,
                    'path' => $path ? wp_normalize_path( $path ) : '',
                )
            );
        }

        $sql = self::read_backup_file( $path );
        if ( $sql === false || $sql === '' ) {
            return self::build_restore_result(
                false,
                'read_failed',
                array(
                    'file' => $file,
                    'path' => wp_normalize_path( $path ),
                )
            );
        }

        $statements = self::split_sql_statements( $sql );
        if ( empty( $statements ) ) {
            return self::build_restore_result(
                false,
                'invalid_backup',
                array(
                    'file' => $file,
                    'path' => wp_normalize_path( $path ),
                )
            );
        }

        $result = self::build_restore_result(
            true,
            '',
            array(
                'file' => $file,
                'path' => wp_normalize_path( $path ),
                'total_statements' => count( $statements ),
            )
        );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ( $statements as $statement ) {
            $statement_info = self::get_restore_statement_info( $statement );
            if ( empty( $statement_info['allowed'] ) ) {
                $result['skipped']++;
                $result['skipped_statements'][] = self::build_skipped_restore_statement( $statement, $statement_info );
                continue;
            }

            $query_result = $wpdb->query( $statement );
            if ( $query_result === false ) {
                return self::build_restore_result(
                    false,
                    'query_failed',
                    array(
                        'file' => $file,
                        'path' => wp_normalize_path( $path ),
                        'total_statements' => count( $statements ),
                        'executed' => $result['executed'],
                        'skipped' => $result['skipped'],
                        'skipped_statements' => $result['skipped_statements'],
                        'tables' => $result['tables'],
                        'statement' => $result['executed'] + 1,
                        'failed_statement' => self::preview_sql_statement( $statement ),
                    )
                );
            }

            $result['executed']++;
            if ( ! empty( $statement_info['table'] ) ) {
                $table = (string) $statement_info['table'];
                if ( ! isset( $result['tables'][ $table ] ) ) {
                    $result['tables'][ $table ] = self::empty_restore_table_summary();
                }
                if ( ! empty( $statement_info['type'] ) && isset( $result['tables'][ $table ][ $statement_info['type'] ] ) ) {
                    $result['tables'][ $table ][ $statement_info['type'] ]++;
                }
            }
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

        return self::finalize_restore_result( $result );
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
        if ( $file === '' || ! preg_match( '/^hsp(?:sc)?-db-backup-[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}(?:-[A-Za-z0-9]{12})?\.sql(?:\.gz)?$/', $file ) ) {
            return null;
        }

        return trailingslashit( self::get_backup_dir() ) . $file;
    }

    protected static function build_backup_delete_error( $code, $file, $path, $details = array() ) {
        return array_merge(
            array(
                'code' => $code,
                'file' => basename( (string) $file ),
                'path' => $path ? wp_normalize_path( $path ) : '',
                'backup_dir' => wp_normalize_path( self::get_backup_dir() ),
            ),
            $details
        );
    }

    protected static function get_file_permissions( $path ) {
        $perms = @fileperms( $path );
        if ( $perms === false ) {
            return '';
        }

        return substr( sprintf( '%o', $perms ), -4 );
    }

    protected static function quote_identifier( $name ) {
        return '`' . str_replace( '`', '``', (string) $name ) . '`';
    }

    protected static function sql_literal( $value ) {
        global $wpdb;

        if ( $value === null ) {
            return 'NULL';
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( method_exists( $wpdb, '_real_escape' ) ) {
            $escaped = $wpdb->_real_escape( (string) $value );
        } elseif ( method_exists( $wpdb, '_escape' ) ) {
            $escaped = $wpdb->_escape( (string) $value );
        } else {
            $escaped = addslashes( (string) $value );
        }

        return "'" . $escaped . "'";
    }

    protected static function write_backup_line( $handle, $line, $compressed ) {
        $data = $line . "\n";
        return $compressed ? gzwrite( $handle, $data ) : fwrite( $handle, $data );
    }

    protected static function backup_filename_token() {
        if ( function_exists( 'wp_generate_password' ) ) {
            return wp_generate_password( 12, false, false );
        }

        return substr( str_replace( array( '+', '/', '=' ), '', base64_encode( random_bytes( 9 ) ) ), 0, 12 );
    }

    protected static function read_backup_file( $path ) {
        if ( substr( $path, -3 ) !== '.gz' ) {
            return file_get_contents( $path );
        }

        if ( ! function_exists( 'gzopen' ) ) {
            return false;
        }

        $handle = gzopen( $path, 'rb' );
        if ( ! $handle ) {
            return false;
        }

        $contents = '';
        while ( ! gzeof( $handle ) ) {
            $chunk = gzread( $handle, 1024 * 1024 );
            if ( $chunk === false ) {
                gzclose( $handle );
                return false;
            }
            $contents .= $chunk;
        }

        gzclose( $handle );
        return $contents;
    }

    protected static function split_sql_statements( $sql ) {
        $statements = array();
        $statement = '';
        $quote = '';
        $escaped = false;
        $length = strlen( $sql );

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $sql[ $i ];
            $next = $i + 1 < $length ? $sql[ $i + 1 ] : '';

            if ( $quote === '' && $char === '-' && $next === '-' ) {
                while ( $i < $length && $sql[ $i ] !== "\n" ) {
                    $i++;
                }
                continue;
            }

            $statement .= $char;

            if ( $quote !== '' ) {
                if ( $escaped ) {
                    $escaped = false;
                    continue;
                }
                if ( $char === '\\' ) {
                    $escaped = true;
                    continue;
                }
                if ( $char === $quote ) {
                    $quote = '';
                }
                continue;
            }

            if ( $char === "'" || $char === '"' || $char === '`' ) {
                $quote = $char;
                continue;
            }

            if ( $char === ';' ) {
                $trimmed = trim( $statement );
                if ( $trimmed !== '' ) {
                    $statements[] = $trimmed;
                }
                $statement = '';
            }
        }

        $trimmed = trim( $statement );
        if ( $trimmed !== '' ) {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    protected static function build_restore_result( $ok, $error = '', $data = array() ) {
        return array_merge(
            array(
                'ok' => (bool) $ok,
                'error' => $error,
                'file' => '',
                'path' => '',
                'total_statements' => 0,
                'executed' => 0,
                'statements' => 0,
                'skipped' => 0,
                'skipped_statements' => array(),
                'tables' => array(),
                'warnings' => array(),
            ),
            $data
        );
    }

    protected static function finalize_restore_result( $result ) {
        $result['statements'] = isset( $result['executed'] ) ? (int) $result['executed'] : 0;

        if ( empty( $result['tables'] ) ) {
            $result['ok'] = false;
            $result['error'] = 'no_tables_restored';
            $result['warnings'][] = 'No WordPress tables were restored. Check whether the backup table prefix matches this site.';
            return $result;
        }

        foreach ( $result['tables'] as $table => $summary ) {
            if ( empty( $summary['drop'] ) || empty( $summary['create'] ) ) {
                $result['warnings'][] = sprintf(
                    'Table %s did not include both DROP TABLE and CREATE TABLE statements, so the restore may not remove rows created after the backup.',
                    $table
                );
            }
        }

        if ( ! empty( $result['skipped'] ) ) {
            $result['warnings'][] = sprintf(
                '%d SQL statement(s) were skipped because they were not allowed for restore.',
                (int) $result['skipped']
            );
        }

        return $result;
    }

    protected static function empty_restore_table_summary() {
        return array(
            'drop' => 0,
            'create' => 0,
            'insert' => 0,
        );
    }

    protected static function get_restore_statement_info( $statement ) {
        if ( preg_match( '/^\s*SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*;?\s*$/i', $statement ) ) {
            return array(
                'allowed' => true,
                'type' => 'set',
                'table' => '',
                'reason' => '',
            );
        }

        if ( ! preg_match( '/^\s*(DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE|INSERT\s+INTO)\s+`?([^`\s(]+)`?/i', $statement, $matches ) ) {
            return array(
                'allowed' => false,
                'type' => '',
                'table' => '',
                'reason' => 'unsupported_statement',
            );
        }

        $table = $matches[2];
        if ( ! self::is_wp_table( $table ) ) {
            return array(
                'allowed' => false,
                'type' => '',
                'table' => $table,
                'reason' => 'non_wordpress_table',
            );
        }

        $keyword = strtoupper( $matches[1] );
        if ( strpos( $keyword, 'DROP' ) === 0 ) {
            $type = 'drop';
        } elseif ( strpos( $keyword, 'CREATE' ) === 0 ) {
            $type = 'create';
        } else {
            $type = 'insert';
        }

        return array(
            'allowed' => true,
            'type' => $type,
            'table' => $table,
            'reason' => '',
        );
    }

    protected static function build_skipped_restore_statement( $statement, $statement_info ) {
        return array(
            'reason' => isset( $statement_info['reason'] ) ? $statement_info['reason'] : 'unknown',
            'table' => isset( $statement_info['table'] ) ? $statement_info['table'] : '',
            'statement' => self::preview_sql_statement( $statement ),
        );
    }

    protected static function preview_sql_statement( $statement ) {
        $statement = preg_replace( '/\s+/', ' ', trim( (string) $statement ) );
        if ( strlen( $statement ) > 220 ) {
            $statement = substr( $statement, 0, 217 ) . '...';
        }

        return $statement;
    }

    protected static function is_allowed_restore_statement( $statement ) {
        $statement_info = self::get_restore_statement_info( $statement );
        return ! empty( $statement_info['allowed'] );
    }

    protected static function protect_backup_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }
    }
}
