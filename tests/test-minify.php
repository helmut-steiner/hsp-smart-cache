<?php

class HSPSC_Minify_Test extends WP_UnitTestCase {
    protected function maybe_minify_asset( $src, $type ) {
        $ref = new ReflectionClass( 'HSPSC_Minify' );
        $method = $ref->getMethod( 'maybe_minify_asset' );
        $method->setAccessible( true );
        return $method->invoke( null, $src, $type );
    }

    protected function reset_asset_url_cache() {
        $ref = new ReflectionClass( 'HSPSC_Minify' );
        $property = $ref->getProperty( 'asset_url_cache' );
        $property->setAccessible( true );
        $property->setValue( null, array() );
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

    public function test_jsx_asset_is_not_minified_by_default() {
        $this->reset_asset_url_cache();
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'minify_js'          => true,
                    'optimize_logged_in' => true,
                )
            )
        );

        $file = WP_CONTENT_DIR . '/hspsc-minify-test.jsx';
        file_put_contents( $file, 'const Element = <div />;' );
        $src = content_url( '/hspsc-minify-test.jsx?ver=1' );

        try {
            $this->assertSame( $src, $this->maybe_minify_asset( $src, 'js' ) );
        } finally {
            wp_delete_file( $file );
        }
    }

    public function test_asset_roots_filter_can_reject_otherwise_local_asset() {
        $this->reset_asset_url_cache();
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'minify_css'         => true,
                    'optimize_logged_in' => true,
                )
            )
        );

        $file = WP_CONTENT_DIR . '/hspsc-minify-root-test.css';
        file_put_contents( $file, 'body { color: red; }' );
        $src = content_url( '/hspsc-minify-root-test.css?ver=1' );

        $filter = function() {
            return array( sys_get_temp_dir() . '/hspsc-nonexistent-root' );
        };
        add_filter( 'hspsc_minify_asset_roots', $filter );

        try {
            $this->assertSame( $src, $this->maybe_minify_asset( $src, 'css' ) );
        } finally {
            remove_filter( 'hspsc_minify_asset_roots', $filter );
            wp_delete_file( $file );
        }
    }

    public function test_asset_larger_than_configured_limit_is_not_minified() {
        $this->reset_asset_url_cache();
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'minify_css'         => true,
                    'optimize_logged_in' => true,
                )
            )
        );

        $file = WP_CONTENT_DIR . '/hspsc-minify-large-test.css';
        file_put_contents( $file, 'body { color: red; }' );
        $src = content_url( '/hspsc-minify-large-test.css?ver=1' );

        $filter = function() {
            return 1;
        };
        add_filter( 'hspsc_max_minify_asset_size', $filter );

        try {
            $this->assertSame( $src, $this->maybe_minify_asset( $src, 'css' ) );
        } finally {
            remove_filter( 'hspsc_max_minify_asset_size', $filter );
            wp_delete_file( $file );
        }
    }
}
