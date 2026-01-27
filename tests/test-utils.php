<?php

class HSP_Smart_Cache_Utils_Test extends WP_UnitTestCase {
    public function test_cache_dirs_can_be_created() {
        HSP_Smart_Cache_Utils::ensure_cache_dirs();

        $this->assertDirectoryExists( HSP_SMART_CACHE_PATH . '/pages' );
        $this->assertDirectoryExists( HSP_SMART_CACHE_PATH . '/assets' );
        $this->assertDirectoryExists( HSP_SMART_CACHE_PATH . '/object' );
    }

    public function test_normalize_url_path() {
        $path = HSP_Smart_Cache_Utils::normalize_url_path( 'https://example.com/wp-content/style.css?ver=1' );
        $this->assertSame( '/wp-content/style.css', $path );
    }
}
