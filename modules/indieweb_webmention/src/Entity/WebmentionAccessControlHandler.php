<?php

namespace Drupal\indieweb_webmention\Entity;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access controller for the Webmention entity.
 *
 * @see \Drupal\indieweb_webmention\Entity\WebmentionInterface.
 */
class WebmentionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\indieweb_webmention\Entity\WebmentionInterface $entity */
    switch ($operation) {
      case 'view':
        if (!$entity->isPublished()) {
          return AccessResult::allowedIfHasPermission($account, 'view unpublished webmention entities');
        }
        return AccessResult::allowedIfHasPermission($account, 'view published webmention entities');

      case 'update':
        return AccessResult::allowedIfHasPermission($account, 'edit webmention entities');

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'delete webmention entities');
    }

    // Unknown operation, no opinion.
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'add webmention entities');
  }

}
