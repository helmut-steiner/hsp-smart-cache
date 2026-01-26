<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Cache_Tests {
    public static function run() {
        $results = array();

        $results[] = self::test_cache_dirs();
        $results[] = self::test_minify_html();
        $results[] = self::test_asset_minify();
        $results[] = self::test_page_cache_file_write();
        $results[] = self::test_object_cache_dropin();

        return $results;
    }

    protected static function test_cache_dirs() {
        HSP_Cache_Utils::ensure_cache_dirs();
        $ok = is_dir( HSP_CACHE_PATH . '/pages' ) && is_dir( HSP_CACHE_PATH . '/assets' ) && is_dir( HSP_CACHE_PATH . '/object' );
        return array(
            'label'  => 'Cache directories exist',
            'status' => $ok,
            'details'=> $ok ? '' : 'One or more cache directories are missing.',
        );
    }

    protected static function test_minify_html() {
        $input  = "<div>  Test </div>\n<!-- comment -->";
        $output = HSP_Cache_Minify::minify_html( $input );
        $ok = strpos( $output, 'comment' ) === false && strpos( $output, "\n" ) === false;
        return array(
            'label'  => 'HTML minification works',
            'status' => $ok,
            'details'=> $ok ? '' : 'Minified HTML still contains comments or whitespace.',
        );
    }

    protected static function test_asset_minify() {
        $css = "body { color: red; }";
        $js  = "function t(){ return 1; }";
        $min_css = HSP_Cache_Minify::minify_css( $css );
        $min_js  = HSP_Cache_Minify::minify_js( $js );
        $ok = strlen( $min_css ) <= strlen( $css ) && strlen( $min_js ) <= strlen( $js );
        return array(
            'label'  => 'CSS/JS minification works',
            'status' => $ok,
            'details'=> $ok ? '' : 'Minified output is not smaller than input.',
        );
    }

    protected static function test_page_cache_file_write() {
        HSP_Cache_Utils::ensure_cache_dirs();
        $file = HSP_CACHE_PATH . '/pages/test-cache.html';
        $data = '<html>ok</html>';
        $ok = (bool) file_put_contents( $file, $data );
        if ( $ok ) {
            $ok = file_exists( $file );
        }
        return array(
            'label'  => 'Page cache file write',
            'status' => $ok,
            'details'=> $ok ? '' : 'Unable to write cache file. Check permissions.',
        );
    }

    protected static function test_object_cache_dropin() {
        $enabled = HSP_Cache_Settings::get( 'object_cache' );
        $exists  = file_exists( WP_CONTENT_DIR . '/object-cache.php' );
        $ok = $enabled ? $exists : true;
        return array(
            'label'  => 'Object cache drop-in present',
            'status' => $ok,
            'details'=> $ok ? '' : 'Object cache enabled but drop-in missing.',
        );
    }
}
