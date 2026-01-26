<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "WP_TESTS_DIR not found. Please install the WordPress test suite.\n";
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

function _manually_load_hsp_smart_cache() {
    require dirname( __DIR__ ) . '/hsp-smart-cache.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_hsp_smart_cache' );

require $_tests_dir . '/includes/bootstrap.php';
