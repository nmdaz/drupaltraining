<?php

namespace Drupal\Core\Entity;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Provides an interface for entity type managers.
 */
interface EntityTypeManagerInterface extends PluginManagerInterface, CachedDiscoveryInterface {

  /**
   * Creates a new access control handler instance.
   *
   * @param string $entity_type_id
   *   The entity type ID for this access control handler.
   *
   * @return \Drupal\Core\Entity\EntityAccessControlHandlerInterface
   *   An access control handler instance.
   */
  public function getAccessControlHandler($entity_type_id);

  /**
   * Creates a new storage instance.
   *
   * @param string $entity_type_id
   *   The entity type ID for this storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   A storage instance.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   */
  public function getStorage($entity_type_id);

  /**
   * Creates a new view builder instance.
   *
   * @param string $entity_type_id
   *   The entity type ID for this view builder.
   *
   * @return \Drupal\Core\Entity\EntityViewBuilderInterface
   *   A view builder instance.
   */
  public function getViewBuilder($entity_type_id);

  /**
   * Creates a new entity list builder.
   *
   * @param string $entity_type_id
   *   The entity type ID for this list builder.
   *
   * @return \Drupal\Core\Entity\EntityListBuilderInterface
   *   An entity list builder instance.
   */
  public function getListBuilder($entity_type_id);

  /**
   * Creates a new form instance.
   *
   * @param string $entity_type_id
   *   The entity type ID for this form.
   * @param string $operation
   *   The name of the operation to use, e.g., 'default'.
   *
   * @return \Drupal\Core\Entity\EntityFormInterface
   *   A form instance.
   */
  public function getFormObject($entity_type_id, $operation);

  /**
   * Gets all route provider instances.
   *
   * @param string $entity_type_id
   *   The entity type ID for the route providers.
   *
   * @return \Drupal\Core\Entity\Routing\EntityRouteProviderInterface[]
   *   An array of all the route providers for this entity type.
   */
  public function getRouteProviders($entity_type_id);

  /**
   * Checks whether a certain entity type has a certain handler.
   *
   * @param string $entity_type_id
   *   The ID of the entity type.
   * @param string $handler_type
   *   The name of the handler.
   *
   * @return bool
   *   Returns TRUE if the entity type has the handler, else FALSE.
   */
  public function hasHandler($entity_type_id, $handler_type);

  /**
   * Returns a handler instance for the given entity type and handler.
   *
   * Entity handlers are instantiated once per entity type and then cached
   * in the entity type manager, and so subsequent calls to getHandler() for
   * a particular entity type and handler type will return the same object.
   * This means that properties on a handler may be used as a static cache,
   * although as the handler is common to all entities of the same type,
   * any data that is per-entity should be keyed by the entity ID.
   *
   * @param string $entity_type_id
   *   The entity type ID for this handler.
   * @param string $handler_type
   *   The handler type to create an instance for.
   *
   * @return object
   *   A handler instance.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function getHandler($entity_type_id, $handler_type);

  /**
   * Creates new handler instance.
   *
   * Usually \Drupal\Core\Entity\EntityTypeManagerInterface::getHandler() is
   * preferred since that method has additional checking that the class exists
   * and has static caches.
   *
   * @param mixed $class
   *   The handler class to instantiate.
   * @param \Drupal\Core\Entity\EntityTypeInterface $definition
   *   The entity type definition.
   *
   * @return object
   *   A handler instance.
   */
  public function createHandlerInstance($class, ?EntityTypeInterface $definition = NULL);

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface|null
   *   A plugin definition, or NULL if the plugin ID is invalid and
   *   $exception_on_invalid is FALSE.
   */
  public function getDefinition($entity_type_id, $exception_on_invalid = TRUE);

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface[]
   *   An array of plugin definitions (empty array if no definitions were
   *   found). Keys are plugin IDs.
   */
  public function getDefinitions();

}
