<?php
/**
 * Runs a persistent, bounded rebuild of anonymous page-cache entries.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates manual starts and trusted background workers.
 */
class HSPSC_Rebuild {
	const OPTION              = 'hspsc_rebuild_job';
	const HOOK                = 'hspsc_run_rebuild_batch';
	const MAX_PAGES           = 10000;
	const MAX_SITEMAPS        = 200;
	const MAX_SITEMAP_DEPTH   = 5;
	const MAX_DISCOVERY_BYTES = 32 * MB_IN_BYTES;
	const MAX_URL_BYTES       = MB_IN_BYTES;
	const MAX_URL_LENGTH      = 2048;
	const BATCH_SIZE          = 4;
	const BATCH_SECONDS       = 15;
	const MAX_TIMEOUT         = 10;
	const RESULTS_PER_PAGE    = 25;

	/**
	 * Registers the trusted cron entry point.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ) );
	}

	/**
	 * Starts a new public rebuild. Its first worker performs the purge.
	 *
	 * @param string      $url Specific page URL, or empty for the entire public cache.
	 * @param string|null $accept Explicit navigation Accept header, or null to read the current request.
	 * @return array|WP_Error Public job status or startup failure.
	 */
	public static function start( $url = '', $accept = null ) {
		if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
			return new WP_Error( 'hspsc_rebuild_disabled', __( 'Enable page caching before starting a rebuild.', 'hsp-smart-cache' ) );
		}
		if ( ! is_string( $url ) || strlen( $url ) > self::MAX_URL_LENGTH || ( '' !== $url && '' !== HSPSC_Page::validate_public_warming_url( $url ) ) ) {
			return new WP_Error( 'hspsc_rebuild_invalid_url', __( 'This URL is not eligible for a public page cache rebuild.', 'hsp-smart-cache' ) );
		}
		$accept = null === $accept ? HSPSC_Page::get_warming_accept() : $accept;
		if ( ! HSPSC_Page::is_valid_warming_accept( $accept ) ) {
			return new WP_Error( 'hspsc_rebuild_invalid_accept', __( 'The request has an invalid Accept header.', 'hsp-smart-cache' ) );
		}
		$lock = self::lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$old = self::read_job( true );
			if ( self::is_active( $old ) ) {
				return new WP_Error( 'hspsc_rebuild_active', __( 'A public page cache rebuild is already running.', 'hsp-smart-cache' ) );
			}
			$job = self::new_job( $url, $accept );
			return self::start_attempt( $job );
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Gives failed work a new attempt ID while retaining successful outcomes.
	 *
	 * @param string $job_id Expected current job ID.
	 * @return array|WP_Error Updated public status or a retry error.
	 */
	public static function retry( $job_id ) {
		$lock = self::lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$job = self::read_job( true );
			if ( ! self::is_current( $job, $job_id ) ) {
				return self::stale_error();
			}
			if ( self::is_active( $job ) ) {
				return new WP_Error( 'hspsc_rebuild_active', __( 'Wait for the current rebuild attempt to finish before retrying.', 'hsp-smart-cache' ) );
			}
			if ( ! empty( $job['failure'] ) || ! HSPSC_Settings::get( 'page_cache' ) ) {
				return new WP_Error( 'hspsc_rebuild_not_retryable', __( 'Start a new public page cache rebuild to continue.', 'hsp-smart-cache' ) );
			}
			$retry_count = 0;
			foreach ( array( 'sitemaps', 'pages' ) as $bucket ) {
				foreach ( $job[ $bucket ] as &$record ) {
					if ( self::can_retry( $record ) ) {
						$record['status'] = 'pending';
						$record['reason'] = '';
						++$retry_count;
					}
				}
				unset( $record );
				$job['cursor'][ $bucket ] = 0;
			}
			if ( 0 === $retry_count ) {
				return new WP_Error( 'hspsc_rebuild_nothing_to_retry', __( 'There are no retryable failures in this rebuild.', 'hsp-smart-cache' ) );
			}
			$job['id']              = wp_generate_uuid4();
			$job['started_at']      = time();
			$job['finished_at']     = 0;
			$job['discovery_bytes'] = 0;
			self::set_state( $job, self::next_work( $job ) );
			$result = self::start_attempt( $job );
			if ( ! is_wp_error( $result ) ) {
				wp_clear_scheduled_hook( self::HOOK, array( $job_id ) );
			}
			return $result;
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Reads a bounded page of public progress and results.
	 *
	 * @param int $page One-based result page.
	 * @return array Job status, or an empty array when none exists.
	 */
	public static function get_status( $page = 1 ) {
		$job = self::read_job();
		return $job ? self::summarize( $job, $page ) : array();
	}

	/**
	 * Processes a short batch from cron or an authorized admin callback.
	 *
	 * @param string $job_id Expected current job ID.
	 * @return array|WP_Error Public progress or a worker error.
	 */
	public static function run_batch( $job_id ) {
		if ( ! is_string( $job_id ) || ! wp_is_uuid( $job_id, 4 ) ) {
			return self::stale_error();
		}
		$lock = self::lock();
		if ( is_wp_error( $lock ) ) {
			// A cron event can be consumed while an AJAX worker holds the lock.
			$job = self::read_job();
			if ( 'hspsc_rebuild_busy' === $lock->get_error_code() && self::is_current( $job, $job_id ) && self::is_active( $job ) ) {
				self::schedule( $job_id );
			}
			return $lock;
		}
		try {
			$job = self::read_job( true );
			if ( ! self::is_current( $job, $job_id ) ) {
				return self::stale_error();
			}
			if ( ! self::is_active( $job ) ) {
				return self::summarize( $job );
			}
			// Arm recovery before filesystem work or the first network request.
			if ( ! self::schedule( $job_id ) ) {
				return self::fail_scheduling( $job );
			}
			if ( ! HSPSC_Settings::get( 'page_cache' ) ) {
				$job['failure'] = 'cache-disabled';
				return self::finish_worker( $job, null );
			}
			if ( $job['needs_purge'] && ! self::purge_public_cache( $job ) ) {
				return self::finish_worker( $job, null );
			}
			self::recover_interrupted_work( $job );
			$deadline = microtime( true ) + self::BATCH_SECONDS;
			$work     = self::next_work( $job );
			for ( $step = 0; null !== $work && $step < self::BATCH_SIZE; ++$step ) {
				if ( $step > 0 && microtime( true ) + $job['timeout'] >= $deadline ) {
					break;
				}
				self::set_state( $job, $work );
				$job['in_flight'] = $work;
				// This commits the previous result and claims the next item in one write.
				if ( ! self::save_job( $job ) ) {
					return self::storage_error();
				}
				self::process_work( $job, $work );
				$job['in_flight'] = null;
				$work             = self::next_work( $job );
			}
			return self::finish_worker( $job, $work );
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Clears pending workers and reports during plugin deactivation.
	 *
	 * @return bool Whether both persisted state and scheduled events were cleared.
	 */
	public static function reset() {
		$lock = self::lock( true );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			delete_option( self::OPTION );
			$removed = wp_unschedule_hook( self::HOOK, true );
			return false !== $removed && ! is_wp_error( $removed ) && false === get_option( self::OPTION );
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Seeds the queue from the starting URL and documented language roots.
	 *
	 * @param string $url Target URL or empty for all.
	 * @param string $accept Exact initiating request representation.
	 * @return array Initial durable job.
	 */
	protected static function new_job( $url, $accept ) {
		$roots         = array_values( array_unique( array_merge( array( home_url( '/' ) ), HSPSC_WPML::language_urls() ) ) );
		$roots         = array_values(
			array_filter(
				$roots,
				static function ( $root ) {
					return is_string( $root ) && strlen( $root ) <= self::MAX_URL_LENGTH && '' !== HSPSC_Page::get_url_origin( $root );
				}
			)
		);
		$roots_limited = count( $roots ) > self::MAX_SITEMAPS;
		$roots         = array_slice( $roots, 0, self::MAX_SITEMAPS );
		$origins       = array_values( array_unique( array_filter( array_map( array( 'HSPSC_Page', 'get_url_origin' ), array_merge( $roots, array( site_url() ) ) ) ) ) );
		$job           = array(
			'id'              => wp_generate_uuid4(),
			'state'           => '' === $url ? 'discovering' : 'running',
			'started_at'      => time(),
			'finished_at'     => 0,
			'target'          => $url,
			'accept'          => $accept,
			'roots'           => $roots,
			'origins'         => $origins,
			'timeout'         => max( 3, min( self::MAX_TIMEOUT, (int) HSPSC_Settings::get( 'preload_timeout', 8 ) ) ),
			'needs_purge'     => true,
			'in_flight'       => null,
			'cursor'          => array(
				'sitemaps' => 0,
				'pages'    => 0,
			),
			'sitemaps'        => array(),
			'pages'           => array(),
			'issues'          => array(),
			'url_bytes'       => 0,
			'discovery_bytes' => 0,
			'failure'         => '',
		);
		if ( '' !== $url ) {
			self::enqueue( $job, 'pages', $url );
			return $job;
		}
		if ( $roots_limited ) {
			self::record_limit( $job, home_url( '/' ), 'language-root-limit' );
		}
		foreach ( $roots as $root ) {
			if ( ! self::enqueue( $job, 'pages', $root ) ) {
				break;
			}
		}
		self::enqueue( $job, 'sitemaps', HSPSC_Preload::get_sitemap_url() );
		return $job;
	}

	/**
	 * Clears only public page entries before any request in the initial attempt.
	 *
	 * @param array $job Durable job state.
	 * @return bool Whether processing may continue.
	 */
	protected static function purge_public_cache( &$job ) {
		$target = $job['target'];
		if ( '' !== $target && '' !== HSPSC_Page::validate_public_warming_url( $target ) ) {
			$job['failure'] = 'invalid-url';
			return false;
		}
		$cleared = '' === $target ? HSPSC_Page::clear_public_cache() : HSPSC_Page::clear_public_cache_for_url( $target );
		if ( ! $cleared ) {
			$job['failure'] = 'purge-failed';
			return false;
		}
		$job['needs_purge'] = false;
		return true;
	}

	/**
	 * Adds one deduplicated entry while preserving bounded durable storage.
	 *
	 * @param array  $job Durable job state.
	 * @param string $bucket Either sitemaps or pages.
	 * @param string $url Decoded URL as found in the document.
	 * @param int    $depth Sitemap depth from the starting document.
	 * @param string $source Sitemap containing the entry.
	 * @return bool Whether the current document may continue queuing entries.
	 */
	protected static function enqueue( &$job, $bucket, $url, $depth = 0, $source = '' ) {
		if ( ! is_string( $url ) || strlen( $url ) > self::MAX_URL_LENGTH ) {
			self::record_limit( $job, $source, 'url-too-long' );
			return true;
		}
		$origin = HSPSC_Page::get_url_origin( $url );
		$parts  = '' !== $origin ? wp_parse_url( $url ) : false;
		$key    = hash( 'sha256', is_array( $parts ) && ! isset( $parts['fragment'] ) ? $origin . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) : $url );
		if ( isset( $job[ $bucket ][ $key ] ) ) {
			return true;
		}
		$is_sitemap = 'sitemaps' === $bucket;
		$limit      = $is_sitemap ? self::MAX_SITEMAPS : self::MAX_PAGES;
		if ( count( $job[ $bucket ] ) >= $limit ) {
			self::record_limit( $job, $source, $is_sitemap ? 'sitemap-count-limit' : 'page-count-limit' );
			return false;
		}
		if ( $job['url_bytes'] + strlen( $url ) > self::MAX_URL_BYTES ) {
			self::record_limit( $job, $source, 'url-byte-limit' );
			return false;
		}
		$record = array(
			'url'    => $url,
			'status' => 'pending',
			'reason' => '',
			'type'   => $is_sitemap ? 'sitemap' : 'page',
		);
		if ( ! in_array( $origin, $job['origins'], true ) ) {
			$record['status'] = $is_sitemap ? 'failed' : 'skipped';
			$record['reason'] = 'invalid-url';
		}
		if ( $is_sitemap ) {
			$record['depth'] = $depth;
			if ( $depth > self::MAX_SITEMAP_DEPTH ) {
				$record['status'] = 'failed';
				$record['reason'] = 'sitemap-depth-limit';
			}
		}
		$job[ $bucket ][ $key ] = $record;
		$job['url_bytes']      += strlen( $url );
		return true;
	}

	/**
	 * Records a discovery ceiling once, without adding unbounded omitted URLs.
	 *
	 * @param array  $job Durable job state.
	 * @param string $source Sitemap that exceeded the bound.
	 * @param string $reason Fixed safety ceiling code.
	 */
	protected static function record_limit( &$job, $source, $reason ) {
		if ( ! isset( $job['issues'][ $reason ] ) ) {
			$job['issues'][ $reason ] = array(
				'url'    => $source,
				'status' => 'failed',
				'reason' => $reason,
				'type'   => 'sitemap',
			);
		}
	}

	/**
	 * Selects the next pending entry without discarding durable outcomes.
	 *
	 * @param array $job Durable job state.
	 * @return array|null Bucket and record key, or null when all work is done.
	 */
	protected static function next_work( &$job ) {
		foreach ( array( 'sitemaps', 'pages' ) as $bucket ) {
			$keys = array_keys( $job[ $bucket ] );
			while ( isset( $keys[ $job['cursor'][ $bucket ] ] ) ) {
				$key = $keys[ $job['cursor'][ $bucket ] ];
				if ( 'pending' === $job[ $bucket ][ $key ]['status'] ) {
					return array( $bucket, $key );
				}
				++$job['cursor'][ $bucket ];
			}
		}
		return null;
	}

	/**
	 * Records a previous worker interruption and allows the next item to proceed.
	 *
	 * @param array $job Durable job state.
	 */
	protected static function recover_interrupted_work( &$job ) {
		if ( null !== $job['in_flight'] ) {
			list( $bucket, $key )             = $job['in_flight'];
			$job[ $bucket ][ $key ]['status'] = 'failed';
			$job[ $bucket ][ $key ]['reason'] = 'worker-interrupted';
			$job['in_flight']                 = null;
		}
	}

	/**
	 * Warms one page or adds the children of one sitemap.
	 *
	 * @param array $job Durable job state.
	 * @param array $work Bucket and record key.
	 */
	protected static function process_work( &$job, $work ) {
		list( $bucket, $key ) = $work;
		$record               = &$job[ $bucket ][ $key ];
		if ( ! in_array( HSPSC_Page::get_url_origin( $record['url'] ), $job['origins'], true ) ) {
			$record['status'] = 'sitemaps' === $bucket ? 'failed' : 'skipped';
			$record['reason'] = 'invalid-url';
			return;
		}
		if ( 'pages' === $bucket ) {
			$result           = HSPSC_Page::warm_public_url( $record['url'], $job['accept'], $job['timeout'] );
			$record['status'] = $result['status'];
			$record['reason'] = $result['reason'];
			return;
		}
		$result                  = HSPSC_Preload::fetch_sitemap( $record['url'], $job['timeout'], self::MAX_DISCOVERY_BYTES - $job['discovery_bytes'] );
		$job['discovery_bytes'] += $result['bytes'];
		if ( '' !== $result['reason'] ) {
			$record['status'] = 'failed';
			$record['reason'] = $result['reason'];
			return;
		}
		$record['status'] = 'done';
		$child_bucket     = 'sitemapindex' === $result['kind'] ? 'sitemaps' : 'pages';
		foreach ( $result['locations'] as $url ) {
			if ( ! self::enqueue( $job, $child_bucket, $url, $record['depth'] + 1, $record['url'] ) ) {
				break;
			}
		}
	}

	/**
	 * Saves a worker checkpoint and retires the cron event when finished.
	 *
	 * @param array      $job Durable job state.
	 * @param array|null $work Next work item.
	 * @return array|WP_Error Public progress or persistence error.
	 */
	protected static function finish_worker( &$job, $work ) {
		self::set_state( $job, $work );
		if ( ! self::save_job( $job ) ) {
			return self::storage_error();
		}
		if ( ! self::is_active( $job ) ) {
			wp_clear_scheduled_hook( self::HOOK, array( $job['id'] ) );
		}
		return self::summarize( $job );
	}

	/**
	 * Derives the job phase and records its finish time.
	 *
	 * @param array      $job Durable job state.
	 * @param array|null $work Next work item.
	 */
	protected static function set_state( &$job, $work ) {
		if ( null !== $work ) {
			$job['state'] = 'sitemaps' === $work[0] ? 'discovering' : 'running';
			return;
		}
		$job['state']       = 'completed';
		$job['finished_at'] = time();
		if ( '' !== $job['failure'] || $job['issues'] ) {
			$job['state'] = 'failed';
			return;
		}
		foreach ( array( 'sitemaps', 'pages' ) as $bucket ) {
			foreach ( $job[ $bucket ] as $record ) {
				if ( 'failed' === $record['status'] ) {
					$job['state'] = 'failed';
					return;
				}
			}
		}
	}

	/**
	 * Produces counts and one page of safe, terminal result rows.
	 *
	 * @param array $job Durable job state.
	 * @param int   $page One-based result page.
	 * @return array Public job status.
	 */
	protected static function summarize( $job, $page = 1 ) {
		$results           = array_values( $job['issues'] );
		$discovery_pending = false;
		$retryable         = false;
		foreach ( $job['sitemaps'] as $record ) {
			if ( 'failed' === $record['status'] ) {
				$results[] = $record;
				$retryable = $retryable || self::can_retry( $record );
			} elseif ( 'pending' === $record['status'] ) {
				$discovery_pending = true;
			}
		}
		$discovery_failed = count( $results );
		$counts           = array(
			'cached'  => 0,
			'skipped' => 0,
			'failed'  => 0,
		);
		foreach ( $job['pages'] as $record ) {
			if ( isset( $counts[ $record['status'] ] ) ) {
				++$counts[ $record['status'] ];
				$results[] = $record;
				$retryable = $retryable || self::can_retry( $record );
			}
		}
		$pages   = max( 1, (int) ceil( count( $results ) / self::RESULTS_PER_PAGE ) );
		$page    = max( 1, min( $pages, (int) $page ) );
		$visible = array_slice( $results, ( $page - 1 ) * self::RESULTS_PER_PAGE, self::RESULTS_PER_PAGE );
		$rows    = array();
		foreach ( $visible as $record ) {
			$rows[] = array(
				'url'    => self::report_url( $record['url'] ),
				'status' => $record['status'],
				'reason' => $record['reason'],
				'type'   => $record['type'],
			);
		}
		$end = 0 < $job['finished_at'] ? $job['finished_at'] : time();
		return array(
			'id'                 => $job['id'],
			'state'              => $job['state'],
			'total'              => count( $job['pages'] ),
			'processed'          => array_sum( $counts ),
			'cached'             => $counts['cached'],
			'skipped'            => $counts['skipped'],
			'failed'             => $counts['failed'],
			'started_at'         => $job['started_at'],
			'finished_at'        => $job['finished_at'],
			'elapsed'            => max( 0, $end - $job['started_at'] ),
			'results'            => $rows,
			'page'               => $page,
			'pages'              => $pages,
			'discovery_complete' => ! $discovery_pending && 0 === $discovery_failed,
			'discovery_failed'   => $discovery_failed,
			'retryable'          => $retryable && '' === $job['failure'],
			'failure'            => $job['failure'],
		);
	}

	/**
	 * Displays a URL without exposing arbitrary request query values.
	 *
	 * @param string $url Original queued URL.
	 * @return string Safe diagnostic URL with public routing arguments only.
	 */
	protected static function report_url( $url ) {
		if ( '' === HSPSC_Page::get_url_origin( $url ) ) {
			return '';
		}
		$base  = preg_split( '/[?#]/', $url, 2 )[0];
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$args  = array();
		if ( is_string( $query ) ) {
			wp_parse_str( $query, $args );
		}
		$public = array();
		foreach ( array( 'p', 'page_id', 'page', 'paged', 'cat', 'author', 'lang', 'sitemap', 'sitemap-subtype' ) as $name ) {
			$pattern = in_array( $name, array( 'lang', 'sitemap', 'sitemap-subtype' ), true ) ? '/^[a-z][a-z0-9_-]{0,31}$/iD' : '/^[0-9]{1,12}$/D';
			if ( isset( $args[ $name ] ) && is_string( $args[ $name ] ) && preg_match( $pattern, $args[ $name ] ) ) {
				$public[ $name ] = $args[ $name ];
			}
		}
		return esc_url_raw( $public ? add_query_arg( $public, $base ) : $base );
	}

	/**
	 * Checks whether a failed record can be attempted again without widening bounds.
	 *
	 * @param array $record Queue record.
	 * @return bool Whether retry should requeue the record.
	 */
	protected static function can_retry( $record ) {
		return 'failed' === $record['status'] && ! in_array( $record['reason'], array( 'invalid-url', 'sitemap-depth-limit' ), true );
	}

	/**
	 * Reads the nonautoloaded job, bypassing request-local cache under the lock.
	 *
	 * @param bool $refresh Whether to discard stale option-cache entries first.
	 * @return array Current job or an empty array.
	 */
	protected static function read_job( $refresh = false ) {
		if ( $refresh ) {
			wp_cache_delete( self::OPTION, 'options' );
			$missing = wp_cache_get( 'notoptions', 'options', true );
			if ( is_array( $missing ) && isset( $missing[ self::OPTION ] ) ) {
				// A request may have read the job before another worker created it.
				wp_cache_delete( 'notoptions', 'options' );
			}
		}
		$job = get_option( self::OPTION, array() );
		return is_array( $job ) ? $job : array();
	}

	/**
	 * Persists one complete job checkpoint without autoloading it.
	 *
	 * @param array $job Durable job state.
	 * @return bool Whether the checkpoint is stored.
	 */
	protected static function save_job( $job ) {
		return update_option( self::OPTION, $job, false ) || get_option( self::OPTION ) === $job;
	}

	/**
	 * Commits an attempt before WordPress can dispatch its first event.
	 *
	 * @param array $job New attempt state held under the job lock.
	 * @return array|WP_Error Public status or startup failure.
	 */
	protected static function start_attempt( $job ) {
		if ( ! self::save_job( $job ) ) {
			return self::storage_error();
		}
		return self::schedule( $job['id'] ) ? self::summarize( $job ) : self::fail_scheduling( $job );
	}

	/**
	 * Retires an attempt that cannot schedule a worker, preserving its report.
	 *
	 * @param array $job Attempt state held under the job lock.
	 * @return WP_Error Scheduling or persistence failure.
	 */
	protected static function fail_scheduling( $job ) {
		$job['failure'] = 'schedule-failed';
		self::set_state( $job, null );
		return self::save_job( $job ) ? self::schedule_error() : self::storage_error();
	}

	/**
	 * Tests whether a request still targets the current job.
	 *
	 * @param array  $job Current durable state.
	 * @param string $job_id Expected job ID.
	 * @return bool Whether IDs match.
	 */
	protected static function is_current( $job, $job_id ) {
		return isset( $job['id'] ) && $job['id'] === $job_id;
	}

	/**
	 * Tests whether a job still has work.
	 *
	 * @param array $job Current durable state.
	 * @return bool Whether the job is active.
	 */
	protected static function is_active( $job ) {
		return in_array( $job['state'] ?? '', array( 'discovering', 'running' ), true );
	}

	/**
	 * Ensures one successor event for the same job ID.
	 *
	 * @param string $job_id Job ID to schedule.
	 * @return bool Whether a successor exists.
	 */
	protected static function schedule( $job_id ) {
		$args = array( $job_id );
		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}
		$scheduled = wp_schedule_single_event( time() + 5, self::HOOK, $args, true );
		return true === $scheduled || (bool) wp_next_scheduled( self::HOOK, $args );
	}

	/**
	 * Locks the stable rebuild inode outside disposable page and asset subtrees.
	 *
	 * @param bool $wait Whether lifecycle cleanup may wait for the current batch.
	 * @return resource|WP_Error Open lock or failure.
	 */
	protected static function lock( $wait = false ) {
		$path = HSPSC_PATH . '/.rebuild-lock';
		if ( is_link( HSPSC_PATH ) || is_link( $path ) || ( ! is_dir( HSPSC_PATH ) && ! wp_mkdir_p( HSPSC_PATH ) ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			return self::storage_error();
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The lock must retain one local inode and never expose filesystem warnings to admin responses.
		$handle = @fopen( $path, 'c+b' );
		if ( false === $handle ) {
			return self::storage_error();
		}
		if ( ! flock( $handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release an unacquired lock handle immediately.
			fclose( $handle );
			return new WP_Error( 'hspsc_rebuild_busy', __( 'Another request is processing the rebuild.', 'hsp-smart-cache' ) );
		}
		return $handle;
	}

	/**
	 * Releases a worker or lifecycle lock.
	 *
	 * @param resource $handle Open lock stream.
	 */
	protected static function unlock( $handle ) {
		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This local stream owns the stable flock descriptor.
		fclose( $handle );
	}

	/**
	 * Builds the error for an unavailable job checkpoint or lock.
	 *
	 * @return WP_Error Safe storage error.
	 */
	protected static function storage_error() {
		return new WP_Error( 'hspsc_rebuild_storage', __( 'The rebuild state could not be stored.', 'hsp-smart-cache' ) );
	}

	/**
	 * Builds the error for an unavailable cron continuation.
	 *
	 * @return WP_Error Safe scheduling error.
	 */
	protected static function schedule_error() {
		return new WP_Error( 'hspsc_rebuild_schedule', __( 'WordPress could not schedule the rebuild worker.', 'hsp-smart-cache' ) );
	}

	/**
	 * Builds the error for a request targeting an old job.
	 *
	 * @return WP_Error Safe stale-job error.
	 */
	protected static function stale_error() {
		return new WP_Error( 'hspsc_rebuild_stale_job', __( 'This rebuild is no longer current.', 'hsp-smart-cache' ) );
	}
}
