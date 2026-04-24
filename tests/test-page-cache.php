<?php

class HSPSC_Page_Test extends WP_UnitTestCase {
    protected function get_cache_path_for_url( $url ) {
        $ref = new ReflectionClass( 'HSPSC_Page' );
        $method = $ref->getMethod( 'get_cache_file_path_for_url' );
        $method->setAccessible( true );
        return $method->invoke( null, $url );
    }

    public function test_clear_cache_for_url_removes_file() {
        HSPSC_Utils::ensure_cache_dirs();
        $url = home_url( '/sample-page/' );
        $file = $this->get_cache_path_for_url( $url );

        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSPSC_Page::clear_cache_for_url( $url );
        $this->assertFileDoesNotExist( $file );
    }

    public function test_clear_cache_for_post_clears_permalink() {
        $post_id = self::factory()->post->create( array(
            'post_title' => 'Cache Test',
            'post_status' => 'publish',
        ) );
        $url = get_permalink( $post_id );
        $file = $this->get_cache_path_for_url( $url );

        HSPSC_Utils::ensure_cache_dirs();
        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSPSC_Page::clear_cache_for_post( $post_id );
        $this->assertFileDoesNotExist( $file );
    }

    public function test_warm_url_with_timeout_issues_request_when_page_cache_enabled() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'page_cache' => true ) )
        );

        $captured = array();
        $mock_http = static function( $preempt, $args, $url ) use ( &$captured ) {
            $captured[] = array( 'url' => $url, 'args' => $args );
            return array(
                'headers'  => array(),
                'body'     => 'ok',
                'response' => array( 'code' => 200, 'message' => 'OK' ),
                'cookies'  => array(),
                'filename' => null,
            );
        };

        add_filter( 'pre_http_request', $mock_http, 10, 3 );
        try {
            HSPSC_Page::warm_url_with_timeout( home_url( '/warm-me/' ), 4 );
        } finally {
            remove_filter( 'pre_http_request', $mock_http, 10 );
        }

        $this->assertCount( 1, $captured );
        $this->assertSame( home_url( '/warm-me/' ), $captured[0]['url'] );
        $this->assertSame( 4, $captured[0]['args']['timeout'] );
    }

    public function test_warm_urls_calls_remote_get_for_each_url() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'page_cache' => true ) )
        );

        $urls = array( home_url( '/a/' ), home_url( '/b/' ) );
        $captured = array();
        $mock_http = static function( $preempt, $args, $url ) use ( &$captured ) {
            $captured[] = $url;
            return array(
                'headers'  => array(),
                'body'     => 'ok',
                'response' => array( 'code' => 200, 'message' => 'OK' ),
                'cookies'  => array(),
                'filename' => null,
            );
        };

        add_filter( 'pre_http_request', $mock_http, 10, 3 );
        try {
            HSPSC_Page::warm_urls( $urls );
        } finally {
            remove_filter( 'pre_http_request', $mock_http, 10 );
        }

        $this->assertCount( 2, $captured );
        $this->assertSame( $urls, $captured );
    }
}
