<?php

namespace Drupal\photos\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\photos\PhotosAlbum;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-arrange view controller.
 */
class PhotosRearrangeController extends ControllerBase {

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request stack.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(Connection $connection, EntityTypeManagerInterface $entity_manager, RendererInterface $renderer, RequestStack $request_stack, RouteMatchInterface $route_match) {
    $this->connection = $connection;
    $this->entityTypeManager = $entity_manager;
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
      $container->get('entity_type.manager'),
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
    return $this->t('Rearrange Photos: @title', [
      '@title' => $node->getTitle(),
    ]);
  }

  /**
   * A custom access check.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\node\NodeInterface $node
   *   The album node entity.
   * @param \Drupal\user\Entity\User $user
   *   The user account being viewed.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(AccountInterface $account = NULL, NodeInterface $node = NULL, User $user = NULL) {
    // Check if user can rearrange this album.
    if ($node && $node->access('update')) {
      if ($node->getType() == 'photos') {
        return AccessResult::allowed();
      }
      // Prevent rearrange tab from showing on other node types.
      return AccessResult::forbidden();
    }
    elseif ($account && $user
      && ($account->id() && $account->hasPermission('create photo')
        || $account->hasPermission('access user profiles')
        && $account->hasPermission('view photo'))
      && ($user->id() == $account->id()
        || $account->hasPermission('administer users'))) {
      return AccessResult::allowed();
    }
    else {
      return AccessResult::forbidden();
    }
  }

  /**
   * Returns photos to be rearranged.
   *
   * @return array
   *   An array of markup for the page content.
   */
  public function contentOverview() {
    $config = $this->config('photos.settings');
    // Get node object.
    $node = $this->routeMatch->getParameter('node');
    $nid = $node->id();
    $output = '';
    $build = [];
    $update_button = '';
    if (isset($node->album['imageorder']) && $node->album['imageorder'] != 'weight|asc') {
      $update_button = ' ' . $this->t('Update album image display order to "Weight - smallest first".');
    }

    // Load library photos.dragndrop.
    $build['#attached']['library'][] = 'photos/photos.dragndrop';
    // Set custom drupalSettings for use in JavaScript file.
    $build['#attached']['drupalSettings']['photos']['album_id'] = $nid;

    // Set custom drupalSettings for use in JavaScript file.
    $build['#attached']['drupalSettings']['photos']['sort'] = 'images';
    $photos_album = new PhotosAlbum($nid);
    $get_limit = $this->requestStack->getCurrentRequest()->query->get('limit');
    $limit = $get_limit ? Html::escape($get_limit) : 50;
    $images = $photos_album->getImages($limit);
    $count = count($images);
    $link_100 = Link::fromTextAndUrl(100, Url::fromUri('base:node/' . $nid . '/photos-rearrange', ['query' => ['limit' => 100]]))->toString();
    $link_500 = Link::fromTextAndUrl(500, Url::fromUri('base:node/' . $nid . '/photos-rearrange', ['query' => ['limit' => 500]]))->toString();
    $output .= $this->t('Limit: @link_100 - @link_500', [
      '@link_100' => $link_100,
      '@link_500' => $link_500,
    ]);
    $default_message = $this->t('%img_count images to rearrange.', ['%img_count' => $count]);
    $output .= '<div id="photos-sort-message">' . $default_message . $update_button . ' ' . '<span id="photos-sort-updates"></span></div>';
    $output .= '<ul id="photos-sortable" class="photos-sortable">';
    foreach ($images as $image) {
      $output .= '<li id="photos_' . $image['photos_image']->id() . '" data-id="' . $image['photos_image']->id() . '" class="photos-sort-grid">';
      $viewBuilder = $this->entityTypeManager->getViewBuilder('photos_image');
      $viewMode = $config->get('view_mode_rearrange_image_page') ?: 'sort';
      $renderImage = $viewBuilder->view($image['photos_image'], $viewMode);
      try {
        $output .= $this->renderer->render($renderImage);
      }
      catch (\Exception $e) {
        watchdog_exception('photos', $e);
      }
      $output .= '</li>';
    }
    $output .= '</ul>';
    $build['#markup'] = $output;
    $build['#cache'] = [
      'tags' => ['node:' . $nid, 'photos:album:' . $nid],
    ];

    return $build;
  }

  /**
   * Rearrange user albums.
   */
  public function albumRearrange() {
    $config = $this->config('photos.settings');
    $output = '';
    $build = [];
    $account = $this->routeMatch->getParameter('user');
    $uid = $account->id();
    // Load library photos.dragndrop.
    $build['#attached']['library'][] = 'photos/photos.dragndrop';
    // Set custom drupalSettings for use in JavaScript file.
    $build['#attached']['drupalSettings']['photos']['uid'] = $uid;
    $build['#attached']['drupalSettings']['photos']['sort'] = 'albums';

    $albums = $this->getAlbums($uid);
    $count = count($albums);
    $limit_uri = Url::fromRoute('photos.album.rearrange', [
      'user' => $uid,
    ], [
      'query' => [
        'limit' => 100,
      ],
    ]);
    $output .= $this->t('Limit: @link', [
      '@link' => Link::fromTextAndUrl(100, $limit_uri)->toString(),
    ]);
    $limit_uri = Url::fromRoute('photos.album.rearrange', [
      'user' => $uid,
    ], [
      'query' => [
        'limit' => 500,
      ],
    ]);
    $output .= ' - ' . Link::fromTextAndUrl(500, $limit_uri)->toString();
    $default_message = $this->t('%album_count albums to rearrange.', ['%album_count' => $count]);
    $output .= '<div id="photos-sort-message">' . $default_message . ' ' . '<span id="photos-sort-updates"></span></div>';
    $output .= '<ul id="photos-sortable" class="photos-sortable">';
    foreach ($albums as $album) {
      $output .= '<li id="photos_' . $album['nid'] . '" data-id="' . $album['nid'] . '" class="photos-sort-grid">';
      $photosImage = $this->entityTypeManager->getStorage('photos_image')->load($album['cover_id']);
      $viewBuilder = $this->entityTypeManager->getViewBuilder('photos_image');
      $viewMode = $config->get('view_mode_rearrange_album_page') ?: 'sort';
      $renderImage = $viewBuilder->view($photosImage, $viewMode);
      $output .= $this->renderer->render($renderImage);
      $output .= '</li>';
    }
    $output .= '</ul>';
    $build['#markup'] = $output;
    $build['#cache'] = [
      'tags' => ['user:' . $uid],
    ];

    return $build;
  }

  /**
   * Get user albums.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   An array of albums to rearrange.
   */
  public function getAlbums($uid) {
    $albums = [];
    $get_limit = $this->requestStack->getCurrentRequest()->query->get('limit');
    $limit = $get_limit ? Html::escape($get_limit) : 50;
    $query = $this->connection->select('node_field_data', 'n');
    $query->join('photos_album', 'p', 'p.album_id = n.nid');
    $query->fields('n', ['nid', 'title']);
    $query->fields('p', ['weight', 'cover_id', 'count']);
    $query->condition('n.uid', $uid);
    $query->range(0, $limit);
    $query->orderBy('p.weight', 'ASC');
    $query->orderBy('n.nid', 'DESC');
    $result = $query->execute();

    foreach ($result as $data) {
      if (isset($data->cover_id) && $data->cover_id != 0) {
        $cover_id = $data->cover_id;
      }
      else {
        $cover_id = $this->connection->query("SELECT id FROM {photos_image_field_data} WHERE album_id = :album_id", [
          ':album_id' => $data->nid,
        ])->fetchField();
        if (empty($cover_id)) {
          // Skip albums with no images.
          continue;
        }
      }
      $albums[] = [
        'weight' => $data->weight,
        'nid' => $data->nid,
        'cover_id' => $cover_id,
        'count' => $data->count,
        'title' => $data->title,
      ];
    }
    return $albums;
  }

  /**
   * Ajax callback to save new image order.
   */
  public function ajaxRearrange() {
    $cache_tags = ['photos_image_list'];
    // @todo convert to CommandInterface class?
    $post_nid = $this->requestStack->getCurrentRequest()->request->get('album_id');
    $post_uid = $this->requestStack->getCurrentRequest()->request->get('uid');
    $post_type = $this->requestStack->getCurrentRequest()->request->get('type');
    $post_order = $this->requestStack->getCurrentRequest()->request->get('order');
    $nid = $post_nid ?: 0;
    $uid = $post_uid ?: 0;
    $type = $post_type ?: 0;
    $new_order = $post_order ?: [];
    $message = '';
    if (!empty($new_order) && is_array($new_order)) {
      if ($type == 'images') {
        if ($nid) {
          $message = $this->editSortSave($new_order, $nid, $type);
        }
      }
      elseif ($type == 'albums') {
        if ($uid) {
          $cache_tags[] = 'node_list';
          // Save sort order for albums.
          $message = $this->editSortAlbumsSave($new_order, $uid);
        }
      }
    }
    if ($nid) {
      // Clear album page cache.
      $cache_tags = array_merge($cache_tags, [
        'node:' . $nid,
        'photos:album:' . $nid,
      ]);
    }
    // Invalidate cache tags.
    Cache::invalidateTags($cache_tags);

    // Build plain text response.
    $response = new Response();
    $response->headers->set('Content-Type', 'text/plain');
    $response->setContent($message);
    return $response;
  }

  /**
   * Save new order.
   *
   * @param array $order
   *   An array of photos_image IDs in order of appearance.
   * @param int $nid
   *   The album node ID.
   * @param string $type
   *   The type (currently only images are supported).
   *
   * @return string
   *   A message or empty string.
   */
  public function editSortSave(array $order = [], $nid = 0, $type = 'images') {
    $message = '';
    if ($nid) {
      $access = FALSE;
      if ($nid) {
        try {
          $node = $this->entityTypeManager->getStorage('node')->load($nid);
          // Check for node_access.
          $access = ($node->getType() == 'photos' && $node->access('update'));
        }
        catch (InvalidPluginDefinitionException $e) {
          watchdog_exception('photos', $e);
        }
        catch (PluginNotFoundException $e) {
          watchdog_exception('photos', $e);
        }
      }
      if ($access) {
        $weight = 0;
        // Update weight for all images in array / album.
        $photosImageStorage = $this->entityTypeManager->getStorage('photos_image');
        foreach ($order as $imageId) {
          if ($type == 'images') {
            // Save sort order for images in album.
            /** @var \Drupal\photos\PhotosImageInterface $photosImage */
            $photosImage = $photosImageStorage->load($imageId);
            $photosImage->set('weight', $weight);
            $photosImage->save();
          }
          $weight++;
        }
        if ($weight > 0) {
          $message = $this->t('Image order saved!');
        }
      }
    }
    return $message;
  }

  /**
   * Save new album weights.
   *
   * @param array $order
   *   An array of album IDs in order of appearance.
   * @param int $uid
   *   The user ID.
   *
   * @return string
   *   A message or empty string.
   */
  public function editSortAlbumsSave(array $order = [], $uid = 0) {
    $message = '';
    if ($uid) {
      $user = $this->currentUser();
      $access = FALSE;
      if ($user->id() == $uid || $user->id() == 1 || $user->hasPermission('edit any photos content')) {
        $weight = 0;
        // Update weight for all albums in array.
        foreach ($order as $album_id) {
          $album_id = str_replace('photos_', '', $album_id);
          try {
            $node = $this->entityTypeManager->getStorage('node')
              ->load($album_id);
            // Check for node_access.
            $access = ($node->getType() == 'photos' && $node->access('update'));
          }
          catch (InvalidPluginDefinitionException $e) {
            watchdog_exception('photos', $e);
          }
          catch (PluginNotFoundException $e) {
            watchdog_exception('photos', $e);
          }
          if ($access) {
            $this->connection->update('photos_album')
              ->fields([
                'weight' => $weight,
              ])
              ->condition('album_id', $album_id)
              ->execute();
            $weight++;
          }
        }
        if ($weight > 0) {
          $message = $this->t('Album order saved!');
        }
      }
    }
    return $message;
  }

}
