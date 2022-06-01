<?php

namespace Drupal\photos_access\Plugin\views\access;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\views\Plugin\views\access\AccessPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;
use Drupal\Core\Session\AccountInterface;

/**
 * Access plugin for photos album and images.
 *
 * @ingroup views_access_plugins
 *
 * @ViewsAccess(
 *   id = "photos_access",
 *   title = @Translation("Photos Access"),
 *   help = @Translation("Access will be granted depending on album settings.")
 * )
 */
class PhotosAccess extends AccessPluginBase implements CacheableDependencyInterface {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs the photos access control handler instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    return $this->t('Album privacy settings');
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    $access = FALSE;
    // Check if we need to be redirected to set the password.
    photos_access_request_album_password();
    // Check if locked and not owner / collaborator.
    $nid = $this->routeMatch->getRawParameter('node');
    $photosAccessNode = _photos_access_pass_type($nid);
    $nid = NULL;
    $uid = FALSE;
    $viewId = 0;
    // Check if user is node author.
    if (isset($photosAccessNode['node'])) {
      $uid = $photosAccessNode['node']->uid;
      $viewId = $photosAccessNode['node']->viewid;
      $nid = $photosAccessNode['node']->nid;
    }
    elseif (isset($photosAccessNode['view'])) {
      $uid = $photosAccessNode['view']->uid;
      $viewId = $photosAccessNode['view']->viewid;
      $nid = $photosAccessNode['view']->nid;
    }
    elseif (isset($photosAccessNode['update'])) {
      $uid = $photosAccessNode['update']->uid;
      $viewId = $photosAccessNode['update']->viewid;
      $nid = $photosAccessNode['update']->nid;
    }
    if ($uid && $account->id() == $uid) {
      // Node owner is allowed access.
      $access = TRUE;
    }
    if ($account->hasPermission('view photo')) {
      if ($viewId && $viewId != 3) {
        // Check node access.
        /** @var \Drupal\node\Entity\Node $node */
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        $access = $node->access('view');
      }
      elseif (isset($photosAccessNode['view']->pass)) {
        // Check password.
        $access = FALSE;
        if (isset($_SESSION[$photosAccessNode['view']->nid . '_' . session_id()]) && $photosAccessNode['view']->pass == $_SESSION[$photosAccessNode['view']->nid . '_' . session_id()] || !photos_access_pass_validate($photosAccessNode)) {
          $access = TRUE;
        }
      }
      else {
        $access = $account->hasPermission('view photo');
      }
    }
    if ($access == FALSE) {
      // We don't want the title visible here or anything from the view, so we
      // throw access denied instead of returning FALSE.
      throw new AccessDeniedHttpException();
    }
    return $access;
  }

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route) {
    $route->setRequirement('_access', 'TRUE');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['user.node_grants:view'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $nid = $this->routeMatch->getRawParameter('node');
    return ['photos:album:' . $nid, 'node:' . $nid];
  }

}
