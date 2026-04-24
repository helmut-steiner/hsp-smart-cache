<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Smart_Cache_Updater {
    const OWNER = 'helmut-steiner';
    const REPO = 'hsp-smart-cache';
    const SLUG = 'hsp-smart-cache';
    const RELEASE_TRANSIENT = 'hsp_smart_cache_github_release';

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_update_transient' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 20, 3 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'filter_upgrader_source_selection' ), 10, 4 );
    }

    public static function filter_update_transient( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
            return $transient;
        }

        $plugin_file = HSP_SMART_CACHE_BASENAME;
        if ( ! isset( $transient->checked[ $plugin_file ] ) ) {
            return $transient;
        }

        $release = self::get_latest_release();
        if ( empty( $release ) ) {
            return $transient;
        }

        $latest_version = self::normalize_version( isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '' );
        if ( $latest_version === '' ) {
            return $transient;
        }

        $package_url = self::resolve_package_url( $release );

        if ( version_compare( $latest_version, HSP_SMART_CACHE_VERSION, '>' ) ) {
            $transient->response[ $plugin_file ] = (object) array(
                'id'           => self::repo_url(),
                'slug'         => self::SLUG,
                'plugin'       => $plugin_file,
                'new_version'  => $latest_version,
                'url'          => self::repo_url(),
                'package'      => $package_url,
                'requires'     => '6.0',
                'tested'       => '6.9',
                'requires_php' => '7.4',
            );
        } else {
            $transient->no_update[ $plugin_file ] = (object) array(
                'id'           => self::repo_url(),
                'slug'         => self::SLUG,
                'plugin'       => $plugin_file,
                'new_version'  => HSP_SMART_CACHE_VERSION,
                'url'          => self::repo_url(),
                'package'      => '',
                'requires'     => '6.0',
                'tested'       => '6.9',
                'requires_php' => '7.4',
            );
        }

        return $transient;
    }

    public static function filter_plugins_api( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== self::SLUG ) {
            return $result;
        }

        $release = self::get_latest_release();
        if ( empty( $release ) ) {
            return $result;
        }

        $version = self::normalize_version( isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '' );
        $body = isset( $release['body'] ) ? (string) $release['body'] : '';
        $published_at = isset( $release['published_at'] ) ? (string) $release['published_at'] : '';

        return (object) array(
            'name'          => 'HSP Smart Cache',
            'slug'          => self::SLUG,
            'version'       => $version !== '' ? $version : HSP_SMART_CACHE_VERSION,
            'author'        => '<a href="https://github.com/' . esc_attr( self::OWNER ) . '">Helmut Steiner</a>',
            'author_profile'=> 'https://github.com/' . self::OWNER,
            'requires'      => '6.0',
            'tested'        => '6.9',
            'requires_php'  => '7.4',
            'last_updated'  => $published_at !== '' ? gmdate( 'Y-m-d', strtotime( $published_at ) ) : '',
            'homepage'      => self::repo_url(),
            'download_link' => self::resolve_package_url( $release ),
            'sections'      => array(
                'description' => wp_kses_post( '<p>Page caching, minification, CDN rewriting, and file-based object caching with settings UI.</p>' ),
                'changelog'   => wp_kses_post( nl2br( esc_html( $body ) ) ),
            ),
        );
    }

    public static function filter_upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( is_wp_error( $source ) || empty( $hook_extra ) || ! is_array( $hook_extra ) ) {
            return $source;
        }

        $action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';
        $type = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';
        $plugins = isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ? $hook_extra['plugins'] : array();

        if ( $action !== 'update' || $type !== 'plugin' || ! in_array( HSP_SMART_CACHE_BASENAME, $plugins, true ) ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            return $source;
        }

        $desired_source = trailingslashit( $remote_source ) . self::SLUG;

        if ( trailingslashit( $source ) === trailingslashit( $desired_source ) ) {
            return $source;
        }

        if ( $wp_filesystem->is_dir( $desired_source ) ) {
            $wp_filesystem->delete( $desired_source, true );
        }

        if ( ! $wp_filesystem->move( $source, $desired_source, true ) ) {
            return new WP_Error(
                'hsp_smart_cache_updater_move_failed',
                __( 'Could not prepare the GitHub release package for installation.', 'hsp-smart-cache' )
            );
        }

        return $desired_source;
    }

    protected static function get_latest_release() {
        $cached = get_site_transient( self::RELEASE_TRANSIENT );
        if ( is_array( $cached ) && ! empty( $cached['tag_name'] ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            self::api_url(),
            array(
                'timeout' => 15,
                'headers' => self::request_headers(),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            set_site_transient( self::RELEASE_TRANSIENT, array(), 10 * MINUTE_IN_SECONDS );
            return array();
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
            set_site_transient( self::RELEASE_TRANSIENT, array(), 10 * MINUTE_IN_SECONDS );
            return array();
        }

        set_site_transient( self::RELEASE_TRANSIENT, $release, HOUR_IN_SECONDS );

        return $release;
    }

    protected static function resolve_package_url( $release ) {
        if ( isset( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                $download_url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
                if ( $download_url !== '' && substr( strtolower( $download_url ), -4 ) === '.zip' ) {
                    return $download_url;
                }
            }
        }

        if ( ! empty( $release['zipball_url'] ) ) {
            return (string) $release['zipball_url'];
        }

        return '';
    }

    protected static function normalize_version( $version ) {
        return ltrim( trim( $version ), "vV" );
    }

    protected static function request_headers() {
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'HSP-Smart-Cache-Updater',
        );

        $token = self::github_token();
        if ( $token !== '' ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    protected static function github_token() {
        $token = defined( 'HSP_SMART_CACHE_GITHUB_TOKEN' ) ? (string) HSP_SMART_CACHE_GITHUB_TOKEN : '';

        $token = apply_filters( 'hspsc_github_token', $token );
        return trim( (string) apply_filters( 'hsp_smart_cache_github_token', $token ) );
    }

    protected static function repo_url() {
        return 'https://github.com/' . self::OWNER . '/' . self::REPO;
    }

    protected static function api_url() {
        return 'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest';
    }
}
