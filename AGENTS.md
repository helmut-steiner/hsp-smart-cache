# AGENTS.md

## Repository Overview

- WordPress plugin: `HSP Smart Cache`.
- Main plugin file: `hsp-smart-cache.php`.
- Public plugin slug and text domain: `hsp-smart-cache`.
- Current plugin version: `0.3.2`.
- Internal PHP/code prefix: `HSPSC`.
- Cache directory: `wp-content/cache/hspsc`.
- Primary code lives in `includes/class-hspsc-*.php`.
- Object-cache drop-in template lives in `dropins/object-cache.php`.
- PHPUnit tests live in `tests/`.

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
- `package.json` has an `overrides` entry for `ajv` to avoid the known npm audit issue in the transitive dependency tree.

## Local Testing

Local tests should run inside Docker through wp-env, using the PowerShell wrapper:

```powershell
.\bin\run-wp-env-tests.ps1
```

Useful variants:

```powershell
.\bin\run-wp-env-tests.ps1 -SkipStart
.\bin\run-wp-env-tests.ps1 -PhpUnitArgs @('--filter', 'HSPSC_Page_Test')
```

From this WSL workspace, the Linux npm shim may fail with a WSL support error. Prefer Windows commands when running wp-env or npm from Codex:

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

## Release Checklist

- Update the plugin header `Version` in `hsp-smart-cache.php`.
- Update `HSPSC_VERSION` in `hsp-smart-cache.php`.
- Update `Stable tag` in `readme.txt`.
- Add changelog entries to both `CHANGELOG.md` and the `readme.txt` changelog.
- Keep `CHANGELOG.md` newest-first.

## WordPress Plugin Practices

- Always follow the WordPress Coding Standards as the baseline for PHP, JavaScript, CSS, HTML, inline documentation, and accessibility work:
  - Reference: https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
  - Treat the standards as required for new and modified project code unless an existing local pattern intentionally differs.
  - Keep code readable, reviewable, and consistent with the surrounding files.
- Keep direct access guards in PHP files:

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
- Load text through the `hsp-smart-cache` text domain, and escape translated strings with helpers such as `esc_html__()`, `esc_attr__()`, `esc_html_e()`, and `esc_attr_e()`.
- Keep public output accessible: semantic markup, visible labels or screen-reader text for controls, keyboard-compatible interactions, and WCAG AA-aware color contrast.
- Do not introduce runtime dependencies or broad abstractions without a clear need in this plugin.
- Avoid unrelated formatting churn. This repo has mixed line endings in places.

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

```bash
npx wp-env run cli wp plugin list
npx wp-env run cli wp option get home
npx wp-env run cli wp cache flush
npx wp-env run cli wp rewrite flush
npx wp-env run cli wp cron event list
npx wp-env run cli wp transient list
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
- `HSPSC_Admin`: admin UI and cache action handlers.
- `HSPSC_Page`: page cache behavior and post/comment invalidation.
- `HSPSC_Minify`: asset minification and asset response headers.
- `HSPSC_CDN`: CDN URL rewriting.
- `HSPSC_Object`: object-cache drop-in sync and object cache controls.
- `HSPSC_Static_Assets`: static asset handling.
- `HSPSC_Render`: rendering helpers.
- `HSPSC_Performance`: performance checks and reporting.
- `HSPSC_Maintenance`: maintenance/database optimization.
- `HSPSC_Preload`: cache preloading.
- `HSPSC_Updater`: update integration.
- `HSPSC_Utils`: shared helpers.

## Git Hygiene

- The worktree may already be dirty. Do not revert unrelated changes.
- Use `git status --short` before staging or committing.
- Stage only the files relevant to the requested change unless the owner asks for a broad commit.
- Prefer non-interactive git commands.
