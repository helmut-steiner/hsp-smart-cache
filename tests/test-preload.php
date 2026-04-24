<?php

class HSPSC_Preload_Test extends WP_UnitTestCase {
    public function test_run_returns_not_ok_when_preload_disabled() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'preload_enabled' => false ) )
        );

        $result = HSPSC_Preload::run();

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 0, $result['count'] );
    }

    public function test_run_warms_urls_from_sitemap_up_to_limit() {
        $sitemap_url = home_url( '/sitemap.xml' );
        $warmed_urls = array();

        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'page_cache'         => true,
                    'preload_enabled'    => true,
                    'preload_sitemap_url'=> $sitemap_url,
                    'preload_limit'      => 2,
                    'preload_timeout'    => 5,
                )
            )
        );

        $mock_http = static function( $preempt, $args, $url ) use ( &$warmed_urls, $sitemap_url ) {
            if ( $url === $sitemap_url ) {
                return array(
                    'headers'  => array(),
                    'body'     => '<urlset><url><loc>' . esc_url_raw( home_url( '/a/' ) ) . '</loc></url><url><loc>' . esc_url_raw( home_url( '/b/' ) ) . '</loc></url><url><loc>' . esc_url_raw( home_url( '/c/' ) ) . '</loc></url></urlset>',
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'cookies'  => array(),
                    'filename' => null,
                );
            }

            $warmed_urls[] = $url;

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
            $result = HSPSC_Preload::run();
        } finally {
            remove_filter( 'pre_http_request', $mock_http, 10 );
        }

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 2, $result['count'] );
        $this->assertCount( 2, $warmed_urls );
        $this->assertContains( home_url( '/a/' ), $warmed_urls );
        $this->assertContains( home_url( '/b/' ), $warmed_urls );
    }
}
