<?php

namespace Drupal\indieweb\MicrosubClient;

use Drupal\indieweb\Entity\WebmentionInterface;

interface MicrosubClientInterface {

  /**
   * Fetch new items.
   */
  public function fetchItems();

  /**
   * Send notification from a webmention.
   *
   * @param \Drupal\indieweb\Entity\WebmentionInterface $webmention
   * @param $parsed
   */
  public function sendNotification(WebmentionInterface $webmention, $parsed = NULL);

}