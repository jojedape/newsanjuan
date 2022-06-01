<?php

namespace Drupal\photos\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\Url;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to configure maintenance settings for this site.
 */
class PhotosAdminSettingsForm extends ConfigFormBase {

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The route builder.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routeBuilder;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'photos_admin_settings';
  }

  /**
   * Constructs PhotosAdminSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Routing\RouteBuilderInterface $route_builder
   *   The route builder.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityDisplayRepositoryInterface $entity_display_repository, EntityFieldManagerInterface $entity_field_manager, ModuleHandlerInterface $module_handler, RouteBuilderInterface $route_builder) {
    parent::__construct($config_factory);

    $this->entityDisplayRepository = $entity_display_repository;
    $this->entityFieldManager = $entity_field_manager;
    $this->moduleHandler = $module_handler;
    $this->routeBuilder = $route_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_display.repository'),
      $container->get('entity_field.manager'),
      $container->get('module_handler'),
      $container->get('router.builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    // Get variables for default values.
    $config = $this->config('photos.settings');

    // Load custom admin css and js library.
    $form['#attached']['library'] = [
      'photos/photos.admin',
    ];

    // Vertical tabs group.
    $form['settings'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Settings'),
    ];

    // Upload settings.
    $form['upload'] = [
      '#title' => $this->t('Upload'),
      '#type' => 'details',
      '#group' => 'settings',
    ];
    $form['upload']['upload_form_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Upload form'),
      '#default_value' => $config->get('upload_form_mode') ?: 0,
      '#description' => $this->t('The type of form that appears on
        /node/*/photos.'),
      '#options' => [
        $this->t('Classic'),
        $this->t('Entity form'),
      ],
    ];
    $form['upload']['classic'] = [
      '#title' => $this->t('Classic form settings'),
      '#type' => 'details',
      '#open' => $config->get('upload_form_mode') ? FALSE : TRUE,
    ];
    // @todo add option to disable multi-upload form.
    // Classic upload form settings.
    $num_options = [
      1 => 1,
      2 => 2,
      3 => 3,
      4 => 4,
      5 => 5,
      6 => 6,
      7 => 7,
      8 => 8,
      9 => 9,
      10 => 10,
    ];
    // @todo this feels dated. Add an unlimited option with add more button?
    $form['upload']['classic']['photos_num'] = [
      '#type' => 'select',
      '#title' => $this->t('Image upload fields'),
      '#default_value' => $config->get('photos_num'),
      '#options' => $num_options,
      '#description' => $this->t('The maximum number of upload fields on
        the classic upload form.'),
    ];

    // Plupload integration settings.
    $module_plupload_exists = $this->moduleHandler->moduleExists('plupload');
    if ($module_plupload_exists) {
      $form['upload']['classic']['photos_plupload_status'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use Plupoad for file uploads'),
        '#default_value' => $config->get('photos_plupload_status'),
      ];
    }
    else {
      $config->set('photos_plupload_status', 0)->save();
      $form['upload']['classic']['photos_plupload_status'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use Plupoad for file uploads'),
        '#disabled' => TRUE,
        '#description' => $this->t('To enable multiuploads and drag&amp;drop upload features, download and install the @link module', [
          '@link' => Link::fromTextAndUrl($this->t('Plupload integration'), Url::fromUri('http://drupal.org/project/plupload'))->toString(),
        ]),
      ];
    }
    // Multi upload form field selection.
    $fields = $this->entityFieldManager->getFieldDefinitions('photos_image', 'photos_image');
    $fieldOptions = [];
    foreach ($fields as $key => $fieldData) {
      $fieldType = $fieldData->getType();
      // Check image fields.
      if ($fieldType == 'image') {
        $fieldOptions[$key] = $this->t('Image: :fieldKey', [
          ':fieldKey' => $key,
        ]);
      }
      // Check media fields.
      if ($fieldType == 'entity_reference') {
        // Check if media field allows image.
        $fieldSettings = $fieldData->getSettings();
        if ($fieldSettings['handler'] == 'default:media'
          && isset($fieldSettings['handler_settings']['target_bundles'])
          && !empty($fieldSettings['handler_settings']['target_bundles'])) {
          // Check all media bundle fields for image.
          foreach ($fieldSettings['handler_settings']['target_bundles'] as $mediaBundle) {
            $mediaFields = $this->entityFieldManager->getFieldDefinitions('media', $mediaBundle);
            foreach ($mediaFields as $mediaFieldKey => $mediaFieldData) {
              $fieldType = $mediaFieldData->getType();
              // Check all image fields in media bundle.
              if ($fieldType == 'image' && $mediaFieldKey != 'thumbnail') {
                $fieldOptions[$key . ':' . $mediaFieldKey . ':' . $mediaBundle] = $this->t('Media: :fieldKey::mediaFieldKey::mediaBundle', [
                  ':fieldKey' => $key,
                  ':mediaFieldKey' => $mediaFieldKey,
                  ':mediaBundle' => $mediaBundle,
                ]);
              }
            }
          }
        }
      }
    }
    if (!empty($fieldOptions)) {
      $form['upload']['classic']['multi_upload_default_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Default multi-upload field'),
        '#description' => $this->t('The default value is field_image.'),
        '#options' => $fieldOptions,
        '#default_value' => $config->get('multi_upload_default_field'),
      ];
    }
    $form['upload']['classic']['photos_size_max'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum image resolution'),
      '#default_value' => $config->get('photos_size_max'),
      '#description' => $this->t('The maximum image resolution example:
        800x600. If an image toolkit is available the image will be scaled to
        fit within the desired maximum dimensions. Make sure this size is larger
        than any image styles used. Leave blank for no restrictions.'),
      '#size' => '40',
    ];
    $form['upload']['classic']['photos_upzip'] = [
      '#type' => 'radios',
      '#title' => $this->t('Allow zip upload'),
      '#default_value' => $config->get('photos_upzip') ?: 0,
      '#description' => $this->t('Users will be allowed to upload images
        compressed into a zip folder.'),
      '#options' => [
        $this->t('Disabled'),
        $this->t('Enabled'),
      ],
    ];
    $form['upload']['classic']['photos_clean_title'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Clean image titles'),
      '#description' => $this->t('This will remove the file extension and
        replace dashes and underscores with spaces when the filename is used for
        the image title.'),
      '#default_value' => $config->get('photos_clean_title'),
    ];

    // Privacy settings.
    $module_photos_access_exists = $this->moduleHandler->moduleExists('photos_access');
    $form['privacy'] = [
      '#title' => $this->t('Privacy'),
      '#type' => 'details',
      '#group' => 'settings',
      '#description' => $this->t('These settings are for password
        protected galleries, private galleries and other settings like adding
        individual users as collaborators.'),
    ];
    // Set warning if private file path is not set.
    if (!PrivateStream::basePath() && $config->get('photos_access_photos')) {
      $description_msg = $this->t('Warning: image files can still be accessed by
        visiting the direct URL. For better security, ask your website admin to
        setup a private file path.');
    }
    else {
      $description_msg = $this->t('The privacy settings appear on the photo
        album node edit page.');
    }
    $form['privacy']['photos_access_photos'] = [
      '#type' => 'radios',
      '#title' => $this->t('Privacy settings'),
      '#default_value' => $config->get('photos_access_photos') ?: 0,
      '#description' => $module_photos_access_exists ? $description_msg : $this->t('Enable the photos access module.'),
      '#options' => [$this->t('Disabled'), $this->t('Enabled')],
      '#required' => TRUE,
      '#disabled' => ($module_photos_access_exists ? FALSE : TRUE),
    ];

    // Album limit per role.
    $form['num'] = [
      '#title' => $this->t('Album limit'),
      '#type' => 'details',
      '#description' => $this->t('The number of albums a user is allowed to
        create. User 1 is not limited.'),
      '#tree' => TRUE,
      '#group' => 'settings',
    ];
    // @todo test if administrator is not limited?
    $roles = user_roles(TRUE);
    foreach ($roles as $key => $role) {
      $form['num']['photos_pnum_' . $key] = [
        '#type' => 'number',
        '#title' => $role->label(),
        '#required' => TRUE,
        '#default_value' => $config->get('photos_pnum_' . $key) ? $config->get('photos_pnum_' . $key) : 20,
        '#min' => 1,
        '#step' => 1,
        '#prefix' => '<div class="photos-admin-inline">',
        '#suffix' => '</div>',
        '#size' => 10,
      ];
    }
    $form['num']['album_photo_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum number of photos per album'),
      '#default_value' => $config->get('album_photo_limit'),
      '#min' => 0,
      '#step' => 1,
      '#size' => 10,
    ];

    // Count settings.
    $form['count'] = [
      '#title' => $this->t('Statistics'),
      '#type' => 'details',
      '#group' => 'settings',
    ];
    $form['count']['photos_image_count'] = [
      '#type' => 'radios',
      '#title' => $this->t('Count image views'),
      '#default_value' => $config->get('photos_image_count') ?: 0,
      '#description' => $this->t('Increment a counter each time image is viewed.'),
      '#options' => [$this->t('Enabled'), $this->t('Disabled')],
    ];
    $form['count']['photos_user_count_cron'] = [
      '#type' => 'radios',
      '#title' => $this->t('Image quantity statistics'),
      '#default_value' => $config->get('photos_user_count_cron') ?: 0,
      '#description' => $this->t('Users/Site images and albums quantity statistics.'),
      '#options' => [
        $this->t('Update count when cron runs (affect the count update).'),
        $this->t('Update count when image is uploaded (affect the upload speed).'),
      ],
    ];

    // Legacy view mode and other advanced settings.
    $legacyViewModeDefault = $config->get('photos_legacy_view_mode');
    $advancedDescription = $this->t('Warning: advanced settings can
      dramatically change the way all photos content appears on this site.
      Please test thoroughly before changing these settings on a live site.
      Site cache might need to be cleared after changing these settings.');
    $form['advanced'] = [
      '#type' => 'details',
      '#group' => 'settings',
      '#title' => $this->t('Advanced'),
      '#description' => $advancedDescription,
      '#open' => $legacyViewModeDefault,
    ];
    if ($this->moduleHandler->moduleExists('views')) {
      // @todo how do we only get views that are type photos?
      $displays = Views::getViewsAsOptions(FALSE, 'enabled', NULL, TRUE, TRUE);
      // @todo add template option instead of views?
      $overrideOptions = ['' => 'Default: photos_album:block_1'];
      $overrideOptions += $displays;
      $form['advanced']['node_field_album_photos_list_view'] = [
        '#title' => $this->t('Album photos image list view'),
        '#type' => 'select',
        '#options' => $overrideOptions,
        '#description' => $this->t('This view is embedded in the "Album photos" field that appears on the @manage_display_link content type.', [
          '@manage_display_link' => Link::fromTextAndUrl($this->t('photo album'), Url::fromRoute('entity.entity_view_display.node.default', [
            'node_type' => 'photos',
          ], [
            'attributes' => [
              'target' => '_blank',
            ],
          ]))->toString(),
        ]),
        '#default_value' => $config->get('node_field_album_photos_list_view') ?: 'photos_album:block_1',
      ];
      $overrideOptions = ['' => 'Photo album node'];
      $overrideOptions += $displays;
      $form['advanced']['album_link_override'] = [
        '#title' => $this->t('Override default album link'),
        '#type' => 'select',
        '#options' => $overrideOptions,
        '#description' => $this->t('The default album cover link. Currently only views with %node as a contextual argument are supported here.'),
        '#default_value' => $config->get('album_link_override') ?: '',
      ];
      $overrideOptions = ['' => ''];
      $overrideOptions += $displays;
      $form['advanced']['user_albums_link_override'] = [
        '#title' => $this->t('Override default user albums link'),
        '#type' => 'select',
        '#options' => $overrideOptions,
        '#description' => $this->t('The default user albums link found on the user profile page.'),
        '#default_value' => $config->get('user_albums_link_override') ?: '',
      ];
      $form['advanced']['user_images_link_override'] = [
        '#title' => $this->t('Override default user images link'),
        '#type' => 'select',
        '#options' => $overrideOptions,
        '#description' => $this->t('The default user images link found on the user profile page.'),
        '#default_value' => $config->get('user_images_link_override') ?: '',
      ];
    }
    // Legacy view mode.
    $form['advanced']['legacy'] = [
      '#type' => 'details',
      '#open' => $legacyViewModeDefault,
      '#title' => $this->t('Legacy settings'),
      '#description' => $this->t('Legacy view mode is to help
        preserve image and album layouts that were configured pre 6.0.x. Only
        use this setting if you need to. It is now recommended to use the custom
        display settings for view modes found in the "Manage display" tab.'),
    ];
    $form['advanced']['legacy']['settings'] = [
      '#markup' => '<p>' . Link::fromTextAndUrl($this->t('Go to legacy settings'), Url::fromRoute('photos.admin.legacy.config'))->toString() . '.</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // ...
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    // Set number of albums per role.
    $num = $form_state->getValue('num');
    foreach ($num as $rnum => $rcount) {
      $this->config('photos.settings')->set($rnum, $rcount);
    }

    // Check current album photos image list view.
    $currentImageListView = $this->config('photos.settings')->get('node_field_album_photos_list_view');
    $currentFormMode = $this->config('photos.settings')->get('upload_form_mode');

    $this->config('photos.settings')
      ->set('album_link_override', $form_state->getValue('album_link_override'))
      ->set('album_photo_limit', $form_state->getValue([
        'num',
        'album_photo_limit',
      ]))
      ->set('upload_form_mode', $form_state->getValue('upload_form_mode'))
      ->set('multi_upload_default_field', $form_state->getValue('multi_upload_default_field'))
      ->set('node_field_album_photos_list_view', $form_state->getValue('node_field_album_photos_list_view'))
      ->set('photos_image_count', $form_state->getValue('photos_image_count'))
      ->set('photos_access_photos', $form_state->getValue('photos_access_photos'))
      ->set('photos_num', $form_state->getValue('photos_num'))
      ->set('photos_plupload_status', $form_state->getValue('photos_plupload_status'))
      ->set('photos_size_max', $form_state->getValue('photos_size_max'))
      ->set('photos_clean_title', $form_state->getValue('photos_clean_title'))
      ->set('photos_upzip', $form_state->getValue('photos_upzip'))
      ->set('photos_user_count_cron', $form_state->getValue('photos_user_count_cron'))
      ->set('user_albums_link_override', $form_state->getValue('user_albums_link_override'))
      ->set('user_images_link_override', $form_state->getValue('user_images_link_override'))
      ->save();

    // Set warning if private file path is not set.
    if (!PrivateStream::basePath() && $form_state->getValue('photos_access_photos')) {
      $this->messenger()->addWarning($this->t('Warning: image files can
        still be accessed by visiting the direct URL. For better security, ask
        your website admin to setup a private file path.'));
    }

    if ($currentFormMode != $form_state->getValue('upload_form_mode')) {
      $this->routeBuilder->rebuild();
    }

    if ($currentImageListView != $form_state->getValue('node_field_album_photos_list_view')) {
      $this->messenger()->addMessage($this->t('Views node_list and photos_image_list cache cleared.'));
      // Clear views cache.
      Cache::invalidateTags(['node_list', 'photos_image_list']);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'photos.settings',
    ];
  }

}
