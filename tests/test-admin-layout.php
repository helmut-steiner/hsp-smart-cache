<?php

class HSPSC_Admin_Layout_Test extends WP_UnitTestCase {
	public function test_tabbed_settings_keep_all_fields_in_one_form_and_actions_separate() {
		$options = array_replace(
			HSPSC_Settings::defaults(),
			array(
				'page_cache_ttl'            => 900,
				'page_cache_excluded_paths' => "/booking/\n/member-area/",
				'cache_logged_in'           => true,
				'optimize_logged_in'        => true,
				'robots_disallow_ai'        => true,
				'render_critical_css'       => '.hero::before { content: "A & B"; }',
				'cdn_enabled'               => true,
				'cdn_url'                   => 'https://cdn.example.org',
			)
		);
		$options_filter = static function () use ( $options ) {
			return $options;
		};
		$diagnostics_filter = static function () {
			return array( array( 'status' => true, 'label' => 'Layout diagnostics marker' ) );
		};
		$backup_dir = trailingslashit( get_temp_dir() ) . 'hspsc-admin-layout-' . wp_generate_uuid4();
		$backup_file = $backup_dir . '/hsp-db-backup-2026-04-25_20-09-17-AbCdEf123456.sql';
		$backup_filter = static function () use ( $backup_dir ) {
			return $backup_dir;
		};
		$original_user = get_current_user_id();
		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->assertTrue( wp_mkdir_p( $backup_dir ) );
			$this->assertNotFalse( file_put_contents( $backup_file, '-- Backup action fixture.' ) );
			if ( is_multisite() ) {
				$sign = new ReflectionMethod( 'HSPSC_Maintenance', 'sign_backup' );
				$this->assertTrue( $sign->invoke( null, $backup_file ) );
			}
			add_filter( 'pre_option_' . HSPSC_Settings::OPTION_KEY, $options_filter );
			add_filter( 'pre_transient_hspsc_test_results', $diagnostics_filter );
			add_filter( 'hspsc_db_backup_dir', $backup_filter );
			$html = $this->render_settings();
			$xpath = $this->parse_markup( $html );

			$this->assert_no_nested_forms( $html );
			$this->assert_settings_form( $xpath, $options );
			$this->assert_panel_membership( $xpath );
			$this->assert_action_forms( $xpath );
		} finally {
			remove_filter( 'pre_option_' . HSPSC_Settings::OPTION_KEY, $options_filter );
			remove_filter( 'pre_transient_hspsc_test_results', $diagnostics_filter );
			remove_filter( 'hspsc_db_backup_dir', $backup_filter );
			HSPSC_Settings::reset_cache();
			wp_set_current_user( $original_user );
			wp_delete_file( $backup_file );
			wp_delete_file( $backup_file . '.sig' );
			if ( is_dir( $backup_dir ) ) {
				rmdir( $backup_dir );
			}
		}
	}

	public function test_settings_page_emits_no_markup_without_manage_options() {
		$original_user = get_current_user_id();
		try {
			foreach ( array( 0, self::factory()->user->create( array( 'role' => 'subscriber' ) ) ) as $user ) {
				wp_set_current_user( $user );
				$this->assertSame( '', $this->render_settings() );
			}
		} finally {
			wp_set_current_user( $original_user );
		}
	}

	private function render_settings() {
		ob_start();
		try {
			HSPSC_Admin::render_settings_page();
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	private function parse_markup( $html ) {
		$document = new DOMDocument();
		$this->assertTrue( $document->loadHTML( '<meta charset="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING ) );
		return new DOMXPath( $document );
	}

	private function assert_no_nested_forms( $html ) {
		// Check the raw tag stream because the DOM parser can repair nested forms.
		$tags = new WP_HTML_Tag_Processor( $html );
		$open = false;
		while ( $tags->next_tag( array( 'tag_name' => 'FORM', 'tag_closers' => 'visit' ) ) ) {
			if ( $tags->is_tag_closer() ) {
				$this->assertTrue( $open, 'Unmatched form closer.' );
				$open = false;
			} else {
				$this->assertFalse( $open, 'A form started inside another form.' );
				$open = true;
			}
		}
		$this->assertFalse( $open, 'Unclosed form.' );
	}

	private function assert_settings_form( $xpath, $options ) {
		$forms = $xpath->query( '//form[@id="hspsc-settings-form"]' );
		$this->assertSame( 1, $forms->length );
		$form = $forms->item( 0 );
		$this->assertSame( 'post', $form->getAttribute( 'method' ) );
		$this->assertSame( 'options.php', basename( wp_parse_url( $form->getAttribute( 'action' ), PHP_URL_PATH ) ) );
		$this->assertSame( 'hspsc_settings_group', $xpath->evaluate( 'string(.//input[@name="option_page"]/@value)', $form ) );
		$this->assertSame( 'update', $xpath->evaluate( 'string(.//input[@name="action"]/@value)', $form ) );
		$this->assertNotFalse( wp_verify_nonce( $xpath->evaluate( 'string(.//input[@name="_wpnonce"]/@value)', $form ), 'hspsc_settings_group-options' ) );

		// Legacy aggregate browser TTL is derived when the visible fields are saved.
		unset( $options['browser_cache_ttl'] );
		$prefix = HSPSC_Settings::OPTION_KEY . '[';
		$seen = array();
		foreach ( $xpath->query( '//*[starts-with(@name,"' . $prefix . '")]' ) as $field ) {
			$name = $field->getAttribute( 'name' );
			$key = substr( $name, strlen( $prefix ), -1 );
			$this->assertSame( $prefix . $key . ']', $name );
			$this->assertArrayHasKey( $key, $options );
			$this->assertArrayNotHasKey( $key, $seen );
			$this->assertSame( 'hspsc-settings-form', $field->getAttribute( 'form' ), $name );
			$this->assertSame( 0, $xpath->query( 'ancestor::form | ancestor::fieldset[@disabled]', $field )->length, $name );
			$this->assertFalse( $field->hasAttribute( 'disabled' ), $name );
			if ( is_bool( $options[ $key ] ) ) {
				$this->assertSame( 'checkbox', $field->getAttribute( 'type' ), $name );
				$this->assertSame( $options[ $key ], $field->hasAttribute( 'checked' ), $name );
				$this->assertSame( '1', $field->getAttribute( 'value' ), $name );
			} else {
				$value = 'textarea' === $field->tagName ? $field->textContent : $field->getAttribute( 'value' );
				$this->assertSame( (string) $options[ $key ], $value, $name );
			}
			$seen[ $key ] = $field;
		}
		$this->assertEqualsCanonicalizing( array_keys( $options ), array_keys( $seen ) );
		$this->assertSame( (string) HSPSC_Rebuild::MAX_TIMEOUT, $seen['preload_timeout']->getAttribute( 'max' ) );
	}

	private function assert_panel_membership( $xpath ) {
		$panel_settings = array(
			'cache'        => array( 'page_cache', 'preload_sitemap_url', 'object_cache' ),
			'optimization' => array( 'minify_html', 'render_defer_js', 'perf_lazy_images', 'cdn_enabled', 'optimize_logged_in' ),
			'maintenance'  => array(),
			'advanced'     => array( 'robots_disallow_ai' ),
		);
		foreach ( $panel_settings as $key => $fields ) {
			$panel = '//*[@id="hspsc-panel-' . $key . '"]';
			$this->assertSame( 1, $xpath->query( $panel )->length, $key );
			$this->assertSame( 1, $xpath->query( '//a[@id="hspsc-tab-' . $key . '" and @href="#hspsc-panel-' . $key . '"]' )->length, $key );
			foreach ( $fields as $name ) {
				$this->assertSame( 1, $xpath->query( $panel . '//*[@name="' . HSPSC_Settings::OPTION_KEY . '[' . $name . ']"]' )->length, $name );
			}
			if ( $fields ) {
				$this->assertSame( 1, $xpath->query( $panel . '//*[@type="submit" and @form="hspsc-settings-form"]' )->length, $key );
			}
		}
		$this->assertSame( 1, $xpath->query( '//*[@id="hspsc-panel-cache"]//*[@id="hspsc-rebuild-report"]' )->length );
		$this->assertSame( 2, $xpath->query( '//*[@id="hspsc-panel-maintenance"]//*[@id="hspsc-db-analysis-panel" or @id="hspsc-db-backups-panel"]' )->length );
		$this->assertStringContainsString( 'Layout diagnostics marker', $xpath->evaluate( 'string(//*[@id="hspsc-panel-advanced"]//*[@id="hspsc-tests-panel"])' ) );
	}

	private function assert_action_forms( $xpath ) {
		$groups = array(
			'cache'       => array( 'hspsc_clear', 'hspsc_run_preload' ),
			'maintenance' => array( 'hspsc_analyze_db', 'hspsc_run_db_cleanup', 'hspsc_create_db_backup', 'hspsc_optimize_db', 'hspsc_restore_db_backup', 'hspsc_delete_db_backup' ),
			'advanced'    => array( 'hspsc_run_tests', 'hspsc_apply_bricks_preset', 'hspsc_restore_defaults' ),
		);
		foreach ( $groups as $panel => $actions ) {
			foreach ( $actions as $action ) {
				$forms = $xpath->query( '//*[@id="hspsc-panel-' . $panel . '"]//form[.//input[@name="action" and @value="' . $action . '"]]' );
				$this->assertSame( 1, $forms->length, $action );
				$form = $forms->item( 0 );
				$this->assertSame( admin_url( 'admin-post.php' ), $form->getAttribute( 'action' ), $action );
				$this->assertSame( 'post', $form->getAttribute( 'method' ), $action );
				$this->assertNotFalse( wp_verify_nonce( $xpath->evaluate( 'string(.//input[@name="_wpnonce"]/@value)', $form ), $action ), $action );
			}
		}
	}
}
