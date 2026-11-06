<?php
/**
 * Database analysis, cleanup and authenticated site-scoped backups.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides privileged database maintenance and backup operations.
 */
class HSPSC_Maintenance {
	const BACKUP_DIR = 'db-backups';

	/**
	 * Deletes supported stale WordPress records and reports affected row counts.
	 *
	 * @return array Cleanup analysis, per-operation counts and error codes.
	 */
	public static function run_db_cleanup() {
		global $wpdb;
		$before   = self::analyze_cleanup_candidates();
		$errors   = array();
		$affected = array();

		$stale_post_where = "p.post_type = 'revision' OR p.post_status IN ('auto-draft','trash')";

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

		// Delete expired transients.
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

		return array(
			'ok'       => empty( $errors ),
			'cleanup'  => $before,
			'affected' => $affected,
			'errors'   => $errors,
		);
	}

	/**
	 * Measures current site-table sizes and estimates reclaimable storage.
	 *
	 * @return array Table metrics, cleanup candidates and overall analysis status.
	 */
	public static function analyze_optimization() {
		global $wpdb;
		$cleanup = self::analyze_cleanup_candidates();

		$wp_tables = self::get_tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read live engine metadata for a maintenance analysis; cached table sizes would misreport current storage.
		$status_rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( empty( $status_rows ) || ! is_array( $status_rows ) ) {
			return array(
				'ok'                   => false,
				'table_count'          => 0,
				'total_size_bytes'     => 0,
				'total_overhead_bytes' => 0,
				'optimizable_tables'   => 0,
				'cleanup'              => $cleanup,
				'tables'               => array(),
			);
		}

		$tables         = array();
		$total_size     = 0;
		$total_overhead = 0;
		$optimizable    = 0;

		foreach ( $status_rows as $row ) {
			$name = isset( $row['Name'] ) ? (string) $row['Name'] : '';
			if ( '' === $name ) {
				continue;
			}
			if ( ! in_array( $name, $wp_tables, true ) ) {
				continue;
			}

			$data_length  = isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0;
			$index_length = isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0;
			$rows         = isset( $row['Rows'] ) ? (int) $row['Rows'] : 0;

			$size           = $data_length + $index_length;
			$overhead       = self::estimate_reclaimable_bytes( $row, $size );
			$is_optimizable = $overhead > 0;

			$total_size     += $size;
			$total_overhead += $overhead;

			if ( $is_optimizable ) {
				++$optimizable;
			}

			$tables[] = array(
				'name'           => $name,
				'rows'           => $rows,
				'size_bytes'     => $size,
				'overhead_bytes' => $overhead,
				'optimizable'    => $is_optimizable,
			);
		}

		return array(
			'ok'                   => true,
			'table_count'          => count( $tables ),
			'total_size_bytes'     => $total_size,
			'total_overhead_bytes' => $total_overhead,
			'optimizable_tables'   => $optimizable,
			'cleanup'              => $cleanup,
			'tables'               => $tables,
			'generated_at'         => gmdate( 'c' ),
		);
	}

	/**
	 * Counts supported cleanup candidates without changing database contents.
	 *
	 * @return array Counts of stale posts, comments and expired transients.
	 */
	public static function analyze_cleanup_candidates() {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count current-site cleanup candidates directly so the administrative preview reflects current data.
		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count current-site cleanup candidates directly so the administrative preview reflects current data.
		$auto_drafts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count current-site cleanup candidates directly so the administrative preview reflects current data.
		$trashed_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count current-site cleanup candidates directly so the administrative preview reflects current data.
		$spam_trash_comments        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" );
		$expired_transient_timeouts = 0;
		$expired_transient_values   = 0;
		foreach ( self::get_transient_timeout_prefixes() as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read prepared live timeout counts immediately before maintenance; these time-dependent totals must not be cached.
			$expired_transient_timeouts += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
					$like,
					$now
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read prepared live transient counts immediately before maintenance; these time-dependent totals must not be cached.
			$expired_transient_values += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(o.option_id) FROM {$wpdb->options} o INNER JOIN {$wpdb->options} t ON o.option_name = REPLACE(t.option_name, '_timeout', '') WHERE t.option_name LIKE %s AND t.option_value < %d",
					$like,
					$now
				)
			);
		}

		return array(
			'revisions'                  => $revisions,
			'auto_drafts'                => $auto_drafts,
			'trashed_posts'              => $trashed_posts,
			'spam_trash_comments'        => $spam_trash_comments,
			'expired_transient_timeouts' => $expired_transient_timeouts,
			'expired_transient_values'   => $expired_transient_values,
			'total_items'                => $revisions + $auto_drafts + $trashed_posts + $spam_trash_comments + $expired_transient_timeouts + $expired_transient_values,
		);
	}

	/**
	 * Estimates useful table overhead according to the database storage engine.
	 *
	 * @param array $status_row One SHOW TABLE STATUS result row.
	 * @param int   $table_size Combined data and index size in bytes.
	 * @return int Conservative estimate of reclaimable bytes.
	 */
	protected static function estimate_reclaimable_bytes( $status_row, $table_size ) {
		$table_size   = max( 0, (int) $table_size );
		$raw_overhead = isset( $status_row['Data_free'] ) ? max( 0, (int) $status_row['Data_free'] ) : 0;
		if ( $table_size <= 0 || $raw_overhead <= 0 ) {
			return 0;
		}

		$engine          = isset( $status_row['Engine'] ) ? strtolower( (string) $status_row['Engine'] ) : '';
		$capped_overhead = min( $raw_overhead, $table_size );

		if ( in_array( $engine, array( 'myisam', 'aria' ), true ) ) {
			return $capped_overhead;
		}

		if ( 'innodb' === $engine ) {
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

	/**
	 * Writes and signs a current-site database backup outside disposable caches.
	 *
	 * @return array Backup metadata on success, or an error code on failure.
	 */
	public static function create_backup() {
		global $wpdb;

		$tables = self::get_tables();
		if ( empty( $tables ) ) {
			return array(
				'ok'    => false,
				'error' => 'no_tables',
			);
		}

		$dir = self::get_backup_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return array(
				'ok'    => false,
				'error' => 'mkdir_failed',
			);
		}

		self::protect_backup_dir( $dir );

		$compressed = function_exists( 'gzopen' );
		$filename   = 'hsp-db-backup-' . gmdate( 'Y-m-d_H-i-s' ) . '-' . self::backup_filename_token() . '.sql' . ( $compressed ? '.gz' : '' );
		$path       = trailingslashit( $dir ) . $filename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Backups stream rows to a validated local path rather than buffering a full database dump.
		$handle = $compressed ? gzopen( $path, 'wb' ) : fopen( $path, 'wb' );
		if ( ! $handle ) {
			return array(
				'ok'    => false,
				'error' => 'open_failed',
			);
		}

		$tables_written = 0;
		$rows_written   = 0;
		$error          = '';

		foreach ( array( '-- HSP Smart Cache database backup', '-- Created at GMT: ' . gmdate( 'c' ), '-- Site URL: ' . site_url(), 'SET FOREIGN_KEY_CHECKS=0;', '' ) as $line ) {
			if ( ! static::write_backup_line( $handle, $line, $compressed ) ) {
				$error = 'write_failed';
				break;
			}
		}

		foreach ( $tables as $table ) {
			if ( '' !== $error ) {
				break;
			}
			$table = (string) $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current schema into the backup using a prepared identifier; SHOW CREATE TABLE does not change the schema.
			$create_row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_A );
			$create_sql = '';
			if ( is_array( $create_row ) ) {
				foreach ( $create_row as $value ) {
					if ( is_string( $value ) && stripos( $value, 'CREATE TABLE' ) === 0 ) {
						$create_sql = $value;
						break;
					}
				}
			}

			if ( '' === $create_sql || ! empty( $wpdb->last_error ) ) {
				$error = 'schema_read_failed';
				break;
			}

			if ( ! static::write_backup_line( $handle, 'DROP TABLE IF EXISTS ' . self::quote_identifier( $table ) . ';', $compressed )
				|| ! static::write_backup_line( $handle, rtrim( $create_sql, " \t\n\r\0\x0B;" ) . ';', $compressed ) ) {
				$error = 'write_failed';
				break;
			}

			$offset     = 0;
			$batch_size = 500;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stream current database rows into the backup using prepared identifiers and numeric limits; an object-cache snapshot is unsuitable.
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, $offset, $batch_size ), ARRAY_A );
				if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
					$error = 'rows_read_failed';
					break;
				}
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) || empty( $row ) ) {
						$error = 'rows_read_failed';
						break 2;
					}

					$columns = array_map( array( __CLASS__, 'quote_identifier' ), array_keys( $row ) );
					$values  = array_map( array( __CLASS__, 'sql_literal' ), array_values( $row ) );
					if ( ! static::write_backup_line(
						$handle,
						'INSERT INTO ' . self::quote_identifier( $table ) . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ');',
						$compressed
					) ) {
						$error = 'write_failed';
						break 2;
					}
					++$rows_written;
				}

				$offset   += $batch_size;
				$row_count = count( $rows );
			} while ( $row_count === $batch_size );

			if ( '' !== $error ) {
				break;
			}
			if ( ! static::write_backup_line( $handle, '', $compressed ) ) {
				$error = 'write_failed';
				break;
			}
			++$tables_written;
		}

		if ( '' === $error && ! static::write_backup_line( $handle, 'SET FOREIGN_KEY_CHECKS=1;', $compressed ) ) {
			$error = 'write_failed';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close and check the local backup stream before signing its bytes.
		$closed = $compressed ? gzclose( $handle ) : fclose( $handle );
		if ( ! $closed && '' === $error ) {
			$error = 'close_failed';
		}
		if ( '' !== $error ) {
			wp_delete_file( $path );
			wp_delete_file( $path . '.sig' );
			return array(
				'ok'    => false,
				'error' => $error,
			);
		}
		if ( ! self::sign_backup( $path ) ) {
			wp_delete_file( $path );
			return array(
				'ok'    => false,
				'error' => 'signature_failed',
			);
		}

		return array(
			'ok'             => true,
			'file'           => $filename,
			'path'           => $path,
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent removal can invalidate the completed file before this advisory size lookup; unavailable sizes become zero.
			'size_bytes'     => (int) @filesize( $path ),
			'created_at_gmt' => gmdate( 'c' ),
			'compressed'     => $compressed,
			'tables'         => $tables_written,
			'rows'           => $rows_written,
		);
	}

	/**
	 * Lists validated backup filenames for this installation, newest first.
	 *
	 * @return array[] Backup filenames, paths, byte sizes and timestamps.
	 */
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
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent backup deletion can race sorting; an unreadable timestamp sorts as zero without emitting a path warning.
				$mtime_a = (int) @filemtime( $a );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent backup deletion can race sorting; an unreadable timestamp sorts as zero without emitting a path warning.
				$mtime_b = (int) @filemtime( $b );
				return $mtime_b <=> $mtime_a;
			}
		);

		$backups = array();
		foreach ( $files as $path ) {
			$name = basename( $path );
			if ( ! self::get_backup_file_path( $name ) || ! self::is_backup_for_current_site( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A validated backup may disappear before stat; the listing represents an unavailable timestamp as zero.
			$mtime     = @filemtime( $path );
			$backups[] = array(
				'file'       => $name,
				'path'       => $path,
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A validated backup may disappear before stat; the listing represents an unavailable byte size as zero.
				'size_bytes' => (int) @filesize( $path ),
				'timestamp'  => $mtime ? (int) $mtime : 0,
			);
		}

		return $backups;
	}

	/**
	 * Removes a validated current-site backup and its signature with diagnostics.
	 *
	 * @param string     $file Backup basename supplied by the administrator.
	 * @param array|null $error Receives deletion diagnostics when removal fails.
	 * @return bool Whether the backup is absent after deletion attempts.
	 */
	public static function delete_backup( $file, &$error = null ) {
		$error = null;
		$path  = self::get_backup_file_path( $file );
		if ( ! $path ) {
			$error = self::build_backup_delete_error( 'invalid_file', $file, $path );
			return false;
		}

		if ( ! file_exists( $path ) ) {
			$error = self::build_backup_delete_error( 'missing_file', $file, $path );
			return false;
		}
		if ( ! self::is_backup_for_current_site( $path ) ) {
			$error = self::build_backup_delete_error( 'site_scope_mismatch', $file, $path );
			return false;
		}

		$backup_dir_path = self::get_backup_dir();
		$backup_dir      = realpath( $backup_dir_path );
		$real_path       = realpath( $path );

		if ( $backup_dir && $real_path ) {
			$backup_dir_check = trailingslashit( wp_normalize_path( $backup_dir ) );
			$path_check       = wp_normalize_path( $real_path );
		} else {
			$backup_dir_check = trailingslashit( wp_normalize_path( $backup_dir_path ) );
			$path_check       = wp_normalize_path( $path );
		}

		if ( strpos( $path_check, $backup_dir_check ) !== 0 ) {
			$error = self::build_backup_delete_error(
				'path_outside_backup_dir',
				$file,
				$path,
				array(
					'backup_dir_check' => $backup_dir_check,
					'path_check'       => $path_check,
				)
			);
			return false;
		}

		if ( ! wp_is_writable( $backup_dir_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best-effort local permission recovery is restricted to the validated backup directory; subsequent deletion checks report failure.
			@chmod( $backup_dir_path, 0755 );
		}

		$deleted  = false;
		$attempts = array();

		$fs = HSPSC_Utils::get_filesystem();
		if ( $fs && $fs->exists( $path ) ) {
			$deleted    = (bool) $fs->delete( $path, false, 'f' );
			$attempts[] = array(
				'method' => 'WP_Filesystem::delete',
				'ok'     => $deleted,
			);
		} else {
			$attempts[] = array(
				'method' => 'WP_Filesystem::exists',
				'ok'     => false,
				'error'  => $fs ? __( 'File not visible to WP_Filesystem.', 'hsp-smart-cache' ) : __( 'WP_Filesystem unavailable.', 'hsp-smart-cache' ),
			);
		}

		if ( ! $deleted && function_exists( 'wp_delete_file' ) ) {
			$deleted    = (bool) wp_delete_file( $path );
			$attempts[] = array(
				'method' => 'wp_delete_file',
				'ok'     => $deleted,
			);
		}

		if ( ! $deleted && file_exists( $path ) ) {
			if ( ! wp_is_writable( $path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best-effort permission recovery follows failed WordPress deletion for this validated local backup; the final result is checked.
				@chmod( $path, 0644 );
			}
			$last_error = error_get_last();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Final local fallback after WordPress deletion fails; preserve its return value and captured PHP diagnostic for administrator feedback.
			$deleted      = @unlink( $path );
			$unlink_error = error_get_last();
			if ( $last_error === $unlink_error ) {
				$unlink_error = null;
			}
			$attempts[] = array(
				'method' => 'unlink',
				'ok'     => $deleted,
				'error'  => $unlink_error && ! empty( $unlink_error['message'] ) ? $unlink_error['message'] : '',
			);
		}

		if ( $deleted || ! file_exists( $path ) ) {
			wp_delete_file( $path . '.sig' );
			return true;
		}

		$error = self::build_backup_delete_error(
			'delete_failed',
			$file,
			$path,
			array(
				'backup_dir_writable' => wp_is_writable( $backup_dir_path ),
				'file_writable'       => wp_is_writable( $path ),
				'file_permissions'    => self::get_file_permissions( $path ),
				'attempts'            => $attempts,
			)
		);

		return false;
	}

	/**
	 * Authenticates and preflights a backup before executing its allowed SQL.
	 *
	 * @param string $file Current-site backup basename to restore.
	 * @return array Restore status, executed and skipped statements, tables and errors.
	 */
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

		$backup_dir = realpath( self::get_backup_dir() );
		$real_path  = realpath( $path );
		if ( ! $backup_dir || ! $real_path || is_link( $path ) || dirname( $real_path ) !== $backup_dir ) {
			return self::build_restore_result( false, 'path_outside_backup_dir', array( 'file' => $file ) );
		}

		// Authenticate the exact bytes we will decompress, including this site's scope.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact validated local backup bytes for signature verification; this path is not a remote URL.
		$contents = file_get_contents( $real_path );
		if ( false === $contents ) {
			return self::build_restore_result( false, 'read_failed', array( 'file' => $file ) );
		}
		$signature_path = $path . '.sig';
		if ( ! is_file( $signature_path ) || is_link( $signature_path ) ) {
			return self::build_restore_result( false, 'missing_signature', array( 'file' => $file ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the regular local signature sidecar without changing its authenticated representation.
		$signature = file_get_contents( $signature_path );
		if ( ! is_string( $signature ) || ! hash_equals( self::backup_signature( $file, $contents ), trim( $signature ) ) ) {
			return self::build_restore_result( false, 'integrity_failed', array( 'file' => $file ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed compressed bytes return false and are reported as read_failed below instead of emitting a decompression warning.
		$sql = substr( $path, -3 ) === '.gz' ? ( function_exists( 'gzdecode' ) ? @gzdecode( $contents ) : false ) : $contents;
		if ( false === $sql || '' === $sql ) {
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
				'file'             => $file,
				'path'             => wp_normalize_path( $path ),
				'total_statements' => count( $statements ),
			)
		);

		// Validate the entire backup before running even its first DROP or INSERT.
		$foreign_keys_disabled = false;
		foreach ( $statements as $statement ) {
			$statement_info = self::get_restore_statement_info( $statement );
			if ( empty( $statement_info['allowed'] ) ) {
				++$result['skipped'];
				$result['skipped_statements'][] = self::build_skipped_restore_statement( $statement, $statement_info );
			}
		}
		if ( $result['skipped'] > 0 ) {
			$result['ok']    = false;
			$result['error'] = 'unsafe_statement';
			return $result;
		}

		foreach ( $statements as $statement ) {
			$statement_info = self::get_restore_statement_info( $statement );
			if ( empty( $statement_info['allowed'] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The signed current-site backup was fully preflighted above against the SQL allowlist; execute its exact statements and clear caches on success.
			$query_result = $wpdb->query( $statement );
			if ( false === $query_result ) {
				if ( $foreign_keys_disabled ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore this connection setting after a failed restore statement; it is fixed SQL and cannot be cached.
					$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
				}
				if ( ! empty( $result['tables'] ) ) {
					// Earlier DROP, CREATE or INSERT statements already changed the database.
					HSPSC_Plugin::flush_all_caches();
				}
				return self::build_restore_result(
					false,
					'query_failed',
					array(
						'file'               => $file,
						'path'               => wp_normalize_path( $path ),
						'total_statements'   => count( $statements ),
						'executed'           => $result['executed'],
						'skipped'            => $result['skipped'],
						'skipped_statements' => $result['skipped_statements'],
						'tables'             => $result['tables'],
						'statement'          => $result['executed'] + 1,
						'failed_statement'   => self::preview_sql_statement( $statement ),
					)
				);
			}

			if ( preg_match( '/^\s*SET\s+FOREIGN_KEY_CHECKS\s*=\s*([01])/i', $statement, $foreign_keys ) ) {
				$foreign_keys_disabled = '0' === $foreign_keys[1];
			}

			++$result['executed'];
			if ( ! empty( $statement_info['table'] ) ) {
				$table = (string) $statement_info['table'];
				if ( ! isset( $result['tables'][ $table ] ) ) {
					$result['tables'][ $table ] = self::empty_restore_table_summary();
				}
				if ( ! empty( $statement_info['type'] ) && isset( $result['tables'][ $table ][ $statement_info['type'] ] ) ) {
					++$result['tables'][ $table ][ $statement_info['type'] ];
				}
			}
		}

		if ( $foreign_keys_disabled ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore this connection setting after the allowlisted restore completes; it is fixed SQL and cannot be cached.
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		}
		$result = self::finalize_restore_result( $result );
		if ( ! empty( $result['ok'] ) ) {
			HSPSC_Plugin::flush_all_caches();
		}
		return $result;
	}

	/**
	 * Runs database-engine optimization for the current site table set.
	 *
	 * @return array Optimized table names, counts and failed table names.
	 */
	public static function optimize_tables() {
		global $wpdb;
		$errors    = array();
		$optimized = array();

		$tables = self::get_tables();
		if ( empty( $tables ) ) {
			return array(
				'ok'               => false,
				'optimized_tables' => 0,
				'tables'           => array(),
				'errors'           => array( 'no_tables' ),
			);
		}
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Execute the requested engine maintenance operation with a prepared current-site table identifier; there is no cacheable result.
			$result = $wpdb->query( $wpdb->prepare( 'OPTIMIZE TABLE %i', $table ) );
			if ( false === $result ) {
				$errors[] = $table;
				continue;
			}

			$optimized[] = $table;
		}

		return array(
			'ok'               => empty( $errors ),
			'optimized_tables' => count( $optimized ),
			'tables'           => $optimized,
			'errors'           => $errors,
		);
	}

	/**
	 * Reads the database table list and retains the current WordPress prefix.
	 *
	 * @return string[] Current-site table names.
	 */
	protected static function get_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Discover current table names for backup and maintenance, then filter to this installation prefix; cached metadata could omit changed tables.
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		if ( ! is_array( $tables ) ) {
			return array();
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

	/**
	 * Checks whether a table belongs to the active WordPress table prefix.
	 *
	 * @param string $table Database table name.
	 * @return bool Whether the table belongs to this site.
	 */
	protected static function is_wp_table( $table ) {
		global $wpdb;

		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$table  = (string) $table;
		if ( '' === $prefix || ! preg_match( '/^[A-Za-z0-9_$]+$/D', $table ) || strpos( $table, $prefix ) !== 0 ) {
			return false;
		}
		if ( is_multisite() ) {
			if ( ! isset( $wpdb->base_prefix ) || ! method_exists( $wpdb, 'tables' ) ) {
				return false;
			}
			// These are per-site operations, including when a network administrator runs them.
			if ( in_array( $table, $wpdb->tables( 'global', true ), true ) ) {
				return false;
			}
			if ( $prefix === $wpdb->base_prefix && preg_match( '/^' . preg_quote( $prefix, '/' ) . '[0-9]+_/', $table ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Executes a trusted cleanup statement and records its affected-row count.
	 *
	 * @param string   $sql Fixed or prepared SQL assembled by the cleanup caller.
	 * @param string   $key Operation identifier used in diagnostic results.
	 * @param array    $affected Receives per-operation affected-row counts.
	 * @param string[] $errors Receives failed operation identifiers.
	 * @return bool Whether the database accepted the statement.
	 */
	protected static function run_counted_query( $sql, $key, &$affected, &$errors ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Only run_db_cleanup supplies fixed or prepared site-table statements; execute each mutation and retain its actual affected-row count.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			$errors[]         = $key;
			$affected[ $key ] = 0;
			return false;
		}

		$affected[ $key ] = (int) $result;
		return true;
	}

	/**
	 * Lists ordinary and site-transient timeout option prefixes.
	 *
	 * @return string[] Supported timeout prefixes.
	 */
	protected static function get_transient_timeout_prefixes() {
		return array(
			'_transient_timeout_',
			'_site_transient_timeout_',
		);
	}

	/**
	 * Returns the filtered, normalized backup directory for this installation.
	 *
	 * @return string Absolute backup directory without a trailing slash.
	 */
	protected static function get_backup_dir() {
		$default = self::get_default_backup_dir();
		$dir     = apply_filters( 'hspsc_db_backup_dir', $default );

		return untrailingslashit( wp_normalize_path( (string) $dir ) );
	}

	/**
	 * Selects a site-scoped backup directory outside the public root when writable.
	 *
	 * @return string Default absolute backup directory.
	 */
	protected static function get_default_backup_dir() {
		$site_dir              = self::get_backup_site_dir_name();
		$wordpress_root_parent = dirname( untrailingslashit( ABSPATH ) );
		if ( is_dir( $wordpress_root_parent ) && wp_is_writable( $wordpress_root_parent ) ) {
			return trailingslashit( $wordpress_root_parent ) . 'hspsc-' . self::BACKUP_DIR . '/' . $site_dir;
		}

		return trailingslashit( WP_CONTENT_DIR ) . 'hspsc-' . self::BACKUP_DIR . '/' . $site_dir;
	}

	/**
	 * Builds a stable site scope from the host, installation directory and path hash.
	 *
	 * @return string Filesystem-safe installation scope.
	 */
	protected static function get_backup_site_dir_name() {
		$install_dir = basename( untrailingslashit( wp_normalize_path( ABSPATH ) ) );
		if ( '' === $install_dir || '.' === $install_dir || DIRECTORY_SEPARATOR === $install_dir ) {
			$install_dir = 'WordPress';
		}

		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$scope = trim( $host . '-' . $install_dir, '-' );
		$scope = strtolower( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $scope ) );
		$scope = trim( $scope, '-' );
		if ( is_multisite() ) {
			// Old shared multisite directories/signatures remain ambiguous and are not adopted.
			$scope .= '-network-' . get_current_network_id() . '-blog-' . get_current_blog_id();
		}

		return sprintf(
			'%s-%s',
			'' !== $scope ? $scope : 'site',
			substr( md5( wp_normalize_path( ABSPATH ) ), 0, 12 )
		);
	}

	/**
	 * Validates a supported backup basename and resolves it within the site scope.
	 *
	 * @param string $file Backup filename to validate.
	 * @return string|null Scoped path, or null for an unsupported filename.
	 */
	protected static function get_backup_file_path( $file ) {
		$file = basename( (string) $file );
		if ( '' === $file || ! preg_match( '/^hsp(?:sc)?-db-backup-[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}(?:-[A-Za-z0-9]{12})?\.sql(?:\.gz)?$/', $file ) ) {
			return null;
		}

		return trailingslashit( self::get_backup_dir() ) . $file;
	}

	/**
	 * Authenticates exact backup bytes together with their basename and site scope.
	 *
	 * @param string $file Backup filename included in the signature context.
	 * @param string $contents Exact stored bytes, before any decompression.
	 * @return string Hexadecimal keyed SHA-256 signature.
	 */
	protected static function backup_signature( $file, $contents ) {
		return hash_hmac( 'sha256', self::backup_signature_context( $file ) . $contents, wp_salt( 'auth' ) );
	}

	/**
	 * Binds a signature to the current installation, multisite blog and filename.
	 *
	 * @param string $file Backup basename.
	 * @return string Signature context preceding the exact backup bytes.
	 */
	protected static function backup_signature_context( $file ) {
		return 'hspsc-backup-v1|' . self::get_backup_site_dir_name() . '|' . basename( $file ) . '|';
	}

	/**
	 * Authenticates a backup stream without loading another full copy into memory.
	 *
	 * @param string $path Regular backup file to hash.
	 * @return string|false Keyed signature, or false when the stream cannot be read.
	 */
	protected static function backup_file_signature( $path ) {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return false;
		}
		$hash = hash_init( 'sha256', HASH_HMAC, wp_salt( 'auth' ) );
		hash_update( $hash, self::backup_signature_context( basename( $path ) ) );
		return hash_update_file( $hash, $path ) ? hash_final( $hash ) : false;
	}

	/**
	 * Rejects foreign or ambiguous backups even when a multisite filter shares a directory.
	 *
	 * @param string $path Candidate backup file.
	 * @return bool Whether listing/deletion may treat it as a current-site backup.
	 */
	protected static function is_backup_for_current_site( $path ) {
		if ( ! is_multisite() ) {
			return true;
		}
		$signature_path = $path . '.sig';
		if ( ! is_file( $signature_path ) || is_link( $signature_path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only a regular local signature sidecar; the backup itself is authenticated through a streaming hash.
		$signature = file_get_contents( $signature_path );
		$expected  = self::backup_file_signature( $path );
		return is_string( $signature ) && is_string( $expected ) && hash_equals( $expected, trim( $signature ) );
	}

	/**
	 * Writes an authenticated sidecar for the exact stored backup bytes.
	 *
	 * @param string $path Absolute path of the newly written backup.
	 * @return bool Whether the backup was read and its signature saved.
	 */
	protected static function sign_backup( $path ) {
		$signature = self::backup_file_signature( $path );
		return false !== $signature && HSPSC_Utils::atomic_write( $path . '.sig', $signature );
	}

	/**
	 * Builds structured deletion diagnostics for privileged administrative feedback.
	 *
	 * @param string      $code Stable deletion error code.
	 * @param string      $file Requested backup filename.
	 * @param string|null $path Resolved backup path, when valid.
	 * @param array       $details Additional filesystem diagnostics.
	 * @return array Normalized deletion diagnostics.
	 */
	protected static function build_backup_delete_error( $code, $file, $path, $details = array() ) {
		return array_merge(
			array(
				'code'       => $code,
				'file'       => basename( (string) $file ),
				'path'       => $path ? wp_normalize_path( $path ) : '',
				'backup_dir' => wp_normalize_path( self::get_backup_dir() ),
			),
			$details
		);
	}

	/**
	 * Reads local permission bits for backup-deletion diagnostics.
	 *
	 * @param string $path Local file or directory to inspect.
	 * @return string Four-digit octal permissions, or an empty string when unavailable.
	 */
	protected static function get_file_permissions( $path ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The file may disappear during deletion diagnostics; a failed stat returns an empty permission string below.
		$perms = @fileperms( $path );
		if ( false === $perms ) {
			return '';
		}

		return substr( sprintf( '%o', $perms ), -4 );
	}

	/**
	 * Quotes a trusted database identifier for portable backup SQL.
	 *
	 * @param string $name Table or column identifier from database metadata.
	 * @return string Backtick-quoted identifier with embedded backticks escaped.
	 */
	protected static function quote_identifier( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Formats a value as portable SQL text for a backup file.
	 *
	 * @param mixed $value Database field value.
	 * @return string SQL literal without request-specific placeholder escapes.
	 */
	protected static function sql_literal( $value ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		// A dump is replayed in another request, outside this connection's query filter.
		return $wpdb->remove_placeholder_escape( $wpdb->prepare( '%s', (string) $value ) );
	}

	/**
	 * Appends one SQL line to the open compressed or plain backup stream.
	 *
	 * @param resource $handle Open backup stream.
	 * @param string   $line SQL or comment text without its final newline.
	 * @param bool     $compressed Whether the stream was opened with gzopen().
	 * @return bool Whether the complete line was written.
	 */
	protected static function write_backup_line( $handle, $line, $compressed ) {
		$data = $line . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Append to the already-open local backup stream so large dumps do not need to be held in memory.
		$written = $compressed ? gzwrite( $handle, $data ) : fwrite( $handle, $data );
		return strlen( $data ) === $written;
	}

	/**
	 * Creates an unpredictable alphanumeric suffix for a backup filename.
	 *
	 * @return string Twelve-character token.
	 */
	protected static function backup_filename_token() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 12, false, false );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode random bytes into an alphanumeric filename suffix; no executable code is encoded or decoded.
		return substr( str_replace( array( '+', '/', '=' ), '', base64_encode( random_bytes( 9 ) ) ), 0, 12 );
	}

	/**
	 * Splits backup SQL on delimiters outside quoted values and line comments.
	 *
	 * @param string $sql Authenticated, decompressed backup SQL.
	 * @return string[] Nonempty SQL statements.
	 */
	protected static function split_sql_statements( $sql ) {
		$statements = array();
		$statement  = '';
		$quote      = '';
		$escaped    = false;
		$length     = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			$next = $i + 1 < $length ? $sql[ $i + 1 ] : '';

			if ( '' === $quote && '-' === $char && '-' === $next ) {
				while ( $i < $length && "\n" !== $sql[ $i ] ) {
					++$i;
				}
				continue;
			}

			$statement .= $char;

			if ( '' !== $quote ) {
				if ( $escaped ) {
					$escaped = false;
					continue;
				}
				if ( '\\' === $char ) {
					$escaped = true;
					continue;
				}
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
				continue;
			}

			if ( ';' === $char ) {
				$trimmed = trim( $statement );
				if ( '' !== $trimmed ) {
					$statements[] = $trimmed;
				}
				$statement = '';
			}
		}

		$trimmed = trim( $statement );
		if ( '' !== $trimmed ) {
			$statements[] = $trimmed;
		}

		return $statements;
	}

	/**
	 * Builds a consistent restore result with per-statement reporting defaults.
	 *
	 * @param bool   $ok Whether the restore has succeeded so far.
	 * @param string $error Stable error code, or an empty string.
	 * @param array  $data Additional restore metadata and counts.
	 * @return array Restore result fields.
	 */
	protected static function build_restore_result( $ok, $error = '', $data = array() ) {
		return array_merge(
			array(
				'ok'                 => (bool) $ok,
				'error'              => $error,
				'file'               => '',
				'path'               => '',
				'total_statements'   => 0,
				'executed'           => 0,
				'statements'         => 0,
				'skipped'            => 0,
				'skipped_statements' => array(),
				'tables'             => array(),
				'warnings'           => array(),
			),
			$data
		);
	}

	/**
	 * Checks restored table coverage and adds actionable completion warnings.
	 *
	 * @param array $result Accumulated restore execution result.
	 * @return array Final restore result with table and statement warnings.
	 */
	protected static function finalize_restore_result( $result ) {
		$result['statements'] = isset( $result['executed'] ) ? (int) $result['executed'] : 0;

		if ( empty( $result['tables'] ) ) {
			$result['ok']         = false;
			$result['error']      = 'no_tables_restored';
			$result['warnings'][] = __( 'No WordPress tables were restored. Check whether the backup table prefix matches this site.', 'hsp-smart-cache' );
			return $result;
		}

		foreach ( $result['tables'] as $table => $summary ) {
			if ( empty( $summary['drop'] ) || empty( $summary['create'] ) ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: Database table name. */
					__( 'Table %s did not include both DROP TABLE and CREATE TABLE statements, so the restore may not remove rows created after the backup.', 'hsp-smart-cache' ),
					$table
				);
			}
		}

		if ( ! empty( $result['skipped'] ) ) {
			$result['warnings'][] = sprintf(
				/* translators: %d: Number of skipped SQL statements. */
				_n( '%d SQL statement was skipped because it was not allowed for restore.', '%d SQL statements were skipped because they were not allowed for restore.', (int) $result['skipped'], 'hsp-smart-cache' ),
				(int) $result['skipped']
			);
		}

		return $result;
	}

	/**
	 * Creates counters for the supported table restore operations.
	 *
	 * @return array Zeroed DROP, CREATE and INSERT counts.
	 */
	protected static function empty_restore_table_summary() {
		return array(
			'drop'   => 0,
			'create' => 0,
			'insert' => 0,
		);
	}

	/**
	 * Validates one SQL statement against the current-site restore allowlist.
	 *
	 * @param string $statement One authenticated backup statement to classify.
	 * @return array Whether the statement is allowed, its type, table and rejection reason.
	 */
	protected static function get_restore_statement_info( $statement ) {
		if ( preg_match( '/^\s*SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*;?\s*$/i', $statement ) ) {
			return array(
				'allowed' => true,
				'type'    => 'set',
				'table'   => '',
				'reason'  => '',
			);
		}

		if ( ! preg_match( '/^\s*(DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE|INSERT\s+INTO)\s+`?([^`\s(]+)`?/i', $statement, $matches ) ) {
			return array(
				'allowed' => false,
				'type'    => '',
				'table'   => '',
				'reason'  => 'unsupported_statement',
			);
		}

		$table = $matches[2];
		if ( ! preg_match( '/^[A-Za-z0-9_$]+$/D', $table ) || ! self::is_wp_table( $table ) ) {
			return array(
				'allowed' => false,
				'type'    => '',
				'table'   => $table,
				'reason'  => 'non_wordpress_table',
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

		$safe       = false;
		$identifier = '(?:`[A-Za-z0-9_$]+`|[A-Za-z0-9_$]+)';
		if ( 'drop' === $type ) {
			$safe = (bool) preg_match( '/^\s*DROP\s+TABLE\s+IF\s+EXISTS\s+' . $identifier . '\s*;?\s*$/iD', $statement );
		} elseif ( 'insert' === $type ) {
			// Generated backups contain literal rows, never SELECTs or SQL expressions.
			$literal = "(?:NULL|0|1|'(?:[^'\\\\]|\\\\.|'')*')";
			$safe    = (bool) preg_match(
				'/^\s*INSERT\s+INTO\s+' . $identifier . '\s*\(\s*' . $identifier . '(?:\s*,\s*' . $identifier . ')*\s*\)\s+VALUES\s*\(\s*'
				. $literal . '(?:\s*,\s*' . $literal . ')*\s*\)\s*;?\s*$/isD',
				$statement
			);
		} elseif ( preg_match( '/^\s*CREATE\s+TABLE\s+' . $identifier . '\s*\(/i', $statement ) ) {
			// Remove string literals before inspecting schema keywords and references.
			$schema          = preg_replace( "/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $statement );
			$keywords        = is_string( $schema ) ? preg_replace( '/`(?:[^`]|``)*`/', '`identifier`', $schema ) : '';
			$safe            = is_string( $schema ) && ! preg_match( '/\b(?:SELECT|LIKE|OUTFILE|DUMPFILE|CONNECTION|TABLESPACE|UNION)\b|\b(?:DATA|INDEX)\s+DIRECTORY\b|\/\*|--|#/i', $keywords );
			$reference_count = $safe ? preg_match_all( '/\bREFERENCES\s+(' . $identifier . ')\s*\(/i', $schema, $references ) : 0;
			if ( $safe && preg_match_all( '/\bREFERENCES\b/i', $keywords ) !== $reference_count ) {
				$safe = false;
			}
			if ( $safe && $reference_count > 0 ) {
				foreach ( $references[1] as $reference ) {
					$reference = trim( $reference, '`' );
					if ( ! preg_match( '/^[A-Za-z0-9_$]+$/D', $reference ) || ! self::is_wp_table( $reference ) ) {
						$safe = false;
						break;
					}
				}
			}
		}

		if ( ! $safe ) {
			return array(
				'allowed' => false,
				'type'    => $type,
				'table'   => $table,
				'reason'  => 'unsupported_statement',
			);
		}

		return array(
			'allowed' => true,
			'type'    => $type,
			'table'   => $table,
			'reason'  => '',
		);
	}

	/**
	 * Formats a rejected statement for privileged restore diagnostics.
	 *
	 * @param string $statement Rejected SQL statement.
	 * @param array  $statement_info Result of the restore allowlist validation.
	 * @return array Rejection reason, table and abbreviated SQL preview.
	 */
	protected static function build_skipped_restore_statement( $statement, $statement_info ) {
		return array(
			'reason'    => isset( $statement_info['reason'] ) ? $statement_info['reason'] : 'unknown',
			'table'     => isset( $statement_info['table'] ) ? $statement_info['table'] : '',
			'statement' => self::preview_sql_statement( $statement ),
		);
	}

	/**
	 * Condenses a SQL statement into a bounded administrative diagnostic preview.
	 *
	 * @param string $statement SQL statement to summarize.
	 * @return string Single-line preview of at most 220 bytes.
	 */
	protected static function preview_sql_statement( $statement ) {
		$statement = preg_replace( '/\s+/', ' ', trim( (string) $statement ) );
		if ( strlen( $statement ) > 220 ) {
			$statement = substr( $statement, 0, 217 ) . '...';
		}

		return $statement;
	}

	/**
	 * Reports whether a statement passes the current-site restore allowlist.
	 *
	 * @param string $statement SQL statement to validate.
	 * @return bool Whether the complete statement is allowed.
	 */
	protected static function is_allowed_restore_statement( $statement ) {
		$statement_info = self::get_restore_statement_info( $statement );
		return ! empty( $statement_info['allowed'] );
	}

	/**
	 * Creates index and web-server deny files in the existing backup directory.
	 *
	 * @param string $dir Validated absolute backup directory.
	 * @return void
	 */
	protected static function protect_backup_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			HSPSC_Utils::atomic_write( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			HSPSC_Utils::atomic_write( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$web_config = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $web_config ) ) {
			HSPSC_Utils::atomic_write(
				$web_config,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n"
				. "  <system.webServer>\n"
				. "    <security>\n"
				. "      <authorization>\n"
				. "        <remove users=\"*\" roles=\"\" verbs=\"\" />\n"
				. "        <add accessType=\"Deny\" users=\"*\" />\n"
				. "      </authorization>\n"
				. "    </security>\n"
				. "  </system.webServer>\n"
				. "</configuration>\n"
			);
		}
	}
}
