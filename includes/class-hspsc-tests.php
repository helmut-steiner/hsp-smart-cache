<?php
/**
 * Provides lightweight cache diagnostics and localized result labels.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides lightweight cache diagnostics and localized result labels.
 */
class HSPSC_Tests {
	/**
	 * Executes cache diagnostics and returns structured results.
	 *
	 * @return array[] Diagnostic codes, labels, status flags, and details.
	 */
	public static function run() {
		$results = array();

		$results[] = self::test_cache_dirs();
		$results[] = self::test_minify_html();
		$results[] = self::test_asset_minify();
		$results[] = self::test_page_cache_file_write();
		$results[] = self::test_object_cache_dropin();

		return $results;
	}

	/**
	 * Translates a stored diagnostic result without running the diagnostic again.
	 *
	 * Result codes remain stable across locales. Legacy results without a code
	 * can be recognized by their original English labels; unknown data is kept.
	 *
	 * @param mixed $result Stored diagnostic result.
	 * @return mixed Result with current-locale text, or the unchanged unknown value.
	 */
	public static function translate_result( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$code = isset( $result['code'] ) ? $result['code'] : '';
		if ( ! isset( $result['code'] ) && isset( $result['label'] ) && is_string( $result['label'] ) ) {
			$legacy_codes = array(
				'Cache directories exist'      => 'cache_dirs',
				'HTML minification works'      => 'minify_html',
				'CSS/JS minification works'    => 'asset_minify',
				'Page cache file write'        => 'page_cache_file_write',
				'Object cache drop-in present' => 'object_cache_dropin',
			);
			$code         = isset( $legacy_codes[ $result['label'] ] ) ? $legacy_codes[ $result['label'] ] : '';
		}

		if ( ! is_string( $code ) ) {
			return $result;
		}

		switch ( $code ) {
			case 'cache_dirs':
				$label   = __( 'Cache directories exist', 'hsp-smart-cache' );
				$details = __( 'One or more cache directories are missing.', 'hsp-smart-cache' );
				break;
			case 'minify_html':
				$label   = __( 'HTML minification works', 'hsp-smart-cache' );
				$details = __( 'Minified HTML still contains comments or whitespace.', 'hsp-smart-cache' );
				break;
			case 'asset_minify':
				$label   = __( 'CSS/JS minification works', 'hsp-smart-cache' );
				$details = __( 'Minified output is not smaller than input.', 'hsp-smart-cache' );
				break;
			case 'page_cache_file_write':
				$label   = __( 'Page cache file write', 'hsp-smart-cache' );
				$details = __( 'Unable to write cache file. Check permissions.', 'hsp-smart-cache' );
				break;
			case 'object_cache_dropin':
				$label   = __( 'Object cache drop-in present', 'hsp-smart-cache' );
				$details = __( 'Object cache enabled but drop-in missing.', 'hsp-smart-cache' );
				break;
			default:
				return $result;
		}

		$result['label']   = $label;
		$result['details'] = empty( $result['status'] ) ? $details : '';

		return $result;
	}

	/**
	 * Checks that the expected page, asset, and object cache directories exist.
	 *
	 * @return array Diagnostic result.
	 */
	protected static function test_cache_dirs() {
		HSPSC_Utils::ensure_cache_dirs();
		$ok = is_dir( HSPSC_PATH . '/pages' ) && is_dir( HSPSC_PATH . '/assets' ) && is_dir( HSPSC_PATH . '/object' );
		return array(
			'code'    => 'cache_dirs',
			'label'   => __( 'Cache directories exist', 'hsp-smart-cache' ),
			'status'  => $ok,
			'details' => $ok ? '' : __( 'One or more cache directories are missing.', 'hsp-smart-cache' ),
		);
	}

	/**
	 * Checks a small HTML minification sample for comments and line breaks.
	 *
	 * @return array Diagnostic result.
	 */
	protected static function test_minify_html() {
		$input  = "<div>  Test </div>\n<!-- comment -->";
		$output = HSPSC_Minify::minify_html( $input );
		$ok     = strpos( $output, 'comment' ) === false && strpos( $output, "\n" ) === false;
		return array(
			'code'    => 'minify_html',
			'label'   => __( 'HTML minification works', 'hsp-smart-cache' ),
			'status'  => $ok,
			'details' => $ok ? '' : __( 'Minified HTML still contains comments or whitespace.', 'hsp-smart-cache' ),
		);
	}

	/**
	 * Checks that simple CSS and JavaScript samples do not grow.
	 *
	 * @return array Diagnostic result.
	 */
	protected static function test_asset_minify() {
		$css     = 'body { color: red; }';
		$js      = 'function t(){ return 1; }';
		$min_css = HSPSC_Minify::minify_css( $css );
		$min_js  = HSPSC_Minify::minify_js( $js );
		$ok      = strlen( $min_css ) <= strlen( $css ) && strlen( $min_js ) <= strlen( $js );
		return array(
			'code'    => 'asset_minify',
			'label'   => __( 'CSS/JS minification works', 'hsp-smart-cache' ),
			'status'  => $ok,
			'details' => $ok ? '' : __( 'Minified output is not smaller than input.', 'hsp-smart-cache' ),
		);
	}

	/**
	 * Checks whether a sample page cache file can be written.
	 *
	 * @return array Diagnostic result.
	 */
	protected static function test_page_cache_file_write() {
		HSPSC_Utils::ensure_cache_dirs();
		$file = HSPSC_PATH . '/pages/test-cache.html';
		$data = '<html>ok</html>';
		$ok   = HSPSC_Utils::atomic_write( $file, $data );
		if ( $ok ) {
			$ok = file_exists( $file );
		}
		return array(
			'code'    => 'page_cache_file_write',
			'label'   => __( 'Page cache file write', 'hsp-smart-cache' ),
			'status'  => $ok,
			'details' => $ok ? '' : __( 'Unable to write cache file. Check permissions.', 'hsp-smart-cache' ),
		);
	}

	/**
	 * Checks that an enabled object cache has an installed drop-in.
	 *
	 * @return array Diagnostic result.
	 */
	protected static function test_object_cache_dropin() {
		$enabled = HSPSC_Settings::get( 'object_cache' );
		$exists  = file_exists( WP_CONTENT_DIR . '/object-cache.php' );
		$ok      = $enabled ? $exists : true;
		return array(
			'code'    => 'object_cache_dropin',
			'label'   => __( 'Object cache drop-in present', 'hsp-smart-cache' ),
			'status'  => $ok,
			'details' => $ok ? '' : __( 'Object cache enabled but drop-in missing.', 'hsp-smart-cache' ),
		);
	}
}
