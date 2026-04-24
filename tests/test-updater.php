<?php

class HSPSC_Updater_FS_Mock {
    public $dirs = array();
    public $deleted = array();
    public $moved = array();
    public $move_result = true;

    public function is_dir( $path ) {
        return ! empty( $this->dirs[ $path ] );
    }

    public function delete( $path, $recursive = false ) {
        $this->deleted[] = array( 'path' => $path, 'recursive' => $recursive );
        unset( $this->dirs[ $path ] );
        return true;
    }

    public function move( $source, $destination, $overwrite = false ) {
        $this->moved[] = array(
            'source'      => $source,
            'destination' => $destination,
            'overwrite'   => $overwrite,
        );
        return $this->move_result;
    }
}

class HSPSC_Updater_Test extends WP_UnitTestCase {
    private $original_wp_filesystem;

    public function set_up(): void {
        parent::set_up();
        global $wp_filesystem;
        $this->original_wp_filesystem = $wp_filesystem;
    }

    public function tear_down(): void {
        global $wp_filesystem;
        $wp_filesystem = $this->original_wp_filesystem;
        delete_site_transient( HSPSC_Updater::RELEASE_TRANSIENT );
        parent::tear_down();
    }

    public function test_filter_update_transient_adds_response_for_newer_release() {
        set_site_transient(
            HSPSC_Updater::RELEASE_TRANSIENT,
            array(
                'tag_name'    => 'v9.9.9',
                'zipball_url' => 'https://example.com/releases/latest.zip',
            )
        );

        $transient = (object) array(
            'checked' => array(
                HSPSC_BASENAME => HSPSC_VERSION,
            ),
        );

        $result = HSPSC_Updater::filter_update_transient( $transient );

        $this->assertNotEmpty( $result->response[ HSPSC_BASENAME ] );
        $this->assertSame( '9.9.9', $result->response[ HSPSC_BASENAME ]->new_version );
    }

    public function test_filter_update_transient_adds_no_update_for_same_version() {
        set_site_transient(
            HSPSC_Updater::RELEASE_TRANSIENT,
            array(
                'tag_name'    => 'v' . HSPSC_VERSION,
                'zipball_url' => 'https://example.com/releases/current.zip',
            )
        );

        $transient = (object) array(
            'checked' => array(
                HSPSC_BASENAME => HSPSC_VERSION,
            ),
        );

        $result = HSPSC_Updater::filter_update_transient( $transient );

        $this->assertNotEmpty( $result->no_update[ HSPSC_BASENAME ] );
        $this->assertSame( HSPSC_VERSION, $result->no_update[ HSPSC_BASENAME ]->new_version );
    }

    public function test_filter_plugins_api_returns_plugin_information() {
        set_site_transient(
            HSPSC_Updater::RELEASE_TRANSIENT,
            array(
                'tag_name'     => 'v1.2.3',
                'body'         => "Line 1\nLine 2",
                'published_at' => '2026-01-05T00:00:00Z',
                'assets'       => array(
                    array( 'browser_download_url' => 'https://example.com/plugin-1.2.3.zip' ),
                ),
            )
        );

        $args = (object) array( 'slug' => HSPSC_Updater::SLUG );

        $result = HSPSC_Updater::filter_plugins_api( false, 'plugin_information', $args );

        $this->assertIsObject( $result );
        $this->assertSame( HSPSC_Updater::SLUG, $result->slug );
        $this->assertSame( '1.2.3', $result->version );
        $this->assertSame( 'https://example.com/plugin-1.2.3.zip', $result->download_link );
        $this->assertStringContainsString( 'Line 1', $result->sections['changelog'] );
    }

    public function test_filter_upgrader_source_selection_moves_release_folder() {
        global $wp_filesystem;
        $wp_filesystem = new HSPSC_Updater_FS_Mock();

        $source = '/tmp/hsp-smart-cache-abcdef';
        $remote_source = '/tmp';
        $desired = trailingslashit( $remote_source ) . HSPSC_Updater::SLUG;
        $wp_filesystem->dirs[ $desired ] = true;

        $result = HSPSC_Updater::filter_upgrader_source_selection(
            $source,
            $remote_source,
            null,
            array(
                'action'  => 'update',
                'type'    => 'plugin',
                'plugins' => array( HSPSC_BASENAME ),
            )
        );

        $this->assertSame( $desired, $result );
        $this->assertNotEmpty( $wp_filesystem->deleted );
        $this->assertNotEmpty( $wp_filesystem->moved );
        $this->assertSame( $source, $wp_filesystem->moved[0]['source'] );
        $this->assertSame( $desired, $wp_filesystem->moved[0]['destination'] );
    }
}
