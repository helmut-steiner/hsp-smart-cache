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

    public function test_rewrite_url_rewrites_modern_media_assets_to_cdn() {
        $urls = array(
            content_url( '/uploads/2026/01/image.avif?ver=1' ),
            content_url( '/uploads/2026/01/video.mp4' ),
            content_url( '/uploads/2026/01/audio.m4a' ),
        );

        foreach ( $urls as $url ) {
            $rewritten = HSPSC_CDN::rewrite_url( $url );

            $this->assertStringStartsWith( 'https://cdn.example.com/', $rewritten );
        }
    }

    public function test_rewrite_url_does_not_rewrite_non_static_file() {
        $url = content_url( '/api/data.json' );

        $rewritten = HSPSC_CDN::rewrite_url( $url );

        $this->assertSame( $url, $rewritten );
    }

	/**
	 * A disabled feature must not repeat frontend integration discovery per URL.
	 */
	public function test_disabled_cdn_skips_frontend_eligibility_checks() {
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		$this->go_to( home_url( '/' ) );
		wp_set_current_user( 0 );
		$this->assertTrue( HSPSC_Utils::should_apply_frontend_optimizations() );
		$calls = 0;
		$count = static function ( $eligible ) use ( &$calls ) {
			++$calls;
			return $eligible;
		};
		add_filter( 'hspsc_should_apply_frontend_optimizations', $count );
		try {
			$url = content_url( '/themes/example/style.css' );
			$this->assertSame( $url, HSPSC_CDN::rewrite_url( $url ) );
			$this->assertSame( $url, HSPSC_CDN::rewrite_script_url( $url, 'example' ) );
			$this->assertSame( 0, $calls );
		} finally {
			remove_filter( 'hspsc_should_apply_frontend_optimizations', $count );
		}
	}

	/**
	 * The full source filter preserves language scripts before CDN rewriting.
	 */
	public function test_script_loader_src_preserves_wpml_sources_and_rewrites_eligible_scripts() {
		$url     = content_url( '/plugins/hspsc-cdn-fixture/language.js?lang=de' );
		$handles = array(
			'wpml-browser-redirect',
			'wcml-multi-currency',
			'sitepress-language-switcher',
		);

		foreach ( $handles as $handle ) {
			$this->assertSame( $url, apply_filters( 'script_loader_src', $url, $handle ) );
		}

		$this->assertSame(
			'https://cdn.example.com/plugins/hspsc-cdn-fixture/language.js?lang=de',
			apply_filters( 'script_loader_src', $url, 'hspsc-cdn-ordinary-script' )
		);
	}
}
