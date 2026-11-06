<?php
/**
 * Authorized progress, results, and retry controls for public page rebuilds.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the background rebuild job to users who can manage cache settings.
 */
class HSPSC_Rebuild_Admin {
	/**
	 * Registers progress assets and separate read, worker, and retry actions.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_notice' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_notice' ) );
		add_action( 'wp_ajax_hspsc_rebuild_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_hspsc_rebuild_tick', array( __CLASS__, 'ajax_tick' ) );
		add_action( 'wp_ajax_hspsc_rebuild_retry', array( __CLASS__, 'ajax_retry' ) );
		add_action( 'admin_post_hspsc_rebuild_retry', array( __CLASS__, 'handle_retry' ) );
	}

	/**
	 * Loads job-independent progress assets for authorized users.
	 *
	 * @param string $screen Admin screen hook suffix, or empty on the frontend.
	 */
	public static function enqueue_assets( $screen = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$plugin_file = dirname( __DIR__ ) . '/hsp-smart-cache.php';
		wp_enqueue_style( 'hspsc-rebuild', plugins_url( 'assets/css/action-notice.css', $plugin_file ), array(), HSPSC_VERSION );
		wp_enqueue_script( 'hspsc-rebuild', plugins_url( 'assets/js/rebuild.js', $plugin_file ), array(), HSPSC_VERSION, true );
		$config = array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'statusNonce'     => wp_create_nonce( 'hspsc_rebuild_status' ),
			'tickNonce'       => wp_create_nonce( 'hspsc_rebuild_tick' ),
			'dismissKey'      => 'hspsc-rebuild-dismissed-' . get_current_blog_id(),
			'requestFailed'   => __( 'Could not reach the rebuild controls. Reload the page to try again.', 'hsp-smart-cache' ),
			'requestComplete' => __( 'Public page cache rebuild queued.', 'hsp-smart-cache' ),
		);
		if ( 'settings_page_hsp-smart-cache' === $screen ) {
			$accept = HSPSC_Page::get_warming_accept();
			// ASCII transport preserves exact header bytes through localization and form encoding.
			$config['navigationAccept'] = is_string( $accept ) ? bin2hex( $accept ) : 'invalid';
		}
		wp_localize_script( 'hspsc-rebuild', 'HSPSCRebuild', $config );
	}

	/**
	 * Prints an empty notice that loads current job state through authorized AJAX.
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<aside id="hspsc-rebuild-notice" class="hspsc-rebuild-notice" aria-label="<?php esc_attr_e( 'Public page cache rebuild', 'hsp-smart-cache' ); ?>" hidden>
			<p class="hspsc-rebuild-notice-message" role="status" aria-live="polite" aria-atomic="true"></p>
			<progress aria-label="<?php esc_attr_e( 'Public page rebuild progress', 'hsp-smart-cache' ); ?>" hidden></progress>
			<p class="hspsc-rebuild-notice-feedback hspsc-rebuild-warning" role="status" hidden></p>
			<div class="hspsc-rebuild-notice-actions">
				<a href="<?php echo esc_url( self::results_url() ); ?>"><?php esc_html_e( 'View results', 'hsp-smart-cache' ); ?></a>
				<?php self::render_retry_form(); ?>
				<button type="button" class="hspsc-rebuild-dismiss"><?php esc_html_e( 'Dismiss', 'hsp-smart-cache' ); ?></button>
			</div>
		</aside>
		<?php
	}

	/**
	 * Builds the settings report URL used by notices and form redirects.
	 *
	 * @return string URL including the report anchor.
	 */
	public static function results_url() {
		return admin_url( 'options-general.php?page=hsp-smart-cache#hspsc-rebuild-report' );
	}

	/**
	 * Selects a return URL without exposing engine error messages.
	 *
	 * @param array|WP_Error $result Job summary or engine error.
	 * @param string         $return_url Successful return URL.
	 * @return string URL for WordPress to validate and redirect to.
	 */
	public static function start_redirect_url( $result, $return_url ) {
		if ( ! is_wp_error( $result ) ) {
			return $return_url;
		}
		$code = $result->get_error_code();
		if ( ! isset( self::error_messages()[ $code ] ) ) {
			$code = 'hspsc_rebuild_error';
		}
		return add_query_arg( 'hspsc_rebuild_error', $code, self::results_url() );
	}

	/**
	 * Replies to a verified start or retry request with queued status.
	 *
	 * @param array|WP_Error $result Job summary or engine error.
	 */
	public static function send_start_result( $result ) {
		if ( is_wp_error( $result ) ) {
			self::send_engine_error( $result );
		}
		wp_send_json_success(
			array(
				'message' => __( 'Public page cache rebuild queued.', 'hsp-smart-cache' ),
				'job'     => self::get_notice_summary( $result ),
			)
		);
	}

	/**
	 * Reads persisted progress without invoking the worker or changing the job.
	 */
	public static function ajax_status() {
		self::ensure_ajax_access( 'hspsc_rebuild_status' );
		$page = max( 1, absint( self::post_value( 'page' ) ) );
		$job  = HSPSC_Rebuild::get_status( $page );
		$data = array( 'job' => self::get_notice_summary( $job ) );
		if ( '1' === self::post_value( 'include_report' ) ) {
			$data['report'] = self::get_report_html( $job );
			$data['page']   = max( 1, absint( $job['page'] ?? 1 ) );
		}
		wp_send_json_success( $data );
	}

	/**
	 * Allows an authorized visible browser to advance the locked background job.
	 */
	public static function ajax_tick() {
		self::ensure_ajax_access( 'hspsc_rebuild_tick' );
		$id = self::get_posted_job_id();
		if ( '' === $id ) {
			self::send_engine_error( new WP_Error( 'hspsc_rebuild_stale_job' ) );
		}
		$result = HSPSC_Rebuild::run_batch( $id );
		if ( is_wp_error( $result ) ) {
			self::send_engine_error( $result );
		}
		wp_send_json_success();
	}

	/**
	 * Verifies a retry nonce bound to the displayed job before retrying failures.
	 */
	public static function ajax_retry() {
		$id = self::get_posted_job_id();
		self::ensure_ajax_access( 'hspsc_rebuild_retry_' . $id );
		if ( '' === $id ) {
			self::send_engine_error( new WP_Error( 'hspsc_rebuild_stale_job' ) );
		}
		self::send_start_result( HSPSC_Rebuild::retry( $id ) );
	}

	/**
	 * Supports the same job-specific retry through a standard settings form.
	 */
	public static function handle_retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
		}
		$id = self::get_posted_job_id();
		check_admin_referer( 'hspsc_rebuild_retry_' . $id );
		$result = '' === $id ? new WP_Error( 'hspsc_rebuild_stale_job' ) : HSPSC_Rebuild::retry( $id );
		wp_safe_redirect( self::start_redirect_url( $result, self::results_url() ) );
		if ( ! apply_filters( 'hspsc_skip_admin_exit', false ) ) {
			exit;
		}
	}

	/**
	 * Prints the latest report and no-script controls on the settings screen.
	 */
	public static function render_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- These allowlisted GET values select read-only report presentation.
		$page = isset( $_GET['hspsc_rebuild_page'] ) && is_string( $_GET['hspsc_rebuild_page'] ) ? max( 1, absint( $_GET['hspsc_rebuild_page'] ) ) : 1;
		$code = isset( $_GET['hspsc_rebuild_error'] ) && is_string( $_GET['hspsc_rebuild_error'] ) ? sanitize_key( wp_unslash( $_GET['hspsc_rebuild_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$job   = HSPSC_Rebuild::get_status( $page );
		$error = self::error_messages()[ $code ] ?? '';
		?>
		<section id="hspsc-rebuild-report" class="hspsc-report-panel" aria-labelledby="hspsc-rebuild-report-title">
			<h2 id="hspsc-rebuild-report-title" class="hspsc-section-title"><?php esc_html_e( 'Public page cache rebuild results', 'hsp-smart-cache' ); ?></h2>
			<p><?php esc_html_e( 'These results describe the cache for public visitors. The toolbar can show BYPASS for your logged-in visit while a public cache entry exists.', 'hsp-smart-cache' ); ?></p>
			<p><?php esc_html_e( 'The rebuild warms the page version requested by the browser that started it. Other browsers can use separate cache entries.', 'hsp-smart-cache' ); ?></p>
			<p id="hspsc-rebuild-action-message" role="status" <?php echo '' === $error ? 'hidden' : 'class="hspsc-rebuild-warning"'; ?>><?php echo esc_html( $error ); ?></p>
			<div data-hspsc-rebuild-report data-page="<?php echo esc_attr( max( 1, absint( $job['page'] ?? 1 ) ) ); ?>">
				<?php echo self::get_report_html( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The report builder escapes every dynamic value. ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders one escaped page of results and the matching retry form.
	 *
	 * @param array $job Current service summary.
	 * @return string Report body markup.
	 */
	protected static function get_report_html( $job ) {
		$notice = self::get_notice_summary( $job );
		ob_start();
		if ( ! $notice ) {
			?>
			<p><?php esc_html_e( 'No public page cache rebuild has run yet.', 'hsp-smart-cache' ); ?></p>
			<?php
			return ob_get_clean();
		}
		$page  = max( 1, absint( $job['page'] ?? 1 ) );
		$pages = max( 1, absint( $job['pages'] ?? 1 ) );
		?>
		<p><strong><?php echo esc_html( $notice['message'] ); ?></strong></p>
		<?php if ( ! empty( $job['started_at'] ) ) : ?>
			<p>
				<?php
				$elapsed  = absint( $job['elapsed'] ?? 0 );
				$duration = sprintf(
					/* translators: %s: localized number of seconds. */
					_n( '%s second', '%s seconds', $elapsed, 'hsp-smart-cache' ),
					number_format_i18n( $elapsed )
				);
				echo esc_html(
					sprintf(
						/* translators: 1: local date and time when the rebuild started, 2: elapsed duration. */
						__( 'Started %1$s. Elapsed time: %2$s.', 'hsp-smart-cache' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $job['started_at'] ) ),
						$duration
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $job['results'] ) ) : ?>
			<div class="hspsc-rebuild-results-table">
				<table class="widefat striped">
					<caption class="screen-reader-text"><?php esc_html_e( 'Page and sitemap rebuild results', 'hsp-smart-cache' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'URL', 'hsp-smart-cache' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'hsp-smart-cache' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'hsp-smart-cache' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'hsp-smart-cache' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $job['results'] as $row ) : ?>
						<tr>
							<td class="hspsc-rebuild-result-url"><?php echo esc_html( self::get_result_url( $row['url'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( 'sitemap' === ( $row['type'] ?? '' ) ? __( 'Sitemap', 'hsp-smart-cache' ) : __( 'Page', 'hsp-smart-cache' ) ); ?></td>
							<td><?php echo esc_html( self::get_result_label( $row['status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( self::get_reason_label( $row['reason'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php if ( $pages > 1 ) : ?>
			<nav class="hspsc-rebuild-pagination" aria-label="<?php esc_attr_e( 'Rebuild result pages', 'hsp-smart-cache' ); ?>">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'hspsc_rebuild_page', $page - 1, self::results_url() ) ); ?>"><?php esc_html_e( 'Previous page', 'hsp-smart-cache' ); ?></a>
				<?php endif; ?>
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: current results page number, 2: total results pages. */
							__( 'Page %1$d of %2$d', 'hsp-smart-cache' ),
							$page,
							$pages
						)
					);
					?>
				</span>
				<?php if ( $page < $pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'hspsc_rebuild_page', $page + 1, self::results_url() ) ); ?>"><?php esc_html_e( 'Next page', 'hsp-smart-cache' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
		<?php
		if ( '' !== $notice['retryNonce'] ) {
			self::render_retry_form( $notice['id'], $notice['retryNonce'] );
		}
		return ob_get_clean();
	}

	/**
	 * Renders the same retry control in the notice shell and the settings report.
	 *
	 * @param string $id Completed job ID, or empty for the job-independent shell.
	 * @param string $nonce Verified job's retry nonce, or empty for the shell.
	 */
	protected static function render_retry_form( $id = '', $nonce = '' ) {
		?>
		<form class="hspsc-rebuild-form hspsc-rebuild-retry-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo '' === $id ? 'hidden' : ''; ?>>
			<input type="hidden" name="action" value="hspsc_rebuild_retry" />
			<input type="hidden" name="job_id" value="<?php echo esc_attr( $id ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Retry failed pages', 'hsp-smart-cache' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Reduces persisted data to the few fields needed by a progress notice.
	 *
	 * @param array $job Current service summary.
	 * @return array|null View data, or null before any job has been started.
	 */
	protected static function get_notice_summary( $job ) {
		$id    = $job['id'] ?? '';
		$state = $job['state'] ?? '';
		if ( ! is_string( $id ) || ! self::is_job_id( $id ) || ! in_array( $state, array( 'discovering', 'running', 'completed', 'failed' ), true ) ) {
			return null;
		}
		$active           = in_array( $state, array( 'discovering', 'running' ), true );
		$failed           = absint( $job['failed'] ?? 0 );
		$discovery_failed = absint( $job['discovery_failed'] ?? 0 );
		$warning          = 'failed' === $state || $failed > 0 || $discovery_failed > 0;
		$total            = absint( $job['total'] ?? 0 );
		$processed        = absint( $job['processed'] ?? 0 );
		$title            = match ( $state ) {
			'discovering' => __( 'Discovering public pages.', 'hsp-smart-cache' ),
			'running'     => __( 'Rebuilding the public page cache.', 'hsp-smart-cache' ),
			default       => $warning ? __( 'Public page cache rebuild finished with failures.', 'hsp-smart-cache' ) : __( 'Public page cache rebuild completed.', 'hsp-smart-cache' ),
		};
		$counts = sprintf(
			/* translators: 1: processed pages, 2: discovered pages, 3: verified cached pages, 4: skipped pages, 5: failed pages. */
			__( '%1$d of %2$d pages processed. %3$d cached, %4$d skipped, %5$d failed.', 'hsp-smart-cache' ),
			$processed,
			$total,
			absint( $job['cached'] ?? 0 ),
			absint( $job['skipped'] ?? 0 ),
			$failed
		);
		$message = $title . ' ' . $counts;
		if ( 'failed' === $state && ! empty( $job['failure'] ) ) {
			$message .= ' ' . self::get_reason_label( $job['failure'] );
		}
		if ( $discovery_failed ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of sitemap discovery failures. */
				_n( '%d sitemap could not be fully read.', '%d sitemaps could not be fully read.', $discovery_failed, 'hsp-smart-cache' ),
				$discovery_failed
			);
		}
		return array(
			'id'            => $id,
			'active'        => $active,
			'message'       => $message,
			'tone'          => $active ? 'active' : ( $warning ? 'warning' : 'success' ),
			'progressMax'   => 'running' === $state && $total ? $total : 0,
			'progressValue' => $processed,
			'retryNonce'    => ! $active && ! empty( $job['retryable'] ) ? wp_create_nonce( 'hspsc_rebuild_retry_' . $id ) : '',
		);
	}

	/**
	 * Displays only absolute HTTP URLs, never paths or embedded credentials.
	 *
	 * @param mixed $url Result URL.
	 * @return string Display URL.
	 */
	protected static function get_result_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Translates a fixed result code.
	 *
	 * @param string $status Engine status code.
	 * @return string Result label.
	 */
	protected static function get_result_label( $status ) {
		return match ( $status ) {
			'cached'  => __( 'Cached', 'hsp-smart-cache' ),
			'skipped' => __( 'Skipped', 'hsp-smart-cache' ),
			'failed'  => __( 'Failed', 'hsp-smart-cache' ),
			default   => __( 'Unknown', 'hsp-smart-cache' ),
		};
	}

	/**
	 * Translates known reason codes without showing internal diagnostics.
	 *
	 * @param string $reason Engine reason code.
	 * @return string Safe result explanation.
	 */
	protected static function get_reason_label( $reason ) {
		return match ( $reason ) {
			'cached', 'verified', 'verified-hit' => __( 'Verified public cache entry.', 'hsp-smart-cache' ),
			'cache-disabled'                => __( 'Page caching is disabled.', 'hsp-smart-cache' ),
			'invalid-accept'                => __( 'The request could not select a supported page representation.', 'hsp-smart-cache' ),
			'excluded'                      => __( 'The URL is private or excluded from caching.', 'hsp-smart-cache' ),
			'unresolved-language'           => __( 'The URL language could not be resolved.', 'hsp-smart-cache' ),
			'private-page'                  => __( 'The page is private or requires authentication.', 'hsp-smart-cache' ),
			'private-response'              => __( 'The server marked the response as private.', 'hsp-smart-cache' ),
			'invalid-url'                  => __( 'URL is outside the allowed public site origins or is malformed.', 'hsp-smart-cache' ),
			'purge-failed'                 => __( 'The existing public cache entries could not be cleared.', 'hsp-smart-cache' ),
			'schedule-failed'              => __( 'WordPress could not schedule the background rebuild.', 'hsp-smart-cache' ),
			'request-error'                => __( 'The server request did not complete.', 'hsp-smart-cache' ),
			'response-status'              => __( 'The URL returned an unsuccessful HTTP response.', 'hsp-smart-cache' ),
			'response-type'                => __( 'The URL did not return an HTML page.', 'hsp-smart-cache' ),
			'response-size'                => __( 'The page exceeded the supported response size.', 'hsp-smart-cache' ),
			'response-encoding'            => __( 'The page used an unsupported response encoding.', 'hsp-smart-cache' ),
			'cache-bypass'                 => __( 'The server did not provide a cacheable response.', 'hsp-smart-cache' ),
			'cache-missing'                => __( 'A fresh public cache entry could not be found after the request.', 'hsp-smart-cache' ),
			'cache-mismatch'               => __( 'The saved cache entry did not match the returned page.', 'hsp-smart-cache' ),
			'sitemap-too-large'            => __( 'The sitemap exceeded the response size limit.', 'hsp-smart-cache' ),
			'invalid-sitemap'              => __( 'The response was not a supported XML sitemap.', 'hsp-smart-cache' ),
			'sitemap-depth-limit'          => __( 'The sitemap index exceeded the nesting limit.', 'hsp-smart-cache' ),
			'sitemap-count-limit'          => __( 'Discovery reached the sitemap count limit.', 'hsp-smart-cache' ),
			'discovery-byte-limit'         => __( 'Discovery reached the total sitemap size limit.', 'hsp-smart-cache' ),
			'page-count-limit'            => __( 'Discovery reached the supported page count limit.', 'hsp-smart-cache' ),
			'language-root-limit'         => __( 'Discovery reached the supported language count limit.', 'hsp-smart-cache' ),
			'url-byte-limit'              => __( 'Discovery reached the total URL size limit.', 'hsp-smart-cache' ),
			'url-too-long'                => __( 'The URL exceeded the supported length.', 'hsp-smart-cache' ),
			'worker-interrupted'          => __( 'The background request was interrupted.', 'hsp-smart-cache' ),
			'private-request'             => __( 'The page is private or excluded from caching.', 'hsp-smart-cache' ),
			'response-headers'            => __( 'The response headers prevented caching.', 'hsp-smart-cache' ),
			'request-key'                 => __( 'The request could not be assigned a cache entry.', 'hsp-smart-cache' ),
			'buffer-conflict'             => __( 'Another output buffer prevented caching.', 'hsp-smart-cache' ),
			'late-private-response'       => __( 'The response became private while rendering.', 'hsp-smart-cache' ),
			'write-failed'                => __( 'The cache entry could not be saved.', 'hsp-smart-cache' ),
			default                       => __( 'No additional details are available.', 'hsp-smart-cache' ),
		};
	}

	/**
	 * Gets a scalar posted value for subsequent validation.
	 *
	 * @param string $key Field name.
	 * @return string Unslashed value, or an empty string.
	 */
	protected static function post_value( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callers authorize requests and validate values before use. The retry identifier must be read to select its action-specific nonce.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * Validates an opaque job identifier before passing it to the engine.
	 *
	 * @param string $id Candidate job ID.
	 * @return bool Whether the ID has a supported format.
	 */
	protected static function is_job_id( $id ) {
		return wp_is_uuid( $id, 4 );
	}

	/**
	 * Returns a validated posted job ID.
	 *
	 * @return string Job ID or an empty string for invalid input.
	 */
	protected static function get_posted_job_id() {
		$id = self::post_value( 'job_id' );
		return self::is_job_id( $id ) ? $id : '';
	}

	/**
	 * Authorizes one privileged AJAX action.
	 *
	 * @param string $nonce_action Specific AJAX action or job-bound retry nonce.
	 */
	protected static function ensure_ajax_access( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'hsp-smart-cache' ) ), 403 );
		}
		if ( ! check_ajax_referer( $nonce_action, '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Refresh the page and try again.', 'hsp-smart-cache' ) ), 403 );
		}
	}

	/**
	 * Returns a localized explanation without forwarding engine error text.
	 *
	 * @param WP_Error $error Engine failure.
	 */
	protected static function send_engine_error( $error ) {
		$messages = self::error_messages();
		$code     = $error->get_error_code();
		wp_send_json_error( array( 'message' => $messages[ $code ] ?? $messages['hspsc_rebuild_error'] ), 409 );
	}

	/**
	 * Lists fixed engine errors allowed in UI and redirect feedback.
	 *
	 * @return array Error codes and safe translated messages.
	 */
	protected static function error_messages() {
		return array(
			'hspsc_rebuild_error'            => __( 'The public page cache rebuild could not be started or updated.', 'hsp-smart-cache' ),
			'hspsc_rebuild_disabled'         => __( 'Enable page caching before rebuilding the public cache.', 'hsp-smart-cache' ),
			'hspsc_rebuild_invalid_url'      => __( 'This URL cannot be used to rebuild the public page cache.', 'hsp-smart-cache' ),
			'hspsc_rebuild_invalid_accept'   => __( 'The request could not select a supported page representation.', 'hsp-smart-cache' ),
			'hspsc_rebuild_active'           => __( 'A public page cache rebuild is already in progress.', 'hsp-smart-cache' ),
			'hspsc_rebuild_stale_job'        => __( 'This rebuild is no longer the latest job. View the current results and try again.', 'hsp-smart-cache' ),
			'hspsc_rebuild_not_retryable'    => __( 'Start a new public page cache rebuild to continue.', 'hsp-smart-cache' ),
			'hspsc_rebuild_nothing_to_retry' => __( 'This rebuild has no retryable failures.', 'hsp-smart-cache' ),
			'hspsc_rebuild_busy'             => __( 'Another request is processing the rebuild. Try again shortly.', 'hsp-smart-cache' ),
			'hspsc_rebuild_schedule'         => __( 'WordPress could not schedule the background rebuild.', 'hsp-smart-cache' ),
			'hspsc_rebuild_storage'          => __( 'The rebuild progress could not be stored.', 'hsp-smart-cache' ),
		);
	}
}
