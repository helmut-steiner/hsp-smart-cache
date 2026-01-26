<?php

class HSP_Cache_Page_Test extends WP_UnitTestCase {
    protected function get_cache_path_for_url( $url ) {
        $ref = new ReflectionClass( 'HSP_Cache_Page' );
        $method = $ref->getMethod( 'get_cache_file_path_for_url' );
        $method->setAccessible( true );
        return $method->invoke( null, $url );
    }

    public function test_clear_cache_for_url_removes_file() {
        HSP_Cache_Utils::ensure_cache_dirs();
        $url = home_url( '/sample-page/' );
        $file = $this->get_cache_path_for_url( $url );

        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSP_Cache_Page::clear_cache_for_url( $url );
        $this->assertFileDoesNotExist( $file );
    }

    public function test_clear_cache_for_post_clears_permalink() {
        $post_id = self::factory()->post->create( array(
            'post_title' => 'Cache Test',
            'post_status' => 'publish',
        ) );
        $url = get_permalink( $post_id );
        $file = $this->get_cache_path_for_url( $url );

        HSP_Cache_Utils::ensure_cache_dirs();
        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSP_Cache_Page::clear_cache_for_post( $post_id );
        $this->assertFileDoesNotExist( $file );
    }
}
