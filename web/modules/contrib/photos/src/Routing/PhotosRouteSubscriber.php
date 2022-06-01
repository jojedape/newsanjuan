<?php

namespace Drupal\photos\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class PhotosRouteSubscriber extends RouteSubscriberBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new PhotosRouteSubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $config = $this->configFactory->get('photos.settings');
    if ($config->get('upload_form_mode') == 1) {
      if ($route = $collection->get('photos.node.management')) {
        $route->setDefaults([
          '_entity_form' => 'photos_image.add',
        ]);
      }
    }
    if ($config->get('photos_legacy_view_mode')) {
      // An attempt to preserve image layouts configured pre 6.0.x.
      if ($route = $collection->get('entity.photos_image.canonical')) {
        $route->setDefault('_controller', '\Drupal\photos\Controller\PhotosLegacyImageViewController::view');
      }
    }
  }

}
