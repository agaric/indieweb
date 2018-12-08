<?php

namespace Drupal\indieweb_test\WebmentionClient;

use Drupal\Core\Url;
use Drupal\indieweb_webmention\WebmentionClient\WebmentionClient;
use Drupal\indieweb_webmention\WebmentionClient\WebmentionClientInterface;

class WebmentionClientTest extends WebmentionClient implements WebmentionClientInterface {

  /**
   * {@inheritdoc}
   */
  public function sendWebmention($sourceURL, $targetURL) {
    $uri = Url::fromRoute('indieweb_test.webmention_endpoint', [], ['absolute' => TRUE])->toString();
    $httpClient = \Drupal::httpClient();
    try {
      $response = $httpClient->get($uri);
      if ($response->getHeader('Location')) {
        return $response;
      }
    }
    catch (\Exception $ignored) {}

    return FALSE;
  }

}
