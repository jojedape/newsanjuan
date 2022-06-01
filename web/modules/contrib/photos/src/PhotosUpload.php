<?php

namespace Drupal\photos;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\media\Entity\Media;
use Drupal\photos\Entity\PhotosImage;

/**
 * Functions to help with uploading images to albums.
 */
class PhotosUpload implements PhotosUploadInterface {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The Current User object.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

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
   * File usage interface to configurate an file object.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * The image factory.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

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
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The token replacement instance.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * Creates a new AliasCleaner.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   The file system service.
   * @param \Drupal\file\FileUsage\FileUsageInterface $file_usage
   *   File usage service.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   The image factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   * @param \Drupal\Core\Utility\Token $token
   *   The token replacement instance.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $connection, AccountInterface $current_user, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_manager, FileSystem $file_system, FileUsageInterface $file_usage, ImageFactory $image_factory, MessengerInterface $messenger, ModuleHandlerInterface $module_handler, StreamWrapperManagerInterface $stream_wrapper_manager, Token $token, TransliterationInterface $transliteration) {
    $this->configFactory = $config_factory;
    $this->connection = $connection;
    $this->currentUser = $current_user;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_manager;
    $this->imageFactory = $image_factory;
    $this->fileSystem = $file_system;
    $this->fileUsage = $file_usage;
    $this->messenger = $messenger;
    $this->moduleHandler = $module_handler;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->token = $token;
    $this->transliteration = $transliteration;
  }

  /**
   * {@inheritdoc}
   */
  public function cleanTitle($title = '') {
    $config = $this->configFactory->get('photos.settings');
    if ($config->get('photos_clean_title')) {
      // Remove extension.
      $title = pathinfo($title, PATHINFO_FILENAME);
      // Replace dash and underscore with spaces.
      $title = preg_replace("/[\-_]/", " ", $title);
      // Trim leading and trailing spaces.
      $title = trim($title);
    }
    return $title;
  }

  /**
   * {@inheritdoc}
   */
  public function path($schemaType = 'default') {
    $fileConfig = $this->configFactory->get('system.file');
    $path[] = 'photos';
    switch ($schemaType) {
      case 'private':
        $scheme = 'private';
        break;

      case 'public':
        $scheme = 'public';
        break;

      case 'default':
      default:
        $scheme = $fileConfig->get('default_scheme');
        break;
    }
    $dirs = [];
    // Prepare directory.
    foreach ($path as $folder) {
      $dirs[] = $folder;
      $finalPath = $scheme . '://' . implode('/', $dirs);
      if (!$this->fileSystem->prepareDirectory($finalPath, FileSystemInterface::CREATE_DIRECTORY)) {
        return FALSE;
      }
    }
    if ($finalPath) {
      // Make sure the path does not end with a forward slash.
      $finalPath = rtrim($finalPath, '/');
    }
    return $finalPath;
  }

  /**
   * {@inheritdoc}
   */
  public function saveImage(FileInterface $file) {
    $config = $this->configFactory->get('photos.settings');
    // @todo maybe pass file object and array of other vars.
    if ($file->id() && isset($file->album_id)) {
      $fid = $file->id();
      $albumId = $file->album_id;
      $album_photo_limit = $config->get('album_photo_limit');

      $photo_count = $this->connection->query("SELECT count FROM {photos_album} WHERE album_id = :album_id", [
        ':album_id' => $albumId,
      ])->fetchField();

      if ($album_photo_limit && ($photo_count >= $album_photo_limit)) {
        return FALSE;
      }

      // Prep image title.
      if (isset($file->title) && !empty($file->title)) {
        $title = $file->title;
      }
      else {
        // Cleanup filename and use as title.
        $title = $this->cleanTitle($file->getFilename());
      }

      // Create photos_image entity.
      /** @var \Drupal\Core\Image\Image $image */
      $image = $this->imageFactory->get($file->getFileUri());
      $defaultWeight = $this->connection->select('photos_image_field_data', 'i')
        ->fields('i', ['weight'])
        ->condition('i.album_id', $albumId)
        ->orderBy('i.weight', 'DESC')
        ->execute()->fetchField();
      if ($image->isValid()) {
        $newPhotosImageEntity = [
          'album_id' => $albumId,
          'title' => $title,
          'weight' => isset($file->weight) ? $file->weight : ($defaultWeight + 1),
          'description' => isset($file->des) ? $file->des : '',
        ];
        // Check if photos_image has default field_image.
        $uploadField = $config->get('multi_upload_default_field');
        $uploadFieldParts = explode(':', $uploadField);
        $field = isset($uploadFieldParts[0]) ? $uploadFieldParts[0] : 'field_image';
        $allBundleFields = $this->entityFieldManager->getFieldDefinitions('photos_image', 'photos_image');
        if (isset($allBundleFields[$field])) {
          $fieldType = $allBundleFields[$field]->getType();
          if ($fieldType == 'image') {
            $newPhotosImageEntity[$field] = [
              'target_id' => $fid,
              'alt' => $title,
              'title' => $title,
              'width' => $image->getWidth(),
              'height' => $image->getHeight(),
            ];
          }
          else {
            // Check media fields.
            if ($fieldType == 'entity_reference') {
              $mediaField = isset($uploadFieldParts[1]) ? $uploadFieldParts[1] : '';
              $mediaBundle = isset($uploadFieldParts[2]) ? $uploadFieldParts[2] : '';
              if ($mediaField && $mediaBundle) {
                // Create new media entity.
                $values = [
                  'bundle' => $mediaBundle,
                  'uid' => $this->currentUser->id(),
                ];
                $values[$mediaField] = [
                  'target_id' => $file->id(),
                ];
                $media = Media::create($values);
                // @todo media name?
                $media->setName('Photo ' . $file->id())->setPublished()->save();
                // Set photos_image media reference field.
                $newPhotosImageEntity[$field] = [
                  'target_id' => $media->id(),
                ];
              }
            }
          }
        }
        $photosImage = PhotosImage::create($newPhotosImageEntity);
        try {
          $photosImage->save();
          if ($photosImage && $photosImage->id()) {
            if (isset($fieldType) && $fieldType == 'image') {
              // Move image to correct directory.
              $fieldThirdPartySettings = $allBundleFields[$field]->getThirdPartySettings('filefield_paths');
              if (!empty($fieldThirdPartySettings) && $fieldThirdPartySettings['enabled']) {
                // Get path from filefield_paths.
                $tokenData = [
                  'file' => $file,
                  $photosImage->getEntityTypeId() => $photosImage,
                ];
                $name = $file->getFilename();
                if (!empty($fieldThirdPartySettings['file_name']['value'])) {
                  $name = filefield_paths_process_string($fieldThirdPartySettings['file_name']['value'], $tokenData, $fieldThirdPartySettings['file_name']['options']);
                }
                // Process filepath.
                $path = filefield_paths_process_string($fieldThirdPartySettings['file_path']['value'], $tokenData, $fieldThirdPartySettings['file_path']['options']);
                $fileUri = $this->streamWrapperManager
                  ->normalizeUri($this->streamWrapperManager->getScheme($file->getFileUri()) . '://' . $path . DIRECTORY_SEPARATOR . $name);
              }
              else {
                // Get path from field settings.
                $fieldSettings = $allBundleFields[$field]->getSettings();
                $uploadLocation = $fieldSettings['file_directory'];
                $uploadLocation = PlainTextOutput::renderFromHtml($this->token->replace($uploadLocation, []));
                $uploadLocation = $this->streamWrapperManager->getScheme($file->getFileUri()) . '://' . $uploadLocation;
                $this->fileSystem->prepareDirectory($uploadLocation, FileSystemInterface::CREATE_DIRECTORY);
                $fileUri = "{$uploadLocation}/{$file->getFilename()}";
                $fileUri = $this->fileSystem->getDestinationFilename($fileUri, FileSystemInterface::EXISTS_RENAME);
                // Move the file.
                $this->fileSystem->move($file->getFileUri(), $fileUri, FileSystemInterface::EXISTS_ERROR);
              }
              // Set the correct URI and save the file.
              $file->setFileUri($fileUri);
              $file->save();
            }
            if ($config->get('photos_user_count_cron')) {
              $user = $this->currentUser;
              PhotosAlbum::setCount('user_image', ($photosImage->getOwnerId() ? $photosImage->getOwnerId() : $user->id()));
              PhotosAlbum::setCount('node_album', $albumId);
            }
            // Save file and add file usage.
            $this->fileUsage->add($file, 'photos', 'node', $albumId);
            // Check admin setting for maximum image resolution.
            if ($photos_size_max = $config->get('photos_size_max')) {
              // Will scale image if needed.
              file_validate_image_resolution($file, $photos_size_max);
              // Get new height and width for field values.
              if (isset($fieldType)) {
                $image = $this->imageFactory->get($file->getFileUri());
                if ($fieldType == 'image') {
                  $image_files = $photosImage->get($field)->getValue();
                  $image_files[0]['height'] = $image->getHeight();
                  $image_files[0]['width'] = $image->getWidth();
                  // Save new height and width.
                  $photosImage->set($field, $image_files);
                  $photosImage->save();
                }
                else {
                  if (isset($media) && isset($mediaField)) {
                    $image_files = $media->get($mediaField)->getValue();
                    $image_files[0]['height'] = $image->getHeight();
                    $image_files[0]['width'] = $image->getWidth();
                    // Save new height and width.
                    $media->set($mediaField, $image_files);
                    $media->save();
                  }
                }
              }
            }
            return TRUE;
          }
        }
        catch (EntityStorageException $e) {
          watchdog_exception('photos', $e);
        }
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function saveExistingMedia($mediaId, $albumId) {
    $config = $this->configFactory->get('photos.settings');
    /** @var \Drupal\media\MediaInterface $mediaItem */
    $mediaItem = NULL;
    try {
      $mediaItem = $this->entityTypeManager->getStorage('media')
        ->load($mediaId);
    }
    catch (InvalidPluginDefinitionException $e) {
    }
    catch (PluginNotFoundException $e) {
    }
    if ($mediaItem) {
      $defaultWeight = $this->connection->select('photos_image_field_data', 'i')
        ->fields('i', ['weight'])
        ->condition('i.album_id', $albumId)
        ->orderBy('i.weight', 'DESC')
        ->execute()->fetchField();
      $newPhotosImageEntity = [
        'album_id' => $albumId,
        'title' => $mediaItem->getName(),
        'weight' => ($defaultWeight + 1),
      ];
      // Check default media field.
      $uploadField = $config->get('multi_upload_default_field');
      $uploadFieldParts = explode(':', $uploadField);
      $field = isset($uploadFieldParts[0]) ? $uploadFieldParts[0] : 'field_image';
      $allBundleFields = $this->entityFieldManager->getFieldDefinitions('photos_image', 'photos_image');
      if (isset($allBundleFields[$field])) {
        $fieldType = $allBundleFields[$field]->getType();
        if ($fieldType == 'entity_reference') {
          $mediaField = isset($uploadFieldParts[1]) ? $uploadFieldParts[1] : '';
          $mediaBundle = isset($uploadFieldParts[2]) ? $uploadFieldParts[2] : '';
          if ($mediaField && $mediaBundle) {
            // Set photos_image media reference field.
            $newPhotosImageEntity[$field] = [
              'target_id' => $mediaId,
            ];
          }
          // Save PhotosImageFile entity.
          $photosImage = PhotosImage::create($newPhotosImageEntity);
          try {
            $photosImage->save();
            if ($photosImage && $photosImage->id()) {
              if ($config->get('photos_user_count_cron')) {
                $user = $this->currentUser;
                PhotosAlbum::setCount('user_image', ($photosImage->getOwnerId() ? $photosImage->getOwnerId() : $user->id()));
                PhotosAlbum::setCount('node_album', $albumId);
              }
              return TRUE;
            }
          }
          catch (EntityStorageException $e) {
            watchdog_exception('photos', $e);
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function unzip($source, array $params, $scheme = 'default') {
    $fileConfig = $this->configFactory->get('system.file');
    $file_count = 0;
    $photo_count = 0;
    if (isset($params['photo_count'])) {
      $photo_count = $params['photo_count'];
    }
    $album_photo_limit = NULL;
    if (isset($params['album_photo_limit'])) {
      $album_photo_limit = $params['album_photo_limit'];
    }
    if (version_compare(PHP_VERSION, '5') >= 0) {
      if (!is_file($source)) {
        $this->messenger->addMessage($this->t('Compressed file does not exist, please check the path: @src', [
          '@src' => $source,
        ]));
        return 0;
      }
      $fileType = ['jpg', 'gif', 'png', 'jpeg', 'JPG', 'GIF', 'PNG', 'JPEG'];
      $zip = new \ZipArchive();
      // Get relative path.
      $default_scheme = $fileConfig->get('default_scheme');
      $relative_path = $this->fileSystem->realpath($default_scheme . "://") . '/';
      $source = str_replace($default_scheme . '://', $relative_path, $source);
      // Open zip archive.
      if ($zip->open($source) === TRUE) {
        for ($x = 0; $x < $zip->numFiles; ++$x) {
          $image = $zip->statIndex($x);
          $filename_parts = explode('.', $image['name']);
          $ext = end($filename_parts);
          if (in_array($ext, $fileType)) {
            if ($album_photo_limit && ($photo_count >= $album_photo_limit)) {
              $this->messenger->addWarning($this->t('Maximum number of photos reached for this album.'));
              break;
            }
            $path = $this->fileSystem->createFilename($image['name'], $this->path($scheme));
            if ($temp_file = file_save_data($zip->getFromIndex($x), $path)) {
              // Update file values.
              $temp_file->album_id = $params['album_id'];
              $temp_file->nid = $params['nid'];
              // Use image file name as title.
              $temp_file->title = $image['name'];
              $temp_file->des = $params['des'];
              // Prepare file entity.
              $file = $temp_file;
              try {
                // Save image.
                $file->save();
                if ($this->saveImage($file)) {
                  $file_count++;
                }
              }
              catch (EntityStorageException $e) {
                watchdog_exception('photos', $e);
              }
            }
          }
        }
        $zip->close();
        // Delete zip file.
        $this->fileSystem->delete($source);
      }
      else {
        $this->messenger->addWarning($this->t('Compressed file does not exist, please try again: @src', [
          '@src' => $source,
        ]));
      }
    }

    return $file_count;
  }

}
