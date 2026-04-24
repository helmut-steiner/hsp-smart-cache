<?php

class HSPSC_Plugin_Handlers_Test extends WP_UnitTestCase {
    protected function get_cache_path_for_url( $url ) {
        $ref = new ReflectionClass( 'HSPSC_Page' );
        $method = $ref->getMethod( 'get_cache_file_path_for_url' );
        $method->setAccessible( true );
        return $method->invoke( null, $url );
    }

    public function set_up(): void {
        parent::set_up();
        HSPSC_Utils::ensure_cache_dirs();
    }

    public function test_filter_robots_txt_appends_ai_rules_when_enabled() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'robots_disallow_ai' => true ) )
        );

        $output = HSPSC_Plugin::filter_robots_txt( "User-agent: *\nDisallow:", true );

        $this->assertStringContainsString( 'User-agent: GPTBot', $output );
        $this->assertStringContainsString( 'User-agent: ClaudeBot', $output );
    }

    public function test_handle_post_change_clears_cached_permalink() {
        $post_id = self::factory()->post->create(
            array(
                'post_title'  => 'Invalidate by Save',
                'post_status' => 'publish',
            )
        );

        $post = get_post( $post_id );
        $url = get_permalink( $post_id );
        $file = $this->get_cache_path_for_url( $url );

        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSPSC_Plugin::handle_post_change( $post_id, $post, true );

        $this->assertFileDoesNotExist( $file );
    }

    public function test_handle_post_delete_clears_cached_permalink() {
        $post_id = self::factory()->post->create(
            array(
                'post_title'  => 'Invalidate by Delete',
                'post_status' => 'publish',
            )
        );

        $url = get_permalink( $post_id );
        $file = $this->get_cache_path_for_url( $url );

        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSPSC_Plugin::handle_post_delete( $post_id );

        $this->assertFileDoesNotExist( $file );
    }

    public function test_handle_comment_change_clears_cached_post_page() {
        $post_id = self::factory()->post->create(
            array(
                'post_title'  => 'Invalidate by Comment',
                'post_status' => 'publish',
            )
        );
        $comment_id = self::factory()->comment->create(
            array(
                'comment_post_ID' => $post_id,
                'comment_content' => 'Nice post',
            )
        );

        $url = get_permalink( $post_id );
        $file = $this->get_cache_path_for_url( $url );

        file_put_contents( $file, '<html>cached</html>' );
        $this->assertFileExists( $file );

        HSPSC_Plugin::handle_comment_change( $comment_id );

        $this->assertFileDoesNotExist( $file );
    }
}
