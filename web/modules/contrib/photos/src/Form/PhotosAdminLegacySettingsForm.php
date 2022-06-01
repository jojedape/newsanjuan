<?php

namespace Drupal\photos\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\photos\PhotosAlbum;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to configure maintenance settings for this site.
 */
class PhotosAdminLegacySettingsForm extends ConfigFormBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

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
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler) {
    parent::__construct($config_factory);

    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler')
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

    // Legacy view mode and other advanced settings.
    $legacyViewModeDefault = $config->get('photos_legacy_view_mode');
    // Legacy view mode.
    $form['legacy'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Legacy settings'),
      '#description' => $this->t('Legacy view mode is to help
        preserve image and album layouts that were configured pre 6.0.x. Only
        use this setting if you need to. It is safe to disable if you have
        common site-wide settings for image sizes and display settings for all
        albums and images. It is now recommended to use the custom display
        settings for view modes found in the "Manage display" tab.'),
    ];
    $form['legacy']['photos_legacy_view_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable legacy view mode'),
      '#description' => $this->t('Changing this setting will clear the
        site cache when the form is saved.'),
      '#default_value' => $legacyViewModeDefault,
    ];

    // Thumb settings.
    if ($size = $config->get('photos_size')) {
      $num = (count($size) + 3);
      $sizes = [];
      foreach ($size as $sizeOption) {
        $sizes[] = [
          'style' => $sizeOption['style'],
          'label' => $sizeOption['name'],
        ];
      }
      $size = $sizes;
    }
    else {
      $num = 3;
      $size = [
        [
          'style' => 'medium',
          'label' => 'Medium',
        ],
        [
          'style' => 'large',
          'label' => 'Large',
        ],
        [
          'style' => 'thumbnail',
          'label' => 'Thumbnail',
        ],
      ];
    }
    $form['legacy']['photos_thumb_count'] = [
      '#type' => 'hidden',
      '#default_value' => $num,
    ];
    $form['legacy']['thumb'] = [
      '#title' => $this->t('Image sizes'),
      '#type' => 'details',
      '#description' => $this->t('Default image sizes. Note: if an image style is deleted after it has been in use for some
        time that may result in broken external image links.'),
    ];
    $thumb_options = image_style_options();
    if (empty($thumb_options)) {
      $image_style_link = Link::fromTextAndUrl($this->t('add image styles'), Url::fromRoute('entity.image_style.collection'))->toString();
      $form['legacy']['thumb']['image_style'] = [
        '#markup' => '<p>One or more image styles required: ' . $image_style_link . '.</p>',
      ];
    }
    else {
      $form['legacy']['thumb']['photos_pager_imagesize'] = [
        '#type' => 'select',
        '#title' => 'Pager size',
        '#default_value' => $config->get('photos_pager_imagesize'),
        '#description' => $this->t('Default pager block image style.'),
        '#options' => $thumb_options,
        '#required' => TRUE,
      ];
      $form['legacy']['thumb']['photos_cover_imagesize'] = [
        '#type' => 'select',
        '#title' => 'Cover size',
        '#default_value' => $config->get('photos_cover_imagesize'),
        '#description' => $this->t('Default album cover image style.'),
        '#options' => $thumb_options,
        '#required' => TRUE,
      ];
      $form['legacy']['thumb']['photos_name_0'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => isset($size[0]['label']) ? $size[0]['label'] : NULL,
        '#size' => '10',
        '#required' => TRUE,
        '#prefix' => '<div class="photos-admin-inline">',
      ];

      $form['legacy']['thumb']['photos_size_0'] = [
        '#type' => 'select',
        '#title' => 'Thumb size',
        '#default_value' => isset($size[0]['style']) ? $size[0]['style'] : NULL,
        '#options' => $thumb_options,
        '#required' => TRUE,
        '#suffix' => '</div>',
      ];
      $empty_option = ['' => ''];
      $thumb_options = $empty_option + $thumb_options;
      $form['legacy']['thumb']['additional_sizes'] = [
        '#markup' => '<p>Additional image sizes ' . Link::fromTextAndUrl($this->t('add more image styles'), Url::fromRoute('entity.image_style.collection'))->toString() . '.</p>',
      ];

      $additional_sizes = 0;
      for ($i = 1; $i < $num; $i++) {
        $form['legacy']['thumb']['photos_name_' . $i] = [
          '#type' => 'textfield',
          '#title' => $this->t('Name'),
          '#default_value' => isset($size[$i]['label']) ? $size[$i]['label'] : NULL,
          '#size' => '10',
          '#prefix' => '<div class="photos-admin-inline">',
        ];
        $form['legacy']['thumb']['photos_size_' . $i] = [
          '#type' => 'select',
          '#title' => $this->t('Size'),
          '#default_value' => isset($size[$i]['style']) ? $size[$i]['style'] : NULL,
          '#options' => $thumb_options,
          '#suffix' => '</div>',
        ];
        $additional_sizes = $i;
      }

      $form['legacy']['thumb']['photos_additional_sizes'] = [
        '#type' => 'hidden',
        '#value' => $additional_sizes,
      ];
    }
    // End thumb settings.
    // Display settings.
    $form['legacy']['display'] = [
      '#title' => $this->t('Display settings'),
      '#type' => 'details',
    ];

    $form['legacy']['display']['global'] = [
      '#type' => 'details',
      '#title' => $this->t('Global Settings'),
      '#description' => $this->t('Albums basic display settings'),
    ];
    $form['legacy']['display']['page'] = [
      '#type' => 'details',
      '#title' => $this->t('Page Settings'),
      '#description' => $this->t('Page (e.g: node/[nid]) display settings'),
      '#prefix' => '<div id="photos-form-page">',
      '#suffix' => '</div>',
    ];
    $form['legacy']['display']['teaser'] = [
      '#type' => 'details',
      '#title' => $this->t('Teaser Settings'),
      '#description' => $this->t('Teaser display settings'),
      '#prefix' => '<div id="photos-form-teaser">',
      '#suffix' => '</div>',
    ];
    $form['legacy']['display']['global']['photos_album_display_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Album display'),
      '#required' => TRUE,
      '#default_value' => $config->get('photos_album_display_type') ?: 'list',
      '#options' => [
        'list' => $this->t('List'),
        'grid' => $this->t('Grid'),
      ],
    ];
    $form['legacy']['display']['global']['photos_display_viewpager'] = [
      '#type' => 'number',
      '#default_value' => $config->get('photos_display_viewpager'),
      '#title' => $this->t('How many images show in each page?'),
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
    ];
    $form['legacy']['display']['global']['photos_album_column_count'] = [
      '#type' => 'number',
      '#default_value' => $config->get('photos_album_column_count') ?: 2,
      '#title' => $this->t('Number of columns'),
      '#description' => $this->t('When using album grid view.'),
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
    ];
    $form['legacy']['display']['global']['photos_display_imageorder'] = [
      '#type' => 'select',
      '#title' => $this->t('Image display order'),
      '#required' => TRUE,
      '#default_value' => $config->get('photos_display_imageorder'),
      '#options' => PhotosAlbum::orderLabels(),
    ];
    $list_imagesize = $config->get('photos_display_list_imagesize');
    $view_imagesize = $config->get('photos_display_view_imagesize');
    $sizes = $config->get('photos_size');
    $sizeOptions = [];
    foreach ($sizes as $size) {
      $sizeOptions[$size['style']] = $size['name'];
    }
    $form['legacy']['display']['global']['photos_display_list_imagesize'] = [
      '#type' => 'select',
      '#title' => $this->t('Image display size (list)'),
      '#required' => TRUE,
      '#default_value' => $list_imagesize,
      '#description' => $this->t('Displayed in the list (e.g: photos/[nid]) of image size.'),
      '#options' => $sizeOptions,
    ];
    $form['legacy']['display']['global']['photos_display_view_imagesize'] = [
      '#type' => 'select',
      '#title' => $this->t('Image display size (page)'),
      '#required' => TRUE,
      '#default_value' => $view_imagesize,
      '#description' => $this->t('Displayed in the page (e.g: photos/{node}/{photos_image}) of image size.'),
      '#options' => $sizeOptions,
    ];
    $form['legacy']['display']['global']['photos_display_user'] = [
      '#type' => 'radios',
      '#title' => $this->t('Allow users to modify this setting when they create a new album.'),
      '#default_value' => $config->get('photos_display_user') ?: 0,
      '#options' => [$this->t('Disabled'), $this->t('Enabled')],
    ];
    // Check if colorbox is enabled.
    $colorbox = FALSE;
    if ($this->moduleHandler->moduleExists('colorbox')) {
      $colorbox = TRUE;
    }
    if ($colorbox) {
      $form['legacy']['display']['global']['photos_display_colorbox_max_height'] = [
        '#type' => 'number',
        '#default_value' => $config->get('photos_display_colorbox_max_height') ?: 100,
        '#title' => $this->t('Colorbox gallery maxHeight percentage.'),
        '#required' => TRUE,
        '#min' => 1,
        '#step' => 1,
      ];
      $form['legacy']['display']['global']['photos_display_colorbox_max_width'] = [
        '#type' => 'number',
        '#default_value' => $config->get('photos_display_colorbox_max_width') ?: 50,
        '#title' => $this->t('Colorbox gallery maxWidth percentage.'),
        '#required' => TRUE,
        '#min' => 1,
        '#step' => 1,
      ];
    }
    $display_options = [
      $this->t('Do not display'),
      $this->t('Display cover'),
      $this->t('Display thumbnails'),
    ];
    if ($colorbox) {
      $display_options[3] = $this->t('Cover with colorbox gallery');
    }
    $form['legacy']['display']['page']['photos_display_page_display'] = [
      '#type' => 'radios',
      '#default_value' => $config->get('photos_display_page_display'),
      '#title' => $this->t('Display setting'),
      '#required' => TRUE,
      '#options' => $display_options,
    ];
    $form['legacy']['display']['page']['photos_display_full_viewnum'] = [
      '#type' => 'number',
      '#default_value' => $config->get('photos_display_full_viewnum'),
      '#title' => $this->t('Display quantity'),
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
      '#prefix' => '<div class="photos-form-count">',
    ];
    $form['legacy']['display']['page']['photos_display_full_imagesize'] = [
      '#type' => 'select',
      '#title' => $this->t('Image display size'),
      '#required' => TRUE,
      '#default_value' => $config->get('photos_display_full_imagesize'),
      '#options' => $sizeOptions,
      '#suffix' => '</div>',
    ];
    $form['legacy']['display']['page']['photos_display_page_user'] = [
      '#type' => 'radios',
      '#title' => $this->t('Allow users to modify this setting when they create a new album.'),
      '#default_value' => $config->get('photos_display_page_user') ?: 0,
      '#options' => [$this->t('Disabled'), $this->t('Enabled')],
    ];
    $form['legacy']['display']['teaser']['photos_display_teaser_display'] = [
      '#type' => 'radios',
      '#default_value' => $config->get('photos_display_teaser_display'),
      '#title' => $this->t('Display setting'),
      '#required' => TRUE,
      '#options' => $display_options,
    ];
    $form['legacy']['display']['teaser']['photos_display_teaser_viewnum'] = [
      '#type' => 'number',
      '#default_value' => $config->get('photos_display_teaser_viewnum'),
      '#title' => $this->t('Display quantity'),
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
      '#prefix' => '<div class="photos-form-count">',
    ];
    $form['legacy']['display']['teaser']['photos_display_teaser_imagesize'] = [
      '#type' => 'select',
      '#title' => $this->t('Image display size'),
      '#required' => TRUE,
      '#default_value' => $config->get('photos_display_teaser_imagesize'),
      '#options' => $sizeOptions,
      '#suffix' => '</div>',
    ];
    $form['legacy']['display']['teaser']['photos_display_teaser_user'] = [
      '#type' => 'radios',
      '#title' => $this->t('Allow users to modify this setting when they create a new album.'),
      '#default_value' => $config->get('photos_display_teaser_user') ?: 0,
      '#options' => [$this->t('Disabled'), $this->t('Enabled')],
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
    // Build $photos_size array.
    $size = [];
    for ($i = 0; $i < $form_state->getValue('photos_thumb_count'); $i++) {
      if ($form_state->getValue('photos_size_' . $i)) {
        $size[] = [
          'name' => $form_state->getValue('photos_name_' . $i),
          'style' => $form_state->getValue('photos_size_' . $i),
        ];
      }
    }
    $photos_size = $size;

    // Check current legacy setting and clear cache if changed.
    $currentLegacySetting = $this->config('photos.settings')->get('photos_legacy_view_mode');

    $this->config('photos.settings')
      ->set('photos_additional_sizes', $form_state->getValue('photos_additional_sizes'))
      ->set('photos_album_column_count', $form_state->getValue('photos_album_column_count'))
      ->set('photos_album_display_type', $form_state->getValue('photos_album_display_type'))
      ->set('photos_cover_imagesize', $form_state->getValue('photos_cover_imagesize'))
      ->set('photos_display_colorbox_max_height', $form_state->getValue('photos_display_colorbox_max_height'))
      ->set('photos_display_colorbox_max_width', $form_state->getValue('photos_display_colorbox_max_width'))
      ->set('photos_display_full_imagesize', $form_state->getValue('photos_display_full_imagesize'))
      ->set('photos_display_full_viewnum', $form_state->getValue('photos_display_full_viewnum'))
      ->set('photos_display_imageorder', $form_state->getValue('photos_display_imageorder'))
      ->set('photos_display_list_imagesize', $form_state->getValue('photos_display_list_imagesize'))
      ->set('photos_display_page_display', $form_state->getValue('photos_display_page_display'))
      ->set('photos_display_page_user', $form_state->getValue('photos_display_page_user'))
      ->set('photos_display_teaser_display', $form_state->getValue('photos_display_teaser_display'))
      ->set('photos_display_teaser_imagesize', $form_state->getValue('photos_display_teaser_imagesize'))
      ->set('photos_display_teaser_user', $form_state->getValue('photos_display_teaser_user'))
      ->set('photos_display_teaser_viewnum', $form_state->getValue('photos_display_teaser_viewnum'))
      ->set('photos_display_user', $form_state->getValue('photos_display_user'))
      ->set('photos_display_view_imagesize', $form_state->getValue('photos_display_view_imagesize'))
      ->set('photos_display_viewpager', $form_state->getValue('photos_display_viewpager'))
      ->set('photos_legacy_view_mode', $form_state->getValue('photos_legacy_view_mode'))
      ->set('photos_pager_imagesize', $form_state->getValue('photos_pager_imagesize'))
      ->set('photos_size', $photos_size)
      ->save();

    if ($currentLegacySetting != $form_state->getValue('photos_legacy_view_mode')) {
      $this->messenger()->addMessage($this->t('Cache cleared.'));
      drupal_flush_all_caches();
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
