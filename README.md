# CONTENTS OF THIS FILE

 * Introduction
 * When would you need this?
 * Requirements
 * Installation
 * How To Use It
 * Maintainers

## Introduction

**This is not a module you interact with through the UI, you can't enable it
through the admin interface.**

It provides Drush commands for setting up or fixing your local environment
to have proper file permissions. It takes care of creating sites/default/files
and sites/default/private for your Drupal installation and updating them
in config, so you don't have to do that through the UI. It also takes
care of creating .htaccess files in those subdirectories for security.

To set permissions correctly, the module detects your Apache user and group
automatically.

## When would you need this?

You might be seeing one of the below notices:

```
The directory sites/default/files is not writable.
The directory sites/default/private is not writable.
You may need to set the correct directory at the file system settings page
or change the current directory's permissions so that it is writable.
```

## Requirements

 * Drupal 9, 10 or 11
 * Drush 10, 11, 12 or 13

## Installation

```bash
composer require drupal/file_permissions
drush en file_permissions
```

## How To Use It
```bash
drush fp
```

Optionally specify the web server user and group if auto-detection fails:
```bash
drush fp --user=www-data --group=www-data
```

Preview changes without applying them:
```bash
drush fp --dry-run
```

It is **not recommended** to run this with `sudo` as it will create files owned
by the root user.

## Maintainers

Current maintainers:
 * Tomasz Turczynski (Turek) - https://www.drupal.org/user/412235
