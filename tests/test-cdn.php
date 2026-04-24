<?php

class HSPSC_CDN_Test extends WP_UnitTestCase {
    private $original_request_uri;
    private $original_request_method;
    private $original_pagenow;

    public function set_up(): void {
        parent::set_up();

        $this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
        $this->original_request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
        $this->original_pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;

        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset( $GLOBALS['pagenow'] );

        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'cdn_enabled' => true,
                    'cdn_url'     => 'https://cdn.example.com',
                )
            )
        );
    }

    public function tear_down(): void {
        if ( null === $this->original_request_uri ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $this->original_request_uri;
        }

        if ( null === $this->original_request_method ) {
            unset( $_SERVER['REQUEST_METHOD'] );
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->original_request_method;
        }

        if ( null === $this->original_pagenow ) {
            unset( $GLOBALS['pagenow'] );
        } else {
            $GLOBALS['pagenow'] = $this->original_pagenow;
        }

        parent::tear_down();
    }

    public function test_rewrite_url_rewrites_content_asset_to_cdn() {
        $url = content_url( '/themes/twentytwenty/style.css?ver=1.0' );

        $rewritten = HSPSC_CDN::rewrite_url( $url );

        $this->assertStringStartsWith( 'https://cdn.example.com/', $rewritten );
        $this->assertStringContainsString( '/themes/twentytwenty/style.css', $rewritten );
    }

    public function test_rewrite_url_rewrites_site_asset_to_cdn() {
        $url = site_url( '/wp-content/uploads/2026/01/image.webp' );

        $rewritten = HSPSC_CDN::rewrite_url( $url );

        $this->assertStringStartsWith( 'https://cdn.example.com/', $rewritten );
        $this->assertStringContainsString( '/uploads/2026/01/image.webp', $rewritten );
    }

    public function test_rewrite_url_does_not_rewrite_non_static_file() {
        $url = content_url( '/api/data.json' );

        $rewritten = HSPSC_CDN::rewrite_url( $url );

        $this->assertSame( $url, $rewritten );
    }
}
