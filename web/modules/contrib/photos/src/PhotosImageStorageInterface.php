<?php

namespace Drupal\photos;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines an interface for photos image entity storage classes.
 */
interface PhotosImageStorageInterface extends ContentEntityStorageInterface {

  /**
   * Gets a list of image revision IDs for a specific image.
   *
   * @param \Drupal\photos\PhotosImageInterface $image
   *   The image entity.
   *
   * @return int[]
   *   Image revision IDs (in ascending order).
   */
  public function revisionIds(PhotosImageInterface $image);

  /**
   * Gets a list of revision IDs having a given user as image author.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user entity.
   *
   * @return int[]
   *   Image revision IDs (in ascending order).
   */
  public function userRevisionIds(AccountInterface $account);

  /**
   * Counts the number of revisions in the default language.
   *
   * @param \Drupal\photos\PhotosImageInterface $image
   *   The image entity.
   *
   * @return int
   *   The number of revisions in the default language.
   */
  public function countDefaultLanguageRevisions(PhotosImageInterface $image);

  /**
   * Moves an image from one album to another.
   *
   * @param \Drupal\photos\PhotosImageInterface $image
   *   The image entity.
   * @param int $new_album
   *   The new image album.
   *
   * @return bool
   *   If the image was successfully moved or not.
   */
  public function updatePhotosAlbum(PhotosImageInterface $image, $new_album);

  /**
   * Unsets the language for all images with the given language.
   *
   * @param \Drupal\Core\Language\LanguageInterface $language
   *   The language object.
   */
  public function clearRevisionsLanguage(LanguageInterface $language);

}
