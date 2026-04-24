=== HSP Smart Cache ===
Contributors: Helmut Steiner
Tags: cache, caching, performance, minify, cdn
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.3.3
License: MIT
License URI: https://opensource.org/licenses/MIT
Fast page caching and asset optimization for WordPress with simple controls.

== Description ==
HSP Smart Cache is an all-in-one performance plugin for WordPress with practical controls for caching, frontend optimization, and maintenance.

It includes the following functionality:

* **Page caching** with configurable TTL and optional caching for logged-in users.
* **Automatic cache invalidation** when posts, comments, terms, themes, customizer settings, plugins, or core updates change site output.
* **HTML/CSS/JS minification**, including inline CSS/JS minification in HTML output.
* **Asset minification cache** with generated minified files served from the plugin cache directory.
* **CDN URL rewriting** for static assets (CSS, JS, images, fonts).
* **File-based object cache drop-in** with automatic install/remove syncing based on settings.
* **Browser cache headers** for pages and assets with configurable TTL values.
* **Static asset caching rules** for Apache (.htaccess), including optional immutable directives and gzip/deflate compression.
* **Render-blocking optimization** controls: defer/async for scripts, handle exclusions, preconnect hints, preload for fonts/CSS, and inline critical CSS.
* **Additional frontend performance controls**: lazy loading for images/iframes, decoding=async for images, disable emojis/embeds/dashicons-for-guests, and custom DNS prefetch hints.
* **Cache preload** from sitemap URLs with configurable max URLs and per-request timeout.
* **Built-in cache diagnostics** from the settings page.
* **Database maintenance tools**: cleanup (revisions, trashed content, expired transients), optimization analysis summary, table optimization, automatic timestamped backup before optimization, and backup management (list, restore, delete).
* **GitHub release updater integration** for plugin update checks and release changelog display.

The settings page groups these features into clear sections and provides one-click maintenance actions for day-to-day operations.

== Installation ==
1. Upload the `hsp-smart-cache` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure settings under Settings → HSP Smart Cache.

== Frequently Asked Questions ==
= Does it cache logged-in users by default? =
No. You can enable this option in settings if your content is safe to cache per user.

= How do I clear the cache? =
Use the **Clear All Caches** button on the settings page.

= How do I test the cache? =
Use the **Run Cache Tests** button on the settings page.

== Changelog ==

= 0.3.3 =
* Clear stale plugin update responses when the installed version already matches the latest GitHub release.
* Load plugin update details from the GitHub release readme so the modal shows the release description and full changelog.

= 0.3.2 =
* Renamed internal PHP prefixes, hooks, actions, options, transients, and cache paths to the HSPSC prefix.
* Renamed include files to use the HSPSC prefix.
* Updated tests for the HSPSC prefix rename.

= 0.3.1 =
* Fixed test pipeline
* Fixed npm security vulnerabilities

= 0.3.0 =
* Add database optimization analysis summary before running optimize.
* Create automatic timestamped database backups before optimization.
* Add backup management in settings: list, restore, and delete backups.
* Expand automated test coverage across maintenance/admin flows and core feature modules.

= 0.2.1 =
* Add native WordPress plugin update integration via GitHub Releases.
* Show release details/changelog in the plugin update modal.
* Handle GitHub ZIP extraction folder renaming during update installs.

= 0.2.0 =
* Disable frontend optimizations for logged-in users by default.
* Add new option to allow frontend optimizations for logged-in users.
* Apply logged-in optimization guard consistently across minify, render, CDN rewrite, and performance tweaks.

= 0.1.9 =
* Skip cache/minify/render optimizations on Bricks builder and preview requests.
* Avoid adding defer/async to scripts with inline before/after/data extras to prevent editor JS errors.

= 0.1.8 =
* Never cache backend and login requests (`/wp-admin/`, `wp-login.php`, `wp-register.php`, AJAX).
* Clear all cache layers after WordPress core, plugin, and theme updates.

= 0.1.7 =
* Avoid caching redirect responses to prevent blank cached pages.

= 0.1.6 =
* Avoid WP_Filesystem usage in object cache to prevent early-load errors and chown warnings.

= 0.1.5 =
* Minify inline CSS and JS inside HTML output.
* Standardize internal class references to HSP_Smart_Cache_*.
* Add test coverage for inline minification.

= 0.1.4 =
* Maintenance and security fixes.

= 0.1.3 =
* Maintenance and security fixes.

= 0.1.2 =
* Maintenance and security fixes.

= 0.1.0 =
* Initial release.
