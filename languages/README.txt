HSP Smart Cache translations

English is the source language. Bundled WordPress locales:
de_DE, es_ES, fr_FR, it_IT, pt_BR, nl_NL, pl_PL, ru_RU, ja, zh_CN, ar.

WordPress uses the site language or the signed-in user's profile language.
Locale identifiers must match exactly; other regional variants fall back to
English unless a matching WordPress language pack is installed. A WordPress
language pack takes precedence over the bundled catalog for the same locale.

Files
  hsp-smart-cache.pot          Source message template.
  hsp-smart-cache-<locale>.po  Editable translations, including plural forms.
  hsp-smart-cache-<locale>.mo  Compiled translations loaded by WordPress.

Updating translations (run from the repository root with WP-CLI):

  wp i18n make-pot . languages/hsp-smart-cache.pot --slug=hsp-smart-cache --domain=hsp-smart-cache --exclude=tests,dist,bin --skip-js

Merge the template into each PO file with a gettext editor such as Poedit.
Translate every new message and plural form, remove obsolete entries, and
resolve fuzzy entries. Preserve printf placeholders, such as %d and %1$s,
and technical identifiers, URLs, cache status codes, and plugin names.
Compile all PO files after editing:

  wp i18n make-mo languages

JavaScript UI messages use PHP gettext calls passed to wp_localize_script(),
so they are included by the PHP extraction step. Revisit --skip-js if script
strings move to JavaScript translation APIs in a future change.

Run the WordPress translation regression tests through the repository wrapper:

  .\bin\run-wp-env-tests.ps1 -PhpUnitArgs @('--filter', 'HSPSC_Translations_Test')

The release workflow includes the languages directory automatically. No
translation compiler or additional runtime dependency is needed on the site.

References:
https://developer.wordpress.org/cli/commands/i18n/make-pot/
https://developer.wordpress.org/cli/commands/i18n/make-mo/
https://developer.wordpress.org/reference/functions/load_plugin_textdomain/
