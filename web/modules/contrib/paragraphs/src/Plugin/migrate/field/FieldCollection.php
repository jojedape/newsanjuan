<?php

namespace Drupal\paragraphs\Plugin\migrate\field;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_drupal\Plugin\migrate\field\FieldPluginBase;

/**
 * Field Plugin for field collection migrations.
 *
 * @todo Implement ::defineValueProcessPipeline()
 * @see https://www.drupal.org/project/paragraphs/issues/2911244
 *
 * @MigrateField(
 *   id = "field_collection",
 *   core = {7},
 *   type_map = {
 *     "field_collection" = "entity_reference_revisions",
 *   },
 *   source_module = "field_collection",
 *   destination_module = "paragraphs",
 * )
 */
class FieldCollection extends FieldPluginBase {

  /**
   * Length of the 'field_' prefix that field collection prepends to bundles.
   */
  const FIELD_COLLECTION_PREFIX_LENGTH = 6;

  /**
   * {@inheritdoc}
   */
  public function alterFieldFormatterMigration(MigrationInterface $migration) {
    $this->addViewModeProcess($migration);
    parent::alterFieldFormatterMigration($migration);
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldFormatterMap() {
    return [
      'field_collection_view' => 'entity_reference_revisions_entity_view',
    // TODO: Change the autogenerated stub.
    ] + parent::getFieldFormatterMap();
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldWidgetMap() {
    return ['field_collection_embed' => 'entity_reference_paragraphs']
      + parent::getFieldWidgetMap();
  }

  /**
   * {@inheritdoc}
   */
  public function alterFieldMigration(MigrationInterface $migration) {
    $settings = [
      'field_collection' => [
        'plugin' => 'field_collection_field_settings',
      ],
    ];
    $migration->mergeProcessOfProperty('settings', $settings);
  }

  /**
   * {@inheritdoc}
   */
  public function alterFieldInstanceMigration(MigrationInterface $migration) {
    $settings = [
      'field_collection' => [
        'plugin' => 'field_collection_field_instance_settings',
      ],
    ];
    $migration->mergeProcessOfProperty('settings', $settings);
  }

  /**
   * Adds process for view mode settings.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration.
   */
  protected function addViewModeProcess(MigrationInterface $migration) {
    $view_mode = [
      'field_collection' => [
        'plugin' => 'paragraphs_process_on_value',
        'source_value' => 'type',
        'expected_value' => 'field_collection',
        'process' => [
          'plugin' => 'get',
          'source' => 'formatter/settings/view_mode',
        ],
      ],
    ];
    $migration->mergeProcessOfProperty('options/settings/view_mode', $view_mode);
  }

}