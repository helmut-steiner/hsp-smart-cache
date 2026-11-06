<?php

class HSPSC_Settings_Test extends WP_UnitTestCase {
    public function test_defaults_are_present() {
        $defaults = HSPSC_Settings::defaults();

        $this->assertArrayHasKey( 'page_cache', $defaults );
        $this->assertArrayHasKey( 'page_cache_ttl', $defaults );
        $this->assertSame( '', $defaults['page_cache_excluded_paths'] );
        $this->assertArrayHasKey( 'cache_logged_in', $defaults );
        $this->assertArrayHasKey( 'minify_html', $defaults );
        $this->assertArrayHasKey( 'minify_css', $defaults );
        $this->assertArrayHasKey( 'minify_js', $defaults );
        $this->assertArrayHasKey( 'object_cache', $defaults );
        $this->assertArrayHasKey( 'object_cache_default_ttl', $defaults );
        $this->assertArrayHasKey( 'object_cache_max_ttl', $defaults );
        $this->assertArrayHasKey( 'cdn_enabled', $defaults );
        $this->assertArrayHasKey( 'cdn_url', $defaults );
        $this->assertArrayHasKey( 'perf_lazy_images', $defaults );
        $this->assertArrayHasKey( 'perf_lazy_iframes', $defaults );
        $this->assertArrayHasKey( 'perf_decoding_async', $defaults );
        $this->assertArrayHasKey( 'perf_disable_emojis', $defaults );
        $this->assertArrayHasKey( 'perf_disable_embeds', $defaults );
        $this->assertArrayHasKey( 'perf_disable_dashicons', $defaults );
        $this->assertArrayHasKey( 'perf_dns_prefetch_urls', $defaults );
        $this->assertArrayNotHasKey( 'preload_enabled', $defaults );
        $this->assertArrayHasKey( 'preload_sitemap_url', $defaults );
        $this->assertArrayNotHasKey( 'preload_limit', $defaults );
        $this->assertArrayHasKey( 'preload_timeout', $defaults );
    }

    public function test_sanitize_applies_defaults_and_limits() {
        $input = array(
            'page_cache'     => '1',
            'page_cache_ttl' => '5',
            'cache_logged_in' => '1',
            'minify_html'    => '',
            'minify_css'     => '1',
            'minify_js'      => '1',
            'object_cache'   => '1',
            'object_cache_default_ttl' => '5',
            'object_cache_max_ttl'     => '10',
            'cdn_enabled'    => '1',
            'cdn_url'        => 'https://cdn.example.com',
        );

        $sanitized = HSPSC_Settings::sanitize( $input );

        $this->assertTrue( $sanitized['page_cache'] );
        $this->assertGreaterThanOrEqual( 60, $sanitized['page_cache_ttl'] );
        $this->assertTrue( $sanitized['cache_logged_in'] );
        $this->assertFalse( $sanitized['minify_html'] );
        $this->assertSame( 5, $sanitized['object_cache_default_ttl'] );
        $this->assertSame( 10, $sanitized['object_cache_max_ttl'] );
    }

    public function test_static_asset_rules_generation() {
        $rules = HSPSC_Static_Assets::get_htaccess_rules( 600, true, true );
        $this->assertStringContainsString( 'Cache-Control', $rules );
        $this->assertStringContainsString( 'max-age=600', $rules );
        $this->assertStringContainsString( 'immutable', $rules );
        $this->assertStringContainsString( 'DEFLATE', $rules );
    }

    public function test_ensure_defaults_merges_missing_keys() {
        update_option( HSPSC_Settings::OPTION_KEY, array( 'page_cache' => false ) );

        HSPSC_Settings::ensure_defaults();

        $options = get_option( HSPSC_Settings::OPTION_KEY );
        $this->assertIsArray( $options );
        $this->assertArrayHasKey( 'minify_html', $options );
        $this->assertFalse( $options['page_cache'] );
    }

    public function test_get_returns_default_for_unknown_key() {
        $value = HSPSC_Settings::get( 'nonexistent_key', 'fallback' );
        $this->assertSame( 'fallback', $value );
    }

    public function test_page_cache_exclusions_are_exact_normalized_paths() {
        $input = "/booking\r\n /booking/ \n/gr%C3%BCn//\n/subsite/./member/../profile/\n/\nhttps://example.org/private/\n//example.org/private/\n/booking/?x=1\n/booking/#part\n/private/*\n/encoded%3Fquery/";
        $result = HSPSC_Settings::sanitize_page_cache_excluded_paths( $input );
        $this->assertSame( "/booking/\n/grün/\n/subsite/profile/\n/", $result );
        $this->assertSame( '', HSPSC_Settings::sanitize_page_cache_excluded_paths( array( '/private/' ) ) );
        $this->assertSame( '', HSPSC_Settings::sanitize_page_cache_excluded_paths( 123 ) );
        $settings = HSPSC_Settings::sanitize( array( 'page_cache_excluded_paths' => "/booking\n/booking/" ) );
        $this->assertSame( '/booking/', $settings['page_cache_excluded_paths'] );
    }

    /**
     * Saving an exclusion again must preserve literal percent-encoded text.
     *
     * @dataProvider repeatable_page_cache_paths
     */
    public function test_page_cache_exclusion_sanitization_is_repeatable( $input, $expected ) {
        $saved = HSPSC_Settings::sanitize_page_cache_excluded_paths( $input );
        $this->assertSame( $expected, $saved );
        $this->assertSame( $saved, HSPSC_Settings::sanitize_page_cache_excluded_paths( $saved ) );
        $this->assertSame( $saved, HSPSC_Utils::normalize_page_cache_path( $saved ) );

        $settings = HSPSC_Settings::sanitize( array( 'page_cache_excluded_paths' => $saved ) );
        $settings = HSPSC_Settings::sanitize( $settings );
        $this->assertSame( $saved, $settings['page_cache_excluded_paths'] );
    }

    public function repeatable_page_cache_paths() {
        return array(
            'encoded space text' => array( '/offers%2520private', '/offers%2520private/' ),
            'encoded question mark text' => array( '/raw%253Fvalue/', '/raw%253Fvalue/' ),
            'percent followed by hex digits' => array( '/sale%25de/', '/sale%25de/' ),
            'nested percent escapes' => array( '/nested%252520text/', '/nested%252520text/' ),
            'literal percent' => array( '/discount%25/', '/discount%25/' ),
            'encoded slash text' => array( '/literal%252Fslash/', '/literal%252Fslash/' ),
            'encoded dot text' => array( '/%252e%252e/booking/', '/%252e%252e/booking/' ),
            'decoded dot segments' => array( '/other/%2e%2e/booking%2F/', '/booking/' ),
            'segment spaces' => array( '/%20booking%20', '/ booking /' ),
            'unicode' => array( '/gr%C3%BCn/', '/grün/' ),
        );
    }
}
