<?php

/**
 * Counts source I/O independently of generated-file and URL memoization hits.
 */
class HSPSC_Minify_Read_Counting_Fixture extends HSPSC_Minify {
	public static $source_reads = 0;

	public static function optimize( $src, $type ) {
		return parent::maybe_minify_asset( $src, $type );
	}

	protected static function read_asset_contents( $file_path ) {
		++self::$source_reads;
		return parent::read_asset_contents( $file_path );
	}
}

class HSPSC_Minify_Test extends WP_UnitTestCase {
    protected $asset_files = array();
    protected $original_cookies;

    public function set_up(): void {
        parent::set_up();
        $this->original_cookies = $_COOKIE;
        $_COOKIE = array();
        wp_set_current_user( 0 );
        $this->go_to( home_url( '/' ) );
        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
        HSPSC_Utils::reset_request_cache();
        $this->reset_asset_url_cache();
    }

    public function tear_down(): void {
        foreach ( $this->asset_files as $file ) {
            wp_delete_file( $file );
        }
        wp_deregister_script( 'hspsc-test-protected' );
        wp_deregister_style( 'hspsc-test-protected' );
        $_COOKIE = $this->original_cookies;
        HSPSC_Utils::reset_request_cache();
        $this->reset_asset_url_cache();
        parent::tear_down();
    }

    protected function create_asset( $filename, $contents ) {
        $file = WP_CONTENT_DIR . '/hspsc-safety-' . $filename;
        file_put_contents( $file, $contents );
        $this->asset_files[] = $file;
        return content_url( '/hspsc-safety-' . $filename );
    }

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

    public function test_minify_html_preserves_inline_css_and_js() {
        $input  = '<style>body { color: red; }</style><script>/*c*/var a=1;</script>';
        $output = HSPSC_Minify::minify_html( $input );

        $this->assertSame( $input, $output );
    }

    public function test_javascript_comment_markers_in_strings_and_templates_are_preserved() {
        $js = 'const label = "/* keep */"; const html = `<!-- keep -->`; const pattern = /[/*]/;';
        $this->assertSame( $js, HSPSC_Minify::minify_js( $js ) );
    }

    public function test_html_preserves_raw_text_and_inline_json() {
        $html = '<pre> A\n  B <!-- keep --> </pre><textarea> > < </textarea>'
            . '<script type="application/json">{"html":"<!-- keep --> > <"}</script>'
            . '<script data-no-optimize="1">const text = "/* keep */";</script>'
            . '<style>.label::after { content: "<!-- keep -->"; }</style>';
        $this->assertSame( $html, HSPSC_Minify::minify_html( $html ) );
    }

    public function test_html_preserves_nested_templates_and_significant_intertag_spacing() {
        $template = '<template><template> <!-- keep --> </template>  <span> x </span></template>';
        $this->assertSame( $template, HSPSC_Minify::minify_html( $template ) );
        $html = '<span>Hello</span> <span>world</span>';
        $this->assertSame( $html, HSPSC_Minify::minify_html( $html ) );
    }

    public function test_html_preserves_dynamic_block_and_knockout_markers() {
        $html = '<!-- wp:group --><div><!-- ko if: cart -->items<!-- /ko --></div><!-- /wp:group -->';
        $this->assertSame( $html, HSPSC_Minify::minify_html( $html ) );
    }

    public function test_css_strings_escapes_and_custom_properties_are_preserved() {
        $css = '.label::after { content: "/* not a comment */"; --gap:  ; }';
        $this->assertSame( $css, HSPSC_Minify::minify_css( $css ) );
        $this->assertStringContainsString( 'a :hover', HSPSC_Minify::minify_css( 'a :hover { color: red; }' ) );
    }

    public function test_stylesheet_with_relative_dependencies_keeps_its_original_url() {
        foreach ( array( 'body{background:url(../image.png)}', '@import "./colors.css"; body{color:red}' ) as $index => $css ) {
            $src = $this->create_asset( 'relative-' . $index . '.css', $css );
            $this->assertSame( $src, $this->maybe_minify_asset( $src, 'css' ) );
        }
    }

	/**
	 * A new request reuses validated generated assets without reading source bytes.
	 */
	public function test_generated_asset_hits_skip_source_io_but_recheck_privacy_and_mtime() {
		$src  = $this->create_asset( 'warm-read-count.css', 'body { color: red; }' );
		$file = WP_CONTENT_DIR . '/hspsc-safety-warm-read-count.css';
		HSPSC_Minify_Read_Counting_Fixture::$source_reads = 0;
		$generated = HSPSC_Minify_Read_Counting_Fixture::optimize( $src, 'css' );
		$this->assertNotSame( $src, $generated );
		$this->assertSame( 1, HSPSC_Minify_Read_Counting_Fixture::$source_reads );
		$this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );

		// Empty the per-request URL memo to exercise the on-disk cache fast path.
		$this->reset_asset_url_cache();
		$this->assertSame( $generated, HSPSC_Minify_Read_Counting_Fixture::optimize( $src, 'css' ) );
		$this->assertSame( 1, HSPSC_Minify_Read_Counting_Fixture::$source_reads );

		$_COOKIE['fct_cart_hash'] = 'late-private-session';
		$this->assertSame( $src, HSPSC_Minify_Read_Counting_Fixture::optimize( $src, 'css' ) );
		$this->assertSame( 1, HSPSC_Minify_Read_Counting_Fixture::$source_reads );
		unset( $_COOKIE['fct_cart_hash'] );

		touch( $file, filemtime( $file ) + 5 );
		clearstatcache( true, $file );
		$this->reset_asset_url_cache();
		$changed = HSPSC_Minify_Read_Counting_Fixture::optimize( $src, 'css' );
		$this->assertNotSame( $generated, $changed );
		$this->assertSame( 2, HSPSC_Minify_Read_Counting_Fixture::$source_reads );
		$this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $changed, PHP_URL_PATH ) );
	}

    public function test_asset_memoization_rechecks_late_cart_sessions_without_caching_bypasses() {
        $src = $this->create_asset( 'late-cart.css', 'body { color: red; }' );
        $_COOKIE['fct_cart_hash'] = 'shopper-a';
        $this->assertSame( $src, $this->maybe_minify_asset( $src, 'css' ) );

        unset( $_COOKIE['fct_cart_hash'] );
        $generated = $this->maybe_minify_asset( $src, 'css' );
        $this->assertNotSame( $src, $generated );
        $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );

        $_COOKIE['fct_cart_hash'] = 'shopper-b';
        $this->assertSame( $src, $this->maybe_minify_asset( $src, 'css' ) );
    }

    public function test_late_session_restores_original_urls_before_tags_are_output() {
        $style = $this->create_asset( 'tag-session.css', 'body { color: blue; }' );
        $script = $this->create_asset( 'tag-session.js', 'window.ready = true;' );
        $generated_style = HSPSC_Minify::filter_style_src( $style, 'hspsc-late-style' );
        $generated_script = HSPSC_Minify::filter_script_src( $script, 'hspsc-late-script' );
        $this->assertNotSame( $style, $generated_style );
        $this->assertNotSame( $script, $generated_script );
        foreach ( array( $generated_style, $generated_script ) as $generated ) {
            $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
        }

        $_COOKIE['fct_cart_hash'] = 'shopper-c';
        $style_tag = '<link rel="stylesheet" href="' . esc_url( $generated_style ) . '" />';
        $script_tag = '<script src="' . esc_url( $generated_script ) . '"></script>';
        $this->assertStringContainsString( esc_url( $style ), HSPSC_Minify::restore_excluded_style_url( $style_tag, 'hspsc-late-style', $generated_style ) );
        $this->assertStringContainsString( esc_url( $script ), HSPSC_Minify::restore_excluded_script_url( $script_tag, 'hspsc-late-script', $generated_script ) );
    }

    public function test_modules_and_importing_scripts_keep_their_original_url() {
        $scripts = array(
            'module.mjs' => 'console.log("module");',
            'import.js' => 'import { cart } from "./cart.js"; cart();',
            'dynamic.js' => 'import("./chunk.js").then(start);',
            'base.js' => 'new URL("./worker.js", import.meta.url);',
        );
        foreach ( $scripts as $filename => $contents ) {
            $src = $this->create_asset( $filename, $contents );
            $this->assertSame( $src, $this->maybe_minify_asset( $src, 'js' ) );
        }
    }

    public function test_commerce_and_configurable_script_exclusions_keep_original_url() {
        $src = $this->create_asset( 'cart.js', 'window.cartReady = true;' );
        foreach ( array( 'fluent-cart-app', 'fct-checkout', 'wc-add-to-cart', 'stripe' ) as $handle ) {
            $this->assertSame( $src, HSPSC_Minify::filter_script_src( $src, $handle ) );
        }
        $exclude = static function ( $patterns ) {
            $patterns[] = 'custom-cart';
            return $patterns;
        };
        add_filter( 'hspsc_script_optimization_exclusions', $exclude );
        try {
            $this->assertSame( $src, HSPSC_Minify::filter_script_src( $src, 'custom-cart' ) );
        } finally {
            remove_filter( 'hspsc_script_optimization_exclusions', $exclude );
        }
    }

	/**
	 * Language scripts keep their original query arguments and inline settings.
	 */
	public function test_wpml_and_wcml_scripts_keep_original_sources_and_localization() {
		$src = add_query_arg( 'lang', 'de', $this->create_asset( 'language.js', 'window.languageReady = true;' ) );
		$handles = array(
			'wpml-browser-redirect',
			'wpml-language-bar',
			'sitepress-language-switcher',
			'wcml-multi-currency',
			'browser-redirect',
			'jquery-cookie',
		);
		foreach ( $handles as $handle ) {
			$this->assertTrue( HSPSC_Minify::is_script_excluded( $handle, $src ) );
			$this->assertSame( $src, HSPSC_Minify::filter_script_src( $src, $handle ) );
		}

		wp_register_script( 'wpml-browser-redirect', $src );
		wp_localize_script(
			'wpml-browser-redirect',
			'wpml_browser_redirect_params',
			array(
				'pageLanguage' => 'de',
				'languageUrls' => array( 'en' => home_url( '/?lang=en' ) ),
			)
		);
		$script_data = wp_scripts()->get_data( 'wpml-browser-redirect', 'data' );

		try {
			$this->assertStringContainsString( '"pageLanguage":"de"', $script_data );
			$this->assertSame( $src, HSPSC_Minify::filter_script_src( $src, 'wpml-browser-redirect' ) );
			$this->assertSame( $script_data, wp_scripts()->get_data( 'wpml-browser-redirect', 'data' ) );
		} finally {
			wp_deregister_script( 'wpml-browser-redirect' );
		}
	}

	/**
	 * Excluding WPML scripts never enables a redirect disabled by site code.
	 */
	public function test_wpml_redirect_enqueue_opt_out_is_not_changed() {
		$enqueue_checks = 0;
		$disable        = static function ( $enqueue ) use ( &$enqueue_checks ) {
			++$enqueue_checks;
			return false;
		};
		$src            = $this->create_asset( 'redirect-disabled.js', 'window.redirectReady = true;' );
		add_filter( 'wpml_enqueue_browser_redirect_language', $disable );

		try {
			$this->assertFalse( apply_filters( 'wpml_enqueue_browser_redirect_language', true ) );
			$this->assertSame( $src, HSPSC_Minify::filter_script_src( $src, 'wpml-browser-redirect' ) );
			$this->assertSame( 1, $enqueue_checks );
			$this->assertFalse( wp_script_is( 'wpml-browser-redirect', 'enqueued' ) );
		} finally {
			remove_filter( 'wpml_enqueue_browser_redirect_language', $disable );
		}
	}

    public function test_registered_module_and_no_optimize_metadata_skip_minification() {
        $src = $this->create_asset( 'protected.js', 'window.ready = true;' );
        wp_register_script( 'hspsc-test-protected', $src );
        wp_script_add_data( 'hspsc-test-protected', 'type', 'module' );
        $this->assertSame( $src, HSPSC_Minify::filter_script_src( $src, 'hspsc-test-protected' ) );
        wp_script_add_data( 'hspsc-test-protected', 'type', 'text/javascript' );
        wp_script_add_data( 'hspsc-test-protected', 'data-no-optimize', true );
        $this->assertSame( $src, HSPSC_Minify::filter_script_src( $src, 'hspsc-test-protected' ) );
    }

    public function test_late_script_tag_opt_out_restores_original_source_url() {
        $src = $this->create_asset( 'late.js', 'window.ready = true;' );
        $generated = HSPSC_Minify::filter_script_src( $src, 'hspsc-test-protected' );
        $this->assertNotSame( $src, $generated );
        $file = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
        $this->asset_files[] = $file;
        $tag = '<script data-no-optimize="1" src="' . esc_url( $generated ) . '"></script>';
        $restored = HSPSC_Minify::restore_excluded_script_url( $tag, 'hspsc-test-protected', $generated );
        $this->assertStringContainsString( esc_url( $src ), $restored );
        $this->assertStringNotContainsString( esc_url( $generated ), $restored );
    }

    public function test_stylesheet_no_optimize_metadata_and_late_attribute_are_respected() {
        $src = $this->create_asset( 'protected.css', 'body { color: red; }' );
        wp_register_style( 'hspsc-test-protected', $src );
        wp_style_add_data( 'hspsc-test-protected', 'data-no-optimize', true );
        $this->assertSame( $src, HSPSC_Minify::filter_style_src( $src, 'hspsc-test-protected' ) );
        wp_deregister_style( 'hspsc-test-protected' );
        $generated = HSPSC_Minify::filter_style_src( $src, 'hspsc-test-protected' );
        $this->assertNotSame( $src, $generated );
        $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
        $tag = '<link data-no-optimize="1" rel="stylesheet" href="' . esc_url( $generated ) . '" />';
        $this->assertStringContainsString( esc_url( $src ), HSPSC_Minify::restore_excluded_style_url( $tag, 'hspsc-test-protected', $generated ) );
    }

    public function test_asset_headers_require_an_exact_existing_generated_file_request() {
        $method = new ReflectionMethod( 'HSPSC_Minify', 'is_generated_asset_request' );
        $method->setAccessible( true );
        $server = $_SERVER;
        HSPSC_Utils::ensure_cache_dirs();
        $filename = md5( 'hspsc-test-asset-headers' ) . '.min.css';
        $file = HSPSC_PATH . '/assets/' . $filename;
        file_put_contents( $file, 'body{color:red}' );
        $this->asset_files[] = $file;
        $path = wp_parse_url( HSPSC_URL . '/assets/' . $filename, PHP_URL_PATH );
        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/private-page/?asset=' . $path;
            $this->assertFalse( $method->invoke( null ) );
            $_SERVER['REQUEST_URI'] = $path;
            $this->assertTrue( $method->invoke( null ) );
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $this->assertFalse( $method->invoke( null ) );
            $_SERVER['REQUEST_METHOD'] = 'GET';
            wp_delete_file( $file );
            $this->assertFalse( $method->invoke( null ) );
        } finally {
            $_SERVER = $server;
        }
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
