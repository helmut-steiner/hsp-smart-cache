<?php

class HSPSC_Tests_Runner_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        HSPSC_Utils::ensure_cache_dirs();
        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
    }

    public function test_run_returns_expected_result_set() {
        $results = HSPSC_Tests::run();

        $this->assertIsArray( $results );
        $this->assertCount( 5, $results );
		$this->assertSame(
			array( 'cache_dirs', 'minify_html', 'asset_minify', 'page_cache_file_write', 'object_cache_dropin' ),
			array_column( $results, 'code' )
		);

        foreach ( $results as $result ) {
            $this->assertArrayHasKey( 'label', $result );
            $this->assertArrayHasKey( 'status', $result );
            $this->assertArrayHasKey( 'details', $result );
            $this->assertIsBool( $result['status'] );
        }
    }

	/**
	 * A shared result must use the reader's locale without rerunning diagnostics.
	 */
	public function test_stored_german_result_is_rendered_in_french_without_changing_state() {
		$original_translations = $GLOBALS['l10n']['hsp-smart-cache'] ?? null;
		$original_locale       = WP_Translation_Controller::get_instance()->get_locale();
		$original_registry     = $GLOBALS['wp_textdomain_registry'];

		$file = HSPSC_PATH . '/pages/test-cache.html';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local regression fixture.
		$original_content = is_file( $file ) ? file_get_contents( $file ) : false;
		$fixture          = '<html>Diagnostic rendering must not replace this file.</html>';
		$this->assertTrue( HSPSC_Utils::atomic_write( $file, $fixture ) );

		try {
			$this->with_locale(
				'de_DE',
				function () {
					$result = HSPSC_Tests::translate_result(
						array(
							'code'    => 'page_cache_file_write',
							'status'  => false,
							'context' => 'original diagnostic',
						)
					);
					$this->assertSame( 'Schreiben der Seiten-Cache-Datei', $result['label'] );
					$this->assertSame( 'Cache-Datei konnte nicht geschrieben werden. Prüfen Sie die Berechtigungen.', $result['details'] );
					set_transient( 'hspsc_test_results', array( $result ), 300 );
				}
			);

			$stored_results = get_transient( 'hspsc_test_results' );
			$stored_timeout = get_option( '_transient_timeout_hspsc_test_results' );
			$this->with_locale(
				'fr_FR',
				function () use ( $stored_results ) {
					$result = HSPSC_Tests::translate_result( $stored_results[0] );
					$this->assertSame( 'Écriture du fichier de cache de page', $result['label'] );
					$this->assertSame( 'Impossible d’écrire le fichier de cache. Vérifiez les droits d’accès.', $result['details'] );
					$this->assertFalse( $result['status'] );
					$this->assertSame( 'original diagnostic', $result['context'] );
				}
			);

			$this->assertSame( $stored_results, get_transient( 'hspsc_test_results' ) );
			$this->assertSame( $stored_timeout, get_option( '_transient_timeout_hspsc_test_results' ) );
			$this->assertSame( $original_translations, $GLOBALS['l10n']['hsp-smart-cache'] ?? null );
			$this->assertSame( $original_locale, WP_Translation_Controller::get_instance()->get_locale() );
			$this->assertSame( $original_registry, $GLOBALS['wp_textdomain_registry'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Checks a local regression fixture.
			$this->assertSame( $fixture, file_get_contents( $file ) );
		} finally {
			delete_transient( 'hspsc_test_results' );
			if ( false === $original_content ) {
				wp_delete_file( $file );
			} else {
				HSPSC_Utils::atomic_write( $file, $original_content );
			}
		}
	}

	/**
	 * Every diagnostic label must support code-less English results already saved.
	 *
	 * @dataProvider diagnostic_labels
	 * @param string $code    Stable diagnostic code.
	 * @param string $english Original English label.
	 * @param string $french  Expected French label.
	 */
	public function test_legacy_english_results_are_translated( $code, $english, $french ) {
		$this->with_locale(
			'fr_FR',
			function () use ( $code, $english, $french ) {
				$legacy = array(
					'label'   => $english,
					'status'  => true,
					'details' => '',
				);
				$result = HSPSC_Tests::translate_result( $legacy );
				$this->assertSame( $french, $result['label'] );
				$this->assertTrue( $result['status'] );
				$this->assertSame( '', $result['details'] );
				$this->assertArrayNotHasKey( 'code', $result );

				$legacy['code'] = $code;
				$coded_result   = HSPSC_Tests::translate_result( $legacy );
				$this->assertSame( $french, $coded_result['label'] );
				$this->assertSame( $code, $coded_result['code'] );
			}
		);
	}

	/**
	 * Known result identities and independently expected translated labels.
	 *
	 * @return array[] Diagnostic label test cases.
	 */
	public static function diagnostic_labels() {
		return array(
			array( 'cache_dirs', 'Cache directories exist', 'Les répertoires de cache existent' ),
			array( 'minify_html', 'HTML minification works', 'La minification HTML fonctionne' ),
			array( 'asset_minify', 'CSS/JS minification works', 'La minification CSS/JS fonctionne' ),
			array( 'page_cache_file_write', 'Page cache file write', 'Écriture du fichier de cache de page' ),
			array( 'object_cache_dropin', 'Object cache drop-in present', 'L’extension avancée de cache d’objets est présente' ),
		);
	}

	/**
	 * Unrecognized legacy or custom results must retain their original contents.
	 */
	public function test_unknown_results_are_preserved() {
		$results = array(
			array(
				'code'    => 'future_diagnostic',
				'label'   => 'Cache directories exist',
				'status'  => false,
				'details' => 'Custom failure.',
			),
			array(
				'label'   => 'Unrecognized legacy test',
				'status'  => false,
				'details' => 'Original details.',
			),
			array(
				'code'  => array( 'malformed' ),
				'label' => 'Original label',
			),
			array( 'label' => array( 'malformed' ) ),
			array(),
			null,
			'Invalid result',
		);

		foreach ( $results as $result ) {
			$this->assertSame( $result, HSPSC_Tests::translate_result( $result ) );
		}
	}

	/**
	 * Loads bundled translations for a callback without changing site options.
	 *
	 * @param string   $locale   WordPress locale.
	 * @param callable $callback Assertions to run in the requested locale.
	 */
	private function with_locale( $locale, $callback ) {
		$controller            = WP_Translation_Controller::get_instance();
		$original_locale       = $controller->get_locale();
		$original_translations = $GLOBALS['l10n']['hsp-smart-cache'] ?? null;
		$original_unloaded     = $GLOBALS['l10n_unloaded']['hsp-smart-cache'] ?? null;
		$original_registry     = $GLOBALS['wp_textdomain_registry'];
		$locale_filter         = static function () use ( $locale ) {
			return $locale;
		};

		$GLOBALS['wp_textdomain_registry'] = clone $original_registry;
		unset( $GLOBALS['l10n_unloaded']['hsp-smart-cache'] );
		unload_textdomain( 'hsp-smart-cache', true );
		add_filter( 'determine_locale', $locale_filter, PHP_INT_MAX );
		try {
			HSPSC_Plugin::load_textdomain();
			$callback();
		} finally {
			unload_textdomain( 'hsp-smart-cache', true );
			remove_filter( 'determine_locale', $locale_filter, PHP_INT_MAX );
			$controller->set_locale( $original_locale );
			$GLOBALS['wp_textdomain_registry'] = $original_registry;
			// Restore NOOP instances too, so later tests do not trigger new JIT lookups.
			if ( null !== $original_translations ) {
				$GLOBALS['l10n']['hsp-smart-cache'] = $original_translations;
			}
			if ( null !== $original_unloaded ) {
				$GLOBALS['l10n_unloaded']['hsp-smart-cache'] = $original_unloaded;
			} else {
				unset( $GLOBALS['l10n_unloaded']['hsp-smart-cache'] );
			}
		}
	}
}
