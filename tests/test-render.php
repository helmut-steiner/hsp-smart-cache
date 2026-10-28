<?php

/**
 * Tracks full registry traversals while retaining dependency-array semantics.
 */
class HSPSC_Render_Counting_Registry_Fixture extends ArrayObject {
	public $iterations = 0;

	public function getIterator(): Iterator {
		++$this->iterations;
		return parent::getIterator();
	}
}

class HSPSC_Render_Test extends WP_UnitTestCase {
    protected $original_scripts;
    protected $original_cookies;

    public function set_up(): void {
        parent::set_up();
        $this->original_scripts = isset( $GLOBALS['wp_scripts'] ) ? $GLOBALS['wp_scripts'] : null;
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        $this->original_cookies = $_COOKIE;
        $_COOKIE = array();
        wp_set_current_user( 0 );
        $this->go_to( home_url( '/' ) );
        HSPSC_Utils::reset_request_cache();
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_defer_js' => true,
            'render_async_js' => false,
            'render_defer_exclusions' => '',
            'render_async_exclusions' => '',
        ) ) );
    }

    public function tear_down(): void {
        $GLOBALS['wp_scripts'] = $this->original_scripts;
        $_COOKIE = $this->original_cookies;
        HSPSC_Utils::reset_request_cache();
        parent::tear_down();
    }

    protected function require_native_strategies() {
        if ( ! method_exists( wp_scripts(), 'get_eligible_loading_strategy' ) ) {
            $this->markTestSkipped( 'Native dependency-aware loading strategies require WordPress 6.3.' );
        }
    }

    protected function print_script( $handle ) {
        HSPSC_Render::apply_script_strategies();
        ob_start();
        wp_scripts()->do_items( array( $handle ) );
        return ob_get_clean();
    }

    public function test_script_tag_gets_defer() {
        $this->require_native_strategies();
        wp_enqueue_script( 'hspsc-leaf', '/hspsc-leaf.js' );
        $this->assertStringContainsString( ' defer', $this->print_script( 'hspsc-leaf' ) );
    }

    public function test_script_tag_exclusion_skips_defer() {
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_defer_js' => true,
            'render_defer_exclusions' => "hspsc-leaf\n",
        ) ) );
        wp_enqueue_script( 'hspsc-leaf', '/hspsc-leaf.js' );
        $this->assertStringNotContainsString( ' defer', $this->print_script( 'hspsc-leaf' ) );
    }

    public function test_script_tag_async_when_defer_off() {
        $this->require_native_strategies();
        update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
            'render_defer_js' => false,
            'render_async_js' => true,
        ) ) );
        wp_enqueue_script( 'hspsc-leaf', '/hspsc-leaf.js' );
        $this->assertStringContainsString( ' async', $this->print_script( 'hspsc-leaf' ) );
    }

    public function test_late_tag_filter_does_not_inject_unsafe_attributes() {
        $tag = '<script src="/custom.js"></script>';
        $this->assertSame( $tag, HSPSC_Render::filter_script_tag( $tag, 'hspsc-unregistered', '/custom.js' ) );
    }

    public function test_blocking_inline_consumer_keeps_its_dependency_blocking() {
        wp_register_script( 'hspsc-library', '/library.js' );
        wp_enqueue_script( 'hspsc-consumer', '/consumer.js', array( 'hspsc-library' ) );
        wp_add_inline_script( 'hspsc-consumer', 'window.consumeCart();' );
        $output = $this->print_script( 'hspsc-consumer' );
        $this->assertStringNotContainsString( ' defer', $output );
        $this->assertStringNotContainsString( ' async', $output );
        $this->assertStringContainsString( 'window.consumeCart();', $output );
        $this->assertLessThan( strpos( $output, 'consumer.js' ), strpos( $output, 'library.js' ) );
    }

    public function test_native_strategy_is_only_added_to_leaf_of_dependency_tree() {
        $this->require_native_strategies();
        wp_register_script( 'hspsc-library', '/library.js' );
        wp_enqueue_script( 'hspsc-leaf', '/leaf.js', array( 'hspsc-library' ) );
        $output = $this->print_script( 'hspsc-leaf' );
        $this->assertFalse( wp_scripts()->get_data( 'hspsc-library', 'strategy' ) );
        $this->assertSame( 'defer', wp_scripts()->get_data( 'hspsc-leaf', 'strategy' ) );
        $this->assertStringContainsString( ' defer', $output );
    }

	/**
	 * Registry growth does not multiply dependency scans for each script.
	 */
	public function test_dependency_graph_is_scanned_once_per_strategy_pass() {
		$this->require_native_strategies();
		wp_register_script( 'hspsc-shared-library', '/library.js' );
		for ( $index = 0; $index < 80; ++$index ) {
			wp_register_script( 'hspsc-graph-leaf-' . $index, '/leaf-' . $index . '.js', array( 'hspsc-shared-library' ) );
		}
		$registry = new HSPSC_Render_Counting_Registry_Fixture( wp_scripts()->registered );
		wp_scripts()->registered = $registry;
		HSPSC_Render::apply_script_strategies();
		$this->assertLessThanOrEqual( 2, $registry->iterations );
		$this->assertFalse( wp_scripts()->get_data( 'hspsc-shared-library', 'strategy' ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'hspsc-graph-leaf-79', 'strategy' ) );

		// Footer registrations must be included rather than reusing an old graph.
		wp_register_script( 'hspsc-footer-library', '/footer-library.js' );
		wp_register_script( 'hspsc-footer-consumer', '/footer-consumer.js', array( 'hspsc-footer-library' ) );
		$registry->iterations = 0;
		HSPSC_Render::apply_script_strategies();
		$this->assertLessThanOrEqual( 2, $registry->iterations );
		$this->assertFalse( wp_scripts()->get_data( 'hspsc-footer-library', 'strategy' ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'hspsc-footer-consumer', 'strategy' ) );
	}

    public function test_existing_native_strategy_is_preserved() {
        $this->require_native_strategies();
        wp_enqueue_script( 'hspsc-leaf', '/leaf.js' );
        wp_script_add_data( 'hspsc-leaf', 'strategy', 'async' );
        $output = $this->print_script( 'hspsc-leaf' );
        $this->assertSame( 'async', wp_scripts()->get_data( 'hspsc-leaf', 'strategy' ) );
        $this->assertStringContainsString( ' async', $output );
    }

    public function test_commerce_scripts_and_no_optimize_metadata_keep_blocking_strategy() {
        wp_enqueue_script( 'fct-checkout', '/checkout.js' );
        wp_enqueue_script( 'hspsc-protected', '/protected.js' );
        wp_script_add_data( 'hspsc-protected', 'data-no-optimize', true );
        HSPSC_Render::apply_script_strategies();
        $this->assertFalse( wp_scripts()->get_data( 'fct-checkout', 'strategy' ) );
        $this->assertFalse( wp_scripts()->get_data( 'hspsc-protected', 'strategy' ) );
    }

    public function test_late_no_optimize_tag_removes_only_plugin_managed_strategy() {
        $this->require_native_strategies();
        wp_enqueue_script( 'hspsc-late-opt-out', '/protected.js' );
        HSPSC_Render::apply_script_strategies();
        $tag = '<script defer data-no-optimize="1" src="/protected.js"></script>';
        $this->assertStringNotContainsString( ' defer', HSPSC_Render::filter_script_tag( $tag, 'hspsc-late-opt-out', '/protected.js' ) );
        $this->assertSame( $tag, HSPSC_Render::filter_script_tag( $tag, 'hspsc-not-managed', '/protected.js' ) );
    }

    public function test_late_cart_session_reverts_only_plugin_managed_loading_strategy() {
        $this->require_native_strategies();
        wp_enqueue_script( 'hspsc-late-session', '/session.js' );
        wp_enqueue_script( 'hspsc-owner-strategy', '/owner.js' );
        wp_script_add_data( 'hspsc-owner-strategy', 'strategy', 'async' );
        HSPSC_Render::apply_script_strategies();
        $this->assertSame( 'defer', wp_scripts()->get_data( 'hspsc-late-session', 'strategy' ) );

        $_COOKIE['fct_cart_hash'] = 'late-shopper';
        $managed_tag = '<script defer src="/session.js"></script>';
        $owner_tag = '<script async src="/owner.js"></script>';
        $this->assertStringNotContainsString( ' defer', HSPSC_Render::filter_script_tag( $managed_tag, 'hspsc-late-session', '/session.js' ) );
        $this->assertSame( '', wp_scripts()->get_data( 'hspsc-late-session', 'strategy' ) );
        $this->assertSame( $owner_tag, HSPSC_Render::filter_script_tag( $owner_tag, 'hspsc-owner-strategy', '/owner.js' ) );
        $this->assertSame( 'async', wp_scripts()->get_data( 'hspsc-owner-strategy', 'strategy' ) );
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
