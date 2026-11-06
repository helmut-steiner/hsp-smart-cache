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
    protected $original_scripts;
    protected $original_styles;

    public function set_up(): void {
        parent::set_up();
        $this->original_scripts = isset( $GLOBALS['wp_scripts'] ) ? $GLOBALS['wp_scripts'] : null;
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        $this->original_styles = isset( $GLOBALS['wp_styles'] ) ? $GLOBALS['wp_styles'] : null;
        $GLOBALS['wp_styles'] = new WP_Styles();
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
        $GLOBALS['wp_scripts'] = $this->original_scripts;
        $GLOBALS['wp_styles'] = $this->original_styles;
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
        foreach ( array( 'asset_url_cache', 'original_script_urls', 'original_style_urls' ) as $name ) {
            $property = $ref->getProperty( $name );
            $property->setAccessible( true );
            $property->setValue( null, array() );
        }
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

	/** Reused assets refresh their usage timestamp without rereading source bytes. */
	public function test_aged_generated_asset_reuse_refreshes_timestamp_without_source_io() {
		$src = $this->create_asset( 'aged-reuse.css', 'body { color: red; }' );
		HSPSC_Minify_Read_Counting_Fixture::$source_reads = 0;
		$url = HSPSC_Minify_Read_Counting_Fixture::optimize( $src, 'css' );
		$asset = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $url, PHP_URL_PATH ) );
		$this->asset_files[] = $asset;
		touch( $asset, time() - MONTH_IN_SECONDS - DAY_IN_SECONDS );
		clearstatcache( true, $asset );
		$this->reset_asset_url_cache();
		$this->assertSame( $url, HSPSC_Minify_Read_Counting_Fixture::optimize( $src, 'css' ) );
		clearstatcache( true, $asset );
		$this->assertGreaterThan( time() - 10, filemtime( $asset ) );
		$this->assertSame( 1, HSPSC_Minify_Read_Counting_Fixture::$source_reads );
	}

	/** Garbage collection preserves assets linked from fresh public/private pages. */
	public function test_asset_gc_preserves_fresh_page_references_and_deletes_only_orphans() {
		HSPSC_Utils::ensure_cache_dirs();
		wp_mkdir_p( HSPSC_PATH . '/pages/private' );
		$public_asset = md5( 'hspsc-public-reference' ) . '.min.css';
		$private_asset = md5( 'hspsc-private-reference' ) . '.min.js';
		$expired_asset = md5( 'hspsc-expired-reference' ) . '.min.css';
		foreach ( array( $public_asset, $private_asset, $expired_asset ) as $name ) {
			$path = HSPSC_PATH . '/assets/' . $name;
			file_put_contents( $path, '/* cached asset */' );
			touch( $path, time() - MONTH_IN_SECONDS - DAY_IN_SECONDS );
			$this->asset_files[] = $path;
		}
		$public_page = HSPSC_PATH . '/pages/' . md5( 'public-reference' ) . '-' . md5( '' ) . '.html';
		$private_page = HSPSC_PATH . '/pages/private/' . md5( 'private-reference' ) . '-user-' . md5( 'user' ) . '-' . md5( '' ) . '.php';
		$expired_page = HSPSC_PATH . '/pages/' . md5( 'expired-reference' ) . '-' . md5( '' ) . '.html';
		// The basename crosses the stream chunk boundary and may use a CDN origin.
		file_put_contents( $public_page, str_repeat( ' ', 65520 ) . '<link href="https://cdn.example.test/' . $public_asset . '">' );
		file_put_contents( $private_page, "<?php exit; ?>\n" . '<script src="/cache/hspsc/assets/' . $private_asset . '"></script>' );
		file_put_contents( $expired_page, '<link href="/cache/hspsc/assets/' . $expired_asset . '">' );
		touch( $expired_page, time() - HSPSC_Settings::get( 'page_cache_ttl' ) - 10 );
		$this->asset_files = array_merge( $this->asset_files, array( $public_page, $private_page, $expired_page ) );
		$unowned = HSPSC_PATH . '/assets/hspsc-unowned-old.css';
		file_put_contents( $unowned, 'keep' );
		touch( $unowned, time() - 2 * MONTH_IN_SECONDS );
		$this->asset_files[] = $unowned;
		clearstatcache();

		HSPSC_Minify::cleanup_old_assets();
		$this->assertFileExists( HSPSC_PATH . '/assets/' . $public_asset );
		$this->assertFileExists( HSPSC_PATH . '/assets/' . $private_asset );
		$this->assertFileDoesNotExist( HSPSC_PATH . '/assets/' . $expired_asset );
		$this->assertFileExists( $unowned );
	}

	/** A configured page lifetime longer than one month also protects its assets. */
	public function test_asset_gc_respects_long_lived_page_cache_references() {
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
			'page_cache_ttl' => 2 * MONTH_IN_SECONDS,
		) ) );
		HSPSC_Utils::ensure_cache_dirs();
		$name = md5( 'hspsc-long-lived-reference' ) . '.min.css';
		$asset = HSPSC_PATH . '/assets/' . $name;
		$page = HSPSC_PATH . '/pages/' . md5( 'long-lived-reference' ) . '-' . md5( '' ) . '.html';
		file_put_contents( $asset, 'body{color:red}' );
		file_put_contents( $page, '<link href="/cache/hspsc/assets/' . $name . '">' );
		touch( $asset, time() - MONTH_IN_SECONDS - DAY_IN_SECONDS );
		touch( $page, time() - MONTH_IN_SECONDS - 100 );
		$this->asset_files = array_merge( $this->asset_files, array( $asset, $page ) );
		clearstatcache();
		HSPSC_Minify::cleanup_old_assets();
		$this->assertFileExists( $asset );
	}

    /**
     * Explicit URL versions survive deployments that retain source timestamps.
     *
     * @dataProvider asset_query_version_provider
     */
    public function test_asset_query_versions_change_generated_urls_without_source_mtime_changes( $type, $parameter, $before, $after ) {
        $src   = $this->create_asset( 'query-version-' . $parameter . '.' . $type, $before );
        $file  = WP_CONTENT_DIR . '/hspsc-safety-query-version-' . $parameter . '.' . $type;
        $mtime = filemtime( $file );
        $first = add_query_arg( $parameter, 'one', $src );
        HSPSC_Minify_Read_Counting_Fixture::$source_reads = 0;

        $generated = HSPSC_Minify_Read_Counting_Fixture::optimize( $first, $type );
        $this->assertNotSame( $first, $generated );
        $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
        $this->assertSame( 1, HSPSC_Minify_Read_Counting_Fixture::$source_reads );

        file_put_contents( $file, $after );
        touch( $file, $mtime );
        clearstatcache( true, $file );
        $this->assertSame( $mtime, filemtime( $file ) );
        $this->reset_asset_url_cache();

        $second  = add_query_arg( $parameter, 'two', $src );
        $changed = HSPSC_Minify_Read_Counting_Fixture::optimize( $second, $type );
        $this->assertNotSame( $second, $changed );
        $this->assertNotSame( $generated, $changed );
        $generated_file      = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $changed, PHP_URL_PATH ) );
        $this->asset_files[] = $generated_file;
        $this->assertSame( $after, file_get_contents( $generated_file ) );
        $this->assertSame( 2, HSPSC_Minify_Read_Counting_Fixture::$source_reads );

        // A later request with the same version still uses the on-disk fast path.
        $this->reset_asset_url_cache();
        $this->assertSame( $changed, HSPSC_Minify_Read_Counting_Fixture::optimize( $second, $type ) );
        $this->assertSame( 2, HSPSC_Minify_Read_Counting_Fixture::$source_reads );
    }

    public function asset_query_version_provider() {
        return array(
            'CSS WordPress version' => array( 'css', 'ver', 'body{color:red}', 'body{color:blue}' ),
            'JS WordPress version'  => array( 'js', 'ver', 'window.ready=1;', 'window.ready=2;' ),
            'CSS custom version'    => array( 'css', 'build', 'body{color:red}', 'body{color:blue}' ),
            'JS custom version'     => array( 'js', 'build', 'window.ready=1;', 'window.ready=2;' ),
        );
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

    /**
     * Vite-style booking bundles and their icon fonts keep their import base.
     * This is an API-shaped fixture, with no Amelia dependency or vendor code.
     */
    public function test_booking_module_chunks_and_icon_fonts_keep_original_urls() {
        $script = $this->create_asset(
            'booking-public.js',
            'import{mount}from"./stepForm-fixture.js";import"./customerPanel-fixture.js";'
                . 'const lazy=()=>import("./calendar-fixture.js");const base=import.meta.url;'
        );
        $style = $this->create_asset(
            'booking-style.css',
            '@font-face{font-family:booking;src:url(./fonts/booking-icons.woff2?fixture)}'
        );
        $this->assertSame( $script, HSPSC_Minify::filter_script_src( $script, 'hspsc-booking-module' ) );
        $this->assertSame( $style, HSPSC_Minify::filter_style_src( $style, 'hspsc-booking-style' ) );
    }

    public function test_late_module_tag_restores_root_relative_source_without_changing_payload() {
        $src = add_query_arg( 'locale', 'de', $this->create_asset( 'late-relative.js', 'window.ready = true;' ) );
        $generated = HSPSC_Minify::filter_script_src( $src, 'hspsc-test-protected' );
        $this->assertNotSame( $src, $generated );
        $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );

        // Booking integrations can add type=module and strip the origin later.
        $relative = wp_parse_url( $generated, PHP_URL_PATH );
        $payload = 'window.assetHint = "' . esc_url( $generated ) . '";';
        $tag = "<script type='module' async crossorigin src='" . esc_url( $relative ) . "'>" . $payload . '</script>';
        $restored = HSPSC_Minify::restore_excluded_script_url( $tag, 'hspsc-test-protected', $generated );
        $processor = new WP_HTML_Tag_Processor( $restored );
        $this->assertTrue( $processor->next_tag( 'script' ) );
        $this->assertSame( esc_url_raw( $src ), $processor->get_attribute( 'src' ) );
        $this->assertSame( 'module', $processor->get_attribute( 'type' ) );
        $this->assertTrue( $processor->get_attribute( 'async' ) );
        $this->assertTrue( $processor->get_attribute( 'crossorigin' ) );
        $this->assertStringContainsString( $payload, $restored );

        $classic_tag = '<script src="' . esc_url( $generated ) . '"></script>';
        $this->assertSame( $classic_tag, HSPSC_Minify::restore_excluded_script_url( $classic_tag, 'hspsc-test-protected', $generated ) );
    }

    public function test_late_stylesheet_opt_out_restores_root_relative_href() {
        $src = $this->create_asset( 'late-relative.css', 'body { color: red; }' );
        $generated = HSPSC_Minify::filter_style_src( $src, 'hspsc-test-protected' );
        $this->assertNotSame( $src, $generated );
        $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
        $relative = wp_parse_url( $generated, PHP_URL_PATH );
        $tag = '<link data-no-optimize="1" rel="stylesheet" media="print" href="' . esc_url( $relative ) . '" />';
        $restored = HSPSC_Minify::restore_excluded_style_url( $tag, 'hspsc-test-protected', $generated );
        $processor = new WP_HTML_Tag_Processor( $restored );
        $this->assertTrue( $processor->next_tag( 'link' ) );
        $this->assertSame( esc_url_raw( $src ), $processor->get_attribute( 'href' ) );
        $this->assertSame( 'print', $processor->get_attribute( 'media' ) );
        $this->assertSame( '1', $processor->get_attribute( 'data-no-optimize' ) );
    }

    public function test_late_module_restoration_preserves_wordpress_inline_scripts() {
        $src = add_query_arg( array( 'locale' => 'de', 'view' => 'booking' ), $this->create_asset( 'inline-module.js', 'window.ready = true;' ) );
        $generated = HSPSC_Minify::filter_script_src( $src, 'hspsc-test-protected' );
        $this->assertNotSame( $src, $generated );
        $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
        wp_register_script( 'hspsc-test-protected', $src, array(), null );
        $before = 'window.bookingSettings = {"locale":"de","asset":"' . $generated . '"};';
        $after = 'window.bookingReady = true;';
        wp_add_inline_script( 'hspsc-test-protected', $before, 'before' );
        wp_add_inline_script( 'hspsc-test-protected', $after, 'after' );
        $late_module = static function ( $tag, $handle ) {
            if ( 'hspsc-test-protected' !== $handle ) {
                return $tag;
            }
            $processor = new WP_HTML_Tag_Processor( $tag );
            while ( $processor->next_tag( 'script' ) ) {
                $url = $processor->get_attribute( 'src' );
                if ( is_string( $url ) ) {
                    $processor->set_attribute( 'src', wp_make_link_relative( $url ) );
                    $processor->set_attribute( 'type', 'module' );
                    $processor->set_attribute( 'crossorigin', true );
                } else {
                    $processor->set_attribute( 'type', 'text/javascript' );
                }
            }
            return $processor->get_updated_html();
        };
        add_filter( 'script_loader_tag', $late_module, 10, 2 );
        try {
            ob_start();
            wp_scripts()->do_items( array( 'hspsc-test-protected' ) );
            $output = ob_get_clean();
        } finally {
            remove_filter( 'script_loader_tag', $late_module, 10 );
        }

        $processor = new WP_HTML_Tag_Processor( $output );
        $this->assertTrue( $processor->next_tag( 'script' ) );
        $this->assertNull( $processor->get_attribute( 'src' ) );
        $this->assertSame( 'text/javascript', $processor->get_attribute( 'type' ) );
        $this->assertTrue( $processor->next_tag( 'script' ) );
        $this->assertSame( $src, $processor->get_attribute( 'src' ) );
        $this->assertSame( 'module', $processor->get_attribute( 'type' ) );
        $this->assertTrue( $processor->get_attribute( 'crossorigin' ) );
        $this->assertTrue( $processor->next_tag( 'script' ) );
        $this->assertNull( $processor->get_attribute( 'src' ) );
        $this->assertStringContainsString( $before, $output );
        $this->assertStringContainsString( $after, $output );
    }

    public function test_late_restoration_ignores_unrelated_tags_and_inline_opt_out_text() {
        $src = $this->create_asset( 'unrelated.js', 'window.ready = true;' );
        $generated = HSPSC_Minify::filter_script_src( $src, 'hspsc-test-protected' );
        $this->assertNotSame( $src, $generated );
        $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
        $relative = wp_make_link_relative( $generated );
        $unrelated = '<script type="module" src="https://unrelated.example' . esc_url( $relative ) . '"></script>';
        $inline = '<script>window.hint = " data-no-optimize=1";</script>';
        $classic = '<script src="' . esc_url( $relative ) . '"></script>';
        $this->assertSame( $unrelated . $inline . $classic, HSPSC_Minify::restore_excluded_script_url( $unrelated . $inline . $classic, 'hspsc-test-protected', $generated ) );

        $module = '<script type="module" src="' . esc_url( $relative ) . '"></script>';
        $restored = HSPSC_Minify::restore_excluded_script_url( $unrelated . $inline . $module, 'hspsc-test-protected', $generated );
        $this->assertStringStartsWith( $unrelated . $inline, $restored );
        $processor = new WP_HTML_Tag_Processor( $restored );
        $this->assertTrue( $processor->next_tag( 'script' ) );
        $this->assertTrue( $processor->next_tag( 'script' ) );
        $this->assertTrue( $processor->next_tag( 'script' ) );
        $this->assertSame( $src, $processor->get_attribute( 'src' ) );
    }

    public function test_stylesheet_restoration_matches_escaped_query_and_skips_other_links() {
        $src = $this->create_asset( 'escaped.css', 'body { color: red; }' );
        $generated = HSPSC_Minify::filter_style_src( $src, 'hspsc-test-protected' );
        $this->assertNotSame( $src, $generated );
        $this->asset_files[] = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
        $filtered = add_query_arg( array( 'ver' => '1', 'locale' => 'de' ), $generated );
        $preconnect = '<link rel="preconnect" href="https://unrelated.example" />';
        $tag = $preconnect . '<link data-no-optimize="1" rel="stylesheet" href="' . esc_url( wp_make_link_relative( $filtered ) ) . '" />';
        $restored = HSPSC_Minify::restore_excluded_style_url( $tag, 'hspsc-test-protected', esc_url( $filtered ) );
        $this->assertStringStartsWith( $preconnect, $restored );
        $processor = new WP_HTML_Tag_Processor( $restored );
        $this->assertTrue( $processor->next_tag( 'link' ) );
        $this->assertTrue( $processor->next_tag( 'link' ) );
        $this->assertSame( $src, $processor->get_attribute( 'href' ) );
    }

	/**
	 * Core's RTL filename selection must survive source and tag filters unchanged.
	 *
	 * @dataProvider native_rtl_stylesheet_cases
	 * @param bool|string $rtl     Native RTL mode or the explicit-file marker.
	 * @param string      $context Public, private, or admin request context.
	 * @param string      $suffix  Native stylesheet filename suffix.
	 */
	public function test_native_rtl_stylesheets_keep_core_urls( $rtl, $context, $suffix ) {
		$styles                 = wp_styles();
		$original_screen        = isset( $GLOBALS['current_screen'] ) ? $GLOBALS['current_screen'] : null;
		$styles->text_direction = 'rtl';
		$styles->do_concat      = false;
		$base                   = $this->create_asset( 'native-direction' . $suffix . '.css', 'body { margin-left: 10px; }' );
		$companion              = $this->create_asset( 'native-direction-rtl' . $suffix . '.css', 'body { margin-right: 10px; }' );
		if ( 'explicit' === $rtl ) {
			$companion = $this->create_asset( 'native-direction-custom.css', 'body { direction: rtl; }' );
			$rtl       = $companion;
		}
		$styles->add( 'hspsc-native-direction', $base, array(), '1' );
		$styles->add_data( 'hspsc-native-direction', 'rtl', $rtl );
		$styles->add_data( 'hspsc-native-direction', 'suffix', $suffix );

		if ( 'admin' === $context ) {
			set_current_screen( 'dashboard' );
		} elseif ( 'private' === $context ) {
			$_COOKIE['fct_cart_hash'] = 'rtl-private-session';
		}

		try {
			ob_start();
			$styles->do_items( array( 'hspsc-native-direction' ) );
			$output = ob_get_clean();
		} finally {
			$GLOBALS['current_screen'] = $original_screen;
		}

		$links     = array();
		$processor = new WP_HTML_Tag_Processor( $output );
		while ( $processor->next_tag( 'link' ) ) {
			$links[ $processor->get_attribute( 'id' ) ] = $processor->get_attribute( 'href' );
		}
		$expected = array();
		if ( 'replace' !== $rtl ) {
			$expected['hspsc-native-direction-css'] = add_query_arg( 'ver', '1', $base );
		}
		$expected['hspsc-native-direction-rtl-css'] = add_query_arg( 'ver', '1', $companion );
		$this->assertSame( $expected, $links );
	}

	/**
	 * Covers native additive, replacement and explicit RTL source selection.
	 *
	 * @return array[] Native RTL stylesheet cases.
	 */
	public static function native_rtl_stylesheet_cases() {
		$cases = array();
		foreach ( array( true, 'replace', 'explicit' ) as $rtl ) {
			foreach ( array( 'public', 'private', 'admin' ) as $context ) {
				foreach ( array( '', '.min' ) as $suffix ) {
					$name           = ( true === $rtl ? 'additive' : $rtl ) . '-' . $context . ( $suffix ? '-minified' : '' );
					$cases[ $name ] = array( $rtl, $context, $suffix );
				}
			}
		}
		return $cases;
	}

	/**
	 * A native RTL opt-out must not disable ordinary stylesheet optimization.
	 *
	 * @dataProvider ordinary_stylesheet_directions
	 * @param string $direction Stylesheet text direction.
	 */
	public function test_ordinary_stylesheets_still_minify( $direction ) {
		$styles                 = wp_styles();
		$styles->text_direction = $direction;
		$styles->do_concat      = false;
		$src                    = $this->create_asset( 'ordinary-direction.css', 'body { color: blue; }' );
		$styles->add( 'hspsc-ordinary-direction', $src, array(), null );
		if ( 'ltr' === $direction ) {
			$styles->add_data( 'hspsc-ordinary-direction', 'rtl', 'replace' );
		}
		ob_start();
		$styles->do_items( array( 'hspsc-ordinary-direction' ) );
		$output    = ob_get_clean();
		$processor = new WP_HTML_Tag_Processor( $output );
		$this->assertTrue( $processor->next_tag( 'link' ) );
		$generated = $processor->get_attribute( 'href' );
		$this->assertStringStartsWith( HSPSC_URL . '/assets/', $generated );
		$file                = HSPSC_PATH . '/assets/' . basename( wp_parse_url( $generated, PHP_URL_PATH ) );
		$this->asset_files[] = $file;
		$this->assertFileExists( $file );
	}

	/**
	 * Covers active LTR and RTL directions without native RTL companion output.
	 *
	 * @return array[] Directions that still allow ordinary CSS optimization.
	 */
	public static function ordinary_stylesheet_directions() {
		return array(
			'LTR with unused RTL metadata' => array( 'ltr' ),
			'RTL without companion'        => array( 'rtl' ),
		);
	}

	/**
	 * Another integration owns a rewritten URL when HSP did not change the asset.
	 */
	public function test_untouched_stylesheet_does_not_restore_another_filters_url() {
		$src = $this->create_asset( 'unchanged-url.css', 'body { background: url(./image.png); }' );
		$this->assertSame( $src, HSPSC_Minify::filter_style_src( $src, 'hspsc-untouched' ) );
		$_COOKIE['fct_cart_hash'] = 'late-private-session';

		$href = content_url( '/another-integration.css' );
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This fixture exercises a later filter's URL rewrite, not real stylesheet output.
		$tag = '<link rel="stylesheet" href="' . esc_url( $href ) . '" />';
		$this->assertSame( $tag, HSPSC_Minify::restore_excluded_style_url( $tag, 'hspsc-untouched', $href ) );
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
