<?php

class HSPSC_Public_Warming_Test extends WP_UnitTestCase {
	private $server;
	private $cookies;
	private $query;
	private $options;
	private $files = array();

	public function set_up(): void {
		parent::set_up();
		$this->server  = $_SERVER;
		$this->cookies = $_COOKIE;
		$this->query   = $_GET;
		$this->options = get_option( HSPSC_Settings::OPTION_KEY );
		$this->files   = array();
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		HSPSC_Utils::ensure_cache_dirs();
	}

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		$_SERVER = $this->server;
		$_COOKIE = $this->cookies;
		$_GET    = $this->query;
		if ( false === $this->options ) {
			delete_option( HSPSC_Settings::OPTION_KEY );
		} else {
			update_option( HSPSC_Settings::OPTION_KEY, $this->options );
		}
		HSPSC_Settings::reset_cache();
		parent::tear_down();
	}

	private function invoke_page( $name, ...$args ) {
		$method = new ReflectionMethod( 'HSPSC_Page', $name );
		$method->setAccessible( true );
		return $method->invokeArgs( null, $args );
	}

	private function public_file( $url, $accept, $language = false ) {
		$key = $this->invoke_page( 'get_url_cache_key', $url, '', $language );
		return $this->invoke_page( 'get_variant_cache_file_path', $key, $accept );
	}

	private function store_file( $file, $contents ) {
		$this->files[] = $file;
		$this->assertTrue( HSPSC_Utils::atomic_write( $file, $contents ) );
	}

	private function response( $body, $headers = array(), $status = 200 ) {
		return array(
			'headers'  => array_merge(
				array(
					'content-type'  => 'text/html; charset=UTF-8',
					'cache-control' => 'private, no-cache, max-age=600, must-revalidate',
					'vary'          => 'Accept, Accept-Encoding',
					'x-hspsc-cache' => 'MISS',
				),
				$headers
			),
			'body'     => $body,
			'response' => array( 'code' => $status ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function with_http( $callback, $run ) {
		add_filter( 'pre_http_request', $callback, 10, 3 );
		try {
			return $run();
		} finally {
			remove_filter( 'pre_http_request', $callback, 10 );
		}
	}

	public function test_warming_accept_preserves_exact_bytes_and_only_defaults_when_absent() {
		unset( $_SERVER['HTTP_ACCEPT'] );
		$this->assertSame( 'text/html', HSPSC_Page::get_warming_accept() );
		$accept = 'text/html; profile="https://example.org/doc", image/webp';
		$_SERVER['HTTP_ACCEPT'] = wp_slash( $accept );
		$this->assertSame( $accept, HSPSC_Page::get_warming_accept() );
		$_SERVER['HTTP_ACCEPT'] = '';
		$this->assertSame( '', HSPSC_Page::get_warming_accept() );
		foreach ( array( null, array( 'text/html' ), "text/html\r\nCookie: test", str_repeat( 'x', 8193 ) ) as $invalid ) {
			$_SERVER['HTTP_ACCEPT'] = $invalid;
			$this->assertNull( HSPSC_Page::get_warming_accept() );
		}
	}

	public function test_private_or_unapproved_targets_are_rejected_without_loopbacks() {
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array( 'page_cache_excluded_paths' => '/members/' ) ) );
		$cart = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$password_page = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => 'fixture-password' )
		);
		$private_pages = static function () use ( $cart ) {
			return array( $cart );
		};
		add_filter( 'hspsc_private_commerce_page_ids', $private_pages );
		$unexpected = function () {
			$this->fail( 'An excluded target initiated a loopback.' );
		};
		try {
			$this->with_http(
				$unexpected,
				function () use ( $cart, $password_page ) {
					$paths = array(
						'/wp-admin',
						'/wp-admin/options-general.php?page=hsp-smart-cache',
						'/subdir/wp-admin/admin-ajax.php',
						'/front/../wp%2dadmin/options.php',
						'/wp-json/wp/v2/settings',
						'/wp-login.php',
						'/wp-cron.php?doing_wp_cron=123',
						'/wp-mail.php',
						'/front/../wp%2dmail.php',
						'/page/?rest_route=/wp/v2/settings',
						'/page/?bricks=run',
						'/page/?preview=true',
						'/page/?_wpnonce=fixture',
						'/page/?add-to-cart=42',
						'/page/?action=unknown-action',
						'/members/?lang=de',
					);
					foreach ( $paths as $path ) {
						$this->assertSame( array( 'status' => 'skipped', 'reason' => 'excluded' ), HSPSC_Page::warm_public_url( home_url( $path ), 'text/html' ), $path );
					}
					foreach ( array( $cart, $password_page ) as $post_id ) {
						$url = add_query_arg( 'page_id', $post_id, home_url( '/' ) );
						$this->assertSame( array( 'status' => 'skipped', 'reason' => 'private-page' ), HSPSC_Page::warm_public_url( $url, 'text/html' ) );
					}
					$this->assertSame( 'invalid-url', HSPSC_Page::warm_public_url( 'https://outside.example.test/', 'text/html' )['reason'] );
					$this->assertSame( 'invalid-accept', HSPSC_Page::warm_public_url( home_url( '/' ), null )['reason'] );
					$this->assertSame( '', HSPSC_Page::validate_public_warming_url( home_url( '/wp-sitemap.xml' ), true ) );
					$this->assertSame( 'excluded', HSPSC_Page::validate_public_warming_url( home_url( '/wp-admin/options.php' ), true ) );
					$this->assertSame( 'private-page', HSPSC_Page::validate_public_warming_url( add_query_arg( 'page_id', $cart, home_url( '/' ) ), true ) );
				}
			);
		} finally {
			remove_filter( 'hspsc_private_commerce_page_ids', $private_pages );
		}
	}

	public function test_warming_verifies_exact_fresh_nonempty_file_without_forwarding_request_credentials() {
		$url    = home_url( '/warm-exact/?lang=de' );
		$accept = 'text/html,image/webp';
		$body   = '<html>Verified public representation</html>';
		$file   = $this->public_file( $url, $accept );
		$this->store_file( $this->public_file( $url, 'text/html' ), $body );
		$_SERVER['REQUEST_URI']        = '/wp-admin/admin-post.php';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fixture-token';
		$_SERVER['HTTP_ACCEPT']        = 'text/html';
		$_COOKIE                      = array( 'wordpress_logged_in_fixture' => 'fixture-cookie' );
		$_GET                         = array( 'action' => 'hspsc_rebuild_all' );
		$server = $_SERVER;
		$cookies = $_COOKIE;
		$query = $_GET;
		$this->with_http(
			function ( $preempt, $args, $target ) use ( $url, $accept, $body ) {
				$this->assertSame( $url, $target );
				$this->assertSame( $accept, $args['headers']['Accept'] );
				$this->assertSame( 'identity', $args['headers']['Accept-Encoding'] );
				$this->assertSame( array(), $args['cookies'] );
				$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
				$this->assertArrayNotHasKey( 'Cookie', $args['headers'] );
				$this->assertSame( 0, $args['redirection'] );
				$this->assertSame( 30, $args['timeout'] );
				$this->assertSame( HSPSC_Page::WARMING_RESPONSE_LIMIT + 1, $args['limit_response_size'] );
				return $this->response( $body );
			},
			function () use ( $url, $accept, $file, $body ) {
				$this->assertSame( 'cache-missing', HSPSC_Page::warm_public_url( $url, $accept, 999 )['reason'] );
				$this->store_file( $file, '' );
				$this->assertSame( 'cache-missing', HSPSC_Page::warm_public_url( $url, $accept, 999 )['reason'] );
				$this->store_file( $file, $body );
				touch( $file, time() - HOUR_IN_SECONDS - 10 );
				$this->assertSame( 'cache-missing', HSPSC_Page::warm_public_url( $url, $accept, 999 )['reason'] );
				$this->store_file( $file, '<html>Different HTML</html>' );
				$this->assertSame( 'cache-mismatch', HSPSC_Page::warm_public_url( $url, $accept, 999 )['reason'] );
				$this->store_file( $file, $body );
				$this->assertSame( array( 'status' => 'cached', 'reason' => 'cached' ), HSPSC_Page::warm_public_url( $url, $accept, 999 ) );
			}
		);
		$this->assertSame( $server, $_SERVER );
		$this->assertSame( $cookies, $_COOKIE );
		$this->assertSame( $query, $_GET );
	}

	/** @dataProvider unsuccessful_warming_responses */
	public function test_response_headers_cannot_overrule_failures_or_privacy( $code, $headers, $status, $reason ) {
		$url    = home_url( '/warm-response-guard/' );
		$accept = 'text/html';
		$body   = '<html>Existing public entry</html>';
		$this->store_file( $this->public_file( $url, $accept ), $body );
		$result = $this->with_http(
			function () use ( $body, $headers, $code ) {
				return null === $code ? new WP_Error( 'http_request_failed', 'fixture timeout' ) : $this->response( $body, $headers, $code );
			},
			static function () use ( $url, $accept ) {
				return HSPSC_Page::warm_public_url( $url, $accept );
			}
		);
		$this->assertSame( array( 'status' => $status, 'reason' => $reason ), $result );
	}

	public static function unsuccessful_warming_responses() {
		return array(
			'timeout'       => array( null, array(), 'failed', 'request-error' ),
			'redirect'      => array( 302, array( 'location' => '/another/' ), 'failed', 'response-status' ),
			'HTTP error'    => array( 500, array(), 'failed', 'response-status' ),
			'Markdown'      => array( 200, array( 'content-type' => 'text/markdown' ), 'failed', 'response-type' ),
			'encoded'       => array( 200, array( 'content-encoding' => 'gzip' ), 'failed', 'response-encoding' ),
			'cookie'        => array( 200, array( 'set-cookie' => 'session=fixture' ), 'skipped', 'private-response' ),
			'private'       => array( 200, array( 'cache-control' => 'private' ), 'skipped', 'private-response' ),
			'no store'      => array( 200, array( 'cache-control' => 'private, no-cache, max-age=600, must-revalidate, no-store' ), 'skipped', 'private-response' ),
			'cookie vary'   => array( 200, array( 'vary' => 'Accept, Cookie' ), 'skipped', 'private-response' ),
			'write failure' => array( 200, array( 'x-hspsc-cache' => 'BYPASS', 'x-hspsc-cache-reason' => 'write-failed' ), 'failed', 'write-failed' ),
			'private veto'  => array( 200, array( 'x-hspsc-cache' => 'BYPASS', 'x-hspsc-cache-reason' => 'late-private-response' ), 'skipped', 'private-response' ),
			'missing status' => array( 200, array( 'x-hspsc-cache' => '' ), 'failed', 'cache-bypass' ),
		);
	}

	public function test_public_purges_advance_generation_and_preserve_other_cache_types() {
		$url     = home_url( '/warm-clear-one/' );
		$public  = $this->public_file( $url, 'text/html' );
		$variant = $this->public_file( $url, 'text/html,image/webp' );
		$other   = $this->public_file( home_url( '/warm-clear-two/' ), 'text/html' );
		$private = HSPSC_PATH . '/pages/private/' . substr( basename( $public ), 0, 32 ) . '-user-' . md5( 'session' ) . '-' . md5( 'text/html' ) . '.php';
		$asset   = HSPSC_PATH . '/assets/public-rebuild-preserved.min.css';
		$object  = HSPSC_PATH . '/object/public-rebuild-preserved.php';
		foreach ( array( $public, $variant, $other, $asset ) as $file ) {
			$this->store_file( $file, 'owned test fixture' );
		}
		foreach ( array( $private, $object ) as $file ) {
			$this->store_file( $file, HSPSC_Page::PRIVATE_FILE_GUARD . 'protected fixture' );
		}
		$before = $this->invoke_page( 'get_cache_generation' );
		$this->assertTrue( HSPSC_Page::clear_public_cache_for_url( $url ) );
		$this->assertNotSame( $before, $this->invoke_page( 'get_cache_generation' ) );
		$this->assertFileDoesNotExist( $public );
		$this->assertFileDoesNotExist( $variant );
		$this->assertFileExists( $other );
		$this->assertTrue( HSPSC_Page::clear_public_cache() );
		$this->assertFileDoesNotExist( $other );
		foreach ( array( $private, $asset, $object ) as $file ) {
			$this->assertFileExists( $file );
		}
	}

	public function test_streamed_socket_chunks_are_decoded_and_temporary_files_are_deleted() {
		$url    = home_url( '/streamed-warming/' );
		$accept = 'text/html';
		$body   = '<html>Streamed public body</html>';
		$this->store_file( $this->public_file( $url, $accept ), $body );
		$files = array();
		$mode  = 'socket';
		$this->with_http(
			function ( $preempt, $args, $target ) use ( $url, $body, &$mode, &$files ) {
				$this->assertSame( $url, $target );
				$this->assertTrue( $args['stream'] );
				$this->assertFileExists( $args['filename'] );
				$this->assertStringNotContainsString( wp_normalize_path( ABSPATH ), wp_normalize_path( $args['filename'] ) );
				$files[] = $args['filename'];
				$headers = 'encoded' === $mode ? array( 'content-encoding' => 'gzip' ) : array();
				$reply   = $this->response( '', $headers );
				$wire    = new \WpOrg\Requests\Response();
				$wire->raw = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n";
				$reply['http_response'] = new WP_HTTP_Requests_Response( $wire, $args['filename'] );
				if ( 'curl' === $mode ) {
					// WordPress emits this hook for the transport that removes chunk framing.
					do_action( 'http_api_curl', null, $args, $target );
				}
				$first = substr( $body, 0, 10 );
				$rest  = substr( $body, 10 );
				$chunks = dechex( strlen( $first ) ) . "\r\n" . $first . "\r\n"
					. dechex( strlen( $rest ) ) . "\r\n" . $rest . "\r\n0\r\n\r\n";
				file_put_contents( $args['filename'], 'socket' === $mode ? $chunks : $body );
				return $reply;
			},
			function () use ( $url, $accept, &$mode, &$files ) {
				foreach ( array( 'socket', 'curl' ) as $mode ) {
					$this->assertSame( array( 'status' => 'cached', 'reason' => 'cached' ), HSPSC_Page::warm_public_url( $url, $accept ) );
					$this->assertFileDoesNotExist( end( $files ) );
				}
				$mode = 'encoded';
				$this->assertSame( array( 'status' => 'failed', 'reason' => 'response-encoding' ), HSPSC_Page::warm_public_url( $url, $accept ) );
				$this->assertFileDoesNotExist( end( $files ) );
			}
		);
	}

	public function test_partial_wpml_context_requires_a_valid_fresh_proof() {
		$screen = get_current_screen();
		$hooks  = array(
			'wpml_current_language'  => static function () { return 'en'; },
			'wpml_default_language'  => static function () { return 'en'; },
			'wpml_active_languages'  => static function () { return array( 'en' => array() ); },
			'wpml_permalink'         => static function ( $url ) { return $url; },
		);
		foreach ( $hooks as $hook => $callback ) {
			add_filter( $hook, $callback );
		}
		set_current_screen( 'dashboard' );
		$url    = home_url( '/default-language-inner-page/' );
		$accept = 'text/html,image/webp';
		$body   = '<html>Verified default language body</html>';
		$mode   = 'missing';
		$replay = '';
		try {
			$this->assertNull( HSPSC_WPML::url_context( $url ) );
			$file = $this->public_file( $url, $accept, 'en' );
			$this->store_file( $file, $body );
			$this->assertTrue( HSPSC_Page::clear_public_cache_for_url( $url ) );
			$this->assertFileDoesNotExist( $file );
			$this->store_file( $file, $body );
			$this->with_http(
				function ( $preempt, $args ) use ( $file, $body, &$mode, &$replay ) {
					$challenge = $args['headers']['X-HSPSC-Warm-Check'];
					$this->assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/', $challenge );
					$etag  = $this->invoke_page( 'get_content_etag', $file, $body );
					$proof = $this->invoke_page( 'get_warming_proof', $challenge, $file, $etag );
					if ( 'missing' === $mode ) {
						$replay = $proof;
						return $this->response( $body );
					}
					if ( 'spoofed' === $mode ) {
						$proof = str_repeat( '0', 64 );
					} elseif ( 'replayed' === $mode ) {
						$proof = $replay;
					}
					return $this->response( $body, array( 'x-hspsc-warm-proof' => $proof ) );
				},
				function () use ( $url, $accept, &$mode ) {
					foreach ( array( 'missing', 'spoofed', 'replayed' ) as $mode ) {
						$this->assertSame( array( 'status' => 'skipped', 'reason' => 'unresolved-language' ), HSPSC_Page::warm_public_url( $url, $accept ) );
					}
					$mode = 'valid';
					$this->assertSame( array( 'status' => 'cached', 'reason' => 'cached' ), HSPSC_Page::warm_public_url( $url, $accept ) );
				}
			);
		} finally {
			foreach ( $hooks as $hook => $callback ) {
				remove_filter( $hook, $callback );
			}
			set_current_screen( $screen ?: 'front' );
		}
	}
}
