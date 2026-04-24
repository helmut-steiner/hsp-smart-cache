<?php

class HSPSC_Performance_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'perf_lazy_images' => true,
            'perf_lazy_iframes' => true,
            'perf_decoding_async' => true,
        ) ) );
    }

    public function test_lazy_loading_filters() {
        $img = HSPSC_Performance::filter_lazy_loading( true, 'img' );
        $iframe = HSPSC_Performance::filter_lazy_loading( true, 'iframe' );
        $this->assertTrue( $img );
        $this->assertTrue( $iframe );
    }

    public function test_decoding_async_added() {
        $attr = HSPSC_Performance::filter_image_attributes( array(), null, 'full' );
        $this->assertArrayHasKey( 'decoding', $attr );
        $this->assertSame( 'async', $attr['decoding'] );
    }

    public function test_dns_prefetch_hints_added() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'perf_dns_prefetch_urls' => "//example.com\n//cdn.example.com",
        ) ) );
        $hints = HSPSC_Performance::add_dns_prefetch_hints( array(), 'dns-prefetch' );
        $this->assertContains( '//example.com', $hints );
        $this->assertContains( '//cdn.example.com', $hints );
    }

    public function test_maybe_disable_emojis_adds_filters_and_removes_wpemoji_plugin() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'perf_disable_emojis' => true,
        ) ) );

        HSPSC_Performance::maybe_disable_emojis();

        $plugins = apply_filters( 'tiny_mce_plugins', array( 'wpemoji', 'lists' ) );
        $this->assertNotContains( 'wpemoji', $plugins );
    }

    public function test_maybe_disable_embeds_adds_embed_discover_filter() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'perf_disable_embeds' => true,
        ) ) );

        HSPSC_Performance::maybe_disable_embeds();

        $this->assertNotFalse( has_filter( 'embed_oembed_discover', '__return_false' ) );
    }

    public function test_maybe_disable_dashicons_dequeues_for_guests() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'perf_disable_dashicons' => true,
        ) ) );

        $this->go_to( home_url( '/' ) );
        wp_set_current_user( 0 );
        wp_dequeue_style( 'dashicons' );
        wp_deregister_style( 'dashicons' );
        wp_enqueue_style( 'dashicons', '/wp-includes/css/dashicons.css', array(), '1.0' );
        $this->assertTrue( wp_style_is( 'dashicons', 'enqueued' ) );

        HSPSC_Performance::maybe_disable_dashicons();

        global $wp_styles;
        $this->assertNotContains( 'dashicons', $wp_styles->queue );
    }
}
