<?php

class HSPSC_Runtime_Regressions_Test extends WP_UnitTestCase {
	public function test_critical_css_preserves_css_syntax_and_rejects_arrays() {
		$css = '.hero > h1, [data-state="open"] { content: "<example>"; }';
		$settings = HSPSC_Settings::sanitize( array( 'render_critical_css' => $css ) );
		$this->assertSame( $css, $settings['render_critical_css'] );
		$settings = HSPSC_Settings::sanitize( array( 'render_critical_css' => array( $css ) ) );
		$this->assertSame( '', $settings['render_critical_css'] );
	}
	public function test_failed_release_checks_are_cached_and_retry_after_expiry() {
		$method = new ReflectionMethod( 'HSPSC_Updater', 'get_latest_release' );
		$requests = 0;
		$response = array( 'response' => array( 'code' => 403 ), 'body' => '{}' );
		$http = static function ( $pre, $args, $url ) use ( &$requests, &$response ) {
			if ( 'https://api.github.com/repos/helmut-steiner/hsp-smart-cache/releases/latest' === $url ) {
				++$requests;
				return $response;
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			foreach ( array( $response, new WP_Error( 'http_request_failed', 'Timeout' ), array( 'response' => array( 'code' => 200 ), 'body' => '{}' ) ) as $failure ) {
				delete_site_transient( HSPSC_Updater::RELEASE_TRANSIENT );
				$response = $failure;
				$before = $requests;
				$this->assertSame( array(), $method->invoke( null ) );
				$this->assertSame( array(), $method->invoke( null ) );
				$this->assertSame( $before + 1, $requests );
			}
			delete_site_transient( HSPSC_Updater::RELEASE_TRANSIENT );
			$response = array( 'response' => array( 'code' => 200 ), 'body' => '{"tag_name":"v9.9.9"}' );
			$this->assertSame( 'v9.9.9', $method->invoke( null )['tag_name'] );
		} finally {
			remove_filter( 'pre_http_request', $http, 10 );
		}
	}

	public function tear_down(): void {
		delete_site_transient( HSPSC_Updater::RELEASE_TRANSIENT );
		HSPSC_Settings::reset_cache();
		parent::tear_down();
	}
}
