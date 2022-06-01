<?php

namespace Drupal\photos\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;

/**
 * Defines a confirmation form for deleting images.
 */
class PhotosImageDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'photos_image_confirm_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Do you want to delete this image %title?', ['%title' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Only do this if you are sure!');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete it!');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Nevermind');
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl() {
    /** @var \Drupal\photos\PhotosImageInterface $entity */
    $entity = $this->getEntity();
    return $entity->getAlbumUrl();
  }

  /**
   * {@inheritdoc}
   */
  protected function logDeletionMessage() {
    /** @var \Drupal\photos\PhotosImageInterface $entity */
    $entity = $this->getEntity();
    $this->logger('photos')->notice('Deleted image %title.', ['%title' => $entity->label()]);
  }

}
