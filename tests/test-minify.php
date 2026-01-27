<?php

class HSP_Smart_Cache_Minify_Test extends WP_UnitTestCase {
    public function test_minify_html_removes_comments_and_whitespace() {
        $input  = "<div>  Test </div>\n<!-- comment -->";
        $output = HSP_Smart_Cache_Minify::minify_html( $input );

        $this->assertStringNotContainsString( 'comment', $output );
        $this->assertStringNotContainsString( "\n", $output );
    }

    public function test_minify_css_shortens_content() {
        $css     = "body { color: red; }";
        $min_css = HSP_Smart_Cache_Minify::minify_css( $css );

        $this->assertLessThanOrEqual( strlen( $css ), strlen( $min_css ) );
    }

    public function test_minify_js_shortens_content() {
        $js     = "function t(){ return 1; }";
        $min_js = HSP_Smart_Cache_Minify::minify_js( $js );

        $this->assertLessThanOrEqual( strlen( $js ), strlen( $min_js ) );
    }

    public function test_minify_html_minifies_inline_css_and_js() {
        $input  = '<style>body { color: red; }</style><script>/*c*/var a=1;</script>';
        $output = HSP_Smart_Cache_Minify::minify_html( $input );

        $this->assertStringContainsString( 'body{color:red}', $output );
        $this->assertStringNotContainsString( '/*c*/', $output );
    }
}
