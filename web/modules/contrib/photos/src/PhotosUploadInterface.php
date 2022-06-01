<?php

namespace Drupal\photos;

use Drupal\file\FileInterface;

/**
 * Photos upload and file management functions.
 */
interface PhotosUploadInterface {

  /**
   * Rename file with random name.
   *
   * @param string $title
   *   The file name.
   *
   * @return string
   *   The new name.
   */
  public function cleanTitle($title = '');

  /**
   * Temporary file path.
   *
   * The image file path is now handled in the field settings. This is used
   * if needed before the field settings are triggered.
   *
   * @param string $schemaType
   *   A string with the URL alias to clean up.
   *
   * @return string
   *   The cleaned URL alias.
   */
  public function path($schemaType = 'default');

  /**
   * Attach image file to PhotosImage entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return bool
   *   TRUE if image saved successfully.
   */
  public function saveImage(FileInterface $file);

  /**
   * Attach media to PhotosImage entity.
   *
   * @param int $mediaId
   *   The media entity_id.
   * @param int $albumId
   *   The album entity_id.
   *
   * @return bool
   *   TRUE if media saved successfully.
   */
  public function saveExistingMedia($mediaId, $albumId);

  /**
   * Unzip archive of image files.
   *
   * @param string $source
   *   The zip file location.
   * @param array $params
   *   Array of additional parameters like album id.
   * @param string $scheme
   *   The file scheme.
   *
   * @return int
   *   Uploaded files count.
   */
  public function unzip($source, array $params, $scheme = 'default');

}
