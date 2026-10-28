# TODO

## Follow-up behavior review

- Review the optional `cache_logged_in` setting: normal `wordpress_logged_in_*` cookies currently force a bypass even when the option is enabled. Any change must preserve session privacy and existing commerce exclusions.

## Deployment-specific validation

- Keep checkout browser tests aligned with the installed WooCommerce/FluentCart versions, payment gateways, and theme scripts.
- Verify that hosting/CDN caches honor private/no-cache HTML headers and commerce bypasses.

The security regression suite covers authenticated object payloads, guarded storage, legacy cleanup, signing failures, concurrent cache operations, backup integrity, current-site containment, and complete SQL preflight. Object classes remain supported after signature verification. Existing unsigned backups require a new signed backup before using the built-in restore.
