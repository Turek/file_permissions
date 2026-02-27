# Changelog

All notable changes to this project will be documented in this file.

## 2.0.0 (unreleased)

### Breaking changes
- Requires Drupal 9, 10 or 11
- Requires Drush 10, 11, 12 or 13
- Module must now be installed via Composer and enabled via
  `drush en file_permissions`
- Private directory is no longer auto-created — it must be configured in
  `settings.php` via `$settings['file_private_path']` first

### Added
- `composer.json` with `drupal-module` type for Composer installation
- `drush.services.yml` service definition
- `file_permissions.info.yml` replacing `file_permissions.info`
- `src/Commands/FilePermissionsCommands.php` as annotated Drush command class
- `--user` option to override auto-detected httpd user
- `--group` option to override auto-detected httpd group
- `--dry-run` option to preview changes without applying them
- `.htaccess` and `settings.php` are now set to read-only (`0444`)
  instead of inheriting the general file permission (`0664`)
- `prepareDirectory()` is now called for both public and private
  directories to ensure `.htaccess` files exist
- Private directory permissions are now only applied if the path is
  already configured in `settings.php`
- Error message with manual instructions when httpd user or group
  cannot be auto-detected

### Changed
- Replaced `variable_get()` with Drupal config API
- Replaced `drush_shell_exec()` with Symfony Process
- Replaced D7-style `sites/default/private` auto-creation with
  `settings.php` config check

### Removed
- `file_permissions.drush.inc` replaced by annotated command class
- `file_permissions.info` replaced by `file_permissions.info.yml`
- Auto-creation of `sites/default/private` directory

## 1.0.0

- Initial Drupal 8 release
