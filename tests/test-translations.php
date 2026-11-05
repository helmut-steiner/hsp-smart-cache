<?php
/**
 * Bundled translation loading and plural regression tests.
 *
 * @package HSP_Smart_Cache
 */

/**
 * Exercises WordPress's just-in-time loader against the shipped MO catalogs.
 */
class HSPSC_Translations_Test extends WP_UnitTestCase {

	/**
	 * Translation paths must be registered before normal init callbacks run.
	 */
	public function test_loader_is_registered_on_init() {
		$this->assertSame( 0, has_action( 'init', array( 'HSPSC_Plugin', 'load_textdomain' ) ) );
	}

	/**
	 * Every supported locale must resolve a bundled catalog without a language pack.
	 *
	 * @dataProvider supported_locales
	 * @param string $locale   WordPress locale.
	 * @param string $settings Translated Settings link label.
	 */
	public function test_bundled_catalog_loads_just_in_time( $locale, $settings ) {
		$this->with_locale(
			$locale,
			function () use ( $settings ) {
				$this->assertSame( $settings, __( 'Settings', 'hsp-smart-cache' ) );
				$this->assertTrue( is_textdomain_loaded( 'hsp-smart-cache' ) );
			}
		);
	}

	/**
	 * Available language catalogs and independently expected link labels.
	 *
	 * @return array[] Locale test cases.
	 */
	public static function supported_locales() {
		return array(
			'German'     => array( 'de_DE', 'Einstellungen' ),
			'Spanish'    => array( 'es_ES', 'Ajustes' ),
			'French'     => array( 'fr_FR', 'Réglages' ),
			'Italian'    => array( 'it_IT', 'Impostazioni' ),
			'Portuguese' => array( 'pt_BR', 'Configurações' ),
			'Dutch'      => array( 'nl_NL', 'Instellingen' ),
			'Polish'     => array( 'pl_PL', 'Ustawienia' ),
			'Russian'    => array( 'ru_RU', 'Настройки' ),
			'Japanese'   => array( 'ja', '設定' ),
			'Chinese'    => array( 'zh_CN', '设置' ),
			'Arabic'     => array( 'ar', 'الإعدادات' ),
		);
	}

	/**
	 * Count messages must use the locale's plural rules, including zero and dual.
	 *
	 * @dataProvider plural_examples
	 * @param string $locale   WordPress locale.
	 * @param int    $count    Number of warmed URLs.
	 * @param string $expected Expected formatted translation.
	 */
	public function test_locale_plural_rules( $locale, $count, $expected ) {
		$this->with_locale(
			$locale,
			function () use ( $count, $expected ) {
				$this->assertSame(
					$expected,
					sprintf(
						/* translators: %d: Number of warmed URLs. */
						_n( '%d URL warmed.', '%d URLs warmed.', $count, 'hsp-smart-cache' ),
						$count
					)
				);
			}
		);
	}

	/**
	 * Samples cover all distinct bundled plural-rule families.
	 *
	 * @return array[] Locale, count and expected translation.
	 */
	public static function plural_examples() {
		return array(
			array( 'de_DE', 0, '0 URLs wurden vorgeladen.' ),
			array( 'de_DE', 1, '1 URL wurde vorgeladen.' ),
			array( 'de_DE', 2, '2 URLs wurden vorgeladen.' ),
			array( 'fr_FR', 0, '0 URL préchargée.' ),
			array( 'fr_FR', 1, '1 URL préchargée.' ),
			array( 'fr_FR', 2, '2 URL préchargées.' ),
			array( 'pt_BR', 0, 'Cache preparado para 0 URL.' ),
			array( 'pt_BR', 2, 'Cache preparado para 2 URLs.' ),
			array( 'pl_PL', 1, 'Przygotowano 1 adres URL.' ),
			array( 'pl_PL', 2, 'Przygotowano 2 adresy URL.' ),
			array( 'pl_PL', 12, 'Przygotowano 12 adresów URL.' ),
			array( 'pl_PL', 21, 'Przygotowano 21 adresów URL.' ),
			array( 'pl_PL', 22, 'Przygotowano 22 adresy URL.' ),
			array( 'ru_RU', 1, 'Подготовлен 1 URL.' ),
			array( 'ru_RU', 2, 'Подготовлено 2 URL.' ),
			array( 'ru_RU', 11, 'Подготовлено 11 URL.' ),
			array( 'ru_RU', 21, 'Подготовлен 21 URL.' ),
			array( 'ja', 0, '0 件の URL のキャッシュを生成しました。' ),
			array( 'ja', 2, '2 件の URL のキャッシュを生成しました。' ),
			array( 'zh_CN', 0, '已预热 0 个 URL。' ),
			array( 'zh_CN', 2, '已预热 2 个 URL。' ),
			array( 'ar', 0, 'لم يُجهّز أي رابط (0).' ),
			array( 'ar', 1, 'جُهّز رابط واحد (1).' ),
			array( 'ar', 2, 'جُهّز رابطان (2).' ),
			array( 'ar', 3, 'جُهّزت 3 روابط.' ),
			array( 'ar', 11, 'جُهّز 11 رابطًا.' ),
			array( 'ar', 100, 'جُهّز 100 رابط.' ),
		);
	}

	/**
	 * English remains the source language when no bundled catalog matches.
	 */
	public function test_english_fallback_after_translated_request() {
		$this->with_locale(
			'de_DE',
			function () {
				$this->assertSame( 'Einstellungen', __( 'Settings', 'hsp-smart-cache' ) );
			}
		);
		$this->with_locale(
			'en_US',
			function () {
				$this->assertSame( 'Settings', __( 'Settings', 'hsp-smart-cache' ) );
			}
		);
	}

	/**
	 * Runs a callback with a fresh domain load without changing site/user options.
	 *
	 * @param string   $locale   Locale to resolve for this callback.
	 * @param callable $callback Assertions to run after registering the domain.
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
