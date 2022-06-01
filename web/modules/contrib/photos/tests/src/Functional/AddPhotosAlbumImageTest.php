<?php

namespace Drupal\Tests\photos\Functional;

use Drupal\node\Entity\Node;
use Drupal\photos\PhotosAlbum;
use Drupal\Tests\BrowserTestBase;

/**
 * Test creating a new album, adding an image and updating the image.
 *
 * @group photos
 */
class AddPhotosAlbumImageTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'field',
    'field_ui',
    'file',
    'image',
    'comment',
    'photos',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The user account for testing.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The admin user account for testing.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $adminAccount;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // Create user with permissions to edit own photos.
    $this->account = $this->drupalCreateUser([
      'view photo',
      'create photo',
      'edit own photo',
      'delete own photo',
    ]);
    $this->drupalLogin($this->account);

    // Create admin account that can update field settings.
    $this->adminAccount = $this->drupalCreateUser([
      'administer photos_image fields',
    ]);
  }

  /**
   * Test adding an image to an album and accessing the image edit page.
   */
  public function testAccessPhotosImageEditForm() {

    // Create a test album node.
    $albumTitle = $this->randomMachineName();
    $album = Node::create([
      'type' => 'photos',
      'title' => $albumTitle,
    ]);
    $album->save();

    // Get test image file.
    $testPhotoUri = drupal_get_path('module', 'photos') . '/tests/images/photos-test-picture.jpg';
    $fileSystem = \Drupal::service('file_system');

    // Post image upload form.
    $edit = [
      'files[images_0]' => $fileSystem->realpath($testPhotoUri),
      'title_0' => 'Test photo title',
      'des_0' => 'Test photos description',
    ];
    $this->drupalGet('node/' . $album->id() . '/photos');
    $this->submitForm($edit, 'Confirm upload');

    // Get album images.
    $photosAlbum = new PhotosAlbum($album->id());
    $albumImages = $photosAlbum->getImages(1);
    $photosImage = $albumImages[0]['photos_image'];
    $this->assertEquals($edit['title_0'], $photosImage->getTitle());

    // Access image edit page.
    $this->drupalGet('photos/' . $photosImage->getAlbumId() . '/' . $photosImage->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);

    // Post image edit form.
    $edit = [
      'title[0][value]' => 'Test new title',
    ];
    $this->submitForm($edit, 'Save');

    // Confirm that image title has been updated.
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('photos_image');
    // Must explicitly clear cache to see new title.
    // @see https://www.drupal.org/project/drupal/issues/3040878
    $storage->resetCache([$photosImage->id()]);
    $photosImage = $storage->load($photosImage->id());
    $this->assertEquals($edit['title[0][value]'], $photosImage->getTitle());

    // Test recent albums content overview.
    $this->drupalGet('photos');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains($albumTitle);

    // Check image file directory settings.
    $this->assertStringContainsString('photos/images', $photosImage->field_image->entity->getFileUri());
    // Change image field file directory settings.
    $this->drupalLogin($this->adminAccount);
    $edit = [
      'settings[file_directory]' => 'custom_directory/images',
    ];
    $this->drupalGet('admin/structure/photos/fields/photos_image.photos_image.field_image');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm($edit, 'Save settings');

    // Upload new image.
    $this->drupalLogin($this->account);
    $edit = [
      'files[images_0]' => $fileSystem->realpath($testPhotoUri),
      'title_0' => 'Test2 photo title',
      'des_0' => 'Test2 photos description',
    ];
    $this->drupalGet('node/' . $album->id() . '/photos');
    $this->submitForm($edit, 'Confirm upload');

    // Get album images.
    $photosAlbum = new PhotosAlbum($album->id());
    $albumImages = $photosAlbum->getImages(2);
    $photosImage = $albumImages[1]['photos_image'];
    $this->assertStringContainsString('custom_directory/images', $photosImage->field_image->entity->getFileUri());

  }

}
