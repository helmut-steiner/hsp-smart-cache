# AGENTS.md

## Repository Overview

- WordPress plugin: `HSP Smart Cache`.
- Main plugin file: `hsp-smart-cache.php`.
- Public plugin slug and text domain: `hsp-smart-cache`.
- Read the current version from the plugin header, `HSPSC_VERSION`, and `readme.txt` stable tag; do not rely on an outdated version snapshot in documentation.
- Runtime baseline: WordPress 6.0+ and PHP 7.4+. Do not introduce PHP 8-only syntax.
- Internal PHP/code prefix: `HSPSC`.
- Cache directory: `wp-content/cache/hspsc`.
- Primary code lives in `includes/class-hspsc-*.php`.
- Object-cache drop-in template lives in `dropins/object-cache.php`.
- PHPUnit tests live in `tests/`.
- Runtime classes use explicit `require_once` includes. There is no runtime Composer autoloader or frontend build pipeline.

## Lifecycle and Cache Boundaries

- `HSPSC_Plugin` initializes modules on `plugins_loaded`. Activation ensures cache directories/defaults and synchronizes the drop-in; deactivation removes only the owned drop-in.
- Page-cache lookup runs on `template_redirect` at `PHP_INT_MAX - 1`, buffering at `PHP_INT_MAX`, and finalization is armed on `shutdown` at priority 0. An owned output callback saves the complete response after nested buffers finish naturally, including the WordPress 6.9+ template-enhancement buffer. Integrations must have time to declare dynamic responses before lookup; finalization must recheck request privacy, representation, response headers, and buffer ownership. Never drain foreign buffers or cache an early, cleaned, flushed, or replaced HSP buffer.
- CSS/JS URL minification runs at priority 20; CDN rewriting follows at priority 30.
- `flush_all_caches()` clears page, asset, and object caches; `flush_render_caches()` clears page and asset caches only.
- Post/comment changes invalidate pages; public post changes normally flush all pages to cover archives. Terms, themes, customizer changes, and core/plugin/theme updates also trigger invalidation.

| Location | Purpose and ownership |
| --- | --- |
| `HSPSC_PATH` = `WP_CONTENT_DIR . '/cache/hspsc'` | Disposable plugin cache root; public URL is `HSPSC_URL`. |
| `HSPSC_PATH/pages` | Hashed `.html` page-cache files. |
| `HSPSC_PATH/assets` | Generated minified CSS/JS. |
| `HSPSC_PATH/object` | Guarded `.php` entries with authenticated serialized values, stable locks, access-deny files, and `.gc-lock`. |
| `WP_CONTENT_DIR/object-cache.php` | Installed copy of `dropins/object-cache.php`; preserve other plugins' drop-ins. |
| `ABSPATH . '.htaccess'` | Edit only the `BEGIN HSP Smart Cache` / `END HSP Smart Cache` block. Automatic writes default off. |

- Database backups are separate from disposable caches. The default is `<parent-of-ABSPATH>/hspsc-db-backups/<site-scope>/` if that parent is writable, otherwise `WP_CONTENT_DIR/hspsc-db-backups/<site-scope>/`. The `hspsc_db_backup_dir` filter can override it.
- Backup scope includes host/install directory and an installation-path hash. Preserve site isolation and filename/path validation. Default backups survive ordinary cache purges and uninstall; no automatic backup pruning exists.
- Page keys use namespace 4 and include scheme, host, normalized URI, resolved WPML language when active, and user identity for logged-in contexts. Filenames append a hash of the exact request `Accept` header to isolate negotiated HTML/image representations. Marketing query arguments are omitted only from keys; meaningful arguments such as `lang` and the actual browser URL remain intact. Render-cache schema 4 purges older page and asset cache files when the stored schema changes. URL invalidation must clear every `Accept` variant, and warming forwards the initiating request's `Accept` header.
- Keep `cache_logged_in` and `optimize_logged_in` separate; both default off. Page caching and frontend optimization use different eligibility helpers. The logged-in admin toolbar must show BYPASS when authenticated page caching is disabled; show any anonymous URL HIT/STALE/MISS separately in its details.
- Preserve bypasses for backend/login/AJAX, builders/previews, non-GET methods, password-protected content, sensitive cookies/URIs, and `DONOTCACHEPAGE`. Response checks reject redirects, non-success status, `Set-Cookie`, and private/no-cache/no-store responses. These checks are not a complete commerce integration.
- Cache only HTML responses. Preserve supported `Vary: Accept` / `Accept-Encoding` handling and emit `Vary: Accept` on cached hits and misses; unsupported variation fields, malformed/oversized `Accept`, Markdown, and XML must remain uncacheable. Browser HTML remains private and requires revalidation.
- Cache-status headers use fixed bypass codes (`private-request`, `response-headers`, `request-key`, `buffer-conflict`, `late-private-response`, `write-failed`). Never expose visitor cookie values, filesystem paths, or signing secrets in diagnostics.
- Commerce compatibility must cover actual assigned cart/checkout/account pages, session cookies, request parameters, and dynamic widgets on public pages. English URL fragments alone are insufficient. Add guest-cart and multiple-shopper regression coverage when changing these boundaries.
- Keep warming restricted through `HSPSC_Page::is_warmable_url()` before fetching sitemaps/pages. Accept only exact configured home, site, or WPML language origins, including scheme and port; reject userinfo and do not follow redirects. Decode XML `<loc>` entities before URL validation and preserve language query arguments. Preloading is manual and disabled by default.
- Use `HSPSC_Utils::atomic_write()` for normal generated cache files. Keep cleanup limited to the appropriate subtree with age/extension checks.
- The object-cache drop-in loads before normal plugins. Keep it usable without `HSPSC_Settings` or other plugin classes, with independent filesystem/atomic-write helpers and settings fallbacks.
- Preserve WordPress object-cache semantics: distinct raw keys, `$found` for falsey values, multisite/global groups, non-persistent groups, and expiration. Keep settings defaults and early drop-in TTL fallbacks aligned.
- TTL lookups must guard settings reentry: a cold `get_option()` can populate `alloptions` through the same file cache. After `wp_cache_flush()` and `HSPSC_Settings::reset_cache()`, loading settings must remain bounded and retain configured TTLs once loading completes.
- Keep immutable signing-key derivation request-local, and use the trusted completion marker to avoid repeated legacy-storage scans after successful migration. A failed garbage-collection scheduling marker must not trigger full scans on every write. Do not weaken signature verification, migration retries after failure, or storage guards to reduce I/O.
- Preserve minification URL/realpath/allowed-root/extension/size checks, JavaScript syntax, relative asset references, script dependency ordering, and non-JavaScript inline payloads.
- Reuse validated generated assets without rereading source contents on warm hits, and index script dependency membership once per render pass. Keep cheap disabled-feature/private-request guards before optional integration work. Reevaluate privacy before using request-local asset results; do not memoize negative eligibility or request-wide WPML language state.

## Settings and Documentation Changes

- Settings live in the single `hspsc_settings` option. Coordinate changes across `HSPSC_Settings::defaults()`, `sanitize()`, admin UI, consumers, `tests/test-settings.php`, and `docs/options.md`.
- Settings are memoized. Preserve `reset_cache()` hooks. Request privacy decisions deliberately reevaluate cookies, authorization, query state, and no-cache flags each time; do not memoize them across rendering.
- Settings updates synchronize the drop-in and flush object, page, and asset caches through `HSPSC_Admin::handle_settings_update()`; account for these side effects.
- Implementation is authoritative when descriptive docs lag. Updater `tested` metadata differs from `readme.txt`; check both when changing compatibility metadata.
- Object deserialization accepts legitimate WordPress object classes only after verifying a keyed signature. Backup signatures bind exact bytes, filename, and site scope; unsigned older backups are rejected rather than automatically trusted.

## Naming Rules

- Use `HSPSC` for PHP classes, constants, hooks, options, transients, cache keys, and internal identifiers.
- Keep the public plugin name, slug, and text domain as `HSP Smart Cache` / `hsp-smart-cache` unless the release plan explicitly says otherwise.
- Do not add migration code for old `HSP_Smart_Cache`, `HSP_SMART_CACHE`, `hsp_smart_cache`, or `hsp_cache_` identifiers. The owner controls the installed sites and accepts the prefix rename.
- Include files should use the `class-hspsc-*.php` naming pattern.

## Dependencies

- Node/npm is used for `@wordpress/env`.
- Composer is used for PHPUnit dependencies:
  - `phpunit/phpunit`
  - `yoast/phpunit-polyfills`
- `package-lock.json` is intentionally tracked so GitHub Actions npm caching works.
- Keep `composer.lock` tracked too. Composer dependencies are development-only: PHPUnit `^9.6` and Yoast PHPUnit Polyfills `^2.0`.
- `package.json` has an `overrides` entry for `ajv` to avoid the known npm audit issue in the transitive dependency tree.

## Local Testing

Run commands from the repository root. Docker Desktop must be running. Install Node dependencies on a fresh checkout with `npm.cmd ci` on Windows (`npm ci` elsewhere).

Local PHP tests should run inside Docker through wp-env, using the PowerShell wrapper:

```powershell
.\bin\run-wp-env-tests.ps1
```

Useful variants:

```powershell
.\bin\run-wp-env-tests.ps1 -SkipStart
.\bin\run-wp-env-tests.ps1 -PhpUnitArgs @('--filter', 'HSPSC_Page_Test')
```

- The wrapper adds Docker Desktop's CLI directory to PATH when present, prefers Windows `npm.cmd`/`node.exe`, optionally starts wp-env, installs Composer dependencies inside `tests-cli`, and runs PHPUnit there. It does not stop containers afterward.
- On Windows, stop afterward with `npm.cmd run wp-env:stop` (`npm run wp-env:stop` elsewhere).
- `npm run wp-env:tests` directly invokes PHPUnit and assumes the environment/dependencies are already prepared.
- All wp-env npm scripts use `bin/wp-env-named.js` for a stable Compose project name (`hsp-smart-cache`). Use `HSPSC_WP_ENV_PROJECT_NAME` consistently across start/run/stop if overriding it. Prefer this wrapper over direct `npx wp-env`.
- `.wp-env.json` mounts this plugin at `wp-content/plugins/hsp-smart-cache`; development port is 8898 and test port is 8899.
- Run `npm.cmd run test:wp-env-wrapper` when changing the Node wrapper, npm scripts, or environment naming. It needs no Docker.
- `phpunit.xml.dist` discovers `tests/test-*.php` through `tests/bootstrap.php`. Tests extend `WP_UnitTestCase` and need the WordPress test library (`WP_TESTS_DIR`); plain host PHPUnit is insufficient.
- Normal PHPUnit and CI runs use API-shaped WPML/WCML, Independent Analytics, Slim SEO, and WebP Express fixtures and must not require those plugins, private ZIP files, or license keys. Keep supplied plugin archives outside the repository and out of `.wp-env.json` and CI dependencies.
- Optional live WPML/WCML checks run separately in the local development site. Report the supplied plugin versions, enabled features, URL modes, and observed results separately from fixture tests; restore local test changes afterward.
- Match behavioral changes to the corresponding module tests. Preserve cleanup of settings, hooks, request globals, cache files, drop-ins, and database fixtures. Settings-screen diagnostics do not replace PHPUnit coverage.
- Report checks actually run. For documentation-only edits, diff/reference checks suffice; do not start Docker unnecessarily.

When working from WSL, the Linux npm shim may fail. Use Windows commands if needed:

```bash
cmd.exe /d /c "set PATH=C:\Program Files\Docker\Docker\resources\bin;%PATH%&& npm.cmd run wp-env:start"
cmd.exe /d /c "set PATH=C:\Program Files\Docker\Docker\resources\bin;%PATH%&& npm.cmd run wp-env:tests"
cmd.exe /d /c "set PATH=C:\Program Files\Docker\Docker\resources\bin;%PATH%&& npm.cmd run wp-env:stop"
```

Stop wp-env after test runs when possible:

```bash
cmd.exe /d /c "set PATH=C:\Program Files\Docker\Docker\resources\bin;%PATH%&& npm.cmd run wp-env:stop"
```

## CI

- Main test workflow: `.github/workflows/phpunit.yml`.
- CI runs on `ubuntu-latest`.
- Actions currently use Node 24 compatible versions:
  - `actions/checkout@v6`
  - `actions/setup-node@v6`
- CI command path:
  - `npm ci`
  - `./bin/run-wp-env-tests.ps1`
  - `npm run wp-env:stop` in an `always()` cleanup step
- Node is version 24. The separate Node wrapper regression script is not currently called by this workflow.
- `.github/workflows/release.yml` builds a ZIP rooted at `hsp-smart-cache/` on published releases or manual dispatch; asset upload requires a tag ref.

## Release Checklist

- Update the plugin header `Version` in `hsp-smart-cache.php`.
- Update `HSPSC_VERSION` in `hsp-smart-cache.php`.
- Update `Stable tag` in `readme.txt`.
- Add changelog entries to both `CHANGELOG.md` and the `readme.txt` changelog.
- Keep `CHANGELOG.md` newest-first.
- Do not bump versions for unrelated changes unless a release is requested.
- Keep compatibility metadata consistent between `readme.txt` and `HSPSC_Updater` when changing it.
- Preserve ZIP folder/public slug/updater assumptions. Updates come from `helmut-steiner/hsp-smart-cache`; the updater prefers release ZIP assets and reads tagged `readme.txt` using `v<version>`.

## WordPress Plugin Practices

- Always follow the WordPress Coding Standards as the baseline for PHP, JavaScript, CSS, HTML, inline documentation, and accessibility work:
  - Reference: https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
  - Treat the standards as required for new and modified project code unless an existing local pattern intentionally differs.
  - Keep code readable, reviewable, and consistent with the surrounding files.
- Keep direct access guards in runtime PHP files (`uninstall.php` uses `WP_UNINSTALL_PLUGIN`; tests use their bootstrap):

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

- Use WordPress APIs for options, filesystem paths, escaping, sanitization, nonces, capabilities, hooks, and cron.
- For admin actions, verify nonce and capability before mutating cache, settings, filesystem, or database state.
- Escape output close to render time with the appropriate WordPress escaping helper.
- Sanitize input before storing or using it.
- Validate and normalize data before it crosses module boundaries or reaches cache storage.
- Prefer prepared statements through `$wpdb->prepare()` for custom SQL.
- Use strict comparisons where practical and avoid loose truthiness for request data, option values, and cache flags.
- Use `wp_unslash()` before sanitizing request values from `$_GET`, `$_POST`, `$_REQUEST`, or `$_COOKIE`.
- Use `current_user_can()` checks before privileged actions, and choose the narrowest sensible capability.
- Use nonce checks for state-changing admin requests and AJAX/REST endpoints.
- Use WordPress filesystem, HTTP, cron, object cache, transients, and rewrite APIs instead of direct alternatives when those APIs apply.
- Existing low-level atomic writes and early drop-in filesystem operations are intentional; do not make them depend on late plugin initialization.
- Load text through the `hsp-smart-cache` text domain, and escape translated strings with helpers such as `esc_html__()`, `esc_attr__()`, `esc_html_e()`, and `esc_attr_e()`.
- Keep public output accessible: semantic markup, visible labels or screen-reader text for controls, keyboard-compatible interactions, and WCAG AA-aware color contrast.
- Do not introduce runtime dependencies or broad abstractions without a clear need in this plugin.
- Avoid unrelated formatting churn. This repo has mixed line endings in places.

## Maintenance and Security Boundaries

- Keep `manage_options` and action-specific nonces on both `admin_post_hspsc_*` and `wp_ajax_hspsc_*` handlers. Keep form/AJAX paths equivalent in validation, side effects, and escaped feedback.
- Validate filenames/paths before filesystem operations; never broaden cache purges into backup directories or other plugins' files.
- Backup, table optimization, and restore use the current `$wpdb->prefix`. Preserve identifier validation/quoting and the restore SQL allowlist; do not accept arbitrary uploaded SQL as a supported backup.
- Optimization creates a backup first and aborts on failure. Database cleanup does not automatically back up; do not document it as doing so.
- Restore authenticates a regular current-site file and preflights every statement before SQL execution. Preserve these checks and executed/skipped/table/error reporting. Restore is not transactional; runtime SQL errors can follow already-executed statements.
- Preserve backup directory protections (`index.php`, Apache/IIS deny rules) and site isolation. Server rules and random filenames do not establish file integrity.
- Keep serialized/private cache values inaccessible over HTTP; public cache URLs are for publishable output. Hashing a filename does not provide access control.

## WPML Practices

- Keep WPML optional and defensive. `HSPSC_WPML` uses documented language and URL APIs; do not infer URL directories or domains from language codes. WPML supports directories, different domains, and query parameters: https://wpml.org/documentation/getting-started-guide/language-setup/language-url-options/.
- Isolate page output by the resolved WPML language. Unknown or conflicting language signals must bypass page caching and frontend transformations. Reevaluate language cookies and API context before saving; preserve `lang` query arguments.
- Obtain configured language URLs through WPML APIs. `wpml_active_languages` requires an initialized query after the `wp` action; do not assume it works in early bootstrap or admin preload. Only currently API-resolvable origins may warm; other language domains can be skipped before the full map is available. Never extend asset or warming host lists from request data or construct URLs from language codes.
- Preserve translated WooCommerce/FluentCart private-page exclusions through `wpml_object_id`, keeping base page IDs excluded when translations are unavailable. WCML currency cookies or a registered `wcml_price_currency` API must bypass shared page output and frontend transformations, including first visits before cookies exist because geolocation can personalize prices. With a `WCML_VERSION` marker but no currency API, only the public `get_setting( 'enable_multi_currency', null )` result `0` or `'0'` proves multicurrency disabled; unresolved states bypass. Do not invent partial currency variations. Language-only WPML remains eligible for page caching.
- Preserve WPML/WCML script sources, localization data, loading behavior, and `wpml_enqueue_browser_redirect_language` opt-outs. Do not enable WPML visitor-language redirects from this plugin.
- Translation events, string removal, source-language changes, source-string edits, and language-setting changes invalidate page output. Ordinary frontend string registration must not purge cached pages. Do not append a global language suffix to WordPress object-cache keys.
- WPML regression fixtures validate documented API contracts without installing WPML/WCML. Keep optional live checks separate and report only the versions and configurations actually tested; fixtures and local integration checks do not establish WPML certification.
- Recorded local HTTP checks used WPML 5.2.0, String Translation 5.2.0, WCML 5.7.0, and WooCommerce 11.2.1: EN/DE directory/query/domain output and cache hits, translated WooCommerce private-page bypasses, currency-cookie/cookie-free multicurrency bypasses, and string update/removal invalidation passed. Domain checks used a local Apache Host header. This matrix does not establish translated MO-string rendering, gateway flows, browser JavaScript execution, live FluentCart compatibility, or results for other plugin versions.

## Bricks Builder Practices

- Use Bricks Academy as the primary reference for Bricks-specific behavior, APIs, hooks, custom elements, controls, dynamic data, templates, performance, and CLI usage:
  - Reference: https://academy.bricksbuilder.io/
- Prefer documented Bricks APIs and hooks over DOM scraping, fragile CSS selectors, or assumptions about builder internals.
- Keep Bricks integrations optional and defensive. Check that Bricks classes, functions, constants, or theme context exist before using them.
- Avoid breaking sites that do not use Bricks. Bricks-specific hooks should be registered only when Bricks is active or when the relevant API is available.
- When touching generated CSS, dynamic data, templates, or builder output, account for cache invalidation and Bricks CSS generation behavior.
- Do not mutate builder data directly in the database unless there is no documented API path and the change is explicitly requested.
- Preserve Bricks user content, global classes, templates, components, custom attributes, and responsive settings.
- Keep frontend output compatible with Bricks layouts: avoid global CSS/JS that leaks outside the plugin UI or cache feature surface.
- If Bricks code signatures, custom code settings, or security restrictions are involved, follow Bricks documentation and avoid bypassing safeguards.

## Analytics, SEO, and Image Practices

- Keep `HSPSC_Analytics`, `HSPSC_SEO`, and `HSPSC_Images` optional. Their exact option/meta/action/REST observers invalidate pages only; unrelated analytics writes, visitor-fingerprint salt rotation, scanner statistics, and dashboard settings must not flush caches. Plugin activation/deactivation separately invalidates render caches.
- Independent Analytics renders its tracker inline. Preserve its script body, consent attributes, request payload, and the browser's real campaign query string. Changes to `complianz_options_integrations` must invalidate pages because enabling its Independent Analytics integration changes the tracker to an inert consent-gated script. Presence of `iawp_ignore_visitor`, even empty or `0`, bypasses cache lookup and frontend transformations and must be rechecked before saving. Tracking REST/POST requests remain dynamic. A server-rendered public counter remains a page-cache snapshot until TTL expiry or invalidation; do not disable caching across every tracked page to refresh it.
- Slim SEO output includes metadata, JSON-LD, redirects, links, and optional content negotiation. Preserve non-JavaScript inline payloads and reject non-HTML representations. Successful custom-table auto-link/entity REST mutations require targeted page invalidation; errors, reads, and unrelated routes do not.
- WebP Express can change HTML according to the full `Accept` header. Preserve `picture`/`source` markup and responsive attributes; do not guess browser capabilities from a single image-format substring. Observe its HTML-rewriting options, including network changes, and invalidate every representation.
- Source review covered supplied Independent Analytics Pro 2.14.10 and Slim SEO Pro 1.11.2 archives and official WebP Express 0.25.16 source. API fixtures do not establish live premium-feature validation or certification. Never bypass license activation, copy commercial implementation into the repository, or make normal CI depend on licensed plugins.

## WP-CLI Usage

- Use WP-CLI for repeatable WordPress administration and diagnostics when a command-line check is safer or clearer than manual admin UI steps.
- Reference command documentation before using unfamiliar commands:
  - https://developer.wordpress.org/cli/commands/
- Prefer running WP-CLI inside the active local WordPress environment. With `@wordpress/env`, use the npm scripts or `wp-env run cli` style commands already available in the project before assuming a host-level `wp` binary.
- Typical local patterns:

```bash
npm run wp-env:start
npm run wp-env:tests
npm run wp-env:stop
```

```powershell
node .\bin\wp-env-named.js run cli wp plugin list
node .\bin\wp-env-named.js run cli wp option get home
node .\bin\wp-env-named.js run cli wp cache flush
node .\bin\wp-env-named.js run cli wp rewrite flush
node .\bin\wp-env-named.js run cli wp cron event list
node .\bin\wp-env-named.js run cli wp transient list
```

- From Codex in this workspace, prefer the Windows npm shim commands listed in the Local Testing section when Linux/WSL npm fails.
- Be careful with destructive WP-CLI commands such as `wp db reset`, `wp site empty`, `wp post delete --force`, broad `wp search-replace`, and cache purges on shared environments. Confirm intent before running destructive production-like commands.
- For remote WP-CLI, prefer configured aliases in `wp-cli.yml` or `~/.wp-cli/config.yml`, and be explicit about the target environment before changing data.
- Useful diagnostics for this plugin include `wp plugin status hsp-smart-cache`, `wp option list --search=hspsc`, `wp cache flush`, `wp transient list --search=hspsc`, and `wp cron event list`.

## WordPress MCP Usage

- Prefer a connected WordPress MCP server when it gives safer, structured access to the target WordPress site than raw shell, browser automation, or ad hoc HTTP requests.
- Before using WordPress MCP tools, identify the target site/environment and whether the operation is read-only or mutating.
- Use MCP read operations for site inspection, content lookup, settings review, plugin/theme status, and structured WordPress data access.
- For mutating MCP operations, apply the same rules as WordPress admin and WP-CLI work: confirm the target, verify capability/security expectations, keep changes scoped, and avoid destructive actions unless explicitly requested.
- Do not put secrets, application passwords, nonces, cookies, license keys, or production credentials into repository files or chat-visible notes.
- If both WP-CLI and WordPress MCP can perform the task, choose the tool that is more auditable and less risky for the environment. For bulk or scripted local operations, WP-CLI is usually clearer; for structured remote inspection through an approved connector, MCP is usually safer.

## Architecture Map

- `HSPSC_Plugin`: plugin bootstrap, activation/deactivation, global cache flush hooks.
- `HSPSC_Settings`: settings registration/defaults.
- `HSPSC_Commerce`: optional private-page detection and WooCommerce/FluentCart catalog invalidation.
- `HSPSC_WPML`: optional language isolation, configured language URLs, and translation/settings invalidation.
- `HSPSC_Analytics`: optional Independent Analytics tracking/counter configuration invalidation.
- `HSPSC_SEO`: optional Slim SEO metadata, schema, link, and REST mutation invalidation.
- `HSPSC_Images`: optional WebP Express HTML-rewriting option invalidation.
- `HSPSC_Admin`: admin UI and cache action handlers.
- `HSPSC_Page`: page cache behavior and post/comment invalidation.
- `HSPSC_Minify`: asset minification and asset response headers.
- `HSPSC_CDN`: CDN URL rewriting.
- `HSPSC_Object`: object-cache drop-in sync and object cache controls.
- `HSPSC_Static_Assets`: static asset handling.
- `HSPSC_Render`: script loading, resource hints, and critical CSS.
- `HSPSC_Performance`: lazy loading, decoding, resource hints, and optional frontend feature removal.
- `HSPSC_Tests`: built-in diagnostics, separate from PHPUnit.
- `HSPSC_Maintenance`: maintenance/database optimization.
- `HSPSC_Preload`: cache preloading.
- `HSPSC_Updater`: update integration.
- `HSPSC_Utils`: shared helpers.

## Git Hygiene

- The worktree may already be dirty. Do not revert unrelated changes.
- Use `git status --short` before staging or committing.
- Stage only the files relevant to the requested change unless the owner asks for a broad commit.
- Prefer non-interactive git commands.
- Do not edit generated dependencies/test artifacts in `node_modules/`, `vendor/`, or `.phpunit.result.cache`. Avoid unrelated lockfile churn.
