<?php

/**
 * @file
 * Post update functions for Photos module.
 */

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Migrate images to new photos_image entity.
 */
function photos_post_update_migrate_photos_image_entity_1(&$sandbox = NULL) {
  // Check if temporary table was created.
  if (\Drupal::database()->schema()->tableExists('photos_image_tmp')) {
    if (!isset($sandbox['steps'])) {
      $sandbox['limit'] = 25;
      $sandbox['current_step'] = 0;
      $sandbox['current_fid'] = 0;
      // Count query.
      $query = \Drupal::database()->select('photos_image_tmp', 'i')
        ->fields('i', ['fid']);
      $num_rows = $query->countQuery()->execute()->fetchField();
      $sandbox['steps'] = $num_rows;
    }
    $query = \Drupal::database()->select('photos_image_tmp', 'i');
    $query->join('file_managed', 'f', 'i.fid = f.fid');
    $results = $query->fields('i')
      ->fields('f', ['uid'])
      ->condition('i.fid', $sandbox['current_fid'], '>')
      ->range(0, $sandbox['limit'])
      ->orderBy('i.fid', 'ASC')
      ->execute();
    foreach ($results as $result) {
      $fid = $result->fid;
      $sandbox['current_step']++;
      $sandbox['current_fid'] = $fid;
      try {
        $title = $result->title;
        /** @var \Drupal\file\FileInterface $file */
        $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
        $image = \Drupal::service('image.factory')->get($file->getFileUri());
        if ($image) {
          if (empty($title)) {
            // @note known issue: title can not be null.
            $title = \Drupal::service('photos.upload')->cleanTitle($file->getFilename());
          }
          try {
            // Create new photos_image entity.
            $photosImage = \Drupal::entityTypeManager()->getStorage('photos_image')->create([
              'uid' => $result->uid,
              'album_id' => $result->pid,
              'title' => $title,
              'weight' => $result->wid,
              'description' => $result->des,
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
                  \Drupal::database()->insert('photos_count')
                    ->fields([
                      'cid' => $photosImage->id(),
                      'changed' => \Drupal::time()->getRequestTime(),
                      'type' => 'image_views',
                      'value' => $result->count,
                    ])
                    ->execute();
                }
                catch (\Exception $e) {
                  watchdog_exception('photos', $e);
                }
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
    }

    $sandbox['#finished'] = $sandbox['current_step'] / $sandbox['steps'];
    if ($sandbox['#finished'] >= 1) {
      return t('Updated to use the new photos_image entity.');
    }
  }
  return NULL;
}

/**
 * Update {photos_album}.cover_id to use new photos_image entity id.
 */
function photos_post_update_migrate_photos_image_entity_2(&$sandbox = NULL) {
  // Check if temporary table was created.
  if (\Drupal::database()->schema()->tableExists('photos_image_tmp')) {
    if (!isset($sandbox['steps'])) {
      $sandbox['limit'] = 50;
      $sandbox['current_step'] = 0;
      $sandbox['current_id'] = 0;
      // Count query.
      $query = \Drupal::database()->select('photos_album', 'a')
        ->fields('a', ['cover_id'])
        ->condition('a.cover_id', 0, '!=');
      $num_rows = $query->countQuery()->execute()->fetchField();
      $sandbox['steps'] = $num_rows;
      if ($sandbox['steps'] <= 1) {
        // Drop the temporary table.
        \Drupal::database()->schema()->dropTable('photos_image_tmp');
        $sandbox['#finished'] = 1;
        return t('No albums need to be updated.');
      }
    }

    $results = \Drupal::database()->select('photos_album', 'a')
      ->fields('a')
      ->condition('a.album_id', $sandbox['current_id'], '>')
      ->condition('a.cover_id', 0, '!=')
      ->orderBy('a.album_id', 'ASC')
      ->range(0, $sandbox['limit'])
      ->execute();
    foreach ($results as $result) {
      $sandbox['current_step']++;
      $sandbox['current_id'] = $result->album_id;
      $photosImageIds = \Drupal::entityQuery('photos_image')
        ->condition('field_image.target_id', $result->cover_id)
        ->execute();
      $cover_id = 0;
      if (!empty($photosImageIds)) {
        $cover_id = reset($photosImageIds);
      }
      \Drupal::database()->update('photos_album')
        ->fields([
          'cover_id' => $cover_id,
        ])
        ->condition('album_id', $result->album_id)
        ->execute();
    }
    $sandbox['#finished'] = $sandbox['current_step'] / $sandbox['steps'];
    if ($sandbox['#finished'] >= 1) {
      // Drop the temporary table.
      \Drupal::database()->schema()->dropTable('photos_image_tmp');
      return t('Updated album cover id to use photos_image entity id.');
    }
  }
  return NULL;
}

/**
 * Rebuild photos upload container to add new container parameter.
 */
function photos_post_update_new_photos_upload_container_parameter_token() {
  // Empty update to cause a cache rebuild so that the container is rebuilt.
}
