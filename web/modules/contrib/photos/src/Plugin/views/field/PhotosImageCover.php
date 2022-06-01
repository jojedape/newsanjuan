<?php

namespace Drupal\photos\Plugin\views\field;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\photos\Entity\PhotosImage;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to display album cover in views.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("photos_image_cover")
 */
class PhotosImageCover extends FieldPluginBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * EntityTypeManager class.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * Constructs a EntityLabel object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $connection, EntityTypeManagerInterface $entity_type_manager, EntityDisplayRepositoryInterface $entity_display_repository = NULL) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->connection = $connection;
    $this->entityTypeManager = $entity_type_manager;
    if (!$entity_display_repository) {
      $entity_display_repository = \Drupal::service('entity_display.repository');
    }
    $this->entityDisplayRepository = $entity_display_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository')
    );
  }

  /**
   * Define the available options.
   *
   * @return array
   *   Array of options.
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['link_photo'] = ['default' => ''];
    $options['view_mode'] = ['default' => ''];

    return $options;
  }

  /**
   * Provide the options form.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    // Link options.
    $form['link_photo'] = [
      '#title' => $this->t("Link image"),
      '#description' => $this->t("Link the image to the album page or image page."),
      '#type' => 'radios',
      '#options' => [
        '' => $this->t('None'),
        'album' => $this->t('Album page'),
        'image' => $this->t('Image page'),
      ],
      '#default_value' => $this->options['link_photo'],
    ];

    // Get image styles.
    $viewModeOptions = $this->entityDisplayRepository->getViewModeOptionsByBundle('photos_image', 'photos_image');
    $default = '';
    if (isset($viewModeOptions['cover'])) {
      $default = 'cover';
    }
    $form['view_mode'] = [
      '#title' => $this->t('View mode'),
      '#type' => 'select',
      '#default_value' => $this->options['view_mode'] ?: $default,
      '#options' => $viewModeOptions,
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $renderImage = [];
    $viewMode = $this->options['view_mode'];
    $picture_id = $this->getValue($values);
    $photosImage = FALSE;
    if ($picture_id) {
      /** @var \Drupal\photos\Entity\PhotosImage $photosImage */
      $photosImage = $this->entityTypeManager->getStorage('photos_image')->load($picture_id);
    }
    else {
      if ($values->_entity instanceof PhotosImage) {
        $photosImage = $values->_entity;
        $node = $this->entityTypeManager->getStorage('node')->load($values->_entity->getAlbumId());
      }
      else {
        $node = $values->_entity;
      }
      if ($node->bundle() == 'photos') {
        $nid = $node->id();
        if (!$picture_id) {
          // Get first image for cover photo.
          if ($nid) {
            $picture_id = $this->connection->query("SELECT id FROM {photos_image_field_data} WHERE album_id = :nid ORDER BY id ASC",
              [':nid' => $nid])->fetchField();
          }
        }
        if ($picture_id) {
          $photosImage = $this->entityTypeManager
            ->getStorage('photos_image')
            ->load($picture_id);
        }
      }
    }

    if ($photosImage && $viewMode) {
      $viewBuilder = $this->entityTypeManager->getViewBuilder('photos_image');
      $renderImage = $viewBuilder->view($photosImage, $viewMode);
      // Add the link if option is selected.
      if ($this->options['link_photo'] == 'image') {
        // Link to image page.
        $image = \Drupal::service('renderer')->render($renderImage);
        $renderImage = [
          '#type' => 'link',
          '#title' => $image,
          '#url' => Url::fromRoute('entity.photos_image.canonical', [
            'node' => $photosImage->getAlbumId(),
            'photos_image' => $photosImage->id(),
          ]),
          '#options' => [
            'attributes' => ['html' => TRUE],
          ],
          '#cache' => [
            'tags' => ['photos:image:' . $picture_id],
          ],
        ];
      }
      elseif ($this->options['link_photo'] == 'album') {
        // Get album id and link to album page.
        $node = $values->_entity;
        $nid = $node->id();
        $image = \Drupal::service('renderer')->render($renderImage);
        $renderImage = [
          '#type' => 'link',
          '#title' => $image,
          '#url' => $photosImage->getAlbumUrl(),
          '#options' => [
            'attributes' => ['html' => TRUE],
          ],
          '#cache' => [
            'tags' => [
              'photos:album:' . $nid,
              'photos:image:' . $picture_id,
            ],
          ],
        ];
      }
    }

    return $renderImage;
  }

}
