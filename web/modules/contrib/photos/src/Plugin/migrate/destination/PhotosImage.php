<?php

namespace Drupal\photos\Plugin\migrate\destination;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\photos\PhotosUploadInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Photos image migration destination.
 *
 * @MigrateDestination(
 *   id = "d7_photos_image",
 *   destination_module = "photos"
 * )
 */
class PhotosImage extends DestinationBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The image factory service.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * The photos upload handler.
   *
   * @var \Drupal\photos\PhotosUploadInterface
   */
  protected $photosUpload;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a PhotosImage object.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The current migration.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   The image factory service.
   * @param \Drupal\photos\PhotosUploadInterface $photos_upload
   *   The photos upload service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, Connection $connection, EntityTypeManagerInterface $entity_manager, ImageFactory $image_factory, PhotosUploadInterface $photos_upload, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->connection = $connection;
    $this->entityTypeManager = $entity_manager;
    $this->imageFactory = $image_factory;
    $this->photosUpload = $photos_upload;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('image.factory'),
      $container->get('photos.upload'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $title = $row->getDestinationProperty('title');
    $fid = $row->getDestinationProperty('fid');
    try {
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      $image = $this->imageFactory->get($file->getFileUri());
      if ($image) {
        if (empty($title)) {
          // @note known issue: title can not be null.
          $title = $this->photosUpload->cleanTitle($file->getFilename());
        }
        try {
          // Create new photos_image entity.
          $photosImage = $this->entityTypeManager->getStorage('photos_image')->create([
            'uid' => $row->getDestinationProperty('uid'),
            'album_id' => $row->getDestinationProperty('pid'),
            'title' => $title,
            'weight' => $row->getDestinationProperty('wid'),
            'description' => $row->getDestinationProperty('des'),
            'field_image' => [
              'target_id' => $fid,
              'alt' => $title,
              'title' => $title,
              'width' => $image->getWidth(),
              'height' => $image->getHeight(),
            ],
          ]);
          try {
            $photosImage->save();
            if ($photosImage) {
              try {
                // Move image views to the {photos_count} table.
                $this->connection->insert('photos_count')
                  ->fields([
                    'cid' => $photosImage->id(),
                    'changed' => $this->time->getRequestTime(),
                    'type' => 'image_views',
                    'value' => $row->getDestinationProperty('count'),
                  ])
                  ->execute();
              }
              catch (\Exception $e) {
                watchdog_exception('photos', $e);
              }
              // Successfully created new photos_image entity.
              return [$photosImage->id()];
            }
          }
          catch (EntityStorageException $e) {
            watchdog_exception('photos', $e);
          }
        }
        catch (InvalidPluginDefinitionException $e) {
          watchdog_exception('photos', $e);
        }
        catch (PluginNotFoundException $e) {
          watchdog_exception('photos', $e);
        }
      }
    }
    catch (InvalidPluginDefinitionException $e) {
      watchdog_exception('photos', $e);
    }
    catch (PluginNotFoundException $e) {
      watchdog_exception('photos', $e);
    }

    // Something was missing.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['id']['type'] = 'integer';
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    return [
      'target_id' => $this->t('Image file ID'),
      'album_id' => $this->t('Photos Album node ID'),
      'title' => $this->t('Image title'),
      'description' => $this->t('Image description'),
      'weight' => $this->t('Weight'),
      'value' => $this->t('Image views count'),
    ];
  }

}
