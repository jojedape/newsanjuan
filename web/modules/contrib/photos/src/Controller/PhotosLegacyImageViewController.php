<?php

namespace Drupal\photos\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Image view controller.
 */
class PhotosLegacyImageViewController extends PhotosImageViewController {

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $photos_image, $view_mode = 'full', $langcode = NULL) {
    /** @var \Drupal\photos\Entity\PhotosImage $photosImage */
    $photosImage = $photos_image;
    if (!$photosImage) {
      throw new NotFoundHttpException();
    }

    $build = parent::view($photosImage, $view_mode, $langcode);

    // Legacy view modes rely heavily on {file_managed}.fid.
    $file_ids = $photosImage->getFids();
    $fid = reset($file_ids);
    // @todo we can also check if legacy mode is enabled per node or some other
    // parameters if needed.
    if (!$fid || !is_numeric($fid)) {
      // Legacy view controller can only handle images with {file_managed}.fid.
      return $build;
    }
    $file = NULL;
    if ($fid) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
    }

    // Get config settings.
    // @todo inject config factory?
    $config = \Drupal::config('photos.settings');

    // Get image file and data.
    $imageData = \Drupal::service('image.factory')->get($file->getFileUri());

    // Check if valid image.
    if (!$imageData->isValid()) {
      throw new NotFoundHttpException();
    }

    $image = $this->load($fid);

    if (!$image) {
      throw new NotFoundHttpException();
    }

    $node = $this->entityTypeManager->getStorage('node')->load($image->album_id);
    if ($photosImage->access('update')) {
      $image->ajax['edit_url'] = Url::fromUri('base:photos/image/' . $image->fid . '/update')->toString();
      // Set album cover.
      $image->links['cover'] = Link::createFromRoute($this->t('Set to Cover'), 'photos.album.update.cover', [
        'node' => $image->album_id,
        'photos_image' => $image->id,
      ], [
        'query' => $this->getDestinationArray(),
      ]);
      // Remove parent set to cover link.
      unset($build['content']['links']['cover']);
    }
    $image->class = [
      'title_class' => '',
      'des_class' => '',
    ];
    $image->id = [
      'des_edit' => '',
      'title_edit' => '',
    ];
    $edit = $photosImage->access('update');
    if ($edit) {
      // Image edit link.
      $url = Url::fromUri('base:photos/image/' . $image->fid . '/edit', [
        'query' => [
          'destination' => 'photos/image/' . $image->fid,
        ],
        'attributes' => [
          'class' => ['colorbox-load', 'photos-edit-edit'],
        ],
      ]);
      $image->ajax['edit_link'] = Link::fromTextAndUrl($this->t('Edit'), $url);
    }
    if ($photosImage->access('delete')) {
      // Image delete link.
      // @todo cancel should go back to image. Confirm to album.
      $url = Url::fromUri('base:photos/image/' . $image->fid . '/delete', [
        'query' => [
          'destination' => 'node/' . $image->album_id,
        ],
        'attributes' => [
          'class' => ['colorbox-load', 'photos-edit-delete'],
        ],
      ]);
      $image->ajax['del_link'] = Link::fromTextAndUrl($this->t('Delete'), $url);
    }
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
          '#theme' => 'photos_comment_count',
          '#comcount' => $comCount,
        ];
      }
    }
    $image->links['comment'] = $renderCommentCount;

    // Album images.
    $pager_type = 'album_id';
    $pager_id = $image->album_id;
    $data = isset($image->data) ? unserialize($image->data) : [];
    $style_name = isset($data['view_imagesize']) ? $data['view_imagesize'] : $config->get('photos_display_view_imagesize');

    $image->links['pager'] = $photosImage->getPager($pager_id, $pager_type);
    $legacyImage = [
      'file' => $file,
      'uri' => $image->uri,
      'title' => $image->title,
      'width' => $image->width,
      'height' => $image->height,
    ];
    $image->view = [
      '#theme' => 'photos_image_html',
      '#style_name' => $style_name,
      '#image' => $legacyImage,
      '#cache' => [
        'tags' => [
          'photos:image:' . $fid,
        ],
      ],
    ];

    // Get comments.
    // @todo get comments?
    // Check count image views variable.
    $photos_image_count = $config->get('photos_image_count');
    $image->disable_photos_image_count = $photos_image_count;
    if (!$photos_image_count) {
      $count = 1;
      $this->connection->update('photos_count')
        ->fields(['value' => $count])
        ->expression('value', 'value + :count', [
          ':count' => $count,
        ])
        ->condition('type', 'image_views')
        ->condition('id', $photosImage->id())
        ->execute();
    }
    $image->title = $photosImage->getTitle();
    $image->des = $photosImage->getDescription();

    $GLOBALS['photos'][$image->fid . '_album_id'] = $image->album_id;

    $image_view = [
      '#theme' => 'photos_image_view',
      '#image' => $image,
      '#display_type' => 'view',
      '#cache' => [
        'tags' => [
          'photos:image:' . $fid,
        ],
      ],
    ];

    $build['#view_mode'] = 'legacy';
    $build['photos_legacy_image_view'] = $image_view;

    return $build;
  }

  /**
   * Load image file and album data.
   */
  public function load($fid) {
    // Query image data.
    // @todo check access. Is ->addTag('node_access') needed here? If so, rewrite query.
    //   - I think access is already checked before we get here.
    $db = \Drupal::database();
    // @todo join image field if available.
    $image = $db->query('SELECT f.fid, f.uri, f.filemime, f.created, f.filename, n.title as node_title, a.data, u.uid, u.name, p.*
      FROM {file_managed} f
      INNER JOIN {photos_image__field_image} i ON f.fid = i.field_image_target_id
      INNER JOIN {photos_image_field_data} p ON p.revision_id = i.revision_id
      INNER JOIN {node_field_data} n ON p.album_id = n.nid
      INNER JOIN {photos_album} a ON a.album_id = n.nid
      INNER JOIN {users_field_data} u ON f.uid = u.uid
      WHERE f.fid = :fid', [':fid' => $fid])->fetchObject();
    // Set image height and width.
    if (!isset($image->height) && isset($image->uri)) {
      // The image.factory service will check if our image is valid.
      $image_info = \Drupal::service('image.factory')->get($image->uri);
      if ($image_info->isValid()) {
        $image->width = $image_info->getWidth();
        $image->height = $image_info->getHeight();
      }
      else {
        $image->width = $image->height = NULL;
      }
    }
    return $image;
  }

}
