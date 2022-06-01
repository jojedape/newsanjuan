<?php

namespace Drupal\photos\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\photos\PhotosAlbum;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to edit images.
 */
class PhotosImageEditForm extends ContentEntityForm {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

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
   * The Current User object.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $connection, DateFormatterInterface $date_formatter, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, ModuleHandlerInterface $module_handler, RendererInterface $renderer, EntityTypeBundleInfoInterface $entity_type_bundle_info = NULL, EntityRepositoryInterface $entity_repository, TimeInterface $time = NULL, AccountInterface $current_user) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);

    $this->connection = $connection;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->moduleHandler = $module_handler;
    $this->renderer = $renderer;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('module_handler'),
      $container->get('renderer'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity.repository'),
      $container->get('datetime.time'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\photos\PhotosImageInterface $photosImage */
    $photosImage = $this->entity;

    // @todo phase out type (no more sub-albums...).
    $type = 'album';

    $user = $this->currentUser();

    $form['#title'] = $this->t('Edit @title', [
      '@title' => $photosImage->getTitle(),
    ]);

    // Changed must be sent to the client, for later overwrite error checking.
    $form['changed'] = [
      '#type' => 'hidden',
      '#default_value' => $photosImage->getChangedTime(),
    ];

    // Get node object.
    $node = $this->entityTypeManager->getStorage('node')->load($photosImage->getAlbumId());
    $nid = $node->id();
    $cover = [];
    if (isset($node->album) && isset($node->album['cover_id']) && !empty($node->album['cover_id'])) {
      $photos_album = new PhotosAlbum($node->id());
      $cover = $photos_album->getCover($node->album['cover_id']);
      $cover['id'] = $node->album['cover_id'];
    }
    $photosImage->info = [
      'cover' => $cover,
      'pid' => $node->id(),
      'title' => $node->getTitle(),
      'uid' => $node->getOwnerId(),
    ];

    // @todo build imageView?
    $imageView = [];
    $imageView['photos_image'] = $photosImage;

    // Album.
    $album_update = '';
    if ($photosImage && $user->id() != $photosImage->info['uid']) {
      $title = isset($photosImage->info['title']) ? $photosImage->info['title'] : '';
      $album_update = [$nid, $photosImage->info['title']];
    }
    $uid = $photosImage ? $photosImage->getOwnerId() : $user->id();
    $form['old_uid'] = ['#type' => 'hidden', '#default_value' => $uid];
    // $albumOptions = PhotosAlbum::userAlbumOptions($uid, $album_update);
    if (isset($node->album) && isset($node->album['cover_id'])) {
      $form['cover_id'] = [
        '#type' => 'hidden',
        '#default_value' => $node->album['cover_id'],
      ];
    }
    $form['old_album_id'] = ['#type' => 'hidden', '#default_value' => $nid];

    $form['nid'] = ['#type' => 'hidden', '#default_value' => $nid];
    // $form['type'] = ['#type' => 'hidden', '#value' => $type];
    $account = $this->entityTypeManager->getStorage('user')->load($photosImage->getOwnerId());
    $imageView['href'] = 'photos/' . $photosImage->getAlbumId() . '/' . $photosImage->id();
    $item = [];
    if ($type == 'album' && (!isset($cover['id']) || isset($cover['id']) && $photosImage->id() != $cover['id'])) {
      // Set cover link.
      $cover_url = Url::fromRoute('photos.album.update.cover', [
        'node' => $photosImage->getAlbumId(),
        'photos_image' => $photosImage->id(),
      ], [
        'attributes' => [
          'target' => '_blank',
        ],
      ]);
      $item[] = Link::fromTextAndUrl($this->t('Set to Cover'), $cover_url);
    }
    // @todo counts.
    $form['cover_items'] = [
      '#theme' => 'item_list',
      '#items' => $item,
    ];

    $username = [
      '#theme' => 'username',
      '#account' => $account,
    ];
    $upload_info = $this->t('Uploaded on @time by @name', [
      '@name' => $this->renderer->renderPlain($username),
      '@time' => $this->dateFormatter->format($photosImage->getCreatedTime(), 'short'),
    ]);
    // @todo test moving image with album reference field.
    $form['time']['#markup'] = $upload_info;
    $form['oldtitle'] = [
      '#type' => 'hidden',
      '#default_value' => $photosImage->getTitle(),
    ];

    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // Save changes.
    $photosImage = $this->entity;
    $photosImage->save();

    // Process image cropping data.
    $form_state_values = $form_state->getValues();
    $album_id = $form_state_values['album_id'][0]['target_id'];
    $old_album_id = $form_state_values['old_album_id'];
    $uid = $form_state_values['uid'][0]['target_id'];
    $old_uid = $form_state_values['old_uid'];

    // Clear image page cache.
    Cache::invalidateTags(['photos:image:' . $photosImage->id()]);
    if ($nid = $form_state->getValue('nid')) {
      // Clear album page and node cache.
      Cache::invalidateTags(['photos:album:' . $nid, 'node:' . $nid]);
    }

    if ($album_id) {
      // Update album count.
      PhotosAlbum::setCount('node_album', $album_id);
      // Clear album page and node cache.
      Cache::invalidateTags(['photos:album:' . $album_id, 'node:' . $album_id]);
      if ($old_album_id && $old_album_id != $album_id) {
        // Update old album count.
        PhotosAlbum::setCount('node_album', $old_album_id);
        // Clear old album page and node cache.
        Cache::invalidateTags([
          'photos:album:' . $old_album_id,
          'node:' . $old_album_id,
        ]);
      }
    }

    if ($uid) {
      // Update user count.
      PhotosAlbum::setCount('user_image', $uid);
      if ($old_uid != $uid) {
        PhotosAlbum::setCount('user_image', $old_uid);
      }
    }

    // @todo dependency injection.
    $this->messenger->addMessage($this->t('Changes saved.'));
  }

}
