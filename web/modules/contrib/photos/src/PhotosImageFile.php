<?php

namespace Drupal\photos;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;

/**
 * Create images object.
 */
class PhotosImageFile {

  /**
   * The {file_managed}.fid.
   *
   * @var int
   */
  protected $fid;

  /**
   * Constructs a PhotosImageFile object.
   *
   * @param int $fid
   *   File ID {file_managed}.fid.
   */
  public function __construct($fid) {
    $this->fid = $fid;
  }

  /**
   * Load image file and album data.
   */
  public function load() {
    $fid = $this->fid;
    // Query image data.
    // @todo check access. Is ->addTag('node_access') needed here? If so,
    //   rewrite query. I think access is already checked before we get here.
    $db = \Drupal::database();
    // @note currently legacy mode requires default field_image.
    $image = $db->query('SELECT f.fid, f.uri, f.filemime, f.created, f.filename, n.title as node_title, a.data, u.uid, u.name, p.*
      FROM {file_managed} f
      INNER JOIN {photos_image__field_image} i ON i.field_image_target_id = f.fid
      INNER JOIN {photos_image_field_data} p ON p.revision_id = i.revision_id
      INNER JOIN {node_field_data} n ON p.album_id = n.nid
      INNER JOIN {photos_album} a ON a.album_id = n.nid
      INNER JOIN {users_field_data} u ON f.uid = u.uid
      WHERE i.field_image_target_id = :fid', [':fid' => $fid])->fetchObject();
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

  /**
   * Return render array to view image.
   *
   * @param string $style_name
   *   The image style machine name.
   * @param array $variables
   *   (Optional) variables to override image defaults:
   *   - 'title': image title and alt if alt is empty.
   *   - 'href': image link href.
   *
   * @return array
   *   Render array for image view.
   */
  public function view($style_name = NULL, array $variables = []) {
    $image = $this->load();
    if (isset($variables['title'])) {
      $image->title = $variables['title'];
    }
    if (!$style_name) {
      // Get thumbnail image style from admin settings.
      $image_sizes = \Drupal::config('photos.settings')->get('photos_size');
      $style_name = key($image_sizes);
    }
    if (!$style_name) {
      // Fallback on default thumbnail style.
      $style_name = 'thumbnail';
    }
    // Check scheme and prep image.
    $scheme = \Drupal::service('stream_wrapper_manager')->getScheme($image->uri);
    $uri = $image->uri;
    // If private create temporary derivative.
    if ($scheme == 'private') {
      $photos_image = new PhotosImageFile($image->fid);
      $url = $photos_image->derivative($uri, $style_name, $scheme);
    }
    else {
      // Public and all other images.
      $style = ImageStyle::load($style_name);
      $url = $style->buildUrl($uri);
    }
    // Build image render array.
    $title = isset($image->title) ? $image->title : '';
    $alt = isset($image->alt) ? $image->alt : $title;
    $image_render_array = [
      '#theme' => 'image',
      '#uri' => $url,
      '#alt' => $alt,
      '#title' => $title,
    ];
    if (isset($variables['href'])) {
      $image_render_array = [
        '#type' => 'link',
        '#title' => $image_render_array,
        '#url' => Url::fromUri('base:' . $variables['href']),
      ];
    }

    return $image_render_array;
  }

  /**
   * Generate image style derivatives and return image file URL.
   *
   * Originally added to create private image style derivatives.
   *
   * @param string $uri
   *   File URI.
   * @param string $style_name
   *   Image style name.
   * @param string $scheme
   *   File system scheme.
   *
   * @return string
   *   The image URL.
   */
  public function derivative($uri, $style_name, $scheme = 'private') {
    // @todo adapt this to work with all file system scheme options.
    // Load the image style configuration entity.
    $style = ImageStyle::load($style_name);

    // Create URI with fid_{fid}.
    $pathInfo = pathinfo($uri);
    $ext = strtolower($pathInfo['extension']);
    // Set temporary file destination.
    $destination = $scheme . '://photos/tmp_images/' . $style_name . '/image_' . $this->fid . '.' . $ext;
    // Create image file.
    $style->createDerivative($uri, $destination);

    // Return URL.
    return file_create_url($destination);
  }

  /**
   * Return URL to image file.
   *
   * @note this is not currently in use.
   */
  public function url($uri, $style_name = 'thumbnail') {
    if ($style_name == 'original') {
      $image_styles = image_style_options(FALSE);
      if (isset($image_styles['photos_original'])) {
        $image_url = ImageStyle::load($style_name)->buildUrl($uri);
      }
      else {
        $image_url = file_create_url($uri);
      }
    }
    else {
      $image_url = ImageStyle::load($style_name)->buildUrl($uri);
    }

    return $image_url;
  }

  /**
   * Delete image.
   */
  public function delete($filepath = NULL, $count = FALSE) {
    $fid = $this->fid;
    if (!$filepath) {
      if ($count) {
        $file = File::load($fid);
        $db = \Drupal::database();
        $file->album_id = $db->select('photos_image', 'p')
          ->fields('p', ['album_id'])
          ->condition('fid', $fid)
          ->execute()->fetchField();
        $filepath = $file->getFileUri();
      }
      else {
        $db = \Drupal::database();
        $filepath = $db->query('SELECT uri FROM {file_managed} WHERE fid = :fid', [':fid' => $fid])->fetchField();
      }
    }
    if ($filepath) {
      // If photos_access is enabled.
      if (\Drupal::config('photos.settings')->get('photos_access_photos')) {
        $file_scheme = \Drupal::service('stream_wrapper_manager')->getScheme($filepath);
        if ($file_scheme == 'private') {
          // Delete private image styles.
          $pathinfo = pathinfo($filepath);
          $ext = strtolower($pathinfo['extension']);
          $basename = 'image_' . $fid . '.' . $ext;
          // Find all derivatives for this image.
          $file_uris = \Drupal::service('file_system')->scanDirectory('private://photos/tmp_images', '~\b' . $basename . '\b~');
          foreach ($file_uris as $uri => $data) {
            // Delete.
            \Drupal::service('file_system')->delete($uri);
          }
        }
      }
      $db = \Drupal::database();
      $db->delete('photos_image')
        ->condition('fid', $fid)
        ->execute();
      $db->delete('photos_comment')
        ->condition('fid', $fid)
        ->execute();
      if ($count) {
        // Update image count.
        PhotosAlbum::setCount('node_album', $file->album_id);
        PhotosAlbum::setCount('user_image', $file->getOwnerId());
      }

      if (empty($file)) {
        $file = File::load($fid);
      }
      if (empty($file->album_id)) {
        $db = \Drupal::database();
        $file->album_id = $db->select('photos_image', 'p')
          ->fields('p', ['album_id'])
          ->condition('fid', $file->id())
          ->execute()->fetchField();
      }
      // Delete file usage and delete files.
      $file_usage = \Drupal::service('file.usage');
      $file_usage->delete($file, 'photos', 'node', $file->album_id);
      $file->delete();
      // Clear image cache.
      Cache::invalidateTags(['photos:image:' . $fid]);
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

}
