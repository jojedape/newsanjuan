<?php

namespace Drupal\photos\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\node\NodeInterface;
use Drupal\photos\PhotosUploadInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to upload photos to this site.
 */
class PhotosUploadForm extends FormBase {

  use DependencySerializationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The image factory.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

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
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The photos upload handler.
   *
   * @var \Drupal\photos\PhotosUploadInterface
   */
  protected $photosUpload;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   The file system service.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   The image factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\photos\PhotosUploadInterface $photos_upload
   *   The photos upload handler.
   */
  public function __construct(Connection $connection, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_manager, FileSystem $file_system, ImageFactory $image_factory, MessengerInterface $messenger, ModuleHandlerInterface $module_handler, RouteMatchInterface $route_match, PhotosUploadInterface $photos_upload) {
    $this->connection = $connection;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_manager;
    $this->fileSystem = $file_system;
    $this->imageFactory = $image_factory;
    $this->logger = $this->getLogger('photos');
    $this->messenger = $messenger;
    $this->moduleHandler = $module_handler;
    $this->routeMatch = $route_match;
    $this->photosUpload = $photos_upload;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('image.factory'),
      $container->get('messenger'),
      $container->get('module_handler'),
      $container->get('current_route_match'),
      $container->get('photos.upload')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'photos_upload';
  }

  /**
   * A custom access check.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node entity.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(NodeInterface $node) {
    // Check if user can edit this album.
    if ($node->getType() == 'photos' && $node->access('update')) {
      return AccessResult::allowed();
    }
    else {
      return AccessResult::forbidden();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
    $config = $this->config('photos.settings');
    $nid = $node->id();

    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['new'] = [
      '#title' => $this->t('Image upload'),
      '#weight' => -4,
      '#type' => 'details',
      '#open' => TRUE,
    ];
    $allow_zip = (($config->get('photos_upzip')) ? ' zip' : '');
    // Check if plubload is installed.
    if ($config->get('photos_plupload_status')) {
      $form['new']['plupload'] = [
        '#type' => 'plupload',
        '#title' => $this->t('Upload photos'),
        '#description' => $this->t('Upload multiple images.'),
        '#autoupload' => TRUE,
        '#submit_element' => '#edit-submit',
        '#upload_validators' => [
          'file_validate_extensions' => ['jpg jpeg gif png' . $allow_zip],
        ],
        '#plupload_settings' => [
          'chunk_size' => '1mb',
        ],
      ];
    }
    else {
      // Manual upload form.
      $form['new']['#description'] = $this->t('Allowed types: jpg gif png jpeg@zip', ['@zip' => $allow_zip]);
      $album_photo_limit = $config->get('album_photo_limit');
      $classic_field_count = $config->get('photos_num');
      if ($album_photo_limit && ($classic_field_count > $album_photo_limit)) {
        $classic_field_count = $album_photo_limit;
      }

      for ($i = 0; $i < $classic_field_count; ++$i) {
        $form['new']['images_' . $i] = [
          '#type' => 'file',
        ];
        $form['new']['title_' . $i] = [
          '#type' => 'textfield',
          '#title' => $this->t('Image title'),
        ];
        $form['new']['des_' . $i] = [
          '#type' => 'textarea',
          '#title' => $this->t('Image description'),
          '#cols' => 40,
          '#rows' => 3,
        ];
      }
    }
    if ($this->moduleHandler->moduleExists('media_library_form_element')) {
      // Check photos default multi-upload field.
      $uploadField = $this->config('photos.settings')->get('multi_upload_default_field');
      $uploadFieldParts = explode(':', $uploadField);
      $field = isset($uploadFieldParts[0]) ? $uploadFieldParts[0] : 'field_image';
      $allBundleFields = $this->entityFieldManager->getFieldDefinitions('photos_image', 'photos_image');
      if (isset($allBundleFields[$field])) {
        $fieldType = $allBundleFields[$field]->getType();
        // Check if media field.
        if ($fieldType == 'entity_reference') {
          $mediaField = isset($uploadFieldParts[1]) ? $uploadFieldParts[1] : '';
          $mediaBundle = isset($uploadFieldParts[2]) ? $uploadFieldParts[2] : '';
          if ($mediaField && $mediaBundle) {
            $form['new']['media_images'] = [
              '#type' => 'media_library',
              '#allowed_bundles' => [$mediaBundle],
              '#title' => $this->t('Select media images'),
              '#default_value' => NULL,
              '#description' => $this->t('Select media images to add to this album.'),
              '#cardinality' => -1,
            ];
          }
        }
      }
    }
    // @todo album_id is redundant unless albums become own entity.
    //   - maybe make album_id serial and add nid... or entity_id.
    $form['new']['album_id'] = [
      '#type' => 'value',
      '#value' => $nid,
    ];
    $form['new']['nid'] = [
      '#type' => 'value',
      '#value' => $nid,
    ];
    $form['new']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm upload'),
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('photos.settings');
    $album_id = $form_state->getValue('album_id');
    $album_photo_limit = $config->get('album_photo_limit');

    $photo_count = $this->connection->query("SELECT count FROM {photos_album} WHERE album_id = :album_id", [
      ':album_id' => $album_id,
    ])->fetchField();

    if ($album_photo_limit && ($photo_count >= $album_photo_limit)) {
      $form_state->setErrorByName('new', $this->t('Maximum number of photos reached for this album.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = $this->currentUser();
    $config = $this->config('photos.settings');
    $album_photo_limit = $config->get('album_photo_limit');
    $count = 0;
    $nid = $form_state->getValue('nid');
    $album_id = $form_state->getValue('album_id');
    $photo_count = $this->connection->query("SELECT count FROM {photos_album} WHERE album_id = :album_id", [
      ':album_id' => $album_id,
    ])->fetchField();
    // If photos_access is enabled check viewid.
    $scheme = 'default';
    if ($this->moduleHandler->moduleExists('photos_access')) {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (isset($node->photos_privacy) && isset($node->photos_privacy['viewid'])) {
        $album_viewid = $node->photos_privacy['viewid'];
        if ($album_viewid > 0) {
          // Check for private file path.
          if (PrivateStream::basePath()) {
            $scheme = 'private';
          }
          else {
            // Set warning message.
            $this->messenger->addWarning($this->t('Warning: image
              files can still be accessed by visiting the direct URL. For
              better security, ask your website admin to setup a private file
              path.'));
          }
        }
      }
    }
    // Check if plupload is enabled.
    // @todo check for plupload library?
    if ($config->get('photos_plupload_status')) {
      $plupload_files = $form_state->getValue('plupload');
      foreach ($plupload_files as $uploaded_file) {
        if ($uploaded_file['status'] == 'done') {
          if ($album_photo_limit && ($photo_count >= $album_photo_limit)) {
            $this->messenger()->addWarning($this->t('Maximum number of photos reached for this album.'));
            break;
          }
          // Check for zip files.
          $ext = mb_substr($uploaded_file['name'], -3);
          if ($ext != 'zip' && $ext != 'ZIP') {
            // Prepare directory.
            // @todo move path to after entity is created or move again later if needed.
            // @todo generate temp path before tokens are available.
            $photosPath = $this->photosUpload->path($scheme);
            $photosName = $uploaded_file['name'];
            $file_uri = $this->fileSystem
              ->getDestinationFilename($photosPath . '/' . $photosName, FileSystemInterface::EXISTS_RENAME);
            if ($this->fileSystem->move($uploaded_file['tmppath'], $file_uri)) {
              $path_parts = pathinfo($file_uri);
              $image = $this->imageFactory->get($file_uri);
              if (isset($path_parts['extension']) && $path_parts['extension'] && $image->getWidth()) {
                // Create a file entity.
                /** @var \Drupal\file\FileInterface $file */
                $file = $this->entityTypeManager->getStorage('file')->create([
                  'uri' => $file_uri,
                  'uid' => $user->id(),
                  'status' => FILE_STATUS_PERMANENT,
                  'album_id' => $form_state->getValue('album_id'),
                  'nid' => $form_state->getValue('nid'),
                  'filename' => $photosName,
                  'filesize' => $image->getFileSize(),
                  'filemime' => $image->getMimeType(),
                ]);

                if ($file->save()) {
                  $photo_count++;
                  $this->photosUpload->saveImage($file);
                }
                $count++;
              }
              else {
                $this->fileSystem->delete($file_uri);
                $this->logger->notice('Wrong file type');
              }
            }
            else {
              $this->logger->notice('Upload error. Could not move temp file.');
            }
          }
          else {
            if (!$config->get('photos_upzip')) {
              $this->messenger->addError($this->t('Please set Album
                photos to open zip uploads.'));
            }
            $directory = $this->photosUpload->path();
            $this->fileSystem->prepareDirectory($directory);
            $zip = $this->fileSystem
              ->getDestinationFilename($directory . '/' . $uploaded_file['name'], FileSystemInterface::EXISTS_RENAME);
            if ($this->fileSystem->move($uploaded_file['tmppath'], $zip)) {
              $params = [];
              $params['album_id'] = $form_state->getValue('album_id');
              $params['photo_count'] = $photo_count;
              $params['album_photo_limit'] = $album_photo_limit;
              $params['nid'] = $form_state->getValue('nid');
              $params['title'] = $uploaded_file['name'];
              $params['des'] = '';
              // Unzip it.
              if (!$file_count = $this->photosUpload->unzip($zip, $params, $scheme)) {
                $this->messenger->addError($this->t('Zip upload failed.'));
              }
              else {
                // Update image upload count.
                $count = $count + $file_count;
                $photo_count = $photo_count + $file_count;
              }
            }
          }
        }
        else {
          $this->messenger->addError($this->t('Error uploading some photos.'));
        }
      }
    }
    else {
      // Manual upload form.
      $photos_num = $config->get('photos_num');
      for ($i = 0; $i < $photos_num; ++$i) {
        if (isset($_FILES['files']['name']['images_' . $i]) && $_FILES['files']['name']['images_' . $i]) {
          if ($album_photo_limit && ($photo_count >= $album_photo_limit)) {
            $this->messenger()->addWarning($this->t('Maximum number of photos reached for this album.'));
            break;
          }
          $ext = mb_substr($_FILES['files']['name']['images_' . $i], -3);
          if ($ext != 'zip' && $ext != 'ZIP') {
            // Prepare directory.
            $photosPath = $this->photosUpload->path($scheme);
            $photosName = $_FILES['files']['name']['images_' . $i];
            $file_uri = $this->fileSystem
              ->getDestinationFilename($photosPath . '/' . $photosName, FileSystemInterface::EXISTS_RENAME);
            if ($this->fileSystem->move($_FILES['files']['tmp_name']['images_' . $i], $file_uri)) {
              $path_parts = pathinfo($file_uri);
              $image = $this->imageFactory->get($file_uri);
              // @todo file_validate_is_image?
              if (isset($path_parts['extension']) && $path_parts['extension'] && $image->getWidth()) {
                // Create a file entity.
                /** @var \Drupal\file\FileInterface $file */
                $file = $this->entityTypeManager->getStorage('file')->create([
                  'uri' => $file_uri,
                  'uid' => $user->id(),
                  'status' => FILE_STATUS_PERMANENT,
                  'album_id' => $form_state->getValue('album_id'),
                  'nid' => $form_state->getValue('nid'),
                  'filename' => $photosName,
                  'filesize' => $image->getFileSize(),
                  'filemime' => $image->getMimeType(),
                  'title' => $form_state->getValue('title_' . $i),
                  'des' => $form_state->getValue('des_' . $i),
                ]);

                if ($file->save()) {
                  $this->photosUpload->saveImage($file);
                  $photo_count++;
                }
                $count++;
              }
              else {
                $this->fileSystem->delete($file_uri);
                $this->logger->notice('Wrong file type');
              }
            }
          }
          else {
            // Zip upload from manual upload form.
            if (!$config->get('photos_upzip')) {
              $this->messenger->addError($this->t('Please update settings to allow zip uploads.'));
            }
            else {
              $directory = $this->photosUpload->path();
              $this->fileSystem->prepareDirectory($directory);
              $zip = $this->fileSystem
                ->getDestinationFilename($directory . '/' . trim(basename($_FILES['files']['name']['images_' . $i])), FileSystemInterface::EXISTS_RENAME);
              if ($this->fileSystem->move($_FILES['files']['tmp_name']['images_' . $i], $zip)) {
                $params = [];
                $params['album_id'] = $album_id;
                $params['photo_count'] = $photo_count;
                $params['album_photo_limit'] = $album_photo_limit;
                $params['nid'] = $form_state->getValue('nid') ? $form_state->getValue('nid') : $form_state->getValue('album_id');
                $params['description'] = $form_state->getValue('des_' . $i);
                $params['title'] = $form_state->getValue('title_' . $i);
                if (!$file_count = $this->photosUpload->unzip($zip, $params, $scheme)) {
                  // Upload failed.
                }
                else {
                  $count = $count + $file_count;
                  $photo_count = $photo_count + $file_count;
                }
              }
            }
          }
        }
      }
    }
    // Handle media field.
    $selected_media = explode(',', $form_state->getValue('media_images'));
    foreach ($selected_media as $media_id) {
      if ($album_photo_limit && ($photo_count >= $album_photo_limit)) {
        $this->messenger()->addWarning($this->t('Maximum number of photos reached for this album.'));
        break;
      }
      // Save media to album.
      $mediaSaved = $this->photosUpload->saveExistingMedia($media_id, $nid);
      if ($mediaSaved) {
        $photo_count++;
        $count++;
      }
    }
    // Clear node and album page cache.
    Cache::invalidateTags(['node:' . $nid, 'photos:album:' . $nid]);
    $message = $this->formatPlural($count, '1 image uploaded.', '@count images uploaded.');
    $this->messenger->addMessage($message);
  }

}
