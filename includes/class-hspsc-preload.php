<?php
/**
 * Fetches and parses one bounded sitemap document for the rebuild queue.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides sitemap discovery without performing page warming.
 */
class HSPSC_Preload {
	const MAX_DOCUMENT_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Resolves the configured sitemap or WordPress's actual core index URL.
	 *
	 * @return string Absolute sitemap URL.
	 */
	public static function get_sitemap_url() {
		$sitemap = HSPSC_Settings::get( 'preload_sitemap_url', '' );
		if ( is_string( $sitemap ) && '' !== $sitemap ) {
			return $sitemap;
		}
		return function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server()->index->get_index_url() : home_url( '/wp-sitemap.xml' );
	}

	/**
	 * Reads one allowed document without redirects, credentials or entity expansion.
	 *
	 * @param string $url Sitemap URL.
	 * @param int    $timeout HTTP timeout in seconds.
	 * @param int    $byte_budget Remaining discovery byte allowance.
	 * @return array Document kind, decoded locations, bytes read, and a fixed failure reason.
	 */
	public static function fetch_sitemap( $url, $timeout, $byte_budget = self::MAX_DOCUMENT_BYTES ) {
		$result = array(
			'kind'      => '',
			'locations' => array(),
			'bytes'     => 0,
			'reason'    => '',
		);
		$reason = HSPSC_Page::validate_public_warming_url( $url, true );
		if ( '' !== $reason ) {
			$result['reason'] = $reason;
			return $result;
		}
		$max_bytes = min( self::MAX_DOCUMENT_BYTES, (int) $byte_budget );
		if ( $max_bytes < 1 ) {
			$result['reason'] = 'discovery-byte-limit';
			return $result;
		}
		$response = HSPSC_Utils::get_bounded_identity_response(
			$url,
			array( 'Accept' => 'application/xml,text/xml,*/*;q=0.1' ),
			$timeout,
			$max_bytes
		);
		if ( is_wp_error( $response ) ) {
			$result['reason'] = 'request-error';
			return $result;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) ) {
			$result['reason'] = 'invalid-sitemap';
			return $result;
		}
		$result['bytes'] = strlen( $body );
		if ( $result['bytes'] > $max_bytes ) {
			$result['reason'] = $max_bytes < self::MAX_DOCUMENT_BYTES ? 'discovery-byte-limit' : 'sitemap-too-large';
			return $result;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$result['reason'] = 'response-status';
			return $result;
		}
		if ( ! HSPSC_Utils::is_identity_http_response( $response ) ) {
			$result['reason'] = 'response-encoding';
			return $result;
		}
		$parsed = self::parse_document( $body );
		if ( null === $parsed ) {
			$result['reason'] = 'invalid-sitemap';
			return $result;
		}
		$result['kind']      = $parsed['kind'];
		$result['locations'] = $parsed['locations'];
		return $result;
	}

	/**
	 * Accepts complete sitemap documents without requiring an XML extension.
	 *
	 * @param string $body Bounded response body.
	 * @return array|null Root kind and direct location values, or null for invalid XML structure.
	 */
	protected static function parse_document( $body ) {
		if ( stripos( $body, '<!DOCTYPE' ) !== false ) {
			return null;
		}
		$body = preg_replace( '~<!--.*?-->~s', '', ltrim( $body, "\xEF\xBB\xBF \t\r\n" ) );
		if ( ! is_string( $body ) ) {
			return null;
		}
		// The core stylesheet URL has a query string when plain permalinks are used.
		$prefix = '\A(?:\s*<\?xml(?:-stylesheet)?(?=\s)(?>.*?\?>))*\s*';
		if ( preg_match( '~' . $prefix . '<(sitemapindex|urlset)(?=[\s/])[^>]*/\s*>\s*\z~is', $body, $root ) ) {
			return array(
				'kind'      => strtolower( $root[1] ),
				'locations' => array(),
			);
		}
		if ( ! preg_match( '~' . $prefix . '<(sitemapindex|urlset)(?=[\s>])[^>]*>(.*)</\1\s*>\s*\z~is', $body, $root ) ) {
			return null;
		}
		$kind      = strtolower( $root[1] );
		$entry     = 'sitemapindex' === $kind ? 'sitemap' : 'url';
		$pattern   = '~\G\s*<' . $entry . '(?=[\s>])[^>]*>(.*?)</' . $entry . '\s*>~is';
		$locations = array();
		$consumed  = 0;
		while ( 1 === preg_match( $pattern, $root[2], $match, 0, $consumed ) ) {
			$consumed += strlen( $match[0] );
			// The required loc element is first in both sitemap entry types.
			if ( ! preg_match( '~\A\s*<loc(?=[\s>])[^>]*>(.*?)</loc\s*>~is', $match[1], $location ) ) {
				return null;
			}
			$value = trim( $location[1] );
			if ( str_starts_with( $value, '<![CDATA[' ) && str_ends_with( $value, ']]>' ) ) {
				$value = substr( $value, 9, -3 );
			} else {
				if ( preg_match( '~[<>]|&(?!(?:amp|lt|gt|apos|quot|#[0-9]+|#x[0-9a-fA-F]+);)~', $value ) ) {
					return null;
				}
				$value = html_entity_decode( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			}
			$value = trim( $value );
			if ( '' === $value ) {
				return null;
			}
			$locations[] = $value;
		}
		if ( '' !== trim( substr( $root[2], $consumed ) ) ) {
			return null;
		}
		return array(
			'kind'      => $kind,
			'locations' => $locations,
		);
	}
}
