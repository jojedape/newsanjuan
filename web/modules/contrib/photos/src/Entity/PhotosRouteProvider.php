<?php

namespace Drupal\photos\Entity;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\EntityRouteProviderInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides routes for photos images.
 */
class PhotosRouteProvider implements EntityRouteProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getRoutes(EntityTypeInterface $entity_type) {
    $route_collection = new RouteCollection();
    $parameters = [];
    $parameters['node'] = ['type' => 'entity:node'];
    $route = (new Route('/photos/{node}/{photos_image}'))
      ->addDefaults([
        '_controller' => '\Drupal\photos\Controller\PhotosImageViewController::view',
        '_title_callback' => '\Drupal\photos\Controller\PhotosImageViewController::title',
      ])
      ->setRequirement('_entity_access', 'photos_image.view')
      ->setRequirement('photos_image', '\d+')
      ->setRequirement('node', '\d+')
      ->setOption('parameters', $parameters);
    $route_collection->add('entity.photos_image.canonical', $route);
    $route = (new Route('/photos/{node}/{photos_image}/delete'))
      ->addDefaults([
        '_entity_form' => 'photos_image.delete',
        '_title' => 'Delete image',
      ])
      ->setRequirement('_entity_access', 'photos_image.delete')
      ->setRequirement('photos_image', '\d+')
      ->setRequirement('node', '\d+')
      ->setOption('parameters', $parameters)
      ->setOption('_photos_image_operation_route', TRUE);
    $route_collection->add('entity.photos_image.delete_form', $route);

    $route = (new Route('/photos/{node}/{photos_image}/edit'))
      ->setDefault('_entity_form', 'photos_image.edit')
      ->setRequirement('_entity_access', 'photos_image.update')
      ->setRequirement('photos_image', '\d+')
      ->setRequirement('node', '\d+')
      ->setOption('parameters', $parameters)
      ->setOption('_photos_image_operation_route', TRUE);
    $route_collection->add('entity.photos_image.edit_form', $route);
    return $route_collection;
  }

}
