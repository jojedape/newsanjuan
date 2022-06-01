<?php

namespace Drupal\photos;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Language\LanguageInterface;

/**
 * Defines the storage handler class for photos images.
 *
 * This extends the base storage class, adding required special handling for
 * photos image entities.
 */
class PhotosImageStorage extends SqlContentEntityStorage implements PhotosImageStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function revisionIds(PhotosImageInterface $image) {
    return $this->database->query(
      'SELECT vid FROM {' . $this->getRevisionTable() . '} WHERE fid = :fid ORDER BY vid',
      [':fid' => $image->id()]
    )->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function userRevisionIds(AccountInterface $account) {
    return $this->database->query(
      'SELECT vid FROM {' . $this->getRevisionDataTable() . '} WHERE uid = :uid ORDER BY vid',
      [':uid' => $account->id()]
    )->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function countDefaultLanguageRevisions(PhotosImageInterface $image) {
    return $this->database->query('SELECT COUNT(*) FROM {' . $this->getRevisionDataTable() . '} WHERE fid = :fid AND default_langcode = 1', [
      ':fid' => $image->id(),
    ])->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function updatePhotosAlbum(PhotosImageInterface $image, $new_album) {
    return $this->database->update($this->getBaseTable())
      ->fields(['album_id' => $new_album])
      ->condition('fid', $image->id())
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function clearRevisionsLanguage(LanguageInterface $language) {
    return $this->database->update($this->getRevisionTable())
      ->fields(['langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED])
      ->condition('langcode', $language->getId())
      ->execute();
  }

}
