<?php

class HSPSC_Uninstall_Test extends WP_UnitTestCase {
    public static function linked_cache_directories() {
        return array(
            'linked child directory' => array( false ),
            'linked cache root' => array( true ),
        );
    }

    /** @dataProvider linked_cache_directories */
    public function test_uninstall_preserves_directories_linked_outside_the_cache( $link_root ) {
        if ( ! function_exists( 'proc_open' ) || ! function_exists( 'symlink' ) ) {
            $this->markTestSkipped( 'PHP subprocesses and symbolic links are required.' );
        }
        $directory = trailingslashit( sys_get_temp_dir() ) . 'hspsc-uninstall-' . wp_generate_uuid4();
        $cache = $directory . '/content/cache/hspsc';
        $outside = $directory . '/outside-cache';
        $link = $link_root ? $cache : $cache . '/linked-directory';
        $script = tempnam( sys_get_temp_dir(), 'hspsc-uninstall-test-' );
        wp_mkdir_p( $outside );
        wp_mkdir_p( dirname( $cache ) );
        wp_mkdir_p( $directory . '/wordpress' );
        file_put_contents( $outside . '/sentinel.txt', 'outside cache' );
        file_put_contents( $directory . '/content/object-cache.php', '<?php // Another plugin owns this drop-in.' );
        if ( ! $link_root ) {
            wp_mkdir_p( $cache . '/nested' );
            file_put_contents( $cache . '/nested/generated.html', 'owned cache' );
        }
        try {
            if ( ! @symlink( $outside, $link ) ) {
                $this->markTestSkipped( 'Directory symlinks are unavailable.' );
            }
            $body = <<<'PHP'
function delete_option( $name ) { $GLOBALS['hspsc_deleted_options'][] = $name; }
function wp_unschedule_hook( $hook ) { $GLOBALS['hspsc_unscheduled_hooks'][] = $hook; }
function untrailingslashit( $path ) { return rtrim( $path, '/\\' ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
class HSPSC_Uninstall_Test_Filesystem {
    public function exists( $path ) { return file_exists( $path ); }
    public function is_dir( $path ) { return is_dir( $path ); }
    public function is_writable( $path ) { return is_writable( $path ); }
    public function get_contents( $path ) { return file_get_contents( $path ); }
    public function put_contents( $path, $contents ) { return strlen( $contents ) === file_put_contents( $path, $contents ); }
    public function delete( $path ) { return ! file_exists( $path ) || unlink( $path ); }
    public function rmdir( $path, $recursive = false ) { return @rmdir( $path ); }
}
function WP_Filesystem() { global $wp_filesystem; $wp_filesystem = new HSPSC_Uninstall_Test_Filesystem(); return true; }
require HSPSC_TEST_REPOSITORY . '/uninstall.php';
echo json_encode( array(
    'outside_exists' => file_exists( HSPSC_TEST_DIRECTORY . '/outside-cache/sentinel.txt' ),
    'foreign_dropin_exists' => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
    'generated_exists' => file_exists( HSPSC_PATH . '/nested/generated.html' ),
    'link_exists' => is_link( HSPSC_TEST_LINK ),
    'job_removed' => in_array( 'hspsc_rebuild_job', $GLOBALS['hspsc_deleted_options'], true ),
    'job_unscheduled' => in_array( 'hspsc_run_rebuild_batch', $GLOBALS['hspsc_unscheduled_hooks'], true ),
) );
PHP;
            file_put_contents(
                $script,
                '<?php define( "WP_UNINSTALL_PLUGIN", true );'
                . 'define( "ABSPATH", ' . var_export( $directory . '/wordpress/', true ) . ' );'
                . 'define( "WP_CONTENT_DIR", ' . var_export( $directory . '/content', true ) . ' );'
                . 'define( "HSPSC_PATH", ' . var_export( $cache, true ) . ' );'
                . 'define( "HSPSC_TEST_DIRECTORY", ' . var_export( $directory, true ) . ' );'
                . 'define( "HSPSC_TEST_LINK", ' . var_export( $link, true ) . ' );'
                . 'define( "HSPSC_TEST_REPOSITORY", ' . var_export( dirname( __DIR__ ), true ) . ' );' . $body
            );
            $process = proc_open( array( PHP_BINARY, $script ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
            $this->assertIsResource( $process );
            fclose( $pipes[0] );
            $stdout = stream_get_contents( $pipes[1] );
            $stderr = stream_get_contents( $pipes[2] );
            fclose( $pipes[1] );
            fclose( $pipes[2] );
            $this->assertSame( 0, proc_close( $process ), $stderr );
            $result = json_decode( $stdout, true );
            $this->assertIsArray( $result, $stdout . $stderr );
            $this->assertTrue( $result['outside_exists'] );
            $this->assertTrue( $result['foreign_dropin_exists'] );
            $this->assertFalse( $result['generated_exists'] );
            $this->assertTrue( $result['link_exists'] );
            $this->assertTrue( $result['job_removed'] );
            $this->assertTrue( $result['job_unscheduled'] );
        } finally {
            if ( is_link( $link ) ) {
                unlink( $link );
            }
            unlink( $script );
            HSPSC_Utils::delete_dir_contents( $directory );
            rmdir( $directory );
        }
    }
}
