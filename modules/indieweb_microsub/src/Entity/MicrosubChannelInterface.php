<?php

namespace Drupal\indieweb_microsub\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides an interface for defining IndieWeb Microsub channel entities.
 */
interface MicrosubChannelInterface extends ContentEntityInterface {

  /**
   * Returns the status.
   *
   * @return integer
   */
  public function getStatus();

  /**
   * Returns the channel weight.
   *
   * @return integer
   */
  public function getWeight();

  /**
   * Get the sources.
   *
   * @return \Drupal\indieweb_microsub\Entity\MicrosubSourceInterface[]
   */
  public function getSources();

  /**
   * Get the post types to exclude.
   *
   * @return array
   */
  public function getPostTypesToExclude();

  /**
   * Get the number of unread items.
   *
   * @return int $count
   */
  public function getUnreadCount();

  /**
   * Get the number of items.
   *
   * @return int $count
   */
  public function getItemCount();

}
