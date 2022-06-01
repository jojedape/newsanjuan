<?php

namespace Drupal\photos\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Utility\Html;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\photos\PhotosAlbum;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Album view controller.
 */
class PhotosAlbumController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The image factory.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * The library discovery service.
   *
   * @var \Drupal\Core\Asset\LibraryDiscoveryInterface
   */
  protected $libraryDiscovery;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

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
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   The image factory.
   * @param \Drupal\Core\Asset\LibraryDiscoveryInterface $library_discovery
   *   The library discovery service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request stack.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(Connection $connection, DateFormatterInterface $date_formatter, EntityTypeManagerInterface $entity_manager, ImageFactory $image_factory, LibraryDiscoveryInterface $library_discovery, RendererInterface $renderer, RequestStack $request_stack, RouteMatchInterface $route_match) {
    $this->connection = $connection;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_manager;
    $this->imageFactory = $image_factory;
    $this->libraryDiscovery = $library_discovery;
    $this->renderer = $renderer;
    $this->requestStack = $request_stack;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('image.factory'),
      $container->get('library.discovery'),
      $container->get('renderer'),
      $container->get('request_stack'),
      $container->get('current_route_match')
    );
  }

  /**
   * Set page title.
   */
  public function getTitle() {
    // Get node object.
    $node = $this->routeMatch->getParameter('node');
    $title = 'Album: ' . $node->getTitle();
    return $title;
  }

  /**
   * A custom access check.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\node\NodeInterface $node
   *   The album node entity.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(AccountInterface $account, NodeInterface $node) {
    if (!$node) {
      // Not found.
      throw new NotFoundHttpException();
    }
    // Check access.
    if ($account->hasPermission('view photo') && $node->access('view')) {
      // Allow access.
      return AccessResult::allowed();
    }
    else {
      return AccessResult::forbidden();
    }
  }

  /**
   * Returns an overview of recent albums and photos.
   *
   * @return array
   *   A render array.
   */
  public function albumView() {
    // @todo move to theme function and deprecate this in favor of default view.
    $config = $this->config('photos.settings');
    // Get node object.
    $album = [];
    $node = $this->routeMatch->getParameter('node');
    $nid = $node->id();
    // Get order or set default order.
    $order = explode('|', (isset($node->album['imageorder']) ? $node->album['imageorder'] : $config->get('photos_display_imageorder')));
    $order = PhotosAlbum::orderValueChange($order[0], $order[1]);
    $limit = isset($node->album['viewpager']) ? $node->album['viewpager'] : $config->get('photos_display_viewpager');
    $get_field = $this->requestStack->getCurrentRequest()->query->get('field');
    $get_sort = $this->requestStack->getCurrentRequest()->query->get('sort');
    $column = $get_field ? Html::escape($get_field) : '';
    $sort = isset($get_sort) ? Html::escape($get_sort) : '';
    $term = PhotosAlbum::orderValue($column, $sort, $limit, $order);
    // Album image's query.
    // @todo move to PhotosAlbum()->getImages().
    // @todo entity query?
    $query = $this->connection->select('photos_image_field_data', 'p')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender');
    // @todo p.fid will fail.
    $query->join('users_field_data', 'u', 'u.uid = p.uid');
    $query->fields('p', ['id'])
      ->condition('p.album_id', $nid)
      // @todo change wid to weight.
      // ->orderBy($term['order']['column'], $term['order']['sort'])
      ->limit($term['limit']);
    if ($term['order']['column'] != 'p.id') {
      $query->orderBy('p.id', 'DESC');
    }
    $results = $query->execute();

    // Check comment settings.
    $com = $config->get('photos_comment');
    // Check node access.
    $edit = $node->access('update');
    $del = $node->access('delete');
    $style_name = isset($node->album['list_imagesize']) ? $node->album['list_imagesize'] : $config->get('photos_display_list_imagesize');
    // Necessary when upgrading from D6 to D7.
    // @todo fix in migration if needed?
    $image_styles = image_style_options(FALSE);
    if (!isset($image_styles[$style_name])) {
      $style_name = $config->get('photos_display_list_imagesize');
    }

    // Process images.
    // @todo load multiple list view.
    // @todo use view for default album view.
    foreach ($results as $result) {
      // Load photos image.
      try {
        $photosImage = $this->entityTypeManager->getStorage('photos_image')
          ->load($result->id);
      }
      catch (InvalidPluginDefinitionException $e) {
        watchdog_exception('photos', $e);
        throw new NotFoundHttpException();
      }
      catch (PluginNotFoundException $e) {
        watchdog_exception('photos', $e);
        throw new NotFoundHttpException();
      }
      $render_photos_image = $this->entityTypeManager
        ->getViewBuilder('photos_image')
        ->view($photosImage, 'full');
      $album['view'][] = $render_photos_image;
    }
    if (isset($album['view'][0])) {
      $album['access']['edit'] = $edit;
      // Node edit link.
      $url = Url::fromUri('base:node/' . $nid . '/edit');
      $album['node_edit_url'] = Link::fromTextAndUrl($this->t('Album settings'), $url);

      // Image management link.
      $url = Url::fromUri('base:node/' . $nid . '/photos');
      $album['image_management_url'] = Link::fromTextAndUrl($this->t('Upload photos'), $url);

      // Album URL.
      $album['album_url'] = Url::fromUri('base:photos/' . $nid)->toString();

      $album['links'] = PhotosAlbum::orderLinks('photos/' . $nid, 0, 0, 1);
      $cover_style_name = $config->get('photos_cover_imagesize');
      // Album cover view.
      if (isset($node->album['cover_id'])) {
        $coverId = $node->album['cover_id'];
        $photos_album = new PhotosAlbum($node->id());
        $album['cover'] = $photos_album->getCover($coverId);
      }
      else {
        // @todo is this needed?
        $image_info = $this->imageFactory->get($node->album['cover']['uri']);
        $title = $node->getTitle();
        $album_cover_array = [
          '#theme' => 'image_style',
          '#style_name' => $cover_style_name,
          '#uri' => $node->album['cover']['uri'],
          '#width' => $image_info->getWidth(),
          '#height' => $image_info->getHeight(),
          '#alt' => $title,
          '#title' => $title,
          '#cache' => [
            'tags' => [
              'photos:album:' . $nid,
              'node:' . $nid,
            ],
          ],
        ];
        $album['cover'] = $album_cover_array;
      }
      $album['pager'] = ['#type' => 'pager'];

      // Build album view.
      $album_view_array = [
        '#theme' => 'photos_album_view',
        '#album' => $album,
        '#node' => $node,
        '#cache' => [
          'tags' => [
            'photos:album:' . $nid,
            'node:' . $nid,
          ],
        ],
      ];
      $content = $album_view_array;
    }
    else {
      $content = [
        '#markup' => $this->t('Album is empty'),
        '#cache' => [
          'tags' => [
            'photos:album:' . $nid,
            'node:' . $nid,
          ],
        ],
      ];
    }

    return $content;
  }

  /**
   * Returns content for recent albums.
   *
   * @return array
   *   An array containing markup for the page content.
   */
  public function listView() {
    // @todo convert this to a theme function for photos_album_photo_list field.
    $build = [
      '#cache' => [
        'tags' => [],
      ],
    ];
    // @todo a lot of duplicate code can be consolidated in these controllers.
    $query = $this->connection->select('node', 'n')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender');
    $query->join('photos_album', 'p', 'p.album_id = n.nid');
    $query->fields('n', ['nid']);
    $query->orderBy('n.nid', 'DESC');
    $query->limit(10);
    $query->addTag('node_access');
    $results = $query->execute();

    $build['photos_albums'] = [];
    // Check the setting for album list node view mode.
    $view_mode = $this->config('photos.settings')->get('view_mode_album_list_page');
    if (!$view_mode) {
      $view_mode = 'teaser';
    }
    foreach ($results as $result) {
      $node = $this->entityTypeManager->getStorage('node')->load($result->nid);
      $node_view = $this->entityTypeManager->getViewBuilder('node')->view($node, $view_mode);
      $build['photos_albums'][] = $node_view;
      $build['#cache']['tags'][] = 'node:' . $node->id();
      $build['#cache']['tags'][] = 'photos:album:' . $node->id();
    }
    if (!empty($build['photos_albums'])) {
      $build['#cache']['tags'][] = 'node_list';
      $build['pager'] = ['#type' => 'pager'];
    }
    else {
      $build['photos_albums'][] = [
        '#markup' => $this->t('No albums have been created yet.'),
      ];
    }

    return $build;
  }

}
