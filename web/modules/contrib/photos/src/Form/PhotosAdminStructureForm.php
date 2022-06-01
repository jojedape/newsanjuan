<?php

namespace Drupal\photos\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to configure maintenance settings for this site.
 */
class PhotosAdminStructureForm extends ConfigFormBase {

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

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
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityDisplayRepositoryInterface $entity_display_repository, ModuleHandlerInterface $module_handler) {
    parent::__construct($config_factory);

    $this->entityDisplayRepository = $entity_display_repository;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_display.repository'),
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

    // Display settings.
    $form['display'] = [
      '#title' => $this->t('Display'),
      '#type' => 'container',
    ];
    $form['display']['description'] = [
      '#markup' => $this->t('Default view modes. Add more custom view modes for Photo here: @display_modes_link and enable them here: @view_modes_link.', [
        '@display_modes_link' => Link::fromTextAndUrl($this->t('View modes'), Url::fromRoute('entity.entity_view_mode.collection'))->toString(),
        '@view_modes_link' => Link::fromTextAndUrl($this->t('photos custom display settings'), Url::fromRoute('entity.entity_view_display.photos_image.default'))->toString(),
      ]),
    ];
    $viewModeOptions = $this->entityDisplayRepository->getViewModeOptionsByBundle('photos_image', 'photos_image');
    $form['display']['view_mode_rearrange_album_page'] = [
      '#title' => $this->t('Rearrange albums page'),
      '#type' => 'select',
      '#options' => $viewModeOptions,
      '#default_value' => $config->get('view_mode_rearrange_album_page') ?: 'sort',
    ];
    $form['display']['view_mode_rearrange_image_page'] = [
      '#title' => $this->t('Rearrange images page'),
      '#type' => 'select',
      '#options' => $viewModeOptions,
      '#default_value' => $config->get('view_mode_rearrange_image_page') ?: 'sort',
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

    $this->config('photos.settings')
      ->set('view_mode_rearrange_album_page', $form_state->getValue('view_mode_rearrange_album_page'))
      ->set('view_mode_rearrange_image_page', $form_state->getValue('view_mode_rearrange_image_page'))
      ->save();
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
