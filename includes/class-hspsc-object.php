<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSPSC_Object {
    const SECURITY_REVISION = 1;
    const SECURITY_REVISION_OPTION = 'hspsc_object_cache_security_revision';

    public static function init() {
        // Refresh an owned installed template after a plugin update, including security fixes.
        if ( ! HSPSC_Settings::get( 'object_cache' ) && (int) get_option( self::SECURITY_REVISION_OPTION, 0 ) >= self::SECURITY_REVISION ) {
            $target = WP_CONTENT_DIR . '/object-cache.php';
            if ( is_link( $target ) || ! file_exists( $target ) || ! self::is_own_dropin( $target ) ) {
                return;
            }
        }
        self::sync_dropin();
    }

    public static function sync_dropin() {
        if ( HSPSC_Settings::get( 'object_cache' ) ) {
            self::install_dropin();
        } else {
            self::remove_dropin();
        }
    }

    public static function install_dropin() {
        $source = trailingslashit( dirname( __DIR__ ) ) . 'dropins/object-cache.php';
        $target = WP_CONTENT_DIR . '/object-cache.php';
        if ( is_link( $target ) || ( file_exists( $target ) && ! self::is_own_dropin( $target ) ) ) {
            return false;
        }
        if ( ! is_readable( $source ) ) {
            return false;
        }
        if ( file_exists( $target ) && hash_file( 'sha256', $source ) === hash_file( 'sha256', $target ) ) {
            return true;
        }
        self::secure_legacy_cache();
        $installed = HSPSC_Utils::atomic_write( $target, file_get_contents( $source ) );
        if ( $installed ) {
            // An older class may already be loaded for this request and write old-format files.
            add_action( 'shutdown', array( __CLASS__, 'secure_legacy_cache' ), PHP_INT_MAX, 0 );
        }
        return $installed;
    }

    public static function remove_dropin() {
        $target = WP_CONTENT_DIR . '/object-cache.php';
        if ( is_dir( WP_CONTENT_DIR . '/cache/hspsc/object' ) ) {
            self::secure_legacy_cache();
        }
        if ( ! is_link( $target ) && file_exists( $target ) && self::is_own_dropin( $target ) ) {
            add_action( 'shutdown', array( __CLASS__, 'secure_legacy_cache' ), PHP_INT_MAX, 0 );
            // WordPress versions before 6.7 do not return a deletion status.
            wp_delete_file( $target );
            return ! file_exists( $target );
        }
        return false;
    }

    public static function flush_cache() {
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        // The standalone implementation preserves its deny rules and stable lock files.
        if ( class_exists( 'HSPSC_File_Object_Cache', false ) && method_exists( 'HSPSC_File_Object_Cache', 'replace' ) ) {
            $cache = new HSPSC_File_Object_Cache();
            $cache->flush();
        } else {
            self::clear_cache_files( HSPSC_PATH . '/object', false );
        }
        self::secure_legacy_cache( false );
    }

    /**
     * Protects storage and removes legacy entries without following symlinks.
     *
     * @param bool $record_completion Whether a successful migration updates its trusted marker.
     * @return bool Whether protection and legacy cleanup completed successfully.
     */
    public static function secure_legacy_cache( $record_completion = true ) {
        $dir = WP_CONTENT_DIR . '/cache/hspsc/object';
        if ( is_link( $dir ) || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) ) {
            delete_option( self::SECURITY_REVISION_OPTION );
            return false;
        }
        $files = array(
            'index.php' => "<?php exit;\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
        );
        $protected = true;
        foreach ( $files as $name => $contents ) {
            $path = $dir . '/' . $name;
            if ( is_link( $path ) || is_dir( $path ) ) {
                $protected = false;
            } elseif ( ! file_exists( $path ) && ! HSPSC_Utils::atomic_write( $path, $contents ) ) {
                $protected = false;
            }
        }
        $complete = self::clear_cache_files( $dir, true );
        if ( $protected && $complete ) {
            if ( $record_completion ) {
                // This tiny trusted marker is needed on each disabled-cache request.
                update_option( self::SECURITY_REVISION_OPTION, self::SECURITY_REVISION, true );
            }
            return true;
        }
        delete_option( self::SECURITY_REVISION_OPTION );
        return false;
    }

    protected static function clear_cache_files( $dir, $legacy_only ) {
        if ( ! is_dir( $dir ) || is_link( $dir ) ) {
            return false;
        }
        $items = scandir( $dir );
        if ( false === $items ) {
            return false;
        }
        $complete = true;
        foreach ( $items as $item ) {
            if ( in_array( $item, array( '.', '..', '.locks' ), true ) ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_link( $path ) ) {
                $complete = false;
                continue;
            }
            if ( is_dir( $path ) ) {
                $complete = self::clear_cache_files( $path, $legacy_only ) && $complete;
            } elseif ( substr( $item, -6 ) === '.cache' || ( ! $legacy_only && preg_match( '/^[a-f0-9]{32}\.php$/D', $item ) ) ) {
                wp_delete_file( $path );
                if ( file_exists( $path ) ) {
                    $complete = false;
                }
            }
        }
        return $complete;
    }

    protected static function is_own_dropin( $path ) {
        if ( ! is_readable( $path ) || is_link( $path ) ) {
            return false;
        }
        $contents = file_get_contents( $path, false, null, 0, 4096 );
        return is_string( $contents ) && ( strpos( $contents, 'HSPSC_File_Object_Cache' ) !== false
            || strpos( $contents, 'Drop-in object cache for HSP Smart Cache' ) !== false );
    }
}
