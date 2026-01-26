<?php

class HSP_Cache_Render_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        update_option( HSP_Cache_Settings::OPTION_KEY, array_merge( HSP_Cache_Settings::defaults(), array(
            'render_defer_js' => true,
            'render_async_js' => false,
            'render_defer_exclusions' => '',
            'render_async_exclusions' => '',
        ) ) );
    }

    public function test_script_tag_gets_defer() {
        $tag = '<script src="/wp-includes/js/wp-embed.js"></script>';
        $filtered = HSP_Cache_Render::filter_script_tag( $tag, 'wp-embed', '/wp-includes/js/wp-embed.js' );
        $this->assertStringContainsString( 'defer', $filtered );
    }

    public function test_script_tag_exclusion_skips_defer() {
        update_option( HSP_Cache_Settings::OPTION_KEY, array_merge( HSP_Cache_Settings::defaults(), array(
            'render_defer_js' => true,
            'render_defer_exclusions' => "jquery\n",
        ) ) );
        $tag = '<script src="/wp-includes/js/jquery.js"></script>';
        $filtered = HSP_Cache_Render::filter_script_tag( $tag, 'jquery', '/wp-includes/js/jquery.js' );
        $this->assertStringNotContainsString( 'defer', $filtered );
    }

    public function test_script_tag_async_when_defer_off() {
        update_option( HSP_Cache_Settings::OPTION_KEY, array_merge( HSP_Cache_Settings::defaults(), array(
            'render_defer_js' => false,
            'render_async_js' => true,
        ) ) );
        $tag = '<script src="/wp-includes/js/wp-embed.js"></script>';
        $filtered = HSP_Cache_Render::filter_script_tag( $tag, 'wp-embed', '/wp-includes/js/wp-embed.js' );
        $this->assertStringContainsString( 'async', $filtered );
    }
}
