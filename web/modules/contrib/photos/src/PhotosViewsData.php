<?php

namespace Drupal\photos;

use Drupal\views\EntityViewsData;

/**
 * Provides the views data for photos module and the photos_image entity type.
 */
class PhotosViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    $data['photos_album'] = [];
    $data['photos_album']['table'] = [];
    $data['photos_album']['table']['group'] = $this->t('Photos');
    $data['photos_album']['table']['provider'] = 'photos';

    // Join {node_field_data} and {photos_image_field_data}.
    $data['photos_album']['table']['join'] = [
      'node_field_data' => [
        'left_field' => 'nid',
        'field' => 'album_id',
      ],
      'photos_image_field_data' => [
        'left_field' => 'album_id',
        'field' => 'album_id',
      ],
    ];

    // Cover ID field.
    $data['photos_album']['cover_id'] = [
      'title' => $this->t('Album cover'),
      'help' => $this->t('The album cover image.'),
      'field' => [
        'id' => 'photos_image_cover',
      ],
      'sort' => [
        'id' => 'standard',
      ],
    ];

    // Album weight.
    $data['photos_album']['weight'] = [
      'title' => $this->t('Album weight'),
      'help' => $this->t('The weight of this album.'),
      'field' => [
        'id' => 'numeric',
      ],
      'filter' => [
        'id' => 'numeric',
      ],
      'sort' => [
        'id' => 'standard',
      ],
    ];

    // Album image count.
    // @todo move out to photos_count table.
    $data['photos_album']['count'] = [
      'title' => $this->t('Album image count'),
      'help' => $this->t('The number of images in this album.'),
      'field' => [
        'id' => 'numeric',
      ],
      'filter' => [
        'id' => 'numeric',
      ],
      'sort' => [
        'id' => 'standard',
      ],
    ];

    $data['photos_image_field_data']['table']['group'] = $this->t('Photos');
    $data['photos_image_field_data']['table']['provider'] = 'photos';

    // Join album info to images.
    $data['photos_image_field_data']['table']['join'] = [
      'node_field_data' => [
        'left_field' => 'nid',
        'field' => 'album_id',
      ],
      'photos_album' => [
        'left_field' => 'album_id',
        'field' => 'album_id',
      ],
    ];

    // Album node ID table field.
    $data['photos_image_field_data']['album_id'] = [
      'title' => $this->t('Album'),
      'help' => $this->t('Album nid.'),
      'sort' => [
        'id' => 'standard',
      ],
      'relationship' => [
        'id' => 'standard',
        'base' => 'node_field_data',
        'entity type' => 'node',
        'base field' => 'nid',
        'label' => $this->t('The album'),
        'title' => $this->t('Album'),
        'help' => $this->t('The album associated with this image.'),
      ],
      'argument' => [
        'id' => 'node_nid',
        'name field' => 'title',
        'numeric' => TRUE,
        'validate type' => 'nid',
      ],
    ];

    // Set as album cover link.
    $data['photos_image_field_data']['set_cover'] = [
      'field' => [
        'title' => $this->t('Set as album cover link'),
        'help' => $this->t('Provide a link to set this image as the album cover.'),
        'id' => 'photos_image_set_cover',
      ],
    ];

    // Image views count.
    $data['photos_count'] = [];
    $data['photos_count']['table'] = [];
    $data['photos_count']['table']['group'] = $this->t('Photos');
    $data['photos_count']['table']['provider'] = 'photos';
    // Join {photos_image_field_data}.
    $data['photos_count']['table']['join'] = [
      'photos_image_field_data' => [
        'left_field' => 'id',
        'field' => 'cid',
        'extra' => [
          [
            'field' => 'type',
            'value' => 'image_views',
          ],
        ],
      ],
    ];
    $data['photos_count']['value'] = [
      'title' => $this->t('Image views'),
      'help' => $this->t('Number of times this image has been viewed.'),
      'field' => [
        'id' => 'numeric',
      ],
      'filter' => [
        'id' => 'numeric',
      ],
      'sort' => [
        'id' => 'standard',
      ],
    ];

    return $data;
  }

}
