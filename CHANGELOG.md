# Changelog

## 0.5.0

- Add a Bricks compatibility preset that disables overlapping HSP Smart Cache frontend optimizations so Bricks can handle its native performance toggles.
- Add Bricks detection messaging to the settings quick actions area.
- Add admin-post and AJAX handling for applying compatibility presets without a page refresh.
- Add test coverage for the Bricks preset hooks, settings updates, and admin UI output.

## 0.4.0

- Modernize the settings-page action area with cleaner database maintenance and cache operation controls.
- Add AJAX handling for saving settings and long-running maintenance actions so the page no longer refreshes after completion.
- Add loading/disabled button states and accessible live feedback for admin actions.
- Add cleanup candidate reporting for revisions, auto-drafts, trashed posts, spam/trashed comments, and expired transients.
- Improve database cleanup by removing related post/comment metadata and term relationships.
- Restrict database optimization and backups to WordPress-prefixed tables and quote table identifiers.
- Replace JSON database backups with streamed SQL/SQL.GZ backups, protected backup storage, and SQL restore handling.
- Add a manual database backup action separate from table optimization.
- Expand database maintenance, backup, restore, and admin action test coverage.

## 0.3.3

- Clear stale plugin update responses when the installed version already matches the latest GitHub release.
- Load plugin update details from the GitHub release readme so the modal shows the release description and full changelog.

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
