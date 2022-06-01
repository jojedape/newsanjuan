<?php

namespace Drupal\photos;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides an interface defining a photos image entity.
 */
interface PhotosImageInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, RevisionLogInterface, EntityPublishedInterface {

  /**
   * Denotes that the image is not published.
   */
  const NOT_PUBLISHED = 0;

  /**
   * Denotes that the image is published.
   */
  const PUBLISHED = 1;

  /**
   * Gets the image file ids.
   *
   * @return array
   *   The image file ids.
   */
  public function getFids();

  /**
   * Gets the image title.
   *
   * @return string
   *   Title of the image.
   */
  public function getTitle();

  /**
   * Sets the image title.
   *
   * @param string $title
   *   The image title.
   *
   * @return $this
   *   The called image entity.
   */
  public function setTitle($title);

  /**
   * Gets the image description.
   *
   * @return string
   *   The image description.
   */
  public function getDescription();

  /**
   * Sets the image description.
   *
   * @param string $description
   *   The image description.
   *
   * @return $this
   *   The called image entity.
   */
  public function setDescription($description);

  /**
   * Gets the text format name for the image description.
   *
   * @return string
   *   The text format name.
   */
  public function getFormat();

  /**
   * Sets the text format name for the image description.
   *
   * @param string $format
   *   The text format name.
   *
   * @return $this
   *   The called image entity.
   */
  public function setFormat($format);

  /**
   * Gets the image album id.
   *
   * @return int
   *   The image album id.
   */
  public function getAlbumId();

  /**
   * Sets the image album id.
   *
   * @param int $albumId
   *   The image album id.
   *
   * @return $this
   *   The called image entity.
   */
  public function setAlbumId($albumId);

  /**
   * Gets the album url.
   *
   * @return \Drupal\Core\Url
   *   The album url.
   */
  public function getAlbumUrl();

  /**
   * Gets the image weight.
   *
   * @return int
   *   Weight of the image for custom sort order.
   */
  public function getWeight();

  /**
   * Sets the image weight for custom sorting.
   *
   * @param int $weight
   *   The image weight.
   *
   * @return $this
   *   The called image entity.
   */
  public function setWeight($weight);

  /**
   * Gets the image creation timestamp.
   *
   * @return int
   *   Creation timestamp of the image.
   */
  public function getCreatedTime();

  /**
   * Sets the image creation timestamp.
   *
   * @param int $timestamp
   *   The image creation timestamp.
   *
   * @return $this
   *   The called image entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Gets the page for this image.
   *
   * @param int $id
   *   The pager id: album_id or uid.
   * @param string $type
   *   The type of pager: album_id or uid.
   *
   * @return array
   *   The photos image pager data or render array.
   */
  public function getPager($id, $type);

  /**
   * Gets the image revision creation timestamp.
   *
   * @return int
   *   The UNIX timestamp of when this revision was created.
   */
  public function getRevisionCreationTime();

  /**
   * Sets the image revision creation timestamp.
   *
   * @param int $timestamp
   *   The UNIX timestamp of when this revision was created.
   *
   * @return $this
   *   The called image entity.
   */
  public function setRevisionCreationTime($timestamp);

}
