<?php

namespace Drupal\photos\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\media\Entity\Media;
use Drupal\photos\PhotosAlbum;
use Drupal\photos\PhotosImageInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the photos image entity class.
 *
 * @todo Remove default/fallback entity form operation when #2006348 is done.
 * @see https://www.drupal.org/node/2006348.
 *
 * @ContentEntityType(
 *   id = "photos_image",
 *   label = @Translation("Photo"),
 *   label_collection = @Translation("Photos"),
 *   label_singular = @Translation("photo"),
 *   label_plural = @Translation("photos"),
 *   label_count = @PluralTranslation(
 *     singular = "@count photo",
 *     plural = "@count photos"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\photos\PhotosImageStorage",
 *     "storage_schema" = "Drupal\photos\PhotosImageStorageSchema",
 *     "form" = {
 *       "default" = "Drupal\photos\Form\PhotosImageAddForm",
 *       "add" = "Drupal\photos\Form\PhotosImageAddForm",
 *       "edit" = "Drupal\photos\Form\PhotosImageEditForm",
 *       "delete" = "Drupal\photos\Form\PhotosImageDeleteForm",
 *       "delete-multiple-confirm" = "Drupal\Core\Entity\Form\DeleteMultipleForm",
 *     },
 *     "access" = "Drupal\photos\PhotosAccessControlHandler",
 *     "views_data" = "Drupal\photos\PhotosViewsData",
 *     "list_builder" = "Drupal\photos\PhotosImageListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\photos\Entity\PhotosRouteProvider",
 *     }
 *   },
 *   base_table = "photos_image",
 *   data_table = "photos_image_field_data",
 *   revision_table = "photos_image_revision",
 *   revision_data_table = "photos_image_field_revision",
 *   translatable = TRUE,
 *   show_revision_ui = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "revision_id",
 *     "label" = "title",
 *     "langcode" = "langcode",
 *     "uuid" = "uuid",
 *     "status" = "status",
 *     "published" = "status",
 *     "uid" = "uid",
 *     "owner" = "uid",
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message",
 *   },
 *   admin_permission = "administer nodes",
 *   common_reference_target = TRUE,
 *   field_ui_base_route = "photos.admin",
 *   links = {
 *     "canonical" = "/photos/{node}/{photos_image}",
 *     "add-form" = "/photos/image/add",
 *     "collection" = "/admin/content/photos",
 *     "delete-form" = "/photos/{node}/{photos_image}/delete",
 *     "edit-form" = "/photos/{node}/{photos_image}/edit",
 *     "version-history" = "/photos/{node}/{photos_image}/revisions",
 *     "revision" = "/photos/{node}/{photos_image}/revisions/{photos_image_revision}/view",
 *   }
 * )
 */
class PhotosImage extends EditorialContentEntityBase implements PhotosImageInterface {

  // @todo revision ui @see node.
  use EntityOwnerTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    foreach (array_keys($this->getTranslationLanguages()) as $langcode) {
      $translation = $this->getTranslation($langcode);

      // If no owner has been set explicitly, make the anonymous user the owner.
      if (!$translation->getOwner()) {
        $translation->setOwnerId(0);
      }
    }

    // If no revision author has been set explicitly, make the image owner the
    // revision author.
    if (!$this->getRevisionUser()) {
      $this->setRevisionUserId($this->getOwnerId());
    }

    // @todo check media and look into creating thumbnails and any other
    // derivatives.
  }

  /**
   * {@inheritdoc}
   */
  public function preSaveRevision(EntityStorageInterface $storage, \stdClass $record) {
    parent::preSaveRevision($storage, $record);

    $is_new_revision = $this->isNewRevision();
    if (!$is_new_revision && isset($this->original) && empty($record->revision_log_message)) {
      // If we are updating an existing media item without adding a
      // new revision, we need to make sure $entity->revision_log_message is
      // reset whenever it is empty.
      // Therefore, this code allows us to avoid clobbering an existing log
      // entry with an empty one.
      $record->revision_log_message = $this->original->revision_log_message->value;
    }

    if ($is_new_revision) {
      $record->revision_created = self::getRequestTime();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);

    // @see PhotosImageFile::delete.
    // @todo move count updates to entity delete form?
    foreach ($entities as $entity) {
      $album_id = $entity->getAlbumId();
      // Clear cache.
      Cache::invalidateTags(['node:' . $album_id, 'photos:album:' . $album_id]);
      Cache::invalidateTags(['photos:image:' . $entity->id()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFids() {
    $fids = [];
    $photosImageFields = \Drupal::service('entity_field.manager')->getFieldDefinitions('photos_image', 'photos_image');
    // @todo warn if other unhandled fields exist?
    foreach ($photosImageFields as $key => $field) {
      /** @var \Drupal\Core\Field\FieldDefinitionInterface $field */
      $fieldType = $field->getType();
      if ($fieldType == 'file' || $fieldType == 'image') {
        // Check image and file fields.
        foreach ($this->$key as $item) {
          $fids[$item->entity->id()] = $item->entity->id();
        }
      }
      elseif ($fieldType == 'entity_reference') {
        // Check media fields.
        $settings = $field->getSettings();
        if ($settings['target_type'] == 'media') {
          foreach ($this->$key as $item) {
            $media = Media::load($item->entity->id());
            // @todo maybe getSourceFieldDefinition here?
            $fid = $media->getSource()->getSourceFieldValue($media);
            $fids[$fid] = $fid;
          }
        }
      }
    }
    return $fids;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle($title) {
    $this->set('title', $title);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->get('description')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setDescription($description) {
    $this->set('description', $description);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormat() {
    return $this->get('description')->format;
  }

  /**
   * {@inheritdoc}
   */
  public function setFormat($format) {
    $this->get('description')->format = $format;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAlbumId() {
    return $this->get('album_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setAlbumId($album_id) {
    $this->set('album_id', $album_id);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAlbumUrl() {
    $album_link_override = \Drupal::config('photos.settings')->get('album_link_override');
    if ($album_link_override) {
      $album_link_override = str_replace(':', '.', $album_link_override);
      // @todo add support for other arguments?
      $url = Url::fromRoute('view.' . $album_link_override, [
        'node' => $this->getAlbumId(),
      ]);
    }
    else {
      // Default to the photo album node page.
      $url = Url::fromRoute('entity.node.canonical', [
        'node' => $this->getAlbumId(),
      ]);
    }
    return $url;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->get('weight')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setWeight($weight) {
    $this->set('weight', $weight);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * Gets an array of placeholders for this entity.
   *
   * Individual entity classes may override this method to add additional
   * placeholders if desired. If so, they should be sure to replicate the
   * property caching logic.
   *
   * @param string $rel
   *   The link relationship type, for example: canonical or edit-form.
   *
   * @return array
   *   An array of URI placeholders.
   */
  protected function urlRouteParameters($rel) {
    $uri_route_parameters = [];

    if (!in_array($rel, ['collection', 'add-page', 'add-form'], TRUE)) {
      // The entity ID is needed as a route parameter.
      $uri_route_parameters[$this->getEntityTypeId()] = $this->id();
      // Include album node ID.
      $uri_route_parameters['node'] = $this->getAlbumId();
    }
    if ($rel === 'add-form' && ($this->getEntityType()->hasKey('bundle'))) {
      $parameter_name = $this->getEntityType()->getBundleEntityType() ?: $this->getEntityType()->getKey('bundle');
      $uri_route_parameters[$parameter_name] = $this->bundle();
    }
    if ($rel === 'revision' && $this instanceof RevisionableInterface) {
      $uri_route_parameters[$this->getEntityTypeId() . '_revision'] = $this->getRevisionId();
    }

    return $uri_route_parameters;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The image title.'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // @todo migrate fid to new field_image field.
    // @todo Add an admin setting to select default image or file field for
    // upload form? Check if field exists when upload form loads. OR display
    // message to add field_image to photos_image to enable the upload form?
    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setDescription(t('The image description field.'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'text_default',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'text_textfield',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // @todo look at media thumbnail to use for album cover.
    // @todo get default image style from config settings (or field settings).
    $fields['uid']
      ->setLabel(t('Authored by'))
      ->setDescription(t('The username of the author.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'author',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 4,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the image was created.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'timestamp',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // @todo target_type photos_album if entity is used for album.
    $fields['album_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Album ID'))
      ->setDescription(t('The album node ID.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'entity_reference_label',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 3,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setSetting('target_type', 'node')
      ->setDisplayConfigurable('form', TRUE);

    $fields['status']
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
        'weight' => 120,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('The image weight for custom sort order.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'number_integer',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the image was last edited.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function getRequestTime() {
    return \Drupal::time()->getRequestTime();
  }

  /**
   * {@inheritdoc}
   */
  public function getPager($id, $type = 'album_id') {
    $entity_id = $this->id();
    $db = \Drupal::database();
    $query = $db->select('photos_image_field_data', 'p');
    $query->innerJoin('node_field_data', 'n', 'n.nid = p.album_id');
    $query->fields('p', ['id', 'album_id']);
    $query->fields('n', ['title']);

    // Default order by id.
    $order = ['column' => 'p.id', 'sort' => 'DESC'];
    if ($type == 'album_id') {
      // Viewing album.
      // Order images by album settings.
      $album_data = $db->query('SELECT data FROM {photos_album} WHERE album_id = :album_id', [
        ':album_id' => $id,
      ])->fetchField();
      // @todo look into core serialization API.
      // @see https://www.drupal.org/docs/8/api/serialization-api/serialization-api-overview
      $album_data = unserialize($album_data);
      $default_order = \Drupal::config('photos.settings')->get('photos_display_imageorder');
      $image_order = isset($album_data['imageorder']) ? $album_data['imageorder'] : $default_order;
      $order = explode('|', $image_order);
      $order = PhotosAlbum::orderValueChange($order[0], $order[1]);
      $query->condition('p.album_id', $id);
    }
    elseif ($type == 'uid') {
      // Viewing all user images.
      $query->condition('p.uid', $id);
    }
    $query->orderBy($order['column'], $order['sort']);
    if ($order['column'] != 'p.id') {
      $query->orderBy('p.id', 'DESC');
    }
    $results = $query->execute();

    $stop = $pager['prev'] = $pager['next'] = 0;
    $num = 0;
    // @todo use view mode.
    $previousImageId = NULL;
    $photosImageStorage = \Drupal::entityTypeManager()->getStorage('photos_image');
    $photosImageViewBuilder = \Drupal::entityTypeManager()->getViewBuilder('photos_image');
    foreach ($results as $result) {
      $num++;
      // @todo new pager display view mode.
      if ($stop == 1) {
        $photosImage = $photosImageStorage->load($result->id);
        $image_view = $photosImageViewBuilder->view($photosImage, 'pager');
        $pager['nextView'] = $image_view;
        // Next image.
        $pager['nextUrl'] = Url::fromRoute('entity.photos_image.canonical', [
          'node' => $result->album_id,
          'photos_image' => $photosImage->id(),
        ])->toString();
        break;
      }
      if ($result->id == $entity_id) {
        $photosImage = $photosImageStorage->load($result->id);
        $image_view = $photosImageViewBuilder->view($photosImage, 'pager');
        $pager['currentView'] = $image_view;
        $stop = 1;
      }
      else {
        $previousImageId = $result->id;
      }
      $pager['albumTitle'] = $result->title;
    }
    if ($previousImageId) {
      $photosImage = $photosImageStorage->load($previousImageId);
      $image_view = $photosImageViewBuilder->view($photosImage, 'pager');
      $pager['prevView'] = $image_view;
      // Previous image.
      $pager['prevUrl'] = Url::fromRoute('entity.photos_image.canonical', [
        'node' => $id,
        'photos_image' => $photosImage->id(),
      ])->toString();
    }

    // @todo theme photos_pager with options for image and no-image.
    return $pager;
  }

}
