<?php

namespace Drupal\Tests\photos\Functional;

use Drupal\photos\Entity\PhotosImage;
use Drupal\Tests\BrowserTestBase;

/**
 * Test photos_access album privacy settings.
 *
 * @group photos
 */
class PhotosAccessTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'field_ui',
    'node',
    'file',
    'image',
    'comment',
    'photos',
    'photos_access',
    'photos_views_test',
    'views',
    'views_ui',
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
   * The user account for testing role access.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $accountEditOwnPhotosRole;

  /**
   * The user account for testing access denied.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $accountViewPhotosOnly;

  /**
   * The album node.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $album;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Create admin user and adjust photos admin settings. This user will also
    // be the album owner.
    $this->account = $this->drupalCreateUser([
      'access administration pages',
      'access content',
      'administer display modes',
      'administer nodes',
      'administer site configuration',
      'administer views',
      'create photo',
      'create photos content',
      'delete own photo',
      'edit own photo',
      'edit own photos content',
      'view photo',
    ]);
    $this->drupalLogin($this->account);

    // Enable clean image titles and privacy settings.
    $edit = [
      'photos_access_photos' => 1,
      'photos_clean_title' => TRUE,
    ];
    // @todo more file upload and path tests.
    $this->drupalGet('/admin/config/media/photos');
    $this->submitForm($edit, 'Save configuration');

    // Edit views settings.
    $edit = [
      'access[type]' => 'photos_access',
    ];
    $this->drupalGet('/admin/structure/views/nojs/display/photos_test_view/page_1/access');
    $this->submitForm($edit, 'Apply');
    // Save photos_album view.
    $this->submitForm([], 'Save');

    // Rebuild permissions.
    node_access_rebuild();

    // Create user for access denied tests.
    $this->accountViewPhotosOnly = $this->drupalCreateUser([
      'access content',
      'view photo',
    ]);

    // Create user for role access test.
    $this->drupalCreateRole([
      'access content',
      'view photo',
      'edit own photo',
    ], 'role_access_test', '<em>role_access_test</em>');
    $this->accountEditOwnPhotosRole = $this->drupalCreateUser([]);
    $this->accountEditOwnPhotosRole->addRole('role_access_test');
    $this->accountEditOwnPhotosRole->save();

    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    // Create a locked photos node.
    $this->drupalGet('/node/add/photos');
    $this->assertSession()->statusCodeEquals(200);
    $edit = [
      'title[0][value]' => $this->randomMachineName(),
      'photos_privacy[viewid]' => 1,
    ];
    $this->submitForm($edit, 'Save');
    $storage->resetCache([1]);
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->album = $storage->load(1);
    $this->assertNotNull($this->album->photos_privacy);
    $this->assertEquals($this->album->photos_privacy['viewid'], 1, 'Album is set to locked.');

    // Get test image file.
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = \Drupal::service('file_system');
    $testImageFile = drupal_get_path('module', 'photos') . '/tests/images/photos-test-picture.jpg';
    // Add image to album.
    $edit = [
      'files[images_0]' => $fileSystem->realpath($testImageFile),
    ];
    $this->drupalGet('node/' . $this->album->id() . '/photos');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm($edit, 'Confirm upload');
  }

  /**
   * Test album privacy settings.
   */
  public function testAlbumPrivacySettings() {
    // Get album images.
    $photosImage = $this->container->get('entity_type.manager')->getStorage('photos_image')->load(1);

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($photosImage->field_image->target_id);

    // Check that owner does have access.
    $this->checkAlbumAccess($photosImage, 200, 200, $file->createFileUrl());

    // Switch to regular user.
    $this->drupalLogin($this->accountViewPhotosOnly);
    $this->checkAlbumAccess($photosImage, 403, 403, $file->createFileUrl());

    // Set album privacy settings to open.
    $edit = [
      'photos_privacy[viewid]' => 0,
    ];
    $this->updateAlbumPrivacySettings($edit);

    // File moved to public file system.
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($photosImage->field_image->target_id);

    // Switch to regular user.
    $this->drupalLogin($this->accountViewPhotosOnly);
    // Allowed to view. Not allowed to edit.
    $this->checkAlbumAccess($photosImage, 200, 403, $file->createFileUrl());

    // Test password required.
    $edit = [
      'photos_privacy[viewid]' => 3,
      'photos_privacy[pass]' => 'test',
    ];
    $this->updateAlbumPrivacySettings($edit);

    // File moved to private file system.
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($photosImage->field_image->target_id);

    // Switch to regular user.
    $this->drupalLogin($this->accountViewPhotosOnly);

    // Node page should redirect to password required page.
    $this->drupalGet('node/' . $photosImage->getAlbumId());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Please enter password');
    // Image page should redirect to password required page.
    $this->drupalGet('photos/' . $photosImage->getAlbumId() . '/' . $photosImage->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Please enter password');
    // Raw image path should redirect to password required page.
    $this->drupalGet($file->createFileUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Please enter password');
    // Album views page should redirect to password required page.
    $this->drupalGet('photos/views-test/' . $photosImage->getAlbumId());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Please enter password');
    // Test wrong password.
    $edit = [
      'pass' => 'wrong password',
    ];
    $this->submitForm($edit, 'Submit');
    $this->assertSession()->responseContains('Password required');
    // Test correct password.
    $edit = [
      'pass' => 'test',
    ];
    $this->submitForm($edit, 'Submit');
    // Check if album page is visible.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains($this->album->getTitle());
    // Node edit page should be access denied.
    $this->drupalGet('node/' . $photosImage->getAlbumId() . '/edit');
    $this->assertSession()->statusCodeEquals(403);

    // Test role access.
    $edit = [
      'photos_privacy[viewid]' => 4,
      'photos_privacy[roles][role_access_test]' => TRUE,
    ];
    $this->updateAlbumPrivacySettings($edit);
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($photosImage->field_image->target_id);

    // Switch to regular user.
    $this->drupalLogin($this->accountViewPhotosOnly);
    // Not allowed to view or edit.
    $this->checkAlbumAccess($photosImage, 403, 403, $file->createFileUrl());

    // Switch to user with test_role_access role.
    $this->drupalLogin($this->accountEditOwnPhotosRole);
    // Allowed to view and edit.
    $this->checkAlbumAccess($photosImage, 200, 200, $file->createFileUrl());

    // Test locked with collaborator.
    $edit = [
      'photos_privacy[viewid]' => 1,
      'photos_privacy[updateuser]' => $this->accountViewPhotosOnly->getAccountName() . ' (' . $this->accountViewPhotosOnly->id() . ')',
    ];
    $this->updateAlbumPrivacySettings($edit);
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($photosImage->field_image->target_id);

    // Switch to collaborator.
    $this->drupalLogin($this->accountViewPhotosOnly);
    // Allowed to view or edit.
    $this->checkAlbumAccess($photosImage, 200, 200, $file->createFileUrl());

    // Remove collaborator.
    $edit = [
      'photos_privacy[updateremove][' . $this->accountViewPhotosOnly->id() . ']' => TRUE,
    ];
    $this->updateAlbumPrivacySettings($edit);

    // Switch to collaborator that was removed.
    $this->drupalLogin($this->accountViewPhotosOnly);
    // Not allowed to view or edit.
    $this->checkAlbumAccess($photosImage, 403, 403, $file->createFileUrl());

    // Test password in database, then change to private with collaborator.
    $edit = [
      'photos_privacy[updateuser]' => $this->accountEditOwnPhotosRole->getAccountName() . ' (' . $this->accountEditOwnPhotosRole->id() . ')',
    ];
    $this->updateAlbumPrivacySettings($edit);
    // Switch to non collaborator user.
    $this->drupalLogin($this->accountViewPhotosOnly);
    // Not allowed to view or edit.
    $this->checkAlbumAccess($photosImage, 403, 403, $file->createFileUrl());
  }

  /**
   * Update photos node privacy settings and clear caches.
   *
   * @param array $edit
   *   Form edit parameters.
   */
  protected function updateAlbumPrivacySettings(array $edit = []) {
    // Switch back to album owner.
    $this->drupalLogin($this->account);
    $this->drupalGet('node/' . $this->album->id() . '/edit');
    if (isset($edit['photos_privacy[viewid]']) && $edit['photos_privacy[viewid]'] == 4) {
      // Check if role access option is enabled.
      $this->assertSession()->responseContains('Role access');
    }
    $this->submitForm($edit, 'Save');
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    // Must explicitly clear cache to see privacy settings?
    // @see https://www.drupal.org/project/drupal/issues/3040878
    $storage->resetCache([$this->album->id()]);
    // File may have moved. Reset file cache.
    $this->container->get('entity_type.manager')->getStorage('file')->resetCache();
    // Update album variable.
    $this->album = $storage->load($this->album->id());
    if (isset($edit['photos_privacy[viewid]'])) {
      $this->assertEquals($this->album->photos_privacy['viewid'], $edit['photos_privacy[viewid]'], 'Album privacy settings updated successfully.');
    }
  }

  /**
   * Test access to photo album, photos node, photos_image and raw image file.
   *
   * @param \Drupal\photos\Entity\PhotosImage $photosImage
   *   The photos_image entity.
   * @param int $viewCode
   *   The expected response code.
   * @param int $editCode
   *   The expected response code.
   * @param string $fileUrl
   *   The image file URL to test.
   */
  protected function checkAlbumAccess(PhotosImage $photosImage, $viewCode = 200, $editCode = 403, $fileUrl = NULL) {
    if ($fileUrl) {
      $this->drupalGet($fileUrl);
      $this->assertSession()->statusCodeEquals($viewCode);
    }
    // View image page.
    $this->drupalGet('photos/' . $photosImage->getAlbumId() . '/' . $photosImage->id());
    $this->assertSession()->statusCodeEquals($viewCode);
    // Views album page.
    $this->drupalGet('photos/views-test/' . $photosImage->getAlbumId());
    $this->assertSession()->statusCodeEquals($viewCode);
    // View node page.
    $this->drupalGet('node/' . $photosImage->getAlbumId());
    $this->assertSession()->statusCodeEquals($viewCode);
    // Edit node page.
    $this->drupalGet('node/' . $photosImage->getAlbumId() . '/edit');
    $this->assertSession()->statusCodeEquals($editCode);
  }

}
