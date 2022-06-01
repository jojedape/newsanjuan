<?php

namespace Drupal\photos\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Controller\EntityViewController;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Image view controller.
 */
class PhotosImageViewController extends EntityViewController {
  use RedirectDestinationTrait;
  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The library discovery service.
   *
   * @var \Drupal\Core\Asset\LibraryDiscoveryInterface
   */
  protected $libraryDiscovery;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Asset\LibraryDiscoveryInterface $library_discovery
   *   The library discovery service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, Connection $connection, LibraryDiscoveryInterface $library_discovery, RouteMatchInterface $route_match, EntityRepositoryInterface $entity_repository = NULL) {
    parent::__construct($entity_type_manager, $renderer);
    $this->connection = $connection;
    $this->libraryDiscovery = $library_discovery;
    $this->routeMatch = $route_match;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('database'),
      $container->get('library.discovery'),
      $container->get('current_route_match')
    );
  }

  /**
   * A custom access check.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   */
  public function access(AccountInterface $account) {
    // @todo move to '_entity_access', 'photos_image.view'.
    // Check if user can view account photos.
    $photos_image = $this->routeMatch->getParameter('photos_image');
    // @todo either update access to check entity or get file id...
    if ($photos_image->access('view')) {
      // Allow access.
      return AccessResult::allowed();
    }
    else {
      return AccessResult::forbidden();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $photos_image, $view_mode = 'full', $langcode = NULL) {
    if (!$photos_image) {
      throw new NotFoundHttpException();
    }

    $build = parent::view($photos_image, $view_mode);
    /** @var \Drupal\photos\Entity\PhotosImage $photosImage */
    $photosImage = $photos_image;

    // Get config settings.
    // @todo inject config factory?
    $config = \Drupal::config('photos.settings');

    // Current destination.
    $destination = $this->getDestinationArray();
    // Get album node.
    $node = $this->entityTypeManager->getStorage('node')->load($photos_image->getAlbumId());

    switch ($view_mode) {
      case 'list':
        if ($photosImage->access('edit')) {
          // Image edit link.
          $build['links']['edit'] = [
            '#type' => 'link',
            '#title' => 'Edit',
            '#url' => Url::fromRoute('entity.photos_image.edit_form', [
              'photos_image' => $photosImage->id(),
            ], [
              'query' => [
                $destination,
              ],
              'attributes' => [
                'class' => ['colorbox-load', 'photos-edit-edit'],
              ],
            ]),
          ];
          // Set to album cover link.
          $build['links']['cover'] = [
            '#type' => 'link',
            '#title' => 'Set to Cover',
            '#url' => Url::fromRoute('photos.album.update.cover', [
              'node' => $photosImage->getAlbumId(),
              'photos_image' => $photosImage->id(),
            ], [
              'query' => [
                $destination,
              ],
            ]),
          ];
        }
        if ($photosImage->access('delete')) {
          // Image delete link.
          // @todo cancel should go back to image. Confirm to album.
          $build['links']['delete'] = [
            '#type' => 'link',
            '#title' => 'Delete',
            '#url' => Url::fromRoute('entity.photos_image.delete_form', [
              'photos_image' => $photosImage->id(),
            ], [
              'query' => [
                'destination' => 'node/' . $photosImage->getAlbumId(),
              ],
              'attributes' => [
                'class' => ['colorbox-load', 'photos-edit-delete'],
              ],
            ]),
          ];
        }
        break;

      case 'full':
        // Image pager.
        $build['links']['pager'] = $photosImage->getPager($photosImage->getAlbumId(), 'album_id');

        if ($photosImage->access('update')) {
          // Set image to album cover link.
          $build['links']['cover'] = [
            '#type' => 'link',
            '#title' => 'Set to Cover',
            '#url' => Url::fromRoute('photos.album.update.cover', [
              'node' => $photosImage->getAlbumId(),
              'photos_image' => $photosImage->id(),
            ], [
              'query' => [
                $destination,
              ],
            ]),
          ];
        }

        // Get comments.
        $renderCommentCount = [];
        if ($config->get('photos_comment') && \Drupal::moduleHandler()->moduleExists('comment')) {
          // Comment integration.
          $entities = [
            $photosImage->id() => $photosImage,
          ];
          $stats = \Drupal::service('comment.statistics')->read($entities, 'photos_image');
          if ($stats) {
            $comCount = 0;
            foreach ($stats as $commentStats) {
              $comCount = $comCount + $commentStats->comment_count;
            }
            $renderCommentCount = [
              '#markup' => $this->formatPlural($comCount, "@count comment", "@count comments"),
            ];
          }
        }
        $build['links']['comment'] = $renderCommentCount;

        // Check count image views variable.
        $disableImageVisitCount = $config->get('photos_image_count');
        if (!$disableImageVisitCount) {
          // @todo migrate to core statistics when it can handle other entities.
          // @see https://www.drupal.org/project/drupal/issues/2532334
          $build['#attached']['library'][] = 'photos/photos.statistics';
          $settings = [
            'data' => [
              'id' => $photosImage->id(),
            ],
            'url' => Url::fromRoute('photos.statistics.update')->toString(),
          ];
          $build['#attached']['drupalSettings']['photosStatistics'] = $settings;
        }

        // Attach default styling.
        // @see https://www.drupal.org/docs/8/theming/adding-stylesheets-css-and-javascript-js-to-a-drupal-8-theme#override-extend
        $build['#attached']['library'][] = 'photos/photos.default.style';
        break;

      default:
        break;
    }

    // Since this generates absolute URLs, it can only be cached "per site".
    $build['#cache']['contexts'][] = 'url.site';

    // Given this varies by $this->currentUser->isAuthenticated(), add a cache
    // context based on the anonymous role.
    $build['#cache']['contexts'][] = 'user.roles:anonymous';

    return $build;

  }

  /**
   * The _title_callback for the page that renders a single photos image.
   *
   * @param \Drupal\Core\Entity\EntityInterface $photos_image
   *   The current photos_image.
   *
   * @return string
   *   The page title.
   */
  public function title(EntityInterface $photos_image) {
    $title = '';
    if ($this->entityRepository) {
      $title = $this->entityRepository->getTranslationFromContext($photos_image)
        ->label();
    }
    return $title;
  }

}
