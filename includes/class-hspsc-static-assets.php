<?php
/**
 * Manages scoped Apache caching rules for static assets.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages scoped Apache caching rules for static assets.
 */
class HSPSC_Static_Assets {
	const HTACCESS_BEGIN = '# BEGIN HSP Smart Cache';
	const HTACCESS_END   = '# END HSP Smart Cache';

	/**
	 * Registers settings synchronization and authenticated manual rule application.
	 */
	public static function init() {
		add_action( 'update_option_' . HSPSC_Settings::OPTION_KEY, array( __CLASS__, 'apply_rules' ), 20, 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_apply_on_admin_init' ) );
	}

	/**
	 * Applies requested server rules after capability and nonce checks.
	 */
	public static function maybe_apply_on_admin_init() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$apply = filter_input( INPUT_GET, 'hsp_apply_static_rules', FILTER_UNSAFE_RAW );
		if ( null !== $apply ) {
			$apply = sanitize_key( $apply );
			if ( '1' !== $apply ) {
				return;
			}
			$nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_UNSAFE_RAW );
			if ( ! $nonce || ! wp_verify_nonce( sanitize_text_field( $nonce ), 'hsp_apply_static_rules' ) ) {
				return;
			}
			self::apply_rules( null, get_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() ) );
		}
	}

	/**
	 * Synchronizes the owned Apache block only when automatic writes are enabled.
	 *
	 * @param mixed $old_value Previous settings supplied by the option hook.
	 * @param array $new_value Current sanitized settings.
	 */
	public static function apply_rules( $old_value, $new_value ) {
		$enabled     = ! empty( $new_value['static_asset_cache'] );
		$auto        = ! empty( $new_value['static_asset_auto_write'] );
		$ttl         = isset( $new_value['static_asset_ttl'] ) ? max( 60, intval( $new_value['static_asset_ttl'] ) ) : 604800;
		$immutable   = ! empty( $new_value['static_asset_immutable'] );
		$compression = ! empty( $new_value['static_asset_compression'] );

		if ( ! $auto ) {
			return;
		}

		$fs = HSPSC_Utils::get_filesystem();
		if ( ! $fs ) {
			return;
		}

		$htaccess = ABSPATH . '.htaccess';
		if ( ! $fs->exists( $htaccess ) && ! $fs->is_writable( ABSPATH ) ) {
			return;
		}

		$rules = $enabled ? self::get_htaccess_rules( $ttl, $immutable, $compression ) : '';
		self::update_htaccess_block( $htaccess, $rules );
	}

	/**
	 * Builds revalidation rules with optional immutable generated assets and compression.
	 *
	 * @param int  $ttl Generated-asset freshness lifetime in seconds.
	 * @param bool $immutable Whether to mark generated assets immutable.
	 * @param bool $compression Whether to include Apache compression directives.
	 * @return string Complete managed Apache configuration block.
	 */
	public static function get_htaccess_rules( $ttl, $immutable, $compression = false ) {
		$cache_control = 'public, max-age=' . intval( $ttl );
		if ( $immutable ) {
			$cache_control .= ', immutable';
		}

		// A timestamp on the server cannot refresh a browser's still-fresh URL.
		// Only HSP-generated, versioned filenames may skip revalidation.
		$asset_pattern   = self::get_generated_asset_path_pattern( HSPSC_URL );
		$versioned_rules = '';
		if ( '' !== $asset_pattern ) {
			$versioned_rules = '    Header set Cache-Control "' . $cache_control . '" "expr=%{REQUEST_URI} =~ m#' . $asset_pattern . '# && -f %{REQUEST_FILENAME}"' . "\n";
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
			. '  <FilesMatch "(?i)\\.(' . self::get_cacheable_extensions_pattern() . ")$\">\n"
			. "    Header set Cache-Control \"public, no-cache, max-age=0, must-revalidate\"\n"
			. $versioned_rules
			. "  </FilesMatch>\n"
			. "</IfModule>\n"
			. $compression_rules
			. self::HTACCESS_END . "\n";
	}

	/**
	 * Matches generated assets only within this installation's configured URL.
	 *
	 * @param string $cache_url Public HSP cache URL.
	 * @return string Apache regular expression, or an empty string for unsafe paths.
	 */
	protected static function get_generated_asset_path_pattern( $cache_url ) {
		$path = wp_parse_url( rtrim( $cache_url, '/' ) . '/assets/', PHP_URL_PATH );
		if ( ! is_string( $path ) || '/' !== substr( $path, 0, 1 ) || preg_match( '/[\x00-\x20\x7f"\\\\]/', $path ) || false !== strpos( $path, '%{' ) ) {
			return '';
		}

		return '^' . preg_quote( $path, '#' ) . '[a-f0-9]{32}\\.min\\.(?:css|js)$';
	}

	/**
	 * Checks whether a URL uses a supported static-resource extension.
	 *
	 * @param string $url Resource URL.
	 * @return bool Whether the URL identifies a supported static asset.
	 */
	public static function is_cacheable_asset_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return false;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $extension ) {
			return false;
		}

		return in_array( $extension, self::get_cacheable_extensions(), true );
	}

	/**
	 * Returns the static-resource extensions supported by browser caching.
	 *
	 * @return string[] Supported extensions without leading dots.
	 */
	public static function get_cacheable_extensions() {
		return array(
			'css',
			'js',
			'mjs',
			'png',
			'jpg',
			'jpeg',
			'gif',
			'svg',
			'webp',
			'avif',
			'ico',
			'bmp',
			'tif',
			'tiff',
			'apng',
			'heic',
			'heif',
			'jxl',
			'mp4',
			'm4v',
			'webm',
			'ogv',
			'ogg',
			'mov',
			'avi',
			'wmv',
			'mp3',
			'm4a',
			'aac',
			'wav',
			'flac',
			'opus',
			'pdf',
			'woff2',
			'woff',
			'ttf',
			'otf',
			'eot',
		);
	}

	/**
	 * Builds an escaped Apache extension-alternation pattern.
	 *
	 * @return string Regular-expression alternatives for supported extensions.
	 */
	protected static function get_cacheable_extensions_pattern() {
		return implode( '|', array_map( 'preg_quote', self::get_cacheable_extensions() ) );
	}

	/**
	 * Replaces only the plugin-owned block while preserving unrelated server rules.
	 *
	 * @param string $htaccess Absolute .htaccess path.
	 * @param string $rules Replacement block, or an empty string to remove it.
	 */
	protected static function update_htaccess_block( $htaccess, $rules ) {
		$fs = HSPSC_Utils::get_filesystem();
		if ( ! $fs ) {
			return;
		}
		$contents = '';
		if ( $fs->exists( $htaccess ) ) {
			$contents = $fs->get_contents( $htaccess );
			if ( false === $contents ) {
				return;
			}
		}

		$pattern  = '/\n?' . preg_quote( self::HTACCESS_BEGIN, '/' ) . '.*?' . preg_quote( self::HTACCESS_END, '/' ) . '\n?/s';
		$contents = preg_replace( $pattern, '', $contents );

		if ( ! empty( $rules ) ) {
			$contents = rtrim( $contents ) . "\n\n" . $rules;
		}

		$fs->put_contents( $htaccess, $contents );
	}
}
