<?php

namespace Drupal\indieweb_webmention\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebmentionController extends ControllerBase {

  /**
   * Routing callback: internal webmention endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\Response
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function webmentionInternal(Request $request) {
    $response_code = 400;

    $config = \Drupal::config('indieweb_webmention.settings');
    $webmention_internal = $config->get('webmention_internal');

    // Early return when the internal endpoint is not enabled.
    if (!$webmention_internal) {
      return new JsonResponse('', 404);
    }

    // We validate the request and store it as a webmention which we'll
    // handle later in either cron or drush.
    if ($request->getMethod() == 'POST' &&
      ($source = $request->request->get('source')) &&
      ($target = $request->request->get('target')) &&
      $source != $target) {

      // Save the entity, processing happens later.
      $values = [
        'source' => $source,
        'target' => $target,
        'type' => 'webmention',
        'property' => 'received',
        'status' => 0,
        'uid' => $config->get('webmention_uid'),
      ];
      $webmention = $this->entityTypeManager()->getStorage('indieweb_webmention')->create($values);
      $webmention->save();

      $response_code = 202;
    }

    return new Response("", $response_code);
  }

  /**
   * Routing callback: receive webmentions from an external service, most likely
   * being webmention.io.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function webmentionNotify() {
    $valid = FALSE;

    $config = \Drupal::config('indieweb_webmention.settings');
    $webmention_notify = $config->get('webmention_notify');

    // Early return when the endpoint is not enabled.
    if (!$webmention_notify) {
      return new JsonResponse('', 404);
    }

    // Default response code and message.
    $response_code = 400;
    $response_message = 'Bad request';

    // Check if there's any input from the webhook.
    $input = file('php://input');
    $input = is_array($input) ? array_shift($input) : '';
    $mention = json_decode($input, TRUE);

    $secret = $config->get('webmention_secret');
    if (!empty($mention['secret']) && $mention['secret'] == $secret) {
      $valid = TRUE;
    }

    // We have a valid mention.
    if (!empty($mention) && $valid) {

      // Debug.
      if ($config->get('webmention_log_payload')) {
        $this->getLogger('indieweb_webmention_payload')->notice('object: @object', ['@object' => print_r($mention, 1)]);
      }

      // Remove the base url and protect against empty target.
      $target = trim(str_replace(\Drupal::request()->getSchemeAndHttpHost(), '', $mention['target']));
      if (empty($target)) {
        $target = '/';
      }

      // Check identical webmentions. If the source, target and property are the
      // same, ignore it.
      if ($config->get('webmention_detect_identical')) {
        $source = $mention['source'];
        $property = $mention['post']['wm-property'];
        $exists = $this->entityTypeManager()->getStorage('indieweb_webmention')->checkIdenticalWebmention($source, $target, $property);
        if ($exists) {
          $this->getLogger('indieweb_webmention_identical')->notice('Source @source, target @target and @property already exists.', ['@source' => $source, '@target' => $target, '@property' => $property]);
          $response = ['result' => $response_message];
          return new JsonResponse($response, $response_code);
        }
      }

      $response_code = 202;
      $response_message = 'Webmention was successful';

      $values = [
        'uid' => $config->get('webmention_uid'),
        'target' => ['value' => $target],
        'source' => ['value' => $mention['source']],
        'type' => ['value' => $mention['post']['type']],
        'property' => ['value' => $mention['post']['wm-property']]
      ];

      // Set created to published or wm-received if available.
      if (!empty($mention['post']['published'])) {
        $values['created'] = strtotime($mention['post']['published']);
      }
      elseif (!empty($mention['post']['wm-received'])) {
        $values['created'] = strtotime($mention['post']['wm-received']);
      }

      // Author info.
      foreach (['name', 'photo', 'url'] as $key) {
        if (!empty($mention['post']['author'][$key])) {
          $author_value = trim($mention['post']['author'][$key]);
          if (!empty($author_value)) {
            $values['author_' . $key] = ['value' => $author_value];
          }
        }
      }

      // Url.
      if (!empty($mention['post']['url'])) {
        $values['url'] = ['value' => $mention['post']['url']];
      }

      // Content.
      foreach (['html', 'text'] as $key) {
        if (!empty($mention['post']['content'][$key])) {
          $values['content_' . $key] = ['value' => $mention['post']['content'][$key]];
        }
      }

      // Private or not.
      if (!empty($mention['post']['wm-private'])) {
        $values['private'] = ['value' => TRUE];
      }

      // Rsvp.
      if (!empty($mention['post']['rsvp'])) {
        $values['rsvp'] = ['value' => $mention['post']['rsvp']];
      }

      // Save the entity.
      try {
        /** @var \Drupal\indieweb_webmention\Entity\WebmentionInterface $webmention */
        $webmention = $this->entityTypeManager()->getStorage('indieweb_webmention')->create($values);
        $webmention->save();
      }
      catch (\Exception $ignored) {}

      // Trigger comment creation and microsub notification.
      if (isset($webmention)) {

        /** @var \Drupal\indieweb_webmention\WebmentionClient\WebmentionClientInterface $client */
        $client = \Drupal::service('indieweb.webmention.client');

        // Check syndication. If it exists, no need for further actions.
        if (!$client->sourceExistsAsSyndication($webmention)) {

          // Create a comment.
          $client->createComment($webmention);

          // Notification.
          /** @var \Drupal\indieweb_microsub\MicrosubClient\MicrosubClientInterface $microsub_client */
          if (\Drupal::hasService('indieweb.microsub.client')) {
            $microsub_client = \Drupal::service('indieweb.microsub.client');
            $microsub_client->sendNotification($webmention);
          }

        }

        // Clear cache.
        $this->clearCache($values['target']['value']);
      }

    }

    $response = ['result' => $response_message];
    return new JsonResponse($response, $response_code);
  }

  /**
   * Routing callback: internal pingback endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function pingbackInternal(Request $request) {
    $config = \Drupal::config('indieweb_webmention.settings');
    $pingback_internal = $config->get('pingback_internal');

    // Early return when the endpoint is not enabled.
    if (!$pingback_internal) {
      return new Response('', 404);
    }

    return $this->validatePingback($request, $config);
  }

  /**
   * Routing callback: receive pingbacks from an external service, most likely
   * being webmention.io.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function pingbackNotify(Request $request) {

    $config = \Drupal::config('indieweb_webmention.settings');
    $pingback_notify = $config->get('pingback_notify');

    // Early return when the endpoint is not enabled.
    if (!$pingback_notify) {
      return new Response('', 404);
    }

    return $this->validatePingback($request, $config);
  }

  /**
   * Validate pingback.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  protected function validatePingback(Request $request, $config) {
    // Default response code and message.
    $response_code = 400;
    $response_message = 'Bad request';

    if ($request->getMethod() == 'POST' &&
      ($source = $request->request->get('source')) &&
      ($target = $request->request->get('target')) &&
      $source != $target) {

      if ($this->validateSource($source, $target)) {

        $values = [
          'uid' => $config->get('webmention_uid'),
          'target' => ['value' => $target],
          'source' => ['value' => $source],
          'type' => ['value' => 'pingback'],
          'property' => ['value' => 'pingback']
        ];

        // Save the entity.
        try {
          /** @var \Drupal\indieweb_webmention\Entity\WebmentionInterface $webmention */
          $webmention = $this->entityTypeManager()->getStorage('indieweb_webmention')->create($values);
          $webmention->save();
        }
        catch (\Exception $ignored) {}

        $response_message = "Accepted";
        $response_code = 202;
      }
    }

    return new Response($response_message, $response_code);
  }

  /**
   * Validates that target is linked on source.
   *
   * @param $source
   * @param $target
   *
   * @return bool
   */
  protected function validateSource($source, $target) {
    $valid = FALSE;

    try {
      $client = \Drupal::httpClient();
      $response = $client->get($source);
      $content = $response->getBody();
      if ($content && strpos($content, $target) !== FALSE) {
        $valid = TRUE;
      }
    }
    catch (\Exception $e) {
      $this->getLogger('indieweb_webmention')->notice('Error validating pingback url: @message', ['@message' => $e->getMessage()]);
    }

    return $valid;
  }

  /**
   * Clears the cache for the target URL.
   *
   * @param $target
   */
  protected function clearCache($target) {

    $path = \Drupal::service('path.alias_manager')->getPathByAlias($target);
    try {
      $params = Url::fromUri("internal:" . $path)->getRouteParameters();
      if (!empty($params)) {
        $entity_type = key($params);

        $storage = $this->entityTypeManager()->getStorage($entity_type);
        if ($storage) {
          /** @var \Drupal\Core\Entity\EntityInterface $entity */
          $entity = $storage->load($params[$entity_type]);
          if ($entity) {
            $storage->resetCache([$entity->id()]);
            Cache::invalidateTags([$entity_type . ':' . $entity->id()]);
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('indieweb_webmention')->notice('Error clearing cache for @target: @message', ['@target' => $target, '@message' => $e->getMessage()]);
    }
  }

}
