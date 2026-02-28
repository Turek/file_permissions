<?php

namespace Drupal\file_permissions\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Process\Process;
use Drupal\Core\Site\Settings;

/**
 * Drush commands for file permissions.
 */
class FilePermissionsCommands extends DrushCommands {

  /**
   * Rebuild local environment file permissions.
   *
   * @command file-permissions
   * @aliases fp
   * @option user
   *   Web server user to set as owner. Auto-detected if not provided.
   * @option group
   *   Web server group to set as owner. Auto-detected if not provided.
   * @option dry-run
   *   Show what would be changed without applying any changes.
   * @usage drush fp
   *   Auto-detect httpd user and group.
   * @usage drush fp --user=www-data --group=www-data
   *   Use specified user and group instead of auto-detection.
   * @usage drush fp --dry-run
   *   Preview changes without applying them.
   */
  public function filePermissions(
    array $options = [
      'user' => NULL,
      'group' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    ini_set('memory_limit', -1);

    $ds = DIRECTORY_SEPARATOR;
    $root = DRUPAL_ROOT;

    // Get public files path.
    $files = Settings::get('file_public_path', 'sites' . $ds . 'default' . $ds . 'files');
    // Resolve public files absolute path.
    $files_dir = $this->resolvePath($root, $files);

    // Create public files directory if it doesn't exist.
    if (!file_exists($files_dir)) {
      if (!mkdir($files_dir, 0775, TRUE)) {
        $this->logger()->warning(
          dt('Could not create directory: @dir - please create it manually: mkdir -p @dir',
            ['@dir' => $files_dir]
          )
        );
        return;
      }
    }

    // Fix permissions on private dir only if already configured
    // in settings.php.
    $private_path = Settings::get('file_private_path');
    $private_dir = NULL;
    if (empty($private_path)) {
      $this->logger()->warning(dt(
        'Private files directory is not configured. '
        . 'Set $settings[\'file_private_path\'] in settings.php '
        . 'then re-run this command if required.'
      ));
    }
    else {
      $private_dir = $this->resolvePath($root, $private_path);
      if (!file_exists($private_dir)) {
        $this->logger()->warning(dt(
          'Private directory @dir does not exist. '
          . 'Create it manually then re-run this command.',
          ['@dir' => $private_dir]
        ));
        $private_dir = NULL;
      }
    }

    // Use provided httpd user or fall back to auto-detection.
    $user = $options['user'] ?? $this->getHttpdUser();
    if (empty($user)) {
      $this->logger()->error(dt(
        'Could not detect httpd user. '
        . 'Please provide it manually: drush fp --user=www-data'
      ));
      return;
    }

    // Use provided httpd group or fall back to auto-detection.
    $group = $options['group'] ?? $this->getHttpdGroup($user);
    if (empty($group)) {
      $this->logger()->error(dt(
        'Could not detect httpd group. '
        . 'Please provide it manually: drush fp --group=www-data'
      ));
      return;
    }

    $this->logger()->notice(dt('httpd user: @user', ['@user' => $user]));
    $this->logger()->notice(dt('httpd group: @group', ['@group' => $group]));
    $this->logger()->warning(dt('You may be prompted for your sudo password.'));

    $this->applyPermissions($files_dir, $user, $group, $options['dry-run']);
    if ($private_dir !== NULL) {
      $this->applyPermissions($private_dir, $user, $group, $options['dry-run']);
    }

    // Ensure .htaccess files exist in public and private dirs.
    if (!$options['dry-run']) {
      $file_system = \Drupal::service('file_system');
      $file_system->prepareDirectory(
        $files_dir,
        FileSystemInterface::CREATE_DIRECTORY
      );
      if ($private_dir !== NULL) {
        $file_system->prepareDirectory(
          $private_dir,
          FileSystemInterface::CREATE_DIRECTORY
        );
      }
    }

    $this->logger()->success(dt('File permissions updated.'));
  }

  /**
   * Resolve a potentially relative path against the Drupal root.
   *
   * @param string $root
   *   Drupal root path.
   * @param string $path
   *   Absolute or relative path.
   *
   * @return string
   *   Absolute path.
   */
  protected function resolvePath(string $root, string $path): string {
    if (str_starts_with($path, '/')) {
      return $path;
    }
    return $root . DIRECTORY_SEPARATOR . $path;
  }

  /**
   * Detect the httpd user.
   *
   * @return string
   *   The httpd username.
   */
  protected function getHttpdUser(): string {
    $process = Process::fromShellCommandline(
      'ps axho user,group,comm | grep -E "httpd|apache|nginx"'
      . ' | uniq | grep -v "root" | awk \'END {if ($1 && $2) print $1}\''
    );
    $process->run();
    return trim($process->getOutput());
  }

  /**
   * Get the primary group for a given user.
   *
   * @param string $user
   *   The username.
   *
   * @return string
   *   The group name.
   */
  protected function getHttpdGroup(string $user): string {
    $process = Process::fromShellCommandline('id -Gn ' . escapeshellarg($user));
    $process->run();
    $groups = explode(' ', trim($process->getOutput()));
    return $groups[0] ?? $user;
  }

  /**
   * Apply ownership and permissions to a directory.
   *
   * @param string $dir
   *   Target directory path.
   * @param string $user
   *   Owner username.
   * @param string $group
   *   Owner group.
   * @param bool $dry_run
   *   If TRUE, log commands without executing them.
   */
  protected function applyPermissions(
    string $dir,
    string $user,
    string $group,
    bool $dry_run = FALSE,
  ): void {
    $escaped = escapeshellarg($dir);
    $owner = escapeshellarg($user . ':' . $group);

    // Set ownership.
    $this->runSudo("chown -Rh {$owner} {$escaped}", $dry_run);
    $this->runSudo("chgrp -R {$group} {$escaped}", $dry_run);

    // Set 2775 on dirs.
    // The setgid bit (2) preserves group on new files.
    $this->runSudo(
      "sh -c 'find {$escaped} -type d -print0 | xargs -0 --no-run-if-empty chmod -R 2775'",
      $dry_run
    );

    // Set 0664 on files, excluding .htaccess and settings.php.
    $this->runSudo(
      "sh -c 'find {$escaped} -type f"
      . " -not -name \".htaccess\""
      . " -not -name \"settings.php\""
      . " -print0 | xargs -0 --no-run-if-empty chmod -R 0664'",
      $dry_run
    );

    // Set .htaccess and settings.php files to read-only.
    $this->runSudo(
      "sh -c 'find {$escaped} -type f"
      . " \\( -name \".htaccess\" -o -name \"settings.php\" \\)"
      . " -print0 | xargs -0 --no-run-if-empty chmod -R 0444'",
      $dry_run
    );
  }

  /**
   * Run a shell command with sudo.
   *
   * @param string $command
   *   Command to run (without sudo prefix).
   * @param bool $dry_run
   *   If TRUE, log command without executing it.
   */
  protected function runSudo(string $command, bool $dry_run = FALSE): void {
    if ($dry_run) {
      $this->logger()->notice(dt('DRY-RUN: sudo @cmd', ['@cmd' => $command]));
      return;
    }
    $process = Process::fromShellCommandline('sudo ' . $command);
    $process->setTty(TRUE);
    $process->run();
  }

}
