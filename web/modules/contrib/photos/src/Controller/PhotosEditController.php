<?php

namespace Drupal\photos\Controller;

use Drupal\Core\Url;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\photos\PhotosAlbum;
use Drupal\photos\PhotosImageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edit images and image details.
 */
class PhotosEditController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * The FormBuilder object.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The current request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request stack.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(Connection $connection, CurrentPathStack $current_path, FormBuilderInterface $form_builder, ModuleHandlerInterface $module_handler, RendererInterface $renderer, RequestStack $request_stack, RouteMatchInterface $route_match) {
    $this->connection = $connection;
    $this->currentPath = $current_path;
    $this->formBuilder = $form_builder;
    $this->moduleHandler = $module_handler;
    $this->renderer = $renderer;
    $this->requestStack = $request_stack;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('path.current'),
      $container->get('form_builder'),
      $container->get('module_handler'),
      $container->get('renderer'),
      $container->get('request_stack'),
      $container->get('current_route_match')
    );
  }

  /**
   * A custom access check.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node entity.
   * @param \Drupal\photos\PhotosImageInterface $photos_image
   *   The photos_image entity.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(NodeInterface $node, PhotosImageInterface $photos_image) {
    if ($node && $photos_image) {
      // Update cover.
      if ($node->getType() == 'photos' && $node->access('update')) {
        // Allowed to update album cover image.
        return AccessResult::allowed();
      }
      else {
        // Deny access.
        return AccessResult::forbidden();
      }
    }
    else {
      return AccessResult::neutral();
    }
  }

  /**
   * Set album cover.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The photo album node.
   * @param \Drupal\photos\PhotosImageInterface $photos_image
   *   The photos_image entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to destination or photo album node page.
   */
  public function setAlbumCover(NodeInterface $node, PhotosImageInterface $photos_image) {
    $nid = $node->id();
    $album_id = $this->connection->query('SELECT album_id FROM {photos_image_field_data} WHERE id = :cover_id', [':cover_id' => $photos_image->id()])->fetchField();
    if ($album_id == $nid) {
      $album = new PhotosAlbum($album_id);
      $album->setCover($photos_image->id());
      $get_destination = $this->requestStack->getCurrentRequest()->query->get('destination');
      if ($get_destination) {
        $goto = Url::fromUri('base:' . $get_destination)->toString();
      }
      else {
        $goto = $photos_image->getAlbumUrl()->toString();
      }
      return new RedirectResponse($goto);
    }
    else {
      throw new NotFoundHttpException();
    }
  }

}
