<?php

namespace Drupal\photos\Plugin\migrate\destination;

use Drupal\Core\Database\Connection;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Photos count migration destination.
 *
 * @MigrateDestination(
 *   id = "d7_photos_count",
 *   destination_module = "photos"
 * )
 */
class PhotosCount extends DestinationBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a PhotosCount object.
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

    $this->connection->merge('photos_count')
      ->keys(['id' => $row->getDestinationProperty('id')])
      ->fields([
        'cid' => $row->getDestinationProperty('cid'),
        'changed' => $row->getDestinationProperty('changed'),
        'type' => $row->getDestinationProperty('type'),
        'value' => $row->getDestinationProperty('value'),
      ])
      ->execute();

    return [$row->getDestinationProperty('id')];
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
      'id' => $this->t('Unique ID'),
      'cid' => $this->t('Entity ID'),
      'changed' => $this->t('Last updated'),
      'type' => $this->t('Type of count'),
      'value' => $this->t('Count value'),
    ];
  }

}
