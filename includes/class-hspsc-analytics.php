<?php
/**
 * Independent Analytics tracking configuration and page-cache compatibility.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invalidates cached tracking markup when Independent Analytics settings change.
 *
 * Ordinary analytics collection and visitor-salt rotation do not change the
 * tracking markup. Their writes must not purge public pages on every visit.
 */
class HSPSC_Analytics {
	/**
	 * Registers small option observers without requiring an analytics installation.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'added_option', array( __CLASS__, 'handle_option_change' ), 10, 1 );
		add_action( 'updated_option', array( __CLASS__, 'handle_option_change' ), 10, 1 );
		add_action( 'deleted_option', array( __CLASS__, 'handle_option_change' ), 10, 1 );
	}

	/**
	 * Clears only rendered pages after tracking or public-counter settings change.
	 *
	 * The click-rule option is deleted when a rule is edited, archived, or removed,
	 * then recreated when the tracker next renders. Both operations affect markup.
	 *
	 * @param string $option Option name that changed.
	 * @return void
	 */
	public static function handle_option_change( $option ) {
		if ( ! is_string( $option ) ) {
			return;
		}
		$tracking_options = array(
			'iawp_link_rules',
			'iawp_request_payload_salt',
			'iawp_track_authenticated_users',
			'iawp_blocked_roles',
			'iawp_ignore_via_cookie',
			'complianz_options_integrations',
		);
		if ( in_array( $option, $tracking_options, true ) || 0 === strpos( $option, 'iawp_view_counter_' ) ) {
			HSPSC_Page::clear_cache();
		}
	}
}
