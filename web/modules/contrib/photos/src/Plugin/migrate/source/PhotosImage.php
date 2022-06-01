<?php

namespace Drupal\photos\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Source plugin for photos image content.
 *
 * @MigrateSource(
 *   id = "d7_photos_image",
 *   source_module = "photos"
 * )
 */
class PhotosImage extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {

    $query = $this->select('photos_image', 'i');
    $query->join('file_managed', 'f', 'f.fid = i.fid');
    $query->fields('i', [
      'fid',
      'pid',
      'title',
      'des',
      'wid',
      'count',
      'comcount',
    ])->fields('f', ['uid']);

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'uid' => $this->t('User ID'),
      'fid' => $this->t('File ID'),
      'pid' => $this->t('Photos Album ID'),
      'title' => $this->t('Image title'),
      'des' => $this->t('Image description'),
      'wid' => $this->t('Weight'),
      'count' => $this->t('Image views count'),
      'comcount' => $this->t('Image comment count'),
    ];

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'fid' => [
        'type' => 'integer',
        'alias' => 'i',
      ],
    ];
  }

}
