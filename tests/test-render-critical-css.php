<?php
/**
 * Critical CSS raw-text output regressions.
 *
 * @package HSPSC
 */

class HSPSC_Critical_CSS_Test extends WP_UnitTestCase {
	private $original_cookies;

	public function set_up(): void {
		parent::set_up();
		$this->original_cookies = $_COOKIE;
		$_COOKIE = array();
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/' ) );
		HSPSC_Utils::reset_request_cache();
	}

	public function tear_down(): void {
		$_COOKIE = $this->original_cookies;
		HSPSC_Utils::reset_request_cache();
		parent::tear_down();
	}

	/** CSS raw text must retain selectors, quotes and literal ampersands. */
	public function test_critical_css_preserves_css_syntax_without_html_entities() {
		$css = '.hero > h1,[data-state="open"]::after{content:"A & B";}';
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
			'render_critical_css' => $css,
		) ) );
		ob_start();
		HSPSC_Render::output_critical_css();
		$output = ob_get_clean();
		$this->assertStringContainsString( $css, $output );
		$this->assertStringNotContainsString( '&gt;', $output );
		$this->assertStringNotContainsString( '&quot;', $output );
	}

	/** A closing-style token cannot escape the inline CSS raw-text container. */
	public function test_critical_css_neutralizes_case_insensitive_style_end_tags() {
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
			'render_critical_css' => '.label::after{content:"</StYlE><script>alert(1)</script>";}',
		) ) );
		ob_start();
		HSPSC_Render::output_critical_css();
		$output = ob_get_clean();
		$this->assertSame( 1, preg_match_all( '~</style~i', $output ) );
		$this->assertStringContainsString( '\3C /StYlE>', $output );
	}
}
