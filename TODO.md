# TODO

## Security hardening

### Harden object-cache deserialization

- Review `dropins/object-cache.php` uses of `unserialize()` with `allowed_classes => true`.
- Decide whether cached object support requires all classes, a narrow allowlist, or can tolerate `allowed_classes => false`.
- Consider signing cache payloads to reject tampered files before deserialization.
- Add tests covering scalar cache values, allowed object cache values, rejected/tampered payloads, and cleanup behavior.

Acceptance criteria:

- Cache reads do not instantiate unexpected classes from cache files.
- Existing WordPress object-cache compatibility is preserved where required.
- Invalid or tampered cache files are safely ignored or deleted.

### Add integrity checks for database backups

- Add a manifest or HMAC signature for plugin-created DB backup files.
- Verify integrity before `HSPSC_Maintenance::restore_backup()` executes SQL.
- Keep restore restricted to current-site backup directory and current WordPress table prefix.
- Add tests for valid backups, tampered backups, missing signatures, and wrong-site backup files.

Acceptance criteria:

- Only plugin-created, untampered backups can be restored by default.
- Tampered backup files fail before any SQL statement is executed.
- Error messages remain useful without leaking sensitive filesystem details.
