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

- WordPress 6.x+
- PHP 7.4+

## Installation

1. Copy the `hsp-smart-cache` folder into `wp-content/plugins/`.
2. Activate **HSP Smart Cache** in WordPress.
3. Go to **Settings → HSP Smart Cache** to configure features.

## Usage

- Enable features individually in **Settings → HSP Smart Cache**.
- Use **Clear All Caches** to purge.
- Use **Run Cache Tests** to validate cache routines and permissions.

## PHPUnit Tests

Local via wp-env:

1. Run `npm install`.
2. Run `npm run wp-env:start`.
3. Run `npm run wp-env:tests`.

CI runs PHPUnit via GitHub Actions.

## Notes

- Logged-in caching is **disabled by default** for safety. Enable only if your site output is safe to cache per user.
- The object cache drop-in is installed/removed automatically when toggled.

## Support

Open an issue on GitHub with:

- WordPress + PHP version
- Plugin version
- Steps to reproduce

## License

MIT. See [LICENSE](LICENSE).
