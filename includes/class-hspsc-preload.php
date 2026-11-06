<?php
/**
 * Warms explicitly allowed sitemap URLs on manual request.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warms explicitly allowed sitemap URLs on manual request.
 */
class HSPSC_Preload {
	/**
	 * Fetches the configured sitemap and attempts bounded manual cache warming.
	 *
	 * @return array Status, attempted URL count, and optional error code.
	 */
	public static function run() {
		if ( ! HSPSC_Settings::get( 'preload_enabled' ) ) {
			return array(
				'ok'    => false,
				'count' => 0,
			);
		}

		$sitemap = HSPSC_Settings::get( 'preload_sitemap_url' );
		if ( empty( $sitemap ) ) {
			$sitemap = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server()->index->get_index_url() : home_url( '/wp-sitemap.xml' );
		}
		if ( ! HSPSC_Page::is_warmable_url( $sitemap ) ) {
			return array(
				'ok'    => false,
				'count' => 0,
				'error' => 'invalid_sitemap_url',
			);
		}

		$limit   = intval( HSPSC_Settings::get( 'preload_limit', 50 ) );
		$timeout = intval( HSPSC_Settings::get( 'preload_timeout', 8 ) );

		$urls  = self::fetch_sitemap_urls( $sitemap, $limit );
		$count = 0;
		foreach ( $urls as $url ) {
			HSPSC_Page::warm_url_with_timeout( $url, $timeout );
			++$count;
		}

		return array(
			'ok'    => true,
			'count' => $count,
		);
	}

	/**
	 * Walks bounded sitemap indexes without following redirects or external origins.
	 *
	 * @param string $sitemap_url Validated sitemap URL.
	 * @param int    $limit Maximum number of URLs to return.
	 * @return string[] Approved decoded sitemap locations.
	 */
	protected static function fetch_sitemap_urls( $sitemap_url, $limit ) {
		$limit           = max( 1, intval( $limit ) );
		$pending         = array( array( $sitemap_url, 0 ) );
		$visited         = array();
		$urls            = array();
		$remaining_bytes = 8 * MB_IN_BYTES;
		$fetched_count   = 0;
		$page_count      = 0;
		while ( $pending && $fetched_count < 50 && $page_count < $limit && $remaining_bytes > 0 ) {
			list( $current_url, $depth ) = array_shift( $pending );
			if ( isset( $visited[ $current_url ] ) || ! HSPSC_Page::is_warmable_url( $current_url ) ) {
				continue;
			}
			$visited[ $current_url ] = true;
			++$fetched_count;
			$max_bytes = min( 2 * MB_IN_BYTES, $remaining_bytes );
			$response  = wp_remote_get(
				$current_url,
				array(
					'timeout'             => 8,
					'redirection'         => 0,
					'limit_response_size' => $max_bytes + 1,
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$body             = wp_remote_retrieve_body( $response );
			$remaining_bytes -= strlen( $body );
			if ( 200 !== wp_remote_retrieve_response_code( $response ) || '' === $body || strlen( $body ) > $max_bytes || stripos( $body, '<!DOCTYPE' ) !== false ) {
				continue;
			}
			// Do not expand XML entities or mistake image/video locations for pages.
			$body = ltrim( $body, "\xEF\xBB\xBF \t\r\n" );
			if ( ! preg_match( '~\A(?:<\?xml\b[^?]*\?>\s*)?(?:<!--.*?-->\s*)*<(sitemapindex|urlset)\b[^>]*>~is', $body, $root ) ) {
				continue;
			}
			$is_index = 'sitemapindex' === strtolower( $root[1] );
			$entry    = $is_index ? 'sitemap' : 'url';
			preg_match_all( '~<' . $entry . '\b[^>]*>(.*?)</' . $entry . '\s*>~is', $body, $entries );
			foreach ( $entries[1] as $entry_body ) {
				if ( ! preg_match( '~<loc\b[^>]*>(.*?)</loc\s*>~is', $entry_body, $location ) ) {
					continue;
				}
				$value = trim( $location[1] );
				if ( str_starts_with( $value, '<![CDATA[' ) && str_ends_with( $value, ']]>' ) ) {
					$value = substr( $value, 9, -3 );
				} else {
					$value = html_entity_decode( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
				}
				$url = esc_url_raw( trim( $value ) );
				if ( ! $url || ! HSPSC_Page::is_warmable_url( $url ) ) {
					continue;
				}
				if ( $is_index ) {
					if ( $depth < 5 && count( $pending ) + $fetched_count < 50 && ! isset( $visited[ $url ] ) ) {
						$pending[] = array( $url, $depth + 1 );
					}
				} else {
					if ( ! isset( $urls[ $url ] ) ) {
						$urls[ $url ] = $url;
						++$page_count;
					}
					if ( $page_count >= $limit ) {
						break;
					}
				}
			}
		}
		return array_values( $urls );
	}
}
