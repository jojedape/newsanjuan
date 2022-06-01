<?php

namespace Drupal\photos;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;

/**
 * Create an album object.
 */
class PhotosAlbum {

  use StringTranslationTrait;

  /**
   * Album ID {node}.nid.
   *
   * @var int
   */
  protected $albumId;

  /**
   * Constructs a PhotosAlbum object.
   *
   * @param int $nid
   *   Album ID {node}.nid.
   */
  public function __construct($nid) {
    $this->albumId = $nid;
  }

  /**
   * Page and Teaser display settings.
   */
  public function nodeView($node, $display, $view_mode) {
    // @todo convert to field api. Preserve for legacy mode.
    $album = [];
    $default_style = 'medium';
    if ($display != 0) {
      $default_order = \Drupal::config('photos.settings')->get('photos_display_imageorder');
      $order = explode('|', (isset($node->album['imageorder']) ? $node->album['imageorder'] : $default_order));
      $order = PhotosAlbum::orderValueChange($order[0], $order[1]);
      $default_style = \Drupal::config('photos.settings')->get('photos_display_' . $view_mode . '_imagesize') ?: 'thumbnail';
      $style_name = isset($node->album[$view_mode . '_imagesize']) ? $node->album[$view_mode . '_imagesize'] : $default_style;
    }
    switch ($display) {
      case 0:
        // Display none.
        break;

      case 1:
        // Display cover.
        // @todo get photos_image id and load cover display.
        // @todo add field setting option to link to album...
        $render_photos_image = [];
        if (isset($node->album['cover'])) {
          $render_photos_image = $node->album['cover'];
        }
        else {
          $db = \Drupal::database();
          $cover_id = $db->query('SELECT cover_id FROM {photos_album} WHERE album_id = :nid', [
            ':nid' => $node->id(),
          ])->fetchField();
          if ($cover_id) {
            $photos_image = \Drupal::entityTypeManager()
              ->getStorage('photos_image')
              ->load($cover_id);
            if ($photos_image) {
              // @todo add setting to override cover view_mode?
              $render_photos_image = \Drupal::entityTypeManager()
                ->getViewBuilder('photos_image')
                ->view($photos_image, 'cover');
            }
          }
        }
        return $render_photos_image;

      case 2:
        // Display thumbnails.
        $get_field = \Drupal::request()->query->get('field');
        $get_sort = \Drupal::request()->query->get('sort');
        $column = $get_field ? Html::escape($get_field) : 0;
        $sort = $get_sort ? Html::escape($get_sort) : 0;
        $view_num = \Drupal::config('photos.settings')->get('photos_display_' . $view_mode . '_viewnum') ?: 10;
        $limit = isset($node->album[$view_mode . '_viewnum']) ? $node->album[$view_mode . '_viewnum'] : $view_num;

        $term = PhotosAlbum::orderValue($column, $sort, $limit, $order);
        $db = \Drupal::database();
        $query = $db->select('file_managed', 'f');
        // @note currently legacy mode requires default field_image.
        $query->join('photos_image__field_image', 'i', 'i.field_image_target_id = f.fid');
        $query->join('photos_image_field_data', 'p', 'p.revision_id = i.revision_id');
        $query->fields('f', ['fid']);
        $query->condition('p.album_id', $node->id());
        $query->orderBy($term['order']['column'], $term['order']['sort']);
        $query->range(0, $term['limit']);
        $result = $query->execute();

        $i = 0;
        // Necessary when upgrading from D6 to D7.
        $image_styles = image_style_options(FALSE);
        if (!isset($image_styles[$style_name])) {
          $style_name = \Drupal::config('photos.settings')->get('photos_display_teaser_imagesize');
        }
        // @todo this can use a teaser display mode, but we want to keep legacy
        // support, so if fid can be found from a file or image field that will
        // be a nice backup option.
        $album = [];
        // Thumbnails.
        foreach ($result as $data) {
          $photos_image = new PhotosImageFile($data->fid);
          $variables = [
            'href' => 'photos/image/' . $data->fid,
          ];
          $album[] = $photos_image->view($style_name, $variables);
          ++$i;
        }
        break;

      case 3:
        // Get cover.
        $cover = FALSE;
        if (isset($node->album['cover']) && isset($node->album['cover']['uri'])) {
          $image_render_array = [
            '#theme' => 'image_style',
            '#style_name' => $style_name,
            '#uri' => $node->album['cover']['uri'],
            '#title' => $node->getTitle(),
            '#alt' => $node->getTitle(),
          ];
          $cover = $image_render_array;
        }

        if ($cover) {
          // Cover with colorbox gallery.
          $get_field = \Drupal::request()->query->get('field');
          $get_sort = \Drupal::request()->query->get('sort');
          $column = $get_field ? Html::escape($get_field) : 0;
          $sort = $get_sort ? Html::escape($get_sort) : 0;
          $view_num = \Drupal::config('photos.settings')->get('photos_display_' . $view_mode . '_viewnum') ?: 10;
          $limit = FALSE;

          // Query all images in gallery.
          $term = PhotosAlbum::orderValue($column, $sort, $limit, $order);
          $db = \Drupal::database();
          $query = $db->select('file_managed', 'f');
          // @todo p.fid will fail.
          $query->join('photos_image_field_data', 'p', 'p.fid = f.fid');
          $query->join('users_field_data', 'ufd', 'ufd.uid = f.uid');
          $query->fields('f', [
            'uri',
            'filemime',
            'created',
            'filename',
            'filesize',
          ])
            ->fields('p')
            ->fields('ufd', ['uid', 'name']);
          $query->condition('p.album_id', $node->id());
          $query->orderBy($term['order']['column'], $term['order']['sort']);
          $result = $query->execute();

          $i = 0;
          // Setup colorbox.
          if (\Drupal::moduleHandler()->moduleExists('colorbox')) {
            $style = \Drupal::config('colorbox.settings')->get('custom.style');
            $album['#attached']['library'] = [
              'colorbox/colorbox',
              'colorbox/' . $style,
            ];
            $colorbox_height = \Drupal::config('photos.settings')->get('photos_display_colorbox_max_height') ?: 100;
            $colorbox_width = \Drupal::config('photos.settings')->get('photos_display_colorbox_max_width') ?: 50;
            $js_settings = [
              'maxWidth' => $colorbox_width . '%',
              'maxHeight' => $colorbox_height . '%',
            ];
            $album['#attached']['drupalSettings']['colorbox'] = $js_settings;
          }
          // Display cover and list colorbox image links.
          foreach ($result as $data) {
            $style_name = isset($node->album['view_imagesize']) ? $node->album['view_imagesize'] : $style_name;
            $style = ImageStyle::load($style_name);
            $file_url = $style->buildUrl($data->uri);
            $image = NULL;
            if ($i == 0) {
              $image = $cover;
            }
            $album[] = [
              '#theme' => 'photos_image_colorbox_link',
              '#image' => $image,
              '#image_title' => $data->title,
              '#image_url' => $file_url,
              '#nid' => $node->id(),
            ];
            ++$i;
          }
        }
        break;
    }
    return $album;
  }

  /**
   * Get album cover.
   *
   * @param int $cover_id
   *   The photos_image entity id.
   * @param bool $uri_only
   *   Return image URI only if available (used for photos album media).
   *
   * @return array
   *   Render array.
   */
  public function getCover($cover_id = NULL, $uri_only = FALSE) {
    $albumId = $this->albumId;
    $cover = [];
    if (!$cover_id) {
      // Check album for cover fid.
      $db = \Drupal::database();
      $cover_id = $db->query("SELECT cover_id FROM {photos_album} WHERE album_id = :album_id", [
        ':album_id' => $albumId,
      ])->fetchField();
    }
    // If id is still empty.
    if (empty($cover_id)) {
      // Cover not set, select an image from the album.
      $db = \Drupal::database();
      $query = $db->select('photos_image_field_data', 'p');
      $query->fields('p', ['id']);
      $query->condition('p.album_id', $albumId);
      $cover_id = $query->execute()->fetchField();
    }
    if ($cover_id) {
      // Load image.
      $photos_image = NULL;
      try {
        $photos_image = \Drupal::entityTypeManager()
          ->getStorage('photos_image')
          ->load($cover_id);
      }
      catch (InvalidPluginDefinitionException $e) {
        watchdog_exception('photos', $e);
      }
      catch (PluginNotFoundException $e) {
        watchdog_exception('photos', $e);
      }
      if ($photos_image) {
        if ($uri_only) {
          if ($photos_image->hasField('field_image') && $photos_image->field_image->entity) {
            $cover = $photos_image->field_image->entity->getFileUri();
          }
        }
        else {
          $cover = \Drupal::entityTypeManager()
            ->getViewBuilder('photos_image')
            ->view($photos_image, 'cover');
        }
      }
    }
    return $cover;
  }

  /**
   * Set album cover.
   */
  public function setCover($cover_id = 0) {
    $albumId = $this->albumId;
    // Update cover.
    $db = \Drupal::database();
    $db->update('photos_album')
      ->fields([
        'cover_id' => $cover_id,
      ])
      ->condition('album_id', $albumId)
      ->execute();
    // Clear node and views cache.
    Cache::invalidateTags([
      'node:' . $albumId,
      'photos:album:' . $albumId,
      'photos_image_list',
    ]);
    \Drupal::messenger()->addMessage($this->t('Cover successfully set.'));
  }

  /**
   * Get album images.
   */
  public function getImages($limit = 10) {
    $images = [];
    // Prepare query.
    $get_field = \Drupal::request()->query->get('field');
    $column = $get_field ? Html::escape($get_field) : '';
    $get_sort = \Drupal::request()->query->get('sort');
    $sort = $get_sort ? Html::escape($get_sort) : '';
    $term = PhotosAlbum::orderValue($column, $sort, $limit, [
      'column' => 'p.weight',
      'sort' => 'asc',
    ]);
    // Query images in this album.
    $db = \Drupal::database();
    $query = $db->select('photos_image_field_data', 'p')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender');
    $query->join('users_field_data', 'u', 'p.uid = u.uid');
    $query->join('node_field_data', 'n', 'n.nid = p.album_id');
    $query->fields('p', ['id']);
    $query->condition('p.album_id', $this->albumId);
    $query->limit($term['limit']);
    $query->orderBy($term['order']['column'], $term['order']['sort']);
    if ($term['order']['column'] != 'f.fid') {
      $query->orderBy('p.id', 'DESC');
    }
    // $query->addTag('node_access');
    $results = $query->execute();
    // Prepare images.
    foreach ($results as $result) {
      $photosImage = \Drupal::entityTypeManager()->getStorage('photos_image')->load($result->id);
      $images[] = [
        'photos_image' => $photosImage,
      ];
    }
    return $images;
  }

  /**
   * Limit and sort by links.
   */
  public static function orderLinks($arg, $count = 0, $link = 0, $limit = 0) {
    // @todo move out? This is used in recent images, user images and album.
    // @todo move count and order links out.
    // Get current path.
    $q = \Drupal::service('path.current')->getPath();
    $field = [
      'weight' => t('By weight'),
      'title' => t('By title'),
      'created' => t('By time'),
      'comments' => t('By comments'),
      'filesize' => t('By filesize'),
    ];
    // Check count image views variable.
    $photos_image_count = \Drupal::config('photos.settings')->get('photos_image_count');
    if (!$photos_image_count) {
      $field['visits'] = t('By visits');
    }
    if ($limit) {
      $get_limit = \Drupal::request()->query->get('limit');
      $query = PhotosAlbum::getPagerQuery();
      $links['limit'] = '';
      if (!is_array($limit)) {
        $limit = [5, 10, 20, 30, 40, 50];
      }
      $limit_query = $query;
      $limit_count = count($limit);
      foreach ($limit as $key => $tt) {
        $limit_query['limit'] = $tt;
        $sort = [
          'query' => $limit_query,
          'attributes' => [
            'class' => [
              (isset($get_limit) && $get_limit == $tt) ? 'orderac' : NULL,
            ],
            'rel' => 'nofollow',
          ],
        ];

        $links['limit'] .= Link::fromTextAndUrl($tt, Url::fromUri('base:' . $q, $sort))->toString();
        if ($limit_count != $key) {
          $links['limit'] .= ' ';
        }

      }
    }
    $links['count'] = $count;
    $links['link'] = $link ? $link : NULL;

    $sort_links = Link::fromTextAndUrl(t('Default'), Url::fromUri('base:' . $arg, ['attributes' => ['rel' => 'nofollow']]))->toString() . ' ';
    $sort_link_count = count($field);
    $get_field = \Drupal::request()->query->get('field');
    $get_limit = \Drupal::request()->query->get('limit');
    $get_sort = \Drupal::request()->query->get('sort');
    foreach ($field as $key => $t) {
      if (empty($get_field) || $get_field != $key) {
        $sort = 'desc';
        $class = 'photos-order-desc';
      }
      elseif ($get_sort == 'desc') {
        $sort = 'asc';
        $class = 'photos-order-asc photos-active-sort';
      }
      else {
        $sort = 'desc';
        $class = 'photos-order-desc photos-active-sort';
      }
      $field_query = [
        'sort' => $sort,
        'field' => $key,
      ];
      if ($get_limit) {
        $field_query['limit'] = Html::escape($get_limit);
      }
      $sort_links .= Link::fromTextAndUrl($t, Url::fromUri('base:' . $q, [
        'query' => $field_query,
        'attributes' => [
          'class' => [$class],
          'rel' => 'nofollow',
        ],
      ]))->toString();
      if ($key != $sort_link_count) {
        $sort_links .= ' ';
      }
    }
    if ($sort_links) {
      $links['sort'] = [
        '#markup' => $sort_links,
      ];
    }

    return [
      '#theme' => 'photos_album_links',
      '#links' => $links,
    ];
  }

  /**
   * Returns array of query parameters.
   */
  public static function getPagerQuery() {
    $query_array = ['limit', 'q', 'page', 'destination'];
    // @todo review and update as needed.
    return UrlHelper::filterQueryParameters($_REQUEST, array_merge($query_array, array_keys($_COOKIE)));
  }

  /**
   * Sort order labels.
   */
  public static function orderLabels() {
    return [
      'weight|asc' => t('Weight - smallest first'),
      'weight|desc' => t('Weight - largest first'),
      'title|asc' => t('Title - A-Z'),
      'title|desc' => t('Title - Z-A'),
      'created|desc' => t('Upload Date - newest first'),
      'created|asc' => t('Upload Date - oldest first'),
      'comments|desc' => t('Comments - most first'),
      'comments|asc' => t('Comments - least first'),
      'filesize|desc' => t('Filesize - smallest first'),
      'filesize|asc' => t('Filesize - largest first'),
      'visits|desc' => t('Visits - most first'),
      'visits|asc' => t('Visits - least first'),
    ];
  }

  /**
   * Extends photos order value.
   */
  public static function orderValueChange($field, $sort) {
    // @note timestamp is deprecated, but may exist
    // if albums are migrated from a previous version.
    $array = [
      'weight' => 'p.weight',
      'title' => 'p.title',
      'timestamp' => 'p.id',
      'changed' => 'p.changed',
      'created' => 'p.created',
      'comments' => 'c.comment_count',
      'visits' => 'v.value',
      'filesize' => 'f.filesize',
    ];
    $array1 = [
      'desc' => 'desc',
      'asc' => 'asc',
    ];
    if (isset($array[$field]) && isset($array1[$sort])) {
      return [
        'column' => $array[$field],
        'sort' => $array1[$sort],
      ];
    }
    else {
      // Default if values not found.
      return [
        'column' => 'p.id',
        'sort' => 'desc',
      ];
    }
  }

  /**
   * Query helper: sort order and limit.
   */
  public static function orderValue($field, $sort, $limit, $default = 0) {
    // @todo update default to check album default!
    $default_order = ['column' => 'p.id', 'sort' => 'desc'];
    if (!$field && !$sort) {
      $t['order'] = !$default ? $default_order : $default;
    }
    else {
      if (!$t['order'] = PhotosAlbum::orderValueChange($field, $sort)) {
        $t['order'] = !$default ? $default_order : $default;
      }
    }
    if ($limit) {
      $get_limit = \Drupal::request()->query->get('limit');
      if ($get_limit && !$show = intval($get_limit)) {
        $get_destination = \Drupal::request()->query->get('destination');
        if ($get_destination) {
          $str = $get_destination;
          if (preg_match('/.*limit=(\d*).*/i', $str, $mat)) {
            $show = intval($mat[1]);
          }
        }
      }
      $t['limit'] = isset($show) ? $show : $limit;
    }

    return $t;
  }

  /**
   * Return number of albums or photos.
   */
  public static function getCount($type, $id = 0) {
    $db = \Drupal::database();
    switch ($type) {
      case 'user_album':
      case 'user_image':
      case 'site_album':
      case 'site_image':
        return $db->query("SELECT value FROM {photos_count} WHERE cid = :cid AND type = :type", [
          ':cid' => $id,
          ':type' => $type,
        ])->fetchField();

      case 'node_album':
        return $db->query("SELECT count FROM {photos_album} WHERE album_id = :album_id", [
          ':album_id' => $id,
        ])->fetchField();
    }
  }

  /**
   * Update count.
   *
   * @param bool $cron
   *   If this is being called from cron.
   */
  public static function resetCount($cron = FALSE) {
    PhotosAlbum::setCount('site_album');
    PhotosAlbum::setCount('site_image');
    $time = $cron ? 7200 : 0;
    // @todo optimize. Check if new images since last count.
    // @todo this only works if cron has not run in 2 hours?
    $cron_last = \Drupal::state()->get('system.cron_last', 0);
    if ((\Drupal::time()->getRequestTime() - $cron_last) > $time) {
      $db = \Drupal::database();
      $result = $db->query('SELECT uid FROM {users} WHERE uid != 0');
      foreach ($result as $t) {
        PhotosAlbum::setCount('user_image', $t->uid);
        PhotosAlbum::setCount('user_album', $t->uid);
      }
      $result = $db->query('SELECT album_id FROM {photos_album}');
      foreach ($result as $t) {
        PhotosAlbum::setCount('node_album', $t->album_id);
      }
    }
  }

  /**
   * Calculate number of $type.
   */
  public static function setCount($type, $id = 0) {
    $db = \Drupal::database();
    $requestTime = \Drupal::time()->getRequestTime();
    switch ($type) {
      case 'user_image':
        $count = $db->query('SELECT count(p.id) FROM {photos_image_field_data} p WHERE p.uid = :id', [
          ':id' => $id,
        ])->fetchField();
        $query = $db->update('photos_count');
        $query->fields([
          'value' => $count,
          'changed' => $requestTime,
        ]);
        $query->condition('cid', $id);
        $query->condition('type', $type);
        $affected_rows = $query->execute();
        if (!$affected_rows) {
          $db->insert('photos_count')
            ->fields([
              'cid' => $id,
              'changed' => $requestTime,
              'type' => $type,
              'value' => $count,
            ])
            ->execute();
        }
        // Clear cache tags.
        Cache::invalidateTags(['photos:image:user:' . $id]);
        break;

      case 'user_album':
        $count = $db->query('SELECT count(p.album_id) FROM {photos_album} p INNER JOIN {node_field_data} n ON p.album_id = n.nid WHERE n.uid = :uid',
          [':uid' => $id])->fetchField();
        $query = $db->update('photos_count')
          ->fields([
            'value' => $count,
            'changed' => $requestTime,
          ])
          ->condition('cid', $id)
          ->condition('type', $type);
        $affected_rows = $query->execute();
        if (!$affected_rows) {
          $db->insert('photos_count')
            ->fields([
              'cid' => $id,
              'changed' => $requestTime,
              'type' => $type,
              'value' => $count,
            ])
            ->execute();
        }
        // Clear cache tags.
        Cache::invalidateTags(['photos:album:user:' . $id]);
        break;

      case 'site_album':
        $count = $db->query('SELECT COUNT(album_id) FROM {photos_album}')->fetchField();
        $query = $db->update('photos_count')
          ->fields([
            'value' => $count,
            'changed' => $requestTime,
          ])
          ->condition('cid', 0)
          ->condition('type', $type);
        $affected_rows = $query->execute();
        if (!$affected_rows) {
          $db->insert('photos_count')
            ->fields([
              'cid' => 0,
              'changed' => $requestTime,
              'type' => $type,
              'value' => $count,
            ])
            ->execute();
        }
        break;

      case 'site_image':
        $count = $db->query('SELECT COUNT(id) FROM {photos_image_field_data}')->fetchField();
        $query = $db->update('photos_count')
          ->fields([
            'value' => $count,
            'changed' => $requestTime,
          ])
          ->condition('cid', 0)
          ->condition('type', $type);
        $affected_rows = $query->execute();
        if (!$affected_rows) {
          $db->insert('photos_count')
            ->fields([
              'cid' => 0,
              'changed' => $requestTime,
              'type' => $type,
              'value' => $count,
            ])
            ->execute();
        }
        // Clear cache tags.
        Cache::invalidateTags(['photos:image:recent']);
        break;

      case 'node_album':
        $count = $db->query("SELECT COUNT(id) FROM {photos_image_field_data} WHERE album_id = :album_id", [':album_id' => $id])->fetchField();
        $db->update('photos_album')
          ->fields([
            'count' => $count,
          ])
          ->condition('album_id', $id)
          ->execute();
        break;
    }
  }

  /**
   * Tracks number of albums created and number of albums allowed.
   */
  public static function userAlbumCount() {
    $user = \Drupal::currentUser();
    $user_roles = $user->getRoles();
    $t['create'] = PhotosAlbum::getCount('user_album', $user->id());
    // @todo upgrade path? Check D7 role id and convert pnum variables as needed.
    $role_limit = 0;
    $t['total'] = 20;
    // Check highest role limit.
    foreach ($user_roles as $role) {
      if (\Drupal::config('photos.settings')->get('photos_pnum_' . $role)
        && \Drupal::config('photos.settings')->get('photos_pnum_' . $role) > $role_limit) {
        $role_limit = \Drupal::config('photos.settings')->get('photos_pnum_' . $role);
      }
    }
    if ($role_limit > 0) {
      $t['total'] = $role_limit;
    }

    $t['remain'] = ($t['total'] - $t['create']);
    if ($user->id() != 1 && $t['remain'] <= 0) {
      $t['rest'] = 1;
    }
    return $t;
  }

  /**
   * User albums.
   */
  public static function userAlbumOptions($uid = 0, $current = 0) {
    if (!$uid) {
      $uid = \Drupal::currentUser()->id();
    }
    $output = [];

    // Query user albums.
    $db = \Drupal::database();
    $query = $db->select('node_field_data', 'n');
    $query->join('photos_album', 'a', 'a.album_id = n.nid');
    $query->fields('n', ['nid', 'title']);
    $query->condition('n.uid', $uid);
    $query->orderBy('n.nid', 'DESC');
    $result = $query->execute();

    $true = FALSE;
    foreach ($result as $a) {
      $choice = new \stdClass();
      $choice->option = [$a->nid => $a->title];
      $output[$a->nid] = $choice;
      $true = TRUE;
    }
    if ($current) {
      $choice = new \stdClass();
      $choice->option = [$current[0] => $current[1]];
      $output[$a->nid] = $choice;
    }
    if (!$true) {
      $output = [t('You do not have an album yet.')];
    }

    return $output;
  }

}
