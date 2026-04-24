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
- Avoid unrelated formatting churn. This repo has mixed line endings in places.

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
