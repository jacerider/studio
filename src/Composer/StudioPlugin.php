<?php

namespace Studio\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\PathRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Script\ScriptEvents;
use Studio\Config\Config;
use Composer\Util\Filesystem;

/**
 *
 */
class StudioPlugin implements PluginInterface, EventSubscriberInterface {
  /**
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * Flag indicating if we should clone repositories.
   *
   * @var bool
   */
  protected $clone = FALSE;

  /**
   *
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   *
   */
  public function deactivate(Composer $composer, IOInterface $io) {
  }

  /**
   *
   */
  public function uninstall(Composer $composer, IOInterface $io) {
  }

  /**
   * Get subscribed events.
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::PRE_UPDATE_CMD => 'unlinkStudioPackages',
      ScriptEvents::PRE_INSTALL_CMD => 'unlinkStudioPackages',
      ScriptEvents::POST_UPDATE_CMD => 'symlinkStudioPackages',
      ScriptEvents::POST_INSTALL_CMD => 'symlinkStudioPackages',
      // ScriptEvents::PRE_AUTOLOAD_DUMP => 'symlinkStudioPackages',
    ];
  }

  /**
   * Symlink all managed paths by studio.
   *
   * This happens just before the autoload generator kicks in except with --no-autoloader
   * In that case we create the symlinks on the POST_UPDATE, POST_INSTALL events.
   */
  public function symlinkStudioPackages() {
    $target = $this->composer->getPackage()->getTargetDir() ?? '';
    $studioDir = realpath($target) . DIRECTORY_SEPARATOR . '.studio';
    $intersection = $this->getManagedPackages(TRUE);
    foreach ($intersection as $package) {
      $destination = $this->composer->getInstallationManager()->getInstallPath($package);
      $path = $package->getDistUrl();
      $filesystem = new Filesystem();
      // Create copy of original in the `.studio` directory,
      // we use the original on the next `composer update`.
      if (is_dir($destination)) {
        $copyPath = $studioDir . DIRECTORY_SEPARATOR . $package->getName();
        if ($this->clone) {
          $filesystem->ensureDirectoryExists($copyPath);
          $filesystem->copyThenRemove($destination, $copyPath);
        }
        else {
          if (is_dir($copyPath)) {
            $filesystem->removeDirectory($copyPath);
          }
          $filesystem->removeDirectory($destination);
        }
      }
      if (!$filesystem->isSymlinkedDirectory($destination)) {
        $this->io->writeError("[Studio] Creating symlink to $path for package " . $package->getName());
        $filesystem->relativeSymlink($path, getcwd() . '/' . $destination);
      }
    }
  }

  /**
   * Removes all symlinks managed by studio.
   */
  public function unlinkStudioPackages() {
    $target = $this->composer->getPackage()->getTargetDir() ?? '';
    $studioDir = realpath($target) . DIRECTORY_SEPARATOR . '.studio';
    $intersection = $this->getManagedPackages();
    foreach ($intersection as $package) {
      $destination = $this->composer->getInstallationManager()->getInstallPath($package);
      $filesystem = new Filesystem();
      if ($filesystem->isSymlinkedDirectory($destination)) {
        $filesystem->removeDirectory($destination);
        // If we have an original copy move it back.
        $copyPath = $studioDir . DIRECTORY_SEPARATOR . $package->getName();
        if (is_dir($copyPath)) {
          $filesystem->copyThenRemove($copyPath, $destination);
        }
      }
    }
  }

  /**
   * Get managed packages.
   */
  private function getManagedPackages($showMessage = FALSE) {
    $composerConfig = $this->composer->getConfig();

    // Get array of PathRepository instances for Studio-managed paths.
    $managed = [];
    foreach ($this->getManagedPaths() as $path) {
      $managed[] = new PathRepository(
        ['url' => $path, 'symlink' => TRUE],
        $this->io,
        $composerConfig
      );
    }

    // Intersect PathRepository packages with local repository.
    $intersection = $this->getIntersection(
      $this->composer->getRepositoryManager()->getLocalRepository(),
      $managed
    );

    return $intersection;
  }

  /**
   * Get intersections.
   */
  private function getIntersection(WritableRepositoryInterface $installedRepo, $managedRepos) {
    $managedRepo = new CompositeRepository($managedRepos);

    return array_filter(
      array_map(
        function (PackageInterface $package) use ($managedRepo) {
            return $managedRepo->findPackage($package->getName(), '*');
        },
        $installedRepo->getCanonicalPackages()
      )
    );
  }

  /**
   * Get the list of paths that are being managed by Studio.
   *
   * @return array
   *   The paths.
   */
  private function getManagedPaths() {
    $target = $this->composer->getPackage()->getTargetDir() ?? '';
    $targetDir = realpath($target);
    $config = Config::make("{$targetDir}/studio.json");

    return $config->getPaths();
  }

}
