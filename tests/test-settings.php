<?php

class HSP_Smart_Cache_Settings_Test extends WP_UnitTestCase {
    public function test_defaults_are_present() {
        $defaults = HSP_Smart_Cache_Settings::defaults();

        $this->assertArrayHasKey( 'page_cache', $defaults );
        $this->assertArrayHasKey( 'page_cache_ttl', $defaults );
        $this->assertArrayHasKey( 'cache_logged_in', $defaults );
        $this->assertArrayHasKey( 'minify_html', $defaults );
        $this->assertArrayHasKey( 'minify_css', $defaults );
        $this->assertArrayHasKey( 'minify_js', $defaults );
        $this->assertArrayHasKey( 'object_cache', $defaults );
        $this->assertArrayHasKey( 'cdn_enabled', $defaults );
        $this->assertArrayHasKey( 'cdn_url', $defaults );
        $this->assertArrayHasKey( 'perf_lazy_images', $defaults );
        $this->assertArrayHasKey( 'perf_lazy_iframes', $defaults );
        $this->assertArrayHasKey( 'perf_decoding_async', $defaults );
        $this->assertArrayHasKey( 'perf_disable_emojis', $defaults );
        $this->assertArrayHasKey( 'perf_disable_embeds', $defaults );
        $this->assertArrayHasKey( 'perf_disable_dashicons', $defaults );
        $this->assertArrayHasKey( 'perf_dns_prefetch_urls', $defaults );
        $this->assertArrayHasKey( 'preload_enabled', $defaults );
        $this->assertArrayHasKey( 'preload_sitemap_url', $defaults );
        $this->assertArrayHasKey( 'preload_limit', $defaults );
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
            'cdn_enabled'    => '1',
            'cdn_url'        => 'https://cdn.example.com',
        );

        $sanitized = HSP_Smart_Cache_Settings::sanitize( $input );

        $this->assertTrue( $sanitized['page_cache'] );
        $this->assertGreaterThanOrEqual( 60, $sanitized['page_cache_ttl'] );
        $this->assertTrue( $sanitized['cache_logged_in'] );
        $this->assertFalse( $sanitized['minify_html'] );
    }

    public function test_static_asset_rules_generation() {
        $rules = HSP_Smart_Cache_Static_Assets::get_htaccess_rules( 600, true, true );
        $this->assertStringContainsString( 'Cache-Control', $rules );
        $this->assertStringContainsString( 'max-age=600', $rules );
        $this->assertStringContainsString( 'immutable', $rules );
        $this->assertStringContainsString( 'DEFLATE', $rules );
    }
}
