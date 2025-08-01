<?php

declare(strict_types=1);

namespace Drupal\Tests\config\Functional;

use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Extension\ExtensionLifecycle;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\system\Functional\Module\ModuleTestBase;

/**
 * Tests the largest configuration import possible with all available modules.
 *
 * Note that the use of SchemaCheckTestTrait means that the schema conformance
 * of all default configuration is also tested.
 *
 * @group config
 * @group #slow
 */
class ConfigImportAllTest extends ModuleTestBase {

  use SchemaCheckTestTrait;

  /**
   * A user with the 'synchronize configuration' permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $webUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['config'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'testing';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->webUser = $this->drupalCreateUser(['synchronize configuration']);
    $this->drupalLogin($this->webUser);
  }

  /**
   * Tests that a fixed set of modules can be installed and uninstalled.
   */
  public function testInstallUninstall(): void {

    // Get a list of modules to install.
    $all_modules = $this->container->get('extension.list.module')->getList();
    $all_modules = array_filter($all_modules, function ($module) {
      // Filter out contrib, hidden, testing, experimental, and deprecated
      // modules. We also don't need to enable modules that are already enabled.
      if ($module->origin !== 'core'
        || !empty($module->info['hidden'])
        || $module->status == TRUE
        || $module->info['package'] == 'Testing'
        || $module->info[ExtensionLifecycle::LIFECYCLE_IDENTIFIER] === ExtensionLifecycle::DEPRECATED) {
        return FALSE;
      }
      return TRUE;
    });

    // Install every module possible.
    \Drupal::service('module_installer')->install(array_keys($all_modules));

    $this->assertModules(array_keys($all_modules), TRUE);
    foreach ($all_modules as $module => $info) {
      $this->assertModuleConfig($module);
      $this->assertModuleTablesExist($module);
    }

    // Export active config to sync.
    $this->copyConfig($this->container->get('config.storage'), $this->container->get('config.storage.sync'));

    $this->resetAll();

    // Delete all entities provided by modules that prevent uninstallation. For
    // example, if any content entity exists its provider cannot be uninstalled.
    // So deleting all taxonomy terms allows the Taxonomy to be uninstalled.
    // Additionally, every field is deleted so modules can be uninstalled. For
    // example, if a comment field exists then Comment cannot be uninstalled.
    $entity_type_manager = \Drupal::entityTypeManager();
    foreach ($entity_type_manager->getDefinitions() as $entity_type) {
      if (($entity_type instanceof ContentEntityTypeInterface || in_array($entity_type->id(), ['field_storage_config', 'filter_format'], TRUE))
        && !in_array($entity_type->getProvider(), ['system', 'user'], TRUE)) {
        $storage = $entity_type_manager->getStorage($entity_type->id());
        $storage->delete($storage->loadMultiple());
      }
    }

    // Purge the field data.
    field_purge_batch(1000);

    $all_modules = \Drupal::service('extension.list.module')->getList();
    $database_module = \Drupal::service('database')->getProvider();
    $expected_modules = ['path_alias', 'system', 'user', $database_module];
    // If the database module has dependencies, they are expected too.
    $database_module_extension = \Drupal::service(ModuleExtensionList::class)->get($database_module);
    $database_module_dependencies = $database_module_extension->requires ? array_keys($database_module_extension->requires) : [];

    // Ensure that only core required modules and the install profile can not be
    // uninstalled.
    $validation_reasons = \Drupal::service('module_installer')->validateUninstall(array_keys($all_modules));
    $validation_modules = array_keys($validation_reasons);
    $this->assertEqualsCanonicalizing($expected_modules, $validation_modules);

    $modules_to_uninstall = array_filter($all_modules, function ($module) {
      // Filter profiles, and required and not enabled modules.
      if (!empty($module->info['required']) || $module->status == FALSE || $module->getType() === 'profile') {
        return FALSE;
      }
      return TRUE;
    });

    // Can not uninstall config and use admin/config/development/configuration!
    unset($modules_to_uninstall['config']);

    // Can not uninstall the database module and its dependencies.
    unset($modules_to_uninstall[$database_module]);
    foreach ($database_module_dependencies as $dependency) {
      unset($modules_to_uninstall[$dependency]);
    }

    $this->assertTrue(isset($modules_to_uninstall['comment']), 'The comment module will be disabled');
    $this->assertTrue(isset($modules_to_uninstall['file']), 'The File module will be disabled');
    $this->assertTrue(isset($modules_to_uninstall['editor']), 'The Editor module will be disabled');

    // Uninstall all modules that can be uninstalled.
    \Drupal::service('module_installer')->uninstall(array_keys($modules_to_uninstall));

    $this->assertModules(array_keys($modules_to_uninstall), FALSE);
    foreach ($modules_to_uninstall as $module => $info) {
      $this->assertNoModuleConfig($module);
      $this->assertModuleTablesDoNotExist($module);
    }

    // Import the configuration thereby re-installing all the modules.
    $this->drupalGet('admin/config/development/configuration');
    $this->submitForm([], 'Import all');
    // Modules have been installed that have services.
    $this->rebuildContainer();

    // Check that there are no errors.
    $this->assertSame([], $this->configImporter()->getErrors());

    // Check that all modules that were uninstalled are now reinstalled.
    $this->assertModules(array_keys($modules_to_uninstall), TRUE);
    foreach ($modules_to_uninstall as $module => $info) {
      $this->assertModuleConfig($module);
      $this->assertModuleTablesExist($module);
    }

    // Ensure that we have no configuration changes to import.
    $storage_comparer = new StorageComparer(
      $this->container->get('config.storage.sync'),
      $this->container->get('config.storage')
    );
    $this->assertSame($storage_comparer->getEmptyChangelist(), $storage_comparer->createChangelist()->getChangelist());

    // Now we have all configuration imported, test all of them for schema
    // conformance. Ensures all imported default configuration is valid when
    // all modules are enabled.
    $names = $this->container->get('config.storage')->listAll();
    /** @var \Drupal\Core\Config\TypedConfigManagerInterface $typed_config */
    $typed_config = $this->container->get('config.typed');
    foreach ($names as $name) {
      $config = $this->config($name);
      $this->assertConfigSchema($typed_config, $name, $config->get());
    }
  }

}
