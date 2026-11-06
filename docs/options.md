# HSP Smart Cache Option Reference

This page documents every stored option in `hspsc_settings`. Options are configured in WordPress under **Settings -> HSP Smart Cache**.

The admin screen has four tabs:

| Tab | Contents |
| --- | --- |
| Cache | Cache status, public page rebuilds and results, page/browser/static-asset/object-cache settings, and sitemap configuration. |
| Optimization | Frontend optimization scope, minification, script loading, resource hints, image/performance settings, and CDN rewriting. |
| Maintenance | Database analysis, cleanup, optimization, backups, and restore. |
| Advanced | Cache diagnostics, Bricks compatibility preset, crawler rules, and restoring defaults. |

**Cache** opens by default. Switching tabs keeps unsaved values; **Save all settings** submits the complete settings form across tabs. Links to rebuild results open the Cache tab. Tabs support keyboard navigation, and all sections remain available when JavaScript is disabled. Admin and settings pages remain excluded from page caching, including when logged-in caching is enabled.

## Page Cache

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `page_cache` | `true` | Boolean | Enables full-page HTML caching for cacheable visitor requests. Cached files are stored in `wp-content/cache/hspsc/pages`. |
| `page_cache_ttl` | `3600` | Seconds, minimum `60` | Controls how long a cached page file is considered fresh before it is regenerated. |
| `page_cache_excluded_paths` | Empty | Multiline text | Exact host-relative URL paths to exclude from page caching, one per line. Include installation subdirectories and each translated path. Queries are ignored; percent encoding, repeated separators, dot segments, and trailing slashes are normalized. Literal percent signs stay encoded as `%25`, preserving their meaning across repeated saves. Matching is case-sensitive and does not include subpages. Full URLs and wildcards are rejected. Exclusions are checked before cached hits, before buffering, and before final storage; the frontend toolbar shows BYPASS for excluded paths. Asset optimizations remain independent. |
| `cache_logged_in` | `false` | Boolean | Allows eligible frontend HTML to be cached separately for authenticated users. Admin/settings, login, REST, AJAX, cron, builder, commerce-session and other private requests always bypass. |
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
| `browser_cache_html_ttl` | `600` | Seconds, minimum `60` | HTML cache max-age. HTML is private and always revalidated before reuse, so cart, login, and currency changes reach WordPress. |
| `browser_cache_asset_ttl` | `31536000` | Seconds, minimum `60` | Browser cache lifetime for generated static assets such as minified CSS and JS files. |

## Static Asset Web Server Rules

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `static_asset_cache` | `true` | Boolean | Enables Apache rules that revalidate mutable CSS, JS, images, fonts, audio, and video, while caching existing HSP-generated CSS/JS for the configured lifetime. |
| `static_asset_ttl` | `31536000` | Seconds, minimum `60` | `max-age` for HSP-generated CSS/JS under this installation's cache URL. Other static files use `no-cache, max-age=0, must-revalidate`, including original files with a query version. |
| `static_asset_immutable` | `true` | Boolean | Adds `immutable` only to the HSP-generated CSS/JS rule. Their filenames include source path, modification time, and query version. |
| `static_asset_auto_write` | `false` | Boolean | Automatically writes the generated rules to `.htaccess` when the file is writable. Keep disabled if you prefer manual server config changes. |
| `static_asset_compression` | `false` | Boolean | Adds gzip/deflate compression rules for supported static asset types. |

Version 0.9.0 clears existing page/asset caches and refreshes Apache rules only when automatic writes are already enabled. If rules were installed manually, replace the old HSP block with the new settings-screen snippet. Apache 2.4 with `mod_headers` is required; nginx and CDN policies are configured separately. Previously cached long-lived URLs cannot be remotely evicted by changing these rules: use a new asset URL/version for an immediate update of an already-cached file.

Publishing native posts/pages clears page output, including archives. Native shared templates, template parts, synced blocks, navigation, global styles, and custom CSS clear consuming pages and generated assets. Optional Bricks observers cover content/settings metadata and shared templates, including inline CSS; save ordering was reviewed against Bricks 2.4.4. Page writes and purges share a short filesystem lock and persistent generation outside the pages directory. Requests that began loading content before a purge cannot repopulate the cache afterward. Targeted purges retain unrelated existing files but conservatively reject all currently rendering responses.

Local HTTP checks for 0.9.0 used WordPress 7.1.4, PHP 8.4.27, Bricks 2.4.5, and nginx 1.26.1. Native post/page publishing and authenticated Bricks saves in inline/external CSS modes returned updated HTML for old ETags and 304 responses for current ETags. Metadata-only saves of isolated draft header templates invalidated cached pages. Changing a CSS query version with the source timestamp held constant produced a new generated URL serving the updated bytes. Temporary content, login sessions, and asset fixtures were removed, and Bricks settings were restored. These are HTTP/API integration checks, not browser-UI, booking-flow, or general Bricks certification tests. The local nginx configuration already revalidated original static assets; the generated Apache policy was checked separately against a real Apache server.

## Render Optimization

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `render_defer_js` | `true` | Boolean | Uses native WordPress loading strategies for eligible leaf scripts on WordPress 6.3+. Libraries with dependents, inline extras, commerce scripts, and explicit optimization opt-outs are skipped. Older WordPress retains blocking behavior. |
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

## Public Page Cache Rebuild

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `preload_sitemap_url` | Empty | URL | Sitemap or sitemap-index URL used to discover public pages. If empty, uses WordPress's core sitemap URL. |
| `preload_timeout` | `8` | Seconds, `3`–`10` | Timeout per sitemap/page request. Short batches keep work resumable. |

**Rebuild all public page caches** explicitly starts a background job; there is no separate enable checkbox or 50-page overall limit. Obsolete `preload_enabled` and `preload_limit` values are ignored and disappear on the next settings save. The job clears only anonymous page files, then warms the homepage, API-resolvable language roots, and pages discovered through the sitemap tree. Authenticated page files, generated assets, and object entries are preserved. Admin/settings, login, API, builder, and private pages remain excluded.

Sitemap and page URLs must match an exact configured home, site, or currently API-resolvable WPML language origin, including scheme and port. Before WPML's full active-language map is available, unavailable language mappings are reported rather than guessed. Requests never follow redirects or forward login cookies. XML entities in sitemap `<loc>` values are decoded before validation, preserving language queries. Settings AJAX starts preserve the original document's `Accept` header, just like toolbar navigation. Different browser representations can still require their own first request.

Each successful result verifies a fresh, nonempty anonymous cache file and checks that its bytes match the returned HTML. HTTP failures and missing files are failures; private responses are skipped. Progress and paginated results persist in the non-autoloaded `hspsc_rebuild_job` option until a new rebuild or plugin deactivation/uninstall. Reports show cached, skipped, failed, discovery failures, and duration without raw private query values or filesystem paths. A logged-in visit can still show BYPASS because these results describe public visitor caches.

Workers process up to four items within a short time budget, using WordPress cron and authorized visible-browser requests. Work continues after navigation when cron runs; sites that disable WP-Cron need their normal external cron runner. Concurrent workers share a lock. **Retry failed pages** creates a new attempt for failed pages and retryable sitemap fetches only, preserving successful results and avoiding another full purge. The detailed report remains available after dismissing the notice.

Discovery safeguards are explicit: 10,000 pages, 200 sitemap documents, depth 5, 2 MiB per document, 32 MiB downloaded XML per attempt, and 1 MiB of queued URL text. Reaching a limit produces a discovery failure instead of claiming that every page was rebuilt. Pages absent from the sitemap are not discovered automatically; **Rebuild this page's public cache** handles individual eligible URLs.

## Minification

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `minify_html` | `true` | Boolean | Reduces safe markup whitespace/comments while preserving inline scripts/styles, templates, and preformatted text. |
| `minify_css` | `true` | Boolean | Creates cached CSS only when safe. Files with relative URL/import dependencies retain their original URLs. |
| `minify_js` | `true` | Boolean | Conservatively caches eligible classic JavaScript without rewriting its syntax. Modules, importing scripts, commerce/payment handles, WPML/WCML language scripts, and explicit opt-outs retain their original URLs. |

## Object Cache

| Option key | Default | Type | Description |
| --- | --- | --- | --- |
| `object_cache` | `false` | Boolean | Installs and uses the file-based persistent object cache drop-in from `dropins/object-cache.php`. Disabling removes the drop-in installed by this plugin. |
| `object_cache_default_ttl` | `604800` | Seconds, minimum `0` | Default expiration for writes without an explicit TTL; `0` permits unlimited lifetime subject to the maximum. |
| `object_cache_max_ttl` | `2592000` | Seconds, minimum `0` | Caps longer write expirations and bounds legacy unlimited entries; `0` disables the cap. |

Persistent object entries use PHP execution guards and keyed integrity verification before deserialization, with Apache/IIS access-deny files as additional protection. Old plaintext cache files are discarded. Persistence fails closed to request-local memory if usable WordPress signing secrets or protected storage are unavailable. Other plugins' object-cache drop-ins are preserved.

Signing secrets are derived once per drop-in instance. A successfully completed legacy-storage migration is recorded so disabled object caching does not repeatedly scan the directory. Garbage collection requires a writable scheduling marker before scanning; a failed marker write cannot trigger a full scan on every object write.

## Commerce and Dynamic Content

Guest WooCommerce, FluentCart, and recognized EDD/session cookies bypass page caching and frontend transformations. FluentCart cart hashes and currency cookies are checked before cache lookup. Configured cart, checkout, receipt, account, login, and other private commerce pages are excluded through optional integration APIs, including localized/custom slugs. Relevant product stock, price, and catalog changes invalidate cached pages.

Custom integrations can extend `hspsc_has_sensitive_cookies`, `hspsc_is_sensitive_request_uri`, `hspsc_is_request_cacheable`, `hspsc_private_commerce_page_ids`, and `hspsc_is_dynamic_commerce_request`. The `hspsc_script_optimization_exclusions` filter accepts exact script handles or trailing-`*` patterns and applies to minification and loading strategies.

Only complete `200 text/html` responses are cached. Downloads, redirects, cookie-setting/private responses, and unsupported `Vary` contexts are excluded. `Vary: Accept` and `Accept-Encoding` are supported; other variation fields remain excluded. HTML entries use a separate filename for each exact request `Accept` header, including image-format preferences, and send `Vary: Accept` on hits and misses. Malformed or oversized `Accept` headers bypass caching. URL invalidation removes every representation. Markdown, XML, and other non-HTML responses remain uncached.

Requests are rechecked before writing, including late `DONOTCACHEPAGE`, language, and session changes. HTML revalidation protects the origin's session decisions from previously cached browser/edge responses; separately configured caches must honor these headers and exclusions.

## Analytics, SEO, and Image Integrations

These integrations are optional and require no additional HSP Smart Cache setting. They observe changes to published output while leaving eligible public pages cacheable.

| Integration | Behavior and limits |
| --- | --- |
| Independent Analytics / Pro | Preserves inline tracking, consent attributes, payloads, and the actual browser URL used for campaign tracking. Marketing arguments such as `utm_*` are omitted only from the cache filename. Presence of `iawp_ignore_visitor`, including an empty value, bypasses page caching and frontend transformations before lookup and is checked again before saving. REST and POST tracking requests remain uncached. |
| Independent Analytics configuration | Click-rule, tracker-signature, visitor-exclusion, Complianz consent-integration, and public-counter configuration changes clear pages only. Changing Complianz integration settings invalidates old tracker markup so an executable script cannot remain cached after enabling consent gating. Ordinary tracking/statistics writes and visitor-fingerprint salt rotation do not purge pages. Server-rendered public view counts are snapshots until the page TTL expires or pages are purged; use an appropriate TTL when displaying counters. |
| Slim SEO / Pro | Preserves metadata, JSON-LD, redirects, and negotiated non-HTML output. Output-affecting options, post/term metadata, link-manager actions, and successful auto-link/entity REST mutations clear pages only. Scanner statistics and unrelated dashboard settings do not trigger these purges. |
| WebP Express | Preserves `picture`/`source` markup and responsive image attributes. Exact `Accept` variants isolate browser-specific HTML. HTML-rewriting option changes, including network options, clear page variants without purging generated assets or object data. |

Plugin activation and deactivation clear render caches so newly enabled or removed output is not hidden by existing pages. Source review covered supplied Independent Analytics Pro 2.14.10 and Slim SEO Pro 1.11.2 archives and official WebP Express 0.25.16 source. API-shaped regressions run without these plugins, private ZIP files, or license keys. Source review and fixtures do not establish live premium-feature validation or certification; license activation is not bypassed.

Browser HTML revalidation accepts matching server-added gzip/Brotli validators as well as ordinary validators. Only an eligible, fresh page-cache hit can return `304 Not Modified`; login, cart, analytics opt-out, and representation checks still run first. Malformed conditional headers and unsupported compression suffixes receive a normal response. This saves repeat HTML downloads while WordPress startup and privacy checks still run.

This PHP revalidation path applies to HTML pages. CSS and JavaScript served directly by the web server use its own conditional-request handling. The generated Apache rules give long-lived caching only to HSP-generated CSS/JS; mutable original URLs revalidate. Apache's default compression suffix can affect those files too. Static-file ETag/compression policy must be configured in the web server, outside the HTML matcher. Nginx's native gzip normally uses weak ETags instead of Apache-style suffixes; 0.8.1 preserves these weak validators. Standalone nginx ignores `.htaccess`, so its static cache headers and compression need nginx configuration. A proxy/CDN layer can introduce separate behavior.

Local verification covered WordPress behind Apache gzip and nginx 1.30.6 proxying Apache with upstream compression disabled. Matching revalidation returned 304 with zero body, compatible ETag/Vary metadata, and privacy bypasses intact. The nginx proxy check confirmed that PHP generated the 304; it was not a PHP-FPM/FastCGI integration test. Brotli suffix behavior is covered by regression fixtures and source review.

## Cache Diagnostics

Eligible responses expose `X-HSPSC-Cache: HIT` or `MISS`. A bypass exposes `X-HSPSC-Cache: BYPASS` and a fixed `X-HSPSC-Cache-Reason` code when headers can still be sent:

| Reason | Meaning |
| --- | --- |
| `private-request` | Request eligibility checks exclude caching. |
| `response-headers` | Status, content type, or response headers exclude caching. |
| `request-key` | A safe cache key or representation could not be resolved. |
| `buffer-conflict` | HSP's buffer was finalized too early, cleaned, flushed in fragments, or replaced. Ordinary nested buffers may finish naturally before saving. |
| `late-private-response` | A final eligibility, representation, or response check prevented saving. |
| `write-failed` | The generated page could not be written safely. |

Page capture waits for nested buffers, including WordPress 6.9+ template enhancements, to finish naturally. It does not close foreign buffers. Final privacy and representation checks apply to the completed output.

Anonymous HTML files reside in `wp-content/cache/hspsc/pages/`. Authenticated frontend entries use guarded PHP files in `pages/private/`, isolated by user and login session and protected by directory access-deny rules. WordPress admin and settings pages always bypass the page cache. Other privacy exclusions remain active, and frontend optimization retains its separate opt-in. URL purges invalidate anonymous and authenticated representations together.

HTML validators identify the cached response bytes. Cache hits open and validate a response snapshot before emitting HIT headers; missing or unreadable entries fall back to rendering. Asset cleanup retains generated CSS/JS referenced by fresh cached HTML.

With logged-in page caching disabled, the frontend admin toolbar shows `BYPASS` for the current visit. Its details separately report `Public cache: HIT`, `STALE`, or `MISS` for anonymous visitors. A public cache file does not make the authenticated request eligible.

With logged-in caching enabled, the frontend toolbar checks the current login session’s entry when the page is generated. Cached HTML can include that earlier toolbar status. Inspect `X-HSPSC-Cache` in the response headers for the result of the current request.

These diagnostics do not expose cookie values, signing secrets, or filesystem paths. Absence of a header alone does not identify the source of a slow request.

## WPML

WPML integration is optional and requires no additional plugin setting. Documented WPML APIs supply the current language and actual language URLs, supporting directories, separate domains, and `?lang=` URLs. Page-cache keys include the resolved language as well as the URL context. Unknown or conflicting language signals bypass page caching and frontend transformations.

Configured WooCommerce and FluentCart private pages also exclude their WPML page translations through `wpml_object_id`. WCML currency cookies or a registered `wcml_price_currency` multicurrency API bypass page caching and frontend transformations, even without cookies: geolocation can personalize the first visitor's prices. When WCML is present but its currency API is unavailable, an unresolved multicurrency setting also bypasses caching. Language-only WPML pages remain cacheable. WPML/WCML script handles retain their original source URLs, language query arguments, localization data, and loading behavior. HSP Smart Cache does not enable visitor-language redirects or override WPML's redirect enqueue opt-out.

Translation events, string removal, source-language changes, source-string edits, and WPML language-setting changes invalidate cached pages. Ordinary frontend string registration leaves cached pages intact. Page-cache namespace 4 and render-cache schema 6 purge older page and asset cache files when the schema changes. Persistent object-cache keys retain WordPress semantics rather than receiving a global language suffix.

Normal PHPUnit and CI coverage uses API fixtures without requiring WPML/WCML plugins, private ZIP files, or license keys. Optional live integration checks run separately on a local development installation; report their tested versions, configurations, and results independently. These checks do not establish WPML certification.

Local HTTP validation on 2026-10-27 used WPML 5.2.0, String Translation 5.2.0, WCML 5.7.0, and WooCommerce 11.2.1:

| Check | Observed result |
| --- | --- |
| EN/DE public pages in directory and query-parameter modes | Correct language content; first response `MISS`, then `HIT`. |
| EN/DE public pages in language-domain mode | Correct content and `MISS` to `HIT`, using `Host: de.localhost:8898` on local Apache. |
| Translated WooCommerce cart, checkout, and account pages | Bypassed the page cache. |
| Currency cookies and cookie-free multicurrency requests | Bypassed the page cache; repeated DE/EUR, US/USD, DE/EUR country/currency requests retained their correct currency. |
| String Translation row updates and removals | Invalidated the cached page; the next response was `MISS`. |

These checks cover the supplied versions and local HTTP behavior. Translated MO-file string rendering, payment-gateway flows, and browser JavaScript execution were not verified. FluentCart's WPML integration remains covered by API fixtures rather than this WooCommerce live matrix.

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
| Rebuild all public page caches | Queues a public-only purge and verified sitemap rebuild with persistent progress and results. |
| Rebuild this page's public cache | Queues a public-only rebuild of the current eligible frontend URL. |
| Retry failed pages | Requeues only retryable failures from the displayed rebuild, preserving successful/skipped results. |
| Apply Bricks Preset | Disables HSP emoji/embed cleanup, image/iframe lazy loading, and script defer/async. The confirmation lists the corresponding Bricks Performance settings: leave `Disable lazy loading` unchecked, and enable `Disable emojis` / `Disable embed` if those features are not needed. |
| Restore Defaults | Resets `hspsc_settings` to plugin defaults. |
| Analyze | Reports database cleanup candidates and table optimization information. |
| Clean Database | Deletes revisions, auto-drafts, trashed posts, spam/trashed comments, related metadata, relationships, and expired transients. |
| Create Backup | Creates a timestamped `.sql` or `.sql.gz` backup with a `.sig` integrity sidecar in the site-specific backup directory, separate from disposable caches. |
| Optimize Tables | Creates a backup first, then runs table optimization on WordPress-prefixed tables. |
| Restore Backup | Requires capability/nonce checks, a regular file in the current site's backup directory, a valid signature, and complete SQL preflight before executing any statement. |
| Delete Backup | Deletes a listed SQL backup after nonce and capability checks. |

Backups default to `<parent-of-ABSPATH>/hspsc-db-backups/<site-scope>/` when writable, otherwise `WP_CONTENT_DIR/hspsc-db-backups/<site-scope>/`; `hspsc_db_backup_dir` can override this location. Backups survive cache purges and uninstall. Keep the `.sig` file with its matching backup: changing its filename, site scope, contents, or WordPress auth signing keys invalidates restore verification. Older unsigned backups are rejected by the built-in restore; create a new signed backup before using it. Runtime SQL errors can still leave a partial restore because database DDL is not transactional.

Backup creation aborts and removes incomplete output on database-read or file-write failures; optimization will not proceed with such a backup. If restore fails after changing a table, caches are invalidated to reflect the partial database state.

On multisite, backup storage and signatures include the network and blog IDs. Per-site maintenance excludes network/global tables and other blogs' tables. Legacy multisite backups from the old ambiguous shared scope are not automatically adopted; existing signed single-site backups remain compatible. Uninstall refuses directory links or resolved paths outside the disposable cache tree.
