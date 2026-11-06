<?php
/**
 * Removes plugin settings, owned cache files, and managed server rules.
 *
 * @package HSPSC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
/**
 * Removes plugin-owned data while preserving database backups and foreign drop-ins.
 */
function hspsc_uninstall() {
	delete_option( 'hspsc_settings' );
	delete_option( 'hspsc_cache_schema' );
	delete_option( 'hspsc_object_cache_security_revision' );

	if ( ! defined( 'HSPSC_PATH' ) ) {
		define( 'HSPSC_PATH', WP_CONTENT_DIR . '/cache/hspsc' );
	}

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	WP_Filesystem();
	global $wp_filesystem;
	$fs = $wp_filesystem;

	if ( $fs ) {
		$object_dropin = WP_CONTENT_DIR . '/object-cache.php';
		if ( $fs->exists( $object_dropin ) && hspsc_uninstall_is_own_dropin( $object_dropin, $fs ) ) {
			$fs->delete( $object_dropin );
		}

		$cache_root = realpath( HSPSC_PATH );
		if ( hspsc_delete_dir_contents( HSPSC_PATH, $fs ) && hspsc_uninstall_is_safe_cache_path( HSPSC_PATH, $cache_root ) ) {
			$fs->rmdir( HSPSC_PATH, false );
		}

		$htaccess = ABSPATH . '.htaccess';
		if ( $fs->exists( $htaccess ) && $fs->is_writable( $htaccess ) ) {
			$contents = $fs->get_contents( $htaccess );
			if ( false !== $contents ) {
				$pattern  = '/\n?# BEGIN HSP Smart Cache.*?# END HSP Smart Cache\n?/s';
				$contents = preg_replace( $pattern, '', $contents );
				$fs->put_contents( $htaccess, $contents );
			}
		}
	}
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
/**
 * Removes entries inside the disposable plugin cache tree.
 *
 * @param string             $dir Cache directory to clear.
 * @param WP_Filesystem_Base $fs Initialized WordPress filesystem transport.
 * @param string|null        $root Resolved cache root retained across recursion.
 * @return bool Whether the directory was safe to inspect.
 */
function hspsc_delete_dir_contents( $dir, $fs, $root = null ) {
	$root = null === $root ? realpath( $dir ) : $root;
	if ( ! $fs || ! is_dir( $dir ) || ! hspsc_uninstall_is_safe_cache_path( $dir, $root ) ) {
		return false;
	}
	$items = scandir( $dir );
	if ( ! $items ) {
		return false;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( ! hspsc_uninstall_is_safe_cache_path( $path, $root ) ) {
			continue;
		}
		if ( is_dir( $path ) ) {
			if ( hspsc_delete_dir_contents( $path, $fs, $root ) && hspsc_uninstall_is_safe_cache_path( $path, $root ) ) {
				$fs->rmdir( $path, false );
			}
		} else {
			$fs->delete( $path );
		}
	}
	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
/**
 * Confines deletion to real cache paths, including Windows junction checks.
 *
 * @param string       $path Existing cache entry to inspect.
 * @param string|false $root Resolved cache root.
 * @return bool Whether the entry resolves directly inside the retained root.
 */
function hspsc_uninstall_is_safe_cache_path( $path, $root ) {
	if ( ! is_string( $root ) || '' === $root || is_link( $path ) ) {
		return false;
	}
	$resolved = realpath( $path );
	$parent   = realpath( dirname( $path ) );
	if ( false === $resolved || false === $parent ) {
		return false;
	}
	$resolved = untrailingslashit( wp_normalize_path( $resolved ) );
	$expected = untrailingslashit( wp_normalize_path( $parent . '/' . basename( $path ) ) );
	$root     = untrailingslashit( wp_normalize_path( $root ) );
	if ( '\\' === DIRECTORY_SEPARATOR ) {
		$resolved = strtolower( $resolved );
		$expected = strtolower( $expected );
		$root     = strtolower( $root );
	}
	// is_link() alone does not recognize every Windows directory junction.
	return $resolved === $expected && ( $resolved === $root || strpos( $resolved, $root . '/' ) === 0 );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
/**
 * Checks ownership markers before removing an object-cache drop-in.
 *
 * @param string             $path Drop-in path.
 * @param WP_Filesystem_Base $fs Initialized WordPress filesystem transport.
 * @return bool Whether the file belongs to this plugin.
 */
function hspsc_uninstall_is_own_dropin( $path, $fs ) {
	if ( ! $fs || ! $fs->exists( $path ) ) {
		return false;
	}

	$contents = $fs->get_contents( $path );
	if ( ! is_string( $contents ) ) {
		return false;
	}

	$contents = substr( $contents, 0, 4096 );
	return strpos( $contents, 'HSPSC_File_Object_Cache' ) !== false
		|| strpos( $contents, 'Drop-in object cache for HSP Smart Cache' ) !== false;
}

hspsc_uninstall();
