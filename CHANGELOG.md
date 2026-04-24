# Changelog

## 0.3.2

- Rename internal PHP prefixes, hooks, actions, options, transients, and cache paths to the HSPSC prefix.
- Rename include files to use the HSPSC prefix.
- Update tests for the HSPSC prefix rename.

## 0.3.1

- Fixed test pipeline
- Fixed npm security vulnerabilities

## 0.3.0

- Add database optimization analysis summary before running optimize.
- Create automatic timestamped database backups before optimization.
- Add backup management in settings: list, restore, and delete backups.
- Expand automated test coverage across maintenance/admin flows and core feature modules.

## 0.2.1

- Add native WordPress plugin update integration via GitHub Releases.
- Show release details and changelog in the plugin update modal.
- Handle GitHub ZIP extraction folder renaming during update installs.

## 0.2.0

- Disable frontend optimizations for logged-in users by default.
- Add new option to allow frontend optimizations for logged-in users.
- Apply logged-in optimization guard consistently across minify, render, CDN rewrite, and performance tweaks.

## 0.1.9

- Skip cache/minify/render optimizations on Bricks builder and preview requests.
- Avoid adding defer/async to scripts with inline before/after/data extras to prevent editor JS errors.

## 0.1.8

- Never cache backend and login requests (`/wp-admin/`, `wp-login.php`, `wp-register.php`, AJAX).
- Clear all cache layers after WordPress core, plugin, and theme updates.

## 0.1.7

- Avoid caching redirect responses to prevent blank cached pages.

## 0.1.6

- Avoid WP_Filesystem usage in object cache to prevent early-load errors and chown warnings.

## 0.1.5

- Minify inline CSS and JS inside HTML output.
- Standardize internal class references to HSP_Smart_Cache_* (remove legacy aliases).
- Add test coverage for inline minification.

## 0.1.4

- Maintenance and security fixes.

## 0.1.3

- Maintenance and security fixes.

## 0.1.2

- Maintenance and security fixes.

## 0.1.0

- Initial release with page cache, minification, CDN rewriting, object cache drop-in, and settings UI.
