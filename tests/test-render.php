<?php

class HSPSC_Render_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_defer_js' => true,
            'render_async_js' => false,
            'render_defer_exclusions' => '',
            'render_async_exclusions' => '',
        ) ) );
    }

    public function test_script_tag_gets_defer() {
        $tag = wp_get_script_tag( array( 'src' => '/wp-includes/js/wp-embed.js' ) );
        $filtered = HSPSC_Render::filter_script_tag( $tag, 'wp-embed', '/wp-includes/js/wp-embed.js' );
        $this->assertStringContainsString( 'defer', $filtered );
    }

    public function test_script_tag_exclusion_skips_defer() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_defer_js' => true,
            'render_defer_exclusions' => "jquery\n",
        ) ) );
        $tag = wp_get_script_tag( array( 'src' => '/wp-includes/js/jquery.js' ) );
        $filtered = HSPSC_Render::filter_script_tag( $tag, 'jquery', '/wp-includes/js/jquery.js' );
        $this->assertStringNotContainsString( 'defer', $filtered );
    }

    public function test_script_tag_async_when_defer_off() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_defer_js' => false,
            'render_async_js' => true,
        ) ) );
        $tag = wp_get_script_tag( array( 'src' => '/wp-includes/js/wp-embed.js' ) );
        $filtered = HSPSC_Render::filter_script_tag( $tag, 'wp-embed', '/wp-includes/js/wp-embed.js' );
        $this->assertStringContainsString( 'async', $filtered );
    }

    public function test_output_preconnects_prints_link_tags() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_preconnect_urls' => "https://fonts.gstatic.com\nhttps://cdn.example.com",
        ) ) );

        ob_start();
        HSPSC_Render::output_preconnects();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'rel="preconnect"', $output );
        $this->assertStringContainsString( 'https://fonts.gstatic.com', $output );
        $this->assertStringContainsString( 'https://cdn.example.com', $output );
    }

    public function test_output_preloads_prints_font_and_style_links() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_preload_fonts' => 'https://example.com/fonts/site.woff2',
            'render_preload_css'   => 'https://example.com/theme.css',
        ) ) );

        ob_start();
        HSPSC_Render::output_preloads();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'as="font"', $output );
        $this->assertStringContainsString( 'font/woff2', $output );
        $this->assertStringContainsString( 'as="style"', $output );
    }

    public function test_output_critical_css_prints_inline_style() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_critical_css' => 'body{opacity:1}',
        ) ) );

        ob_start();
        HSPSC_Render::output_critical_css();
        $output = ob_get_clean();

        $this->assertStringContainsString( '<style id="hsp-critical-css">', $output );
        $this->assertStringContainsString( 'body{opacity:1}', $output );
    }
}
