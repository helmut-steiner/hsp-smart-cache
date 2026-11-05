<?php

class HSPSC_Plugin_Publishing_Test extends WP_UnitTestCase {
	private $page_file;
	private $asset_file;

	public function set_up(): void {
		parent::set_up();
		HSPSC_Utils::ensure_cache_dirs();
		$this->page_file  = HSPSC_PATH . '/pages/publishing-consumer.html';
		$this->asset_file = HSPSC_PATH . '/assets/publishing-consumer.min.css';
	}

	public function tear_down(): void {
		wp_delete_file( $this->page_file );
		wp_delete_file( $this->asset_file );
		remove_filter( 'pre_option_template', array( $this, 'bricks_template' ) );
		remove_action( 'bricks/generate_css_file', array( 'HSPSC_Plugin', 'flush_render_caches' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			remove_action( $hook, array( 'HSPSC_Bricks', 'handle_post_meta_change' ), 10 );
		}
		if ( post_type_exists( 'bricks_template' ) ) {
			unregister_post_type( 'bricks_template' );
		}
		parent::tear_down();
	}

	private function cache_consumers() {
		file_put_contents( $this->page_file, '<html>Previously rendered shared content</html>' );
		file_put_contents( $this->asset_file, '.shared { color: red; }' );
	}

	private function assert_consumers_invalidated() {
		$this->assertFileDoesNotExist( $this->page_file );
		$this->assertFileDoesNotExist( $this->asset_file );
	}

	public static function shared_native_post_types() {
		return array(
			'template'      => array( 'wp_template' ),
			'template part' => array( 'wp_template_part' ),
			'synced block'  => array( 'wp_block' ),
			'global style'  => array( 'wp_global_styles' ),
			'navigation'    => array( 'wp_navigation' ),
			'custom CSS'    => array( 'custom_css' ),
		);
	}

	/** @dataProvider shared_native_post_types */
	public function test_shared_native_save_and_delete_invalidate_consuming_pages( $post_type ) {
		$post_id = self::factory()->post->create(
			array( 'post_type' => $post_type, 'post_status' => 'publish', 'post_content' => 'Before' )
		);
		$this->cache_consumers();
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'After' ) );
		$this->assert_consumers_invalidated();

		$this->cache_consumers();
		wp_delete_post( $post_id, true );
		$this->assert_consumers_invalidated();
	}

	public function test_final_post_hook_clears_pages_cached_before_additional_fields_finish() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post    = get_post( $post_id );
		do_action( 'save_post', $post_id, $post, true );
		$this->cache_consumers();
		do_action( 'wp_after_insert_post', $post_id, $post, true, $post );
		$this->assertFileDoesNotExist( $this->page_file );
		$this->assertFileExists( $this->asset_file );
	}

	public static function public_post_types() {
		return array( array( 'post' ), array( 'page' ) );
	}

	/** @dataProvider public_post_types */
	public function test_native_publish_and_update_clear_other_cached_pages_and_archives( $post_type ) {
		$post_id = self::factory()->post->create( array( 'post_type' => $post_type, 'post_status' => 'draft' ) );
		$this->cache_consumers();
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		$this->assertFileDoesNotExist( $this->page_file );
		$this->assertFileExists( $this->asset_file );

		$this->cache_consumers();
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'New published content' ) );
		$this->assertFileDoesNotExist( $this->page_file );
		$this->assertFileExists( $this->asset_file );
	}

	public function bricks_template() {
		return 'bricks';
	}

	private function enable_bricks() {
		add_filter( 'pre_option_template', array( $this, 'bricks_template' ) );
		HSPSC_Bricks::register_hooks();
		register_post_type( 'bricks_template', array( 'public' => false ) );
	}

	public function test_bricks_hooks_are_registered_only_for_the_active_theme() {
		HSPSC_Bricks::register_hooks();
		$this->assertFalse( has_action( 'bricks/generate_css_file', array( 'HSPSC_Plugin', 'flush_render_caches' ) ) );
		$this->assertFalse( has_action( 'updated_post_meta', array( 'HSPSC_Bricks', 'handle_post_meta_change' ) ) );

		$this->enable_bricks();
		$this->assertSame( 10, has_action( 'updated_post_meta', array( 'HSPSC_Bricks', 'handle_post_meta_change' ) ) );
		$this->cache_consumers();
		do_action( 'bricks/generate_css_file', 'post', 'post-123.min.css' );
		$this->assert_consumers_invalidated();
	}

	public static function bricks_render_meta_keys() {
		return array(
			'header'            => array( '_bricks_page_header_2' ),
			'content'           => array( '_bricks_page_content_2' ),
			'footer'            => array( '_bricks_page_footer_2' ),
			'page settings'     => array( '_bricks_page_settings' ),
			'template settings' => array( '_bricks_template_settings' ),
			'template type'     => array( '_bricks_template_type' ),
			'editor mode'       => array( '_bricks_editor_mode' ),
		);
	}

	/** @dataProvider bricks_render_meta_keys */
	public function test_bricks_meta_only_saves_invalidate_without_css_generation( $meta_key ) {
		$this->enable_bricks();
		$post_id = self::factory()->post->create( array( 'post_type' => 'bricks_template', 'post_status' => 'publish' ) );

		$this->cache_consumers();
		add_post_meta( $post_id, $meta_key, 'before' );
		$this->assert_consumers_invalidated();

		$this->cache_consumers();
		update_post_meta( $post_id, $meta_key, 'after' );
		$this->assert_consumers_invalidated();

		$this->cache_consumers();
		delete_post_meta( $post_id, $meta_key );
		$this->assert_consumers_invalidated();
	}

	public function test_bricks_template_status_changes_and_deletion_clear_consumers() {
		$this->enable_bricks();
		$post_id = self::factory()->post->create( array( 'post_type' => 'bricks_template', 'post_status' => 'publish' ) );
		$this->cache_consumers();
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		$this->assert_consumers_invalidated();

		$this->cache_consumers();
		wp_delete_post( $post_id, true );
		$this->assert_consumers_invalidated();
	}

	public function test_bricks_observers_ignore_unrelated_metadata_and_revision_writes() {
		$this->enable_bricks();
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$revision_id = self::factory()->post->create(
			array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post_id )
		);
		$this->cache_consumers();
		update_post_meta( $post_id, '_edit_lock', '123:1' );
		update_post_meta( $post_id, '_bricks_unrelated_diagnostic', 'changed' );
		update_metadata( 'post', $revision_id, '_bricks_page_content_2', array( 'revision' ) );
		$this->assertFileExists( $this->page_file );
		$this->assertFileExists( $this->asset_file );
	}
}
