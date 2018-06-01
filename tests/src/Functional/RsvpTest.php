<?php

namespace Drupal\Tests\indieweb\Functional;

use Drupal\Core\Url;
use Drupal\indieweb_test\WebmentionClient\WebmentionClientTest;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests integration of RSVP.
 *
 * @group indieweb_single
 */
class RsvpTest extends IndiewebBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'page']);
    $this->grantPermissions(Role::load(RoleInterface::ANONYMOUS_ID), ['view published webmention entities']);
    $this->grantPermissions(Role::load(RoleInterface::AUTHENTICATED_ID), ['view published webmention entities']);
  }

  /**
   * Tests RSVP block and allow authenticated users to RSVP
   */
  public function testRsvpBlockAndAuthenticatedUsers() {

    $this->drupalLogin($this->adminUser);
    $this->enableWebmention();

    $this->placeBlock('indieweb_rsvp', ['region' => 'content', 'label' => 'RSVP title block', 'id' => 'rsvp']);
    $this->createPage();
    $this->createPage();
    $node = \Drupal::entityTypeManager()->getStorage('node')->load(1);
    $node_2 = \Drupal::entityTypeManager()->getStorage('node')->load(2);
    $this->drupalLogout();

    $this->drupalGet('node/1');
    $this->assertSession()->responseNotContains('RSVP title block');

    $webmention = $this->getWebmentionPayload($node, 'valid_secret');
    $webmention['post']['rsvp'] = 'yes';
    $webmention['post']['wm-property'] = 'rsvp';
    $webmention['post']['author'] = ['name' => 'swentel'];
    $this->sendWebmentionNotificationRequest($webmention);
    $webmention['post']['author'] = ['name' => 'Dries'];
    $this->sendWebmentionNotificationRequest($webmention);
    $webmention['post']['author'] = ['name' => 'swentie'];
    $webmention['post']['rsvp'] = 'maybe';
    $this->sendWebmentionNotificationRequest($webmention);

    $this->drupalGet('node/1');
    $this->assertSession()->responseContains('RSVP title block');
    $this->assertSession()->responseContains('<div class="item-list"><h3>Yes</h3><ul><li>swentel</li><li>Dries</li></ul></div>');
    $this->assertSession()->responseContains('<div class="item-list"><h3>Maybe</h3><ul><li>swentie</li></ul></div>');

    $this->drupalGet('node/2');
    $this->assertSession()->responseNotContains('RSVP title block');
  }

}
