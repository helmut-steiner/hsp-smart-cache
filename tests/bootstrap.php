<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! getenv( 'WP_TESTS_DIR' ) ) {
    exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$hsp_smart_cache_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $hsp_smart_cache_tests_dir ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $hsp_smart_cache_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $hsp_smart_cache_tests_dir . '/includes/functions.php' ) ) {
    echo "WP_TESTS_DIR not found. Please install the WordPress test suite.\n";
    exit( 1 );
}

require_once $hsp_smart_cache_tests_dir . '/includes/functions.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function hsp_smart_cache_manually_load() {
    require dirname( __DIR__ ) . '/hsp-smart-cache.php';
}

tests_add_filter( 'muplugins_loaded', 'hsp_smart_cache_manually_load' );

require $hsp_smart_cache_tests_dir . '/includes/bootstrap.php';
