<?php

namespace Drupal\photos\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\image\Plugin\Field\FieldFormatter\ImageFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Plugin implementation of the 'media_thumbnail' formatter.
 *
 * @FieldFormatter(
 *   id = "photos_image",
 *   label = @Translation("Photos image"),
 *   description = @Translation("Photos image formatter with option to link to album."),
 *   field_types = {
 *     "image"
 *   }
 * )
 */
class PhotosAlbumCoverImageFormatter extends ImageFormatter {

  /**
   * {@inheritdoc}
   *
   * This has to be overridden because FileFormatterBase expects $item to be
   * of type \Drupal\file\Plugin\Field\FieldType\FileItem and calls
   * isDisplayed() which is not in FieldItemInterface.
   */
  protected function needsEntityLoad(EntityReferenceItem $item) {
    return !$item->hasNewEntity();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);
    $element['image_link']['#options']['photos_album'] = 'Album';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $link_types = [
      'photos_album' => $this->t('Linked to album'),
    ];
    // Display this setting only if image is linked.
    $image_link_setting = $this->getSetting('image_link');
    if (isset($link_types[$image_link_setting])) {
      $summary[] = $link_types[$image_link_setting];
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    $imageLinkSetting = $this->getSetting('image_link');
    if ($imageLinkSetting == 'photos_album') {
      /** @var \Drupal\photos\PhotosImageInterface $photosImage */
      $photosImage = $items->getEntity();
      $url = $photosImage->getAlbumUrl();
      foreach ($elements as $delta => $element) {
        $element['#url'] = $url;
        $elements[$delta] = $element;
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    // This formatter is only available for image file fields in the
    // photos_image entity.
    $type = $field_definition->getType();
    $entityType = $field_definition->getTargetEntityTypeId();
    $targetType = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
    return ($entityType == 'photos_image' && $type == 'image' && $targetType == 'file');
  }

}
