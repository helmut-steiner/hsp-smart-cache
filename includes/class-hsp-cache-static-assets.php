<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Cache_Static_Assets {
    const HTACCESS_BEGIN = '# BEGIN HSP Smart Cache';
    const HTACCESS_END   = '# END HSP Smart Cache';

    public static function init() {
        add_action( 'update_option_' . HSP_Cache_Settings::OPTION_KEY, array( __CLASS__, 'apply_rules' ), 20, 2 );
        add_action( 'admin_init', array( __CLASS__, 'maybe_apply_on_admin_init' ) );
    }

    public static function maybe_apply_on_admin_init() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( isset( $_GET['hsp_apply_static_rules'] ) && $_GET['hsp_apply_static_rules'] === '1' ) {
            self::apply_rules( null, get_option( HSP_Cache_Settings::OPTION_KEY, HSP_Cache_Settings::defaults() ) );
        }
    }

    public static function apply_rules( $old_value, $new_value ) {
        $enabled   = ! empty( $new_value['static_asset_cache'] );
        $auto      = ! empty( $new_value['static_asset_auto_write'] );
        $ttl       = isset( $new_value['static_asset_ttl'] ) ? max( 60, intval( $new_value['static_asset_ttl'] ) ) : 604800;
        $immutable = ! empty( $new_value['static_asset_immutable'] );
        $compression = ! empty( $new_value['static_asset_compression'] );

        if ( ! $auto ) {
            return;
        }

        $htaccess = ABSPATH . '.htaccess';
        if ( ! file_exists( $htaccess ) && ! is_writable( ABSPATH ) ) {
            return;
        }

        $rules = $enabled ? self::get_htaccess_rules( $ttl, $immutable, $compression ) : '';
        self::update_htaccess_block( $htaccess, $rules );
    }

    public static function get_htaccess_rules( $ttl, $immutable, $compression = false ) {
        $cache_control = 'public, max-age=' . intval( $ttl );
        if ( $immutable ) {
            $cache_control .= ', immutable';
        }

        $compression_rules = '';
        if ( $compression ) {
            $compression_rules = "<IfModule mod_deflate.c>\n"
                . "  AddOutputFilterByType DEFLATE text/plain text/html text/xml text/css text/javascript application/javascript application/x-javascript application/json application/xml image/svg+xml\n"
                . "</IfModule>\n"
                . "<IfModule mod_headers.c>\n"
                . "  Header append Vary Accept-Encoding\n"
                . "</IfModule>\n";
        }

        return self::HTACCESS_BEGIN . "\n"
            . "<IfModule mod_headers.c>\n"
            . "  <FilesMatch \"\\.(css|js|png|jpg|jpeg|gif|svg|webp|woff2?|woff|ttf|eot)$\">\n"
            . "    Header set Cache-Control \"" . $cache_control . "\"\n"
            . "  </FilesMatch>\n"
            . "</IfModule>\n"
            . $compression_rules
            . self::HTACCESS_END . "\n";
    }

    protected static function update_htaccess_block( $htaccess, $rules ) {
        $contents = '';
        if ( file_exists( $htaccess ) ) {
            $contents = file_get_contents( $htaccess );
            if ( $contents === false ) {
                return;
            }
        }

        $pattern = '/\n?' . preg_quote( self::HTACCESS_BEGIN, '/' ) . '.*?' . preg_quote( self::HTACCESS_END, '/' ) . '\n?/s';
        $contents = preg_replace( $pattern, '', $contents );

        if ( ! empty( $rules ) ) {
            $contents = rtrim( $contents ) . "\n\n" . $rules;
        }

        file_put_contents( $htaccess, $contents );
    }
}
