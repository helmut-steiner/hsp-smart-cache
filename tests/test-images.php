<?php
/**
 * Dependency-free image representation and cache invalidation regressions.
 *
 * @package HSPSC
 */

/**
 * Exercises image-plugin contracts without installing or converting images.
 */
class HSPSC_Images_Test extends WP_UnitTestCase {
	/**
	 * Original server values.
	 *
	 * @var array
	 */
	private $server;

	/**
	 * Original request cookies.
	 *
	 * @var array
	 */
	private $cookies;

	/**
	 * Original query values.
	 *
	 * @var array
	 */
	private $query;

	/**
	 * Static asset fixture.
	 *
	 * @var string
	 */
	private $asset;

	/**
	 * Shared object fixture.
	 *
	 * @var string
	 */
	private $object;

	/**
	 * Prepares isolated public-request and cache fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->server  = $_SERVER;
		$this->cookies = $_COOKIE;
		$this->query   = $_GET;
		$_COOKIE      = array();
		$_GET         = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );
		wp_set_current_user( 0 );
		update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
		HSPSC_Settings::reset_cache();
		$this->go_to( home_url( '/' ) );
		HSPSC_Utils::ensure_cache_dirs();
		HSPSC_Images::init();
		$this->asset  = HSPSC_PATH . '/assets/images-representation-fixture.js';
		$this->object = HSPSC_PATH . '/object/images-representation-fixture.txt';
		file_put_contents( $this->asset, 'static asset fixture' );
		file_put_contents( $this->object, 'shared object fixture' );
	}

	/**
	 * Restores request state and removes owned cache fixtures.
	 */
	public function tear_down(): void {
		HSPSC_Page::clear_cache_for_url( home_url( '/images-representation-fixture/' ) );
		wp_delete_file( $this->asset );
		wp_delete_file( $this->object );
		$_SERVER = $this->server;
		$_COOKIE = $this->cookies;
		$_GET    = $this->query;
		HSPSC_Utils::reset_request_cache();
		parent::tear_down();
	}

	/**
	 * Seeds the same URL with WebP-capable and fallback HTML.
	 *
	 * @return string[] Both negotiated cache paths.
	 */
	private function seed_variants() {
		$url                    = home_url( '/images-representation-fixture/' );
		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/webp';
		$webp                   = HSPSC_Page::get_cache_file_path_for_url( $url );
		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/png';
		$fallback               = HSPSC_Page::get_cache_file_path_for_url( $url );
		file_put_contents( $webp, '<html><img src="photo.webp" alt="WebP"></html>' );
		file_put_contents( $fallback, '<html><img src="photo.jpg" alt="Fallback"></html>' );
		return array( $webp, $fallback );
	}

	/**
	 * Lists the public option contract mirrored by WebP Express configuration saves.
	 *
	 * @return string[] Option names affecting HTML representation.
	 */
	private function html_options() {
		return array(
			'webp-express-alter-html',
			'webp-express-alter-html-hooks',
			'webp-express-alter-html-replacement',
			'webp-express-alter-html-add-picturefill-js',
			'webp-express-alter-html-options',
		);
	}

	/**
	 * Direct image-URL rewrites cannot contaminate fallback HTML for the same URL.
	 */
	public function test_direct_image_urls_use_separate_webp_and_fallback_cache_files() {
		list( $webp, $fallback ) = $this->seed_variants();
		$this->assertNotSame( $webp, $fallback );
		$this->assertStringContainsString( 'photo.webp', file_get_contents( $webp ) );
		$this->assertStringContainsString( 'photo.jpg', file_get_contents( $fallback ) );

		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/webp';
		$this->assertSame( $webp, HSPSC_Page::get_cache_file_path_for_url( home_url( '/images-representation-fixture/' ) ) );
		$_SERVER['HTTP_ACCEPT'] = 'text/html,image/png';
		$this->assertSame( $fallback, HSPSC_Page::get_cache_file_path_for_url( home_url( '/images-representation-fixture/' ) ) );
	}

	/**
	 * Universal picture markup retains both source types after HTML minification.
	 */
	public function test_picture_markup_preserves_webp_source_and_original_fallback() {
		$html   = '<picture><source srcset="photo.webp 1x, photo-2x.webp 2x" type="image/webp"><img src="photo.jpg" srcset="photo.jpg 1x, photo-2x.jpg 2x" alt="Fallback" loading="lazy"></picture>';
		$output = HSPSC_Minify::minify_html( $html );
		$doc    = new DOMDocument();
		$doc->loadHTML( $output, LIBXML_NOERROR | LIBXML_NOWARNING );
		$source = $doc->getElementsByTagName( 'source' )->item( 0 );
		$image  = $doc->getElementsByTagName( 'img' )->item( 0 );
		$this->assertSame( 1, $doc->getElementsByTagName( 'picture' )->length );
		$this->assertSame( 'image/webp', $source->getAttribute( 'type' ) );
		$this->assertSame( 'photo.webp 1x, photo-2x.webp 2x', $source->getAttribute( 'srcset' ) );
		$this->assertSame( 'photo.jpg', $image->getAttribute( 'src' ) );
		$this->assertSame( 'photo.jpg 1x, photo-2x.jpg 2x', $image->getAttribute( 'srcset' ) );
		$this->assertSame( 'lazy', $image->getAttribute( 'loading' ) );
	}

	/**
	 * Additions, edits and removals of every representation option purge both variants.
	 */
	public function test_ordinary_configuration_changes_clear_variants_and_preserve_other_caches() {
		foreach ( $this->html_options() as $option ) {
			foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
				delete_option( $option );
				if ( 'add' !== $operation ) {
					add_option( $option, 'before' );
				}
				list( $webp, $fallback ) = $this->seed_variants();
				if ( 'add' === $operation ) {
					add_option( $option, 'after' );
				} elseif ( 'update' === $operation ) {
					update_option( $option, 'after' );
				} else {
					delete_option( $option );
				}
				$this->assertFileDoesNotExist( $webp, $option . ':' . $operation );
				$this->assertFileDoesNotExist( $fallback, $option . ':' . $operation );
				$this->assertFileExists( $this->asset );
				$this->assertFileExists( $this->object );
			}
		}
	}

	/**
	 * Network option hooks support WebP Express network activation on the same contract.
	 */
	public function test_network_configuration_hooks_clear_all_variants_without_other_cache_flushes() {
		foreach ( $this->html_options() as $option ) {
			foreach ( array( 'add_site_option', 'update_site_option', 'delete_site_option' ) as $hook ) {
				list( $webp, $fallback ) = $this->seed_variants();
				do_action( $hook, $option, 'after', 'before', 1 );
				$this->assertFileDoesNotExist( $webp, $hook . ':' . $option );
				$this->assertFileDoesNotExist( $fallback, $hook . ':' . $option );
				$this->assertFileExists( $this->asset );
				$this->assertFileExists( $this->object );
			}
		}
	}

	/**
	 * Background state updates must not turn every page into a cache miss.
	 */
	public function test_runtime_state_and_unrelated_options_preserve_cached_variants() {
		list( $webp, $fallback ) = $this->seed_variants();
		foreach ( array( 'webp-express-actions-pending', 'webp-express-messages-pending', 'hspsc_image_unrelated' ) as $option ) {
			update_option( $option, 'changed' );
			do_action( 'update_site_option', $option, 'changed', 'before', 1 );
			$this->assertFileExists( $webp, $option );
			$this->assertFileExists( $fallback, $option );
		}
	}
}
