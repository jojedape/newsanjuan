<?php

namespace Drupal\photos\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\photos\PhotosAlbum;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to upload photos to this site.
 */
class PhotosDirectoryImportForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   The file system service.
   */
  public function __construct(Connection $connection, EntityTypeManagerInterface $entity_manager, ModuleHandlerInterface $module_handler, FileSystem $file_system) {
    $this->connection = $connection;
    $this->entityTypeManager = $entity_manager;
    $this->moduleHandler = $module_handler;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'photos_import_directory';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $submit_text = $this->t('Move images');
    $show_submit = TRUE;
    // Add warning that images will be moved to the album's directory.
    $instructions = $this->t('Add photos to an album from a directory that is already on the server. First choose a user.
                     Then select an album. Then enter the directory where the photos are located. Note that the photos
                     will be moved to the selected albums directory. Warning: large zip files could fail depending on
                     server processing power. If it does fail, try unzipping the folders and running the batch again.');
    $form['instructions'] = [
      '#markup' => '<div>' . $instructions . '</div>',
    ];
    if ($uid = $form_state->getValue('user')) {
      // Look up user albums and generate options for select list.
      $albums = $this->connection->query("SELECT nid, title FROM {node_field_data} WHERE uid = :uid AND type = 'photos'", [':uid' => $uid]);
      $options = [];
      foreach ($albums as $album) {
        $options[$album->nid] = '[nid:' . $album->nid . '] ' . $album->title;
      }
      if (empty($options)) {
        // No albums found for selected user.
        $add_album_link = Link::fromTextAndUrl($this->t('Add new album.'), Url::fromUri('base:node/add/photos'))->toString();
        $form['add_album'] = [
          '#markup' => '<div>' . $this->t('No albums found.') . ' ' . $add_album_link . '</div>',
        ];
        $show_submit = FALSE;
      }
      else {
        // Select album.
        $form['uid'] = ['#type' => 'hidden', '#value' => $uid];
        $form['album'] = [
          '#type' => 'select',
          '#title' => $this->t('Select album'),
          '#options' => $options,
        ];
        // Directory.
        $form['directory'] = [
          '#title' => $this->t('Directory'),
          '#type' => 'textfield',
          '#required' => TRUE,
          '#default_value' => '',
          '#description' => $this->t('Directory containing images. Include / for absolute path. Include
            public:// or private:// to scan a directory in the public or private filesystem.'),
        ];
        // Copy.
        $form['copy'] = [
          '#title' => $this->t('Copy files instead of moving them.'),
          '#type' => 'checkbox',
          '#default_value' => 0,
        ];
      }
    }
    else {
      // User autocomplete.
      $form['user'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Username'),
        '#description' => $this->t('Enter a user name.'),
        '#target_type' => 'user',
        '#tags' => FALSE,
        '#required' => TRUE,
        '#default_value' => '',
        '#process_default_value' => FALSE,
      ];
      $submit_text = $this->t('Select user');
    }

    if ($show_submit) {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $submit_text,
        '#weight' => 10,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $directory = $form_state->getValue('directory');
    // Check if directory exists.
    if (!empty($directory) && !is_dir($directory)) {
      return $form_state->setErrorByName('directory', $this->t('Could not find directory. Please check the path.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('photos.settings');
    $user_value = $form_state->getValue('user');
    $copy = $form_state->getValue('copy');
    if ($user_value) {
      $form_state->setRebuild();
    }
    else {
      // @todo check if file is already in use before moving?
      // - If in use copy?
      $album = $form_state->getValue('album');
      $directory = $form_state->getValue('directory');
      if (file_exists($directory)) {
        $nid = $album;
        $album_uid = $form_state->getValue('uid');
        // If photos_access is enabled check viewid.
        $scheme = 'default';
        if ($this->moduleHandler->moduleExists('photos_access')) {
          $node = $this->entityTypeManager->getStorage('node')->load($nid);
          if (isset($node->photos_privacy) && isset($node->photos_privacy['viewid'])) {
            $album_viewid = $node->photos_privacy['viewid'];
            if ($album_viewid > 0) {
              // Check for private file path.
              // @todo add support for other schemes?
              if (PrivateStream::basePath()) {
                $scheme = 'private';
              }
              else {
                // Set warning message.
                \Drupal::messenger()->addWarning($this->t('Warning: image
                files can still be accessed by visiting the direct URL. For
                better security, ask your website admin to setup a private
                file path.'));
              }
            }
          }
        }
        $account = $this->entityTypeManager->getStorage('user')
          ->load($album_uid);
        // Check if zip is included.
        $allow_zip = $config->get('photos_upzip') ? '|zip|ZIP' : '';
        $file_extensions = 'png|PNG|jpg|JPG|jpeg|JPEG|gif|GIF' . $allow_zip;
        $files = $this->fileSystem->scanDirectory($directory, '/^.*\.(' . $file_extensions . ')$/');

        // Prepare batch.
        $batch_args = [
          $files,
          $account,
          $nid,
          $scheme,
          $copy,
        ];
        $batch = [
          'title' => $this->t('Moving images to gallery'),
          'operations' => [
            [
              '\Drupal\photos\Form\PhotosDirectoryImportForm::moveImageFiles',
              $batch_args,
            ],
          ],
          'finished' => '\Drupal\photos\Form\PhotosDirectoryImportForm::finishedMovingImageFiles',
        ];
        batch_set($batch);
      }
      else {
        \Drupal::messenger()->addError($this->t('Directory not found.'));
      }
    }
  }

  /**
   * Assist batch operation by moving or copying image files to album.
   *
   * @param array $files
   *   The files to be moved or copied.
   * @param \Drupal\user\Entity\User $account
   *   The selected user account.
   * @param int $nid
   *   The album node id.
   * @param string $scheme
   *   The file system scheme.
   * @param bool $copy
   *   If TRUE copy files, if FALSE move files.
   * @param array $context
   *   The batch context array.
   */
  public static function moveImageFiles(array $files, User $account, $nid, $scheme, $copy, array &$context) {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;
      $context['sandbox']['max'] = count($files);
      $context['results']['images_processed'] = 0;
      $context['results']['nid'] = $nid;
      $context['results']['uid'] = $account->id();
      $context['results']['copy'] = $copy;
    }
    $limit = 20;

    $process_files = array_slice($files, $context['sandbox']['current_id'], $limit);

    $count = 0;
    foreach ($process_files as $dir_file) {
      $ext = mb_substr($dir_file->uri, -3);
      if ($ext != 'zip' && $ext != 'ZIP') {
        // Prepare directory.
        $photos_path = \Drupal::service('photos.upload')->path($scheme);
        $photos_name = $dir_file->filename;
        $file_uri = \Drupal::service('file_system')
          ->getDestinationFilename($photos_path . '/' . $photos_name, FileSystemInterface::EXISTS_RENAME);
        // Display current file name.
        $context['message'] = t('Processing:') . ' ' . Html::escape($photos_name);
        if ($copy) {
          $file_processed = \Drupal::service('file_system')->copy($dir_file->uri, $file_uri);
        }
        else {
          $file_processed = \Drupal::service('file_system')->move($dir_file->uri, $file_uri);
        }
        if ($file_processed) {
          // Save file to album. Include title and description.
          /** @var \Drupal\Core\Image\Image $image */
          $image = \Drupal::service('image.factory')->get($file_uri);
          if ($image->getWidth()) {
            // Create a file entity.
            $file = File::create([
              'uri' => $file_uri,
              'uid' => $account->id(),
              'status' => FILE_STATUS_PERMANENT,
              'album_id' => $nid,
              'nid' => $nid,
              'filename' => $photos_name,
              'filesize' => $image->getFileSize(),
              'filemime' => $image->getMimeType(),
            ]);

            try {
              $file->save();
              \Drupal::service('photos.upload')->saveImage($file);
              $count++;
            }
            catch (EntityStorageException $e) {
              watchdog_exception('photos', $e);
            }
          }
        }
      }
      else {
        // Process zip file.
        if (!\Drupal::config('photos.settings')->get('photos_upzip')) {
          \Drupal::messenger()->addError(t('Please update settings to allow zip uploads.'));
        }
        else {
          $directory = \Drupal::service('photos.upload')->path();
          \Drupal::service('file_system')->prepareDirectory($directory);
          // Display current file name.
          $context['message'] = t('Processing:') . ' ' . Html::escape($dir_file->uri);
          $zip = \Drupal::service('file_system')
            ->getDestinationFilename($directory . '/' . trim(basename($dir_file->uri)), FileSystemInterface::EXISTS_RENAME);
          // @todo large zip files could fail here.
          if ($copy) {
            $file_processed = \Drupal::service('file_system')->copy($dir_file->uri, $zip);
          }
          else {
            $file_processed = \Drupal::service('file_system')->move($dir_file->uri, $zip);
          }
          if ($file_processed) {
            $params = [];
            $params['album_id'] = $nid;
            $params['nid'] = $nid;
            $params['des'] = '';
            $params['title'] = $dir_file->filename;
            if (!$file_count = \Drupal::service('photos.upload')->unzip($zip, $params, $scheme)) {
              // Upload failed.
            }
            else {
              $count = $count + $file_count;
            }
          }
        }
      }
      // Update progress.
      $context['sandbox']['progress']++;
      $context['sandbox']['current_id']++;
    }
    $context['results']['images_processed'] += $count;
    // Check if complete.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Finished batch operation moving image files.
   *
   * @param bool $success
   *   Indicates whether the batch process was successful.
   * @param array $results
   *   Results information passed from the processing callback.
   */
  public static function finishedMovingImageFiles($success, array $results) {
    // Clear node and album page cache.
    Cache::invalidateTags([
      'node:' . $results['nid'],
      'photos:album:' . $results['nid'],
    ]);
    // Update count.
    PhotosAlbum::setCount('user_image', $results['uid']);
    PhotosAlbum::setCount('node_album', $results['nid']);
    if ($success) {
      if ($results['copy']) {
        $message = \Drupal::translation()->formatPlural($results['images_processed'], 'One image copied to selected album.', '@count images copied to selected album.');
      }
      else {
        $message = \Drupal::translation()->formatPlural($results['images_processed'], 'One image moved to selected album.', '@count images moved to selected album.');
      }
    }
    else {
      $message = t('Finished with an error.');
    }
    \Drupal::messenger()->addMessage($message);
  }

}
