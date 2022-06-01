<?php

namespace Drupal\photos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Update image view count(s).
 *
 * @package Drupal\statistics\Controller
 */
class PhotosStatisticsUpdateController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Ajax callback to record photos_image visit.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON object containing the image visit count.
   */
  public function updateCount() {
    $photosImageCountDisabled = $this->config('photos.settings')->get('photos_image_count');

    $json = ['count' => 1];
    if (!$photosImageCountDisabled) {
      $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
      if ($id) {
        // $this->request_stack->push(Request::createFromGlobals());
        $json['count'] = $this->recordView($id);
      }
    }

    return new JsonResponse($json);
  }

  /**
   * Record the image view to the database.
   *
   * @param int $id
   *   The photos_image entity id.
   *
   * @return int
   *   The current visit count for this image.
   */
  public function recordView($id) {
    // @todo use core stats instead (when ready).
    $count = 1;
    try {
      $this->connection->merge('photos_count')
        ->keys([
          'cid' => $id,
          'type' => 'image_views',
        ])
        ->fields([
          'value' => $count,
          'changed' => \Drupal::time()->getRequestTime(),
        ])
        ->expression('value', 'value + :count', [
          ':count' => $count,
        ])
        ->execute();
      $count = $this->connection->select('photos_count', 'c')
        ->fields('c', ['value'])
        ->condition('c.cid', $id)
        ->condition('c.type', 'image_views')
        ->execute()->fetchField();
      return $count;
    }
    catch (\Exception $e) {
      \Drupal::logger('photos')->notice('Image view statistics failed.');
      watchdog_exception('photos', $e);
      return $count;
    }
  }

}
