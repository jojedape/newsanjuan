<?php

namespace Drupal\photos;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines an access control handler for photos_image items.
 */
class PhotosAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')
    );
  }

  /**
   * Constructs the photos access control handler instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityTypeManagerInterface $entity_manager) {
    parent::__construct($entity_type);
    $this->entityTypeManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $is_owner = ($account->id() && $account->id() === $entity->getOwnerId());
    switch ($operation) {
      case 'view':
        $accessResult = AccessResult::neutral()->cachePerPermissions();
        // Value is fid, check if user can view this photo's album.
        if (\Drupal::config('photos.settings')->get('photos_access_photos')) {
          // Check if album password is required.
          photos_access_request_album_password();
          $node = _photos_access_pass_type($entity->id(), 1);
          $nid = NULL;
          $uid = FALSE;
          $viewId = 0;
          // Check if user is node author.
          if (isset($node['node'])) {
            $uid = $node['node']->uid;
            $viewId = $node['node']->viewid;
            $nid = $node['node']->nid;
          }
          elseif (isset($node['view'])) {
            $uid = $node['view']->uid;
            $viewId = $node['view']->viewid;
            $nid = $node['view']->nid;
          }
          elseif (isset($node['update'])) {
            $uid = $node['update']->uid;
            $viewId = $node['update']->viewid;
            $nid = $node['update']->nid;
          }
          if ($uid && $account->id() == $uid) {
            // Node owner is allowed access.
            return AccessResult::allowed()->cachePerPermissions();
          }
          if ($account->hasPermission('view photo')) {
            if ($viewId && $viewId < 3) {
              // Check node access.
              $node = $this->entityTypeManager->getStorage('node')->load($nid);
              $accessResult = AccessResult::allowedIf($node->access('view'))
                ->cachePerPermissions()
                ->addCacheableDependency($entity);
            }
            elseif (isset($node['node']) && $node['node']->viewid == 4) {
              // @todo move logic.
              // Check role access.
              $accountRoles = $account->getRoles();
              $node = $this->entityTypeManager->getStorage('node')->load($node['node']->nid);
              if ($node && isset($node->photos_privacy) && isset($node->photos_privacy['roles'])) {
                if (count(array_intersect($accountRoles, $node->photos_privacy['roles'])) !== 0) {
                  $accessResult = AccessResult::allowedIf($account->hasPermission('view photo'))
                    ->cachePerPermissions()
                    ->addCacheableDependency($entity);
                }
              }
            }
            elseif (isset($node['view']) && $node['view']->viewid == 3 && isset($node['view']->pass)) {
              // Check password.
              $correctPassword = FALSE;
              if (isset($_SESSION[$node['view']->nid . '_' . session_id()]) && $node['view']->pass == $_SESSION[$node['view']->nid . '_' . session_id()] || !photos_access_pass_validate($node)) {
                $correctPassword = TRUE;
              }
              $accessResult = AccessResult::allowedIf($correctPassword)
                ->cachePerPermissions()
                ->addCacheableDependency($entity);
            }
            else {
              $accessResult = AccessResult::allowedIf($account->hasPermission('view photo'))
                ->cachePerPermissions()
                ->addCacheableDependency($entity);
            }
          }
        }
        else {
          $accessResult = AccessResult::allowedIfHasPermission($account, 'view photo')
            ->cachePerPermissions()
            ->addCacheableDependency($entity);
        }
        // @todo check if $entity->isPublished().
        return $accessResult;

      case 'update':
        if ($account->hasPermission('edit own photo') && $is_owner) {
          return AccessResult::allowed()->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);
        }
        if ($account->hasPermission('edit any photo')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        return AccessResult::neutral("The following permissions are required: 'edit any photo' OR 'edit own photos'.")->cachePerPermissions();

      case 'delete':
        if ($account->hasPermission('delete any photo')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        if ($account->hasPermission('delete own photo') && $is_owner) {
          return AccessResult::allowed()->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);
        }
        return AccessResult::neutral("The following permissions are required: 'delete any photo' OR 'delete own photos'.")->cachePerPermissions();

      default:
        return AccessResult::neutral()->cachePerPermissions();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    $permissions = [
      'administer nodes',
      'create photo',
    ];
    return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
  }

}
