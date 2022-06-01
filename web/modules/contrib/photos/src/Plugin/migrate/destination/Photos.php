<?php

namespace Drupal\photos\Plugin\migrate\destination;

use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Photos migration destination.
 *
 * @MigrateDestination(
 *   id = "d7_photos",
 *   destination_module = "photos"
 * )
 */
class Photos extends DestinationBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a Photos object.
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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, Connection $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->connection = $connection;
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
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    // Look up {photos_image}.id for cover_id.
    $photosImageIds = \Drupal::entityQuery('photos_image')
      ->condition('field_image.target_id', $row->getDestinationProperty('fid'))
      ->execute();
    $cover_id = 0;
    if (!empty($photosImageIds)) {
      $cover_id = reset($photosImageIds);
    }
    $path = $this->connection->update('photos_album')
      ->fields([
        'cover_id' => $cover_id,
        'weight' => $row->getDestinationProperty('wid'),
        'count' => $row->getDestinationProperty('count'),
        'data' => $row->getDestinationProperty('data'),
      ])
      ->condition('album_id', $row->getDestinationProperty('pid'))
      ->execute();

    return [$path['album_id']];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['album_id']['type'] = 'integer';
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    return [
      'album_id' => $this->t('Photos Album node ID'),
      'cover_id' => $this->t('Album cover file ID'),
      'weight' => $this->t('Weight'),
      'count' => $this->t('Image count'),
      'data' => $this->t('Serialized array of album data'),
    ];
  }

}
