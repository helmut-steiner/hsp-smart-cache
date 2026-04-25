# HSP Smart Cache Option Reference

This page documents every stored option in `hspsc_settings`. Options are configured in WordPress under **Settings -> HSP Smart Cache**.

## Page Cache

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `page_cache` | `true` | Boolean | Enables full-page HTML caching for cacheable visitor requests. Cached files are stored in `wp-content/cache/hspsc/pages`. |
| `page_cache_ttl` | `3600` | Seconds, minimum `60` | Controls how long a cached page file is considered fresh before it is regenerated. |
| `cache_logged_in` | `false` | Boolean | Allows page caching for logged-in users. Keep disabled unless logged-in output is safe to share for the current site. |
| `robots_disallow_ai` | `false` | Boolean | Adds `robots.txt` disallow rules for known AI crawler user agents when the site is public. |

## Frontend Optimization Scope

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `optimize_logged_in` | `false` | Boolean | Applies frontend optimizations to logged-in users. When disabled, minify, CDN rewriting, render tweaks, and performance tweaks are skipped for logged-in requests. |

## Browser Cache Headers

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `browser_cache` | `true` | Boolean | Sends browser caching headers for plugin-managed cached pages and assets. |
| `browser_cache_ttl` | `600` | Seconds, minimum `60` | Legacy fallback TTL kept for compatibility with older settings. Newer installs use the HTML and asset TTL options below. |
| `browser_cache_html_ttl` | `600` | Seconds, minimum `60` | Browser cache lifetime for HTML page responses. |
| `browser_cache_asset_ttl` | `31536000` | Seconds, minimum `60` | Browser cache lifetime for generated static assets such as minified CSS and JS files. |

## Static Asset Web Server Rules

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `static_asset_cache` | `true` | Boolean | Enables generated web server rules for long-lived caching of static files such as CSS, JS, images, fonts, audio, and video. |
| `static_asset_ttl` | `31536000` | Seconds, minimum `60` | `max-age` value used in the generated static asset cache rules. |
| `static_asset_immutable` | `true` | Boolean | Adds the `immutable` Cache-Control directive to static asset rules. Best for versioned assets. |
| `static_asset_auto_write` | `false` | Boolean | Automatically writes the generated rules to `.htaccess` when the file is writable. Keep disabled if you prefer manual server config changes. |
| `static_asset_compression` | `false` | Boolean | Adds gzip/deflate compression rules for supported static asset types. |

## Render Optimization

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `render_defer_js` | `true` | Boolean | Adds `defer` to eligible frontend script tags to reduce render blocking. Scripts with inline before/after/data extras are skipped. |
| `render_defer_exclusions` | Empty | Multiline text | Script handles that must not receive `defer`, one handle per line. Example: `jquery`. |
| `render_async_js` | `false` | Boolean | Adds `async` to eligible frontend scripts when defer handling is not used. Use cautiously because async can change execution order. |
| `render_async_exclusions` | Empty | Multiline text | Script handles that must not receive `async`, one handle per line. |
| `render_preconnect_urls` | Empty | Multiline text | Origins to output as `preconnect` hints, one URL per line. Example: `https://fonts.gstatic.com`. |
| `render_preload_fonts` | Empty | Multiline text | Font file URLs to preload early, one URL per line. |
| `render_preload_css` | Empty | Multiline text | Stylesheet URLs to preload, one URL per line. |
| `render_critical_css` | Empty | CSS text | CSS injected inline in the document head to speed up first render. |

## Additional Performance Tweaks

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `perf_lazy_images` | `true` | Boolean | Adds `loading="lazy"` to images where WordPress considers it safe. |
| `perf_lazy_iframes` | `true` | Boolean | Adds `loading="lazy"` to iframes where WordPress considers it safe. |
| `perf_decoding_async` | `true` | Boolean | Adds `decoding="async"` to images that do not already define a decoding mode. |
| `perf_disable_emojis` | `false` | Boolean | Removes WordPress emoji scripts, styles, DNS prefetch hints, and related TinyMCE plugin handling. |
| `perf_disable_embeds` | `false` | Boolean | Disables oEmbed discovery links, embed scripts, and related REST/embed output. |
| `perf_disable_dashicons` | `false` | Boolean | Removes Dashicons for non-logged-in frontend visitors. |
| `perf_dns_prefetch_urls` | Empty | Multiline text | Hostnames or protocol-relative URLs to output as DNS prefetch hints, one per line. Example: `//fonts.googleapis.com`. |

## Cache Preload

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `preload_enabled` | `false` | Boolean | Enables the manual preload action, which warms page cache entries from sitemap URLs. |
| `preload_sitemap_url` | Empty | URL | Sitemap URL used as the source for preload URLs. If empty, the plugin falls back to the site's default sitemap URL. |
| `preload_limit` | `50` | Integer, minimum `1` | Maximum number of URLs warmed during one preload run. |
| `preload_timeout` | `8` | Seconds, minimum `3` | Per-request timeout used while warming each URL. |

## Minification

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `minify_html` | `true` | Boolean | Minifies frontend HTML output by reducing unnecessary whitespace and comments where safe. |
| `minify_css` | `true` | Boolean | Creates and serves minified CSS files from the plugin cache directory. |
| `minify_js` | `true` | Boolean | Creates and serves minified JavaScript files from the plugin cache directory. |

## Object Cache

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `object_cache` | `false` | Boolean | Installs and uses the file-based persistent object cache drop-in from `dropins/object-cache.php`. Disabling removes the drop-in installed by this plugin. |

## CDN

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `cdn_enabled` | `false` | Boolean | Rewrites static asset URLs to the configured CDN base URL on cacheable frontend responses. |
| `cdn_url` | Empty | URL | Base CDN URL used for static asset rewriting. Example: `https://cdn.example.com`. |

## Maintenance Actions

These controls are not stored options, but they appear on the settings screen and affect plugin-managed files or database state.

| Action | Description |
| --- | --- |
| Clear All Caches | Clears page, asset, and object cache files managed by the plugin. |
| Run Cache Tests | Runs built-in diagnostics for cache directories, file writes, object cache state, and related behavior. |
| Run Cache Preload | Warms page cache entries from the configured sitemap when preload is enabled. |
| Apply Bricks Preset | Disables frontend optimizations that overlap with Bricks native performance settings. |
| Restore Defaults | Resets `hspsc_settings` to plugin defaults. |
| Analyze | Reports database cleanup candidates and table optimization information. |
| Clean Database | Deletes revisions, auto-drafts, trashed posts, spam/trashed comments, related metadata, relationships, and expired transients. |
| Create Backup | Creates a timestamped `.sql` or `.sql.gz` database backup in `wp-content/cache/hspsc/db-backups`. |
| Optimize Tables | Creates a backup first, then runs table optimization on WordPress-prefixed tables. |
| Restore Backup | Restores a listed SQL backup after nonce and capability checks. |
| Delete Backup | Deletes a listed SQL backup after nonce and capability checks. |
