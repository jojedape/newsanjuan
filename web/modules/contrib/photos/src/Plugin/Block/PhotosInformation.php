<?php

namespace Drupal\photos\Plugin\Block;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'Photo information' block.
 *
 * @Block(
 *   id = "photos_information",
 *   admin_label = @Translation("Photo Information"),
 *   category = @Translation("Photos")
 * )
 */
class PhotosInformation extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new BookNavigationBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request stack.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $connection, EntityTypeManagerInterface $entity_manager, RequestStack $request_stack, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->connection = $connection;
    $this->entityTypeManager = $entity_manager;
    $this->requestStack = $request_stack;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, $return_as_object = FALSE) {
    // Check if user can view photos.
    if ($account->hasPermission('view photo')) {
      $access = AccessResult::allowed();
    }
    else {
      $access = AccessResult::forbidden();
    }
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $content = [];

    // Check which pager to load.
    $photosImage = $this->routeMatch->getParameter('photos_image');
    $pager_type = 'album_id';
    if ($photosImage) {
      // Get current image.
      $query = $this->connection->select('photos_image_field_data', 'p');
      $query->join('node_field_data', 'n', 'n.nid = p.album_id');
      $query->join('users_field_data', 'u', 'p.uid = u.uid');
      $query->fields('p')
        ->fields('n', ['nid', 'title'])
        ->fields('u', ['name', 'uid'])
        ->condition('p.id', $photosImage->id());
      $query->addTag('node_access');
      $image = $query->execute()->fetchObject();
      $blockImage = [];
      if ($image) {
        /** @var \Drupal\user\UserInterface $account */
        try {
          $account = $this->entityTypeManager->getStorage('user')
            ->load($image->uid);
          $blockImage['name'] = $account->getDisplayName();
        }
        catch (InvalidPluginDefinitionException $e) {
          watchdog_exception('photos', $e);
        }
        catch (PluginNotFoundException $e) {
          watchdog_exception('photos', $e);
        }
        $blockImage['photos_image'] = $photosImage;
        $pager_id = $image->nid;
        // Get pager image(s).
        $blockImage['pager'] = $photosImage->getPager($pager_id, $pager_type);

        $content = [
          '#theme' => 'photos_image_block',
          '#image' => $blockImage,
          '#cache' => [
            'tags' => [
              'photos:image:' . $photosImage->id(),
              'photos:album:' . $image->nid,
              'node:' . $image->nid,
            ],
          ],
        ];
        $content['#attached']['library'][] = 'photos/photos.block.information';
      }
    }
    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // @todo look into cache_context service.
    return 0;
  }

}
