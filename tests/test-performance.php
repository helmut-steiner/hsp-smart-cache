<?php

class HSP_Smart_Cache_Performance_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        update_option( HSP_Smart_Cache_Settings::OPTION_KEY, array_merge( HSP_Smart_Cache_Settings::defaults(), array(
            'perf_lazy_images' => true,
            'perf_lazy_iframes' => true,
            'perf_decoding_async' => true,
        ) ) );
    }

    public function test_lazy_loading_filters() {
        $img = HSP_Smart_Cache_Performance::filter_lazy_loading( true, 'img' );
        $iframe = HSP_Smart_Cache_Performance::filter_lazy_loading( true, 'iframe' );
        $this->assertTrue( $img );
        $this->assertTrue( $iframe );
    }

    public function test_decoding_async_added() {
        $attr = HSP_Smart_Cache_Performance::filter_image_attributes( array(), null, 'full' );
        $this->assertArrayHasKey( 'decoding', $attr );
        $this->assertSame( 'async', $attr['decoding'] );
    }

    public function test_dns_prefetch_hints_added() {
        update_option( HSP_Smart_Cache_Settings::OPTION_KEY, array_merge( HSP_Smart_Cache_Settings::defaults(), array(
            'perf_dns_prefetch_urls' => "//example.com\n//cdn.example.com",
        ) ) );
        $hints = HSP_Smart_Cache_Performance::add_dns_prefetch_hints( array(), 'dns-prefetch' );
        $this->assertContains( '//example.com', $hints );
        $this->assertContains( '//cdn.example.com', $hints );
    }
}
