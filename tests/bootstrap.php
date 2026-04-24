<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$hspsc_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $hspsc_tests_dir ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $hspsc_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $hspsc_tests_dir . '/includes/functions.php' ) ) {
    echo "WP_TESTS_DIR not found. Please install the WordPress test suite.\n";
    exit( 1 );
}

$hspsc_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $hspsc_autoload ) ) {
    require_once $hspsc_autoload;
}

require_once $hspsc_tests_dir . '/includes/functions.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function hspsc_manually_load() {
    require dirname( __DIR__ ) . '/hsp-smart-cache.php';
}

tests_add_filter( 'muplugins_loaded', 'hspsc_manually_load' );

require $hspsc_tests_dir . '/includes/bootstrap.php';
