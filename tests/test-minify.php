<?php

class HSPSC_Minify_Test extends WP_UnitTestCase {
    protected function maybe_minify_asset( $src, $type ) {
        $ref = new ReflectionClass( 'HSPSC_Minify' );
        $method = $ref->getMethod( 'maybe_minify_asset' );
        $method->setAccessible( true );
        return $method->invoke( null, $src, $type );
    }

    public function test_minify_html_removes_comments_and_whitespace() {
        $input  = "<div>  Test </div>\n<!-- comment -->";
        $output = HSPSC_Minify::minify_html( $input );

        $this->assertStringNotContainsString( 'comment', $output );
        $this->assertStringNotContainsString( "\n", $output );
    }

    public function test_minify_css_shortens_content() {
        $css     = "body { color: red; }";
        $min_css = HSPSC_Minify::minify_css( $css );

        $this->assertLessThanOrEqual( strlen( $css ), strlen( $min_css ) );
    }

    public function test_minify_js_shortens_content() {
        $js     = "function t(){ return 1; }";
        $min_js = HSPSC_Minify::minify_js( $js );

        $this->assertLessThanOrEqual( strlen( $js ), strlen( $min_js ) );
    }

    public function test_minify_html_minifies_inline_css_and_js() {
        $input  = '<style>body { color: red; }</style><script>/*c*/var a=1;</script>';
        $output = HSPSC_Minify::minify_html( $input );

        $this->assertStringContainsString( 'body{color:red}', $output );
        $this->assertStringNotContainsString( '/*c*/', $output );
    }

    public function test_external_asset_urls_are_not_minified_as_local_files() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'minify_css' => true,
                    'optimize_logged_in' => true,
                )
            )
        );

        $src = 'https://cdn.example.com/wp-content/themes/theme/style.css?ver=1';

        $this->assertSame( $src, $this->maybe_minify_asset( $src, 'css' ) );
    }
}
