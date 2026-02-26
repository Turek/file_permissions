<?php

namespace Drupal\file_permissions\Commands;

use Drush\Commands\DrushCommands;
use Symfony\Component\Process\Process;

/**
 * Drush commands for file permissions.
 */
class FilePermissionsCommands extends DrushCommands {

  /**
   * Rebuild local environment file permissions.
   *
   * @command file-permissions
   * @aliases fp
   * @usage drush fp
   */
  public function filePermissions(): void {
    ini_set('memory_limit', -1);

    $ds = DIRECTORY_SEPARATOR;
    $root = DRUPAL_ROOT;

    // Get public files path.
    $files = 'sites' . $ds . 'default' . $ds . 'files';
    if (\Drupal::hasService('stream_wrapper_manager')) {
      $wrapper = \Drupal::service('stream_wrapper_manager')
        ->getViaScheme('public');
      if ($wrapper) {
        $files = $wrapper->getDirectoryPath();
      }
    }

    // Get private files path.
    $private = 'sites' . $ds . 'default' . $ds . 'private';
    $private_config = \Drupal::config('system.file')->get('path.private');
    if (!empty($private_config)) {
      $private = $private_config;
    }

    // Resolve absolute paths.
    $files_dir = $this->resolvePath($root, $files);
    $private_dir = $this->resolvePath($root, $private);

    // Create directories if they don't exist.
    foreach ([$files_dir, $private_dir] as $dir) {
      if (!file_exists($dir)) {
        if (!mkdir($dir, 0775, TRUE)) {
          $this->logger()->warning(
            dt('Could not create directory: @dir', ['@dir' => $dir])
          );
          return;
        }
      }
    }

    // Detect apache user.
    $user = $this->getHttpdUser();
    $group = $this->getHttpdGroup($user);

    $this->logger()->notice(dt('httpd user: @user', ['@user' => $user]));
    $this->logger()->notice(dt('httpd group: @group', ['@group' => $group]));
    $this->logger()
      ->warning(dt('You may be prompted for your sudo password.'));

    $this->applyPermissions($files_dir, $user, $group);
    $this->applyPermissions($private_dir, $user, $group);

    // Set settings.php to read-only.
    $settings = $root . $ds . 'sites' . $ds . 'default' . $ds . 'settings.php';
    if (file_exists($settings)) {
      chmod($settings, 0444);
    }

    // Ensure .htaccess files exist in public and private dirs.
    \Drupal::service('file_system')->prepareDirectory(
      $files_dir,
      \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY
    );

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
   */
  protected function applyPermissions(
    string $dir,
    string $user,
    string $group
  ): void {
    $escaped = escapeshellarg($dir);
    $owner = escapeshellarg($user . ':' . $group);

    // Set ownership.
    $this->runSudo("chown -Rh {$owner} {$escaped}");
    $this->runSudo("chgrp -Rv {$escaped} {$escaped}");

    // Set 2775 on dirs and 0664 on files.
    // The setgid bit (2) preserves group on new files.
    $this->runSudo(
      "sh -c 'find {$escaped} -type d -print0 | xargs -0 chmod -R 2775'"
    );
    $this->runSudo(
      "sh -c 'find {$escaped} -type f -print0 | xargs -0 chmod -R 0664'"
    );
  }

  /**
   * Run a shell command with sudo.
   *
   * @param string $command
   *   Command to run (without sudo prefix).
   */
  protected function runSudo(string $command): void {
    $process = Process::fromShellCommandline('sudo ' . $command);
    $process->setTty(TRUE);
    $process->run();
  }

}

