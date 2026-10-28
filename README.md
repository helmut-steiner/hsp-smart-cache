# HSP Smart Cache

Page caching, HTML/CSS/JS minification, CDN rewriting, and file-based object caching for WordPress.

## Features

- Page caching with TTL
- Optional caching for logged-in users
- HTML/CSS/JS minification
- CDN URL rewriting for static assets
- File-based persistent object cache (drop-in)
- One-click cache purge
- Built-in cache tests from the settings screen

## Requirements

- WordPress 7.1.3+
- PHP 8.4+ (PHP 8.5 is also supported)

Install the latest maintenance/security patches for WordPress and your chosen PHP branch. The declared PHP minimum identifies the supported branch; it does not replace ongoing patch updates. This baseline was reviewed on 2026-10-28 using the [WordPress release archive](https://wordpress.org/download/releases/) and [PHP support schedule](https://www.php.net/supported-versions.php).

## Installation

1. Copy the `hsp-smart-cache` folder into `wp-content/plugins/`.
2. Activate **HSP Smart Cache** in WordPress.
3. Go to **Settings → HSP Smart Cache** to configure features.

## Usage

- Enable features individually in **Settings → HSP Smart Cache**.
- Use **Clear All Caches** to purge.
- Use **Run Cache Tests** to validate cache routines and permissions.

## Documentation

- [Option Reference](docs/options.md) documents every stored plugin option, default value, input type, and maintenance action.

## PHPUnit Tests

Local via wp-env:

1. Run `npm install`.
2. Run `npm run wp-env:start`.
3. Run `npm run wp-env:tests`.

CI runs PHPUnit via GitHub Actions.

WPML/WCML, Independent Analytics, Slim SEO, and WebP Express regressions use API fixtures and require no additional plugins, private ZIP files, or license keys in local PHPUnit or CI runs. Optional live integration checks run separately on a local development installation, with tested versions and configurations reported separately.

Compatibility source review covered the supplied Independent Analytics Pro 2.14.10 and Slim SEO Pro 1.11.2 archives, and official WebP Express 0.25.16 source. Fixtures cover the relevant tracking, metadata, and image-representation contracts. Source review and fixtures do not establish live premium-feature validation or certification, and do not bypass license activation.

Local HTTP validation used WPML 5.2.0, String Translation 5.2.0, WCML 5.7.0, and WooCommerce 11.2.1. EN/DE public pages returned the correct content and reached cache hits in directory, query-parameter, and language-domain modes. Translated cart/checkout/account pages and cookie-based or cookie-free multicurrency requests bypassed caching; string translation updates/removals invalidated cached pages. See the [WPML option reference](docs/options.md#wpml) for the tested scope and limits.

## Notes

- Logged-in caching is **disabled by default** for safety. Enable only if your site output is safe to cache per user.
- The object cache drop-in is installed/removed automatically when toggled.
- WooCommerce/FluentCart session and currency requests, configured private commerce pages, and recognized dynamic contexts bypass shared page caching. Stock and price changes invalidate cached catalog output.
- Optional WPML integration isolates language variants for directory, domain, and query-parameter URLs, excludes translated WooCommerce/FluentCart private pages, and bypasses unknown/conflicting language contexts. Language-only WPML pages remain cacheable. WPML scripts retain their source URLs and loading behavior; translation, string removal/source changes, and language-setting changes invalidate cached pages.
- WCML currency cookies or its registered multicurrency API bypass page caching and frontend transformations, including first visits before cookies exist, when geolocation may personalize prices.
- Independent Analytics tracking remains enabled on eligible cached pages. Campaign arguments are removed only from cache keys, preserving the browser URL for tracking. Ignore-visitor cookies bypass caching; tracking, Complianz consent-integration, and public-counter configuration changes invalidate page output. A server-rendered public view counter remains a snapshot until the page TTL expires or pages are cleared.
- Slim SEO metadata, JSON-LD, redirects, and public HTML remain under the SEO plugin's control. Output-affecting option, metadata, link, and successful REST mutations invalidate pages without purging object data or generated assets.
- WebP Express picture markup is preserved. Page-cache entries vary by the exact request `Accept` header, and `Vary: Accept` is sent on cache hits and misses. Changes to HTML-rewriting options invalidate all page representations; non-HTML Markdown and XML responses remain uncached.
- Manual preload preserves XML-escaped language query arguments and accepts only exact configured home, site, or currently API-resolvable WPML language origins. Admin preload may skip other language domains before WPML's full language map is available. Sitemap and page requests do not follow redirects.
- Page-cache namespace and render-cache schema 4 discard older page and asset cache files when the schema changes. URL purges clear every `Accept` variant, and warming forwards the initiating request's `Accept` header. Preloading warms only that representation: an admin AJAX request using `Accept: */*` does not warm a visitor's different browser-navigation header.
- Page capture allows nested plugin buffers and WordPress 6.9+ template enhancements to finish naturally before saving the complete response. Late privacy and representation checks still apply; incomplete or discarded HSP output remains uncached.
- HTML responses are private and revalidated before reuse. Matching gzip/Brotli validators added by server compression can return `304 Not Modified` without downloading the HTML again; private requests still bypass caching. Server-side page caching remains available for eligible public pages.
- Persistent object files are guarded and authenticated; legacy plaintext entries are discarded automatically. Keep WordPress auth keys configured for persistent storage.
- Repeated work is reduced through request-local signing-key reuse, completed legacy-migration tracking, bounded object-cache cleanup, generated-asset reuse, and indexed script dependencies. Request privacy and late language/session changes are still rechecked.
- Response diagnostics expose `X-HSPSC-Cache: HIT`, `MISS`, or `BYPASS`. Bypasses include a fixed `X-HSPSC-Cache-Reason` code without exposing cache paths or cookie values; see the [option reference](docs/options.md#cache-diagnostics).
- New database backups include a required `.sig` sidecar. The built-in restore rejects unsigned or modified backups before running SQL; preserve the backup's filename, signature, site scope, and signing keys.

## Support

Open an issue on GitHub with:

- WordPress + PHP version
- Plugin version
- Steps to reproduce

## License

Copyright (c) 2026 Helmut Steiner. All rights reserved. Proprietary; see [LICENSE](LICENSE). Earlier copies retain the permissions granted under their original licenses. Third-party components retain their own licenses.
