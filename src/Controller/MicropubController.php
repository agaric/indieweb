<?php

namespace Drupal\indieweb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MicropubController extends ControllerBase {

  /** @var  \Drupal\Core\Config\Config */
  protected $config;

  /**
   * The action of the request.
   *
   * @var string
   */
  public $action = '';

  /**
   * The object type.
   */
  public $object_type = NULL;

  /**
   * The values used to create the node.
   *
   * @var array
   */
  public $values = [];

  /**
   * The request input.
   *
   * @var array
   */
  public $input = [];

  /**
   * The original payload
   *
   * @var null
   */
  public $payload_original = NULL;

  /**
   * The node which is being created.
   *
   * @var \Drupal\node\NodeInterface
   */
  public $node = NULL;

  /**
   * Routing callback: micropub endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function endpoint(Request $request) {
    $this->config = \Drupal::config('indieweb.micropub');
    $micropub_enabled = $this->config->get('micropub_enable');

    // Early response when endpoint is not enabled.
    if (!$micropub_enabled) {
      return new JsonResponse('', 404);
    }

    // Default response code and message.
    $response_code = 400;
    $response_message = 'Bad request';

    // q=syndicate-to request.
    if (isset($_GET['q']) && $_GET['q'] == 'syndicate-to') {

      // Get authorization header, response early if none found.
      $auth_header = $this->getAuthorizationHeader();
      if (!$auth_header) {
        return new JsonResponse('', 401);
      }

      if ($this->isValidToken($auth_header)) {
        $syndicate_channels = [];
        $response_code = 200;
        $channels = indieweb_get_publishing_channels();
        if (!empty($channels)) {
          foreach ($channels as $url => $name) {
            $syndicate_channels[] = [
              'uid' => $url,
              'name' => $name,
            ];
          }
        }

        $response_message = [
          'syndicate-to' => $syndicate_channels
        ];
      }
      else {
        $response_code = 403;
      }

      return new JsonResponse($response_message, $response_code);
    }

    // Indigenous sends POST vars along with multipart, we use all().
    if (strpos($request->headers->get('Content-Type'), 'multipart/form-data') !== FALSE) {
      $input = $request->request->all();
    }
    else {
      $input = $request->getContent();
    }
    // Determine action and input from request. This can either be POST or JSON
    // request. We use p3k/Micropub to handle that part.
    $micropub_request = \p3k\Micropub\Request::create($input);
    if ($micropub_request instanceof \p3k\Micropub\Request && $micropub_request->action) {
      $this->action = $micropub_request->action;
      $mf2 = $micropub_request->toMf2();
      $this->object_type = !empty($mf2['type'][0]) ? $mf2['type'][0] : '';
      $this->input = $mf2['properties'];
    }
    else {
      $description = $micropub_request->error_description ? $micropub_request->error_description : 'Unknown error';
      $this->getLogger('indieweb_micropub')->notice('Error parsing incoming request: @message', ['@message' => $description]);
      return new JsonResponse('Bad request', 400);
    }

    // Attempt to create a node.
    if (!empty($this->input) && $this->action == 'create') {

      // Get authorization header, response early if none found.
      $auth_header = $this->getAuthorizationHeader();
      if (!$auth_header) {
        return new JsonResponse('', 401);
      }

      // Validate token. Return early if it's not valid.
      $valid_token = $this->isValidToken($auth_header);
      if (!$valid_token) {
        return new JsonResponse('', 403);
      }

      // Store original input so it can be inspected by hooks.
      $this->payload_original = $this->input;

      // Log payload.
      if ($this->config->get('micropub_log_payload')) {
        $this->getLogger('indieweb_micropub_payload')->notice('input: @input', ['@input' => print_r($this->input, 1)]);
      }

      // The order here is of importance. Don't change it, unless there's a good
      // reason for, see https://indieweb.org/post-type-discovery. This does not
      // follow the exact rules, because we can be more flexible in Drupal.

      // Event support.
      if ($this->createNodeFromPostType('event') && $this->isHEvent() && $this->hasRequiredInput(['start', 'end', 'name'])) {
        $this->createNode($this->input['name'], 'event');

        // Date.
        $date_field_name = $this->config->get('event_date_field');
        if ($date_field_name && $this->node->hasField($date_field_name)) {
          $this->node->set($date_field_name, ['value' => gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, strtotime($this->input['start'][0])), 'end_value' => gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, strtotime($this->input['end'][0]))]);
        }

        $response = $this->saveNode();
        if ($response instanceof Response) {
          return $response;
        }
      }

      // RSVP support.
      if ($this->createNodeFromPostType('rsvp') && $this->isHEntry() && $this->hasRequiredInput(['in-reply-to', 'rsvp'])) {
        $this->createNode('RSVP on ' . $this->input['in-reply-to'][0], 'rsvp', 'in-reply-to');

        // RSVP field
        $rsvp_field_name = $this->config->get('rsvp_rsvp_field');
        if ($rsvp_field_name && $this->node->hasField($rsvp_field_name)) {
          $this->node->set($rsvp_field_name, $this->input['rsvp']);
        }

        $response = $this->saveNode();
        if ($response instanceof Response) {
          return $response;
        }
      }

      // Repost support.
      if ($this->createNodeFromPostType('repost') && $this->isHEntry() && $this->hasRequiredInput(['repost-of'])) {
        $this->createNode('Repost of ' . $this->input['repost-of'][0], 'repost', 'repost-of');
        $response = $this->saveNode();
        if ($response instanceof Response) {
          return $response;
        }
      }

      // Bookmark support.
      if ($this->createNodeFromPostType('bookmark') && $this->isHEntry() && $this->hasRequiredInput(['bookmark-of'])) {
        $this->createNode('Bookmark of ' . $this->input['bookmark-of'][0], 'bookmark', 'bookmark-of');
        $response = $this->saveNode();
        if ($response instanceof Response) {
          return $response;
        }
      }

      // Like support.
      if ($this->createNodeFromPostType('like') && $this->isHEntry() && $this->hasRequiredInput(['like-of'])) {
        $this->createNode('Like of ' . $this->input['like-of'][0], 'like', 'like-of');
        $response = $this->saveNode();
        if ($response instanceof Response) {
          return $response;
        }
      }

      // Reply support.
      if ($this->createNodeFromPostType('reply') && $this->isHEntry() && $this->hasRequiredInput(['in-reply-to', 'content']) && $this->hasNoKeysSet(['rsvp'])) {
        $this->createNode('In reply to ' . $this->input['in-reply-to'][0], 'reply', 'in-reply-to');
        $response = $this->saveNode();
        if ($response instanceof Response) {
          return $response;
        }
      }

      // Note post type.
      if ($this->createNodeFromPostType('note') && $this->isHEntry() && $this->hasRequiredInput(['content']) && $this->hasNoKeysSet(['name', 'in-reply-to', 'bookmark-of', 'repost-of', 'like-of'])) {
        $this->createNode('Micropub post', 'note');
        $response = $this->saveNode();
        if ($response instanceof Response) {
          return $response;
        }
      }

      // Article post type.
      if ($this->createNodeFromPostType('article') && $this->isHEntry() && $this->hasRequiredInput(['content', 'name']) && $this->hasNoKeysSet(['in-reply-to', 'bookmark-of', 'repost-of', 'like-of'])) {
        $this->createNode($this->input['name'], 'article');
        $response = $this->saveNode();
        if ($response instanceof Response) {
          return $response;
        }
      }

      // If we get end up here, it means that no node has been created.
      $location = \Drupal::moduleHandler()->invokeAll('indieweb_micropub_no_post_made', [$this->payload_original]);
      if (!empty($location[0])) {
        header('Location: ' . $location[0]);
        return new JsonResponse('', 201);
      }

    }

    return new JsonResponse($response_message, $response_code);
  }

  /**
   * Gets the Authorization header from the request.
   *
   * @return null|string|string[]
   */
  protected function getAuthorizationHeader() {
    $auth = NULL;
    $auth_header = \Drupal::request()->headers->get('Authorization');
    if ($auth_header && preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
      $auth = $auth_header;
    }

    return $auth;
  }

  /**
   * Returns whether the input type is a h-entry.
   *
   * @return bool
   */
  protected function isHEntry() {
    return isset($this->object_type) && $this->object_type == 'h-entry';
  }

  /**
   * Returns whether the input type is a h-event.
   *
   * @return bool
   */
  protected function isHEvent() {
    return isset($this->object_type) && $this->object_type == 'h-event';
  }

  /**
   * Check if there's a valid access token in the request.
   *
   * @param $auth_header
   *   The input.
   *
   * @return bool
   */
  protected function isValidToken($auth_header) {
    $valid_token = FALSE;

    try {
      $client = \Drupal::httpClient();
      $headers = [
        'Accept' => 'application/json',
        'Authorization' => $auth_header,
      ];

      $response = $client->get(\Drupal::config('indieweb.indieauth')->get('token_endpoint'), ['headers' => $headers]);
      $json = json_decode($response->getBody());
      if (isset($json->me) && $json->me == $this->config->get('micropub_me')) {
        $valid_token = TRUE;
      }
    }
    catch (\Exception $e) {
      $this->getLogger('indieweb_micropub')->notice('Error validating the access token: @message', ['@message' => $e->getMessage()]);
    }

    return $valid_token;
  }

  /**
   * Checks if required values are in input.
   *
   * @param array $keys
   *
   * @return bool
   */
  protected function hasRequiredInput($keys = []) {
    $has_required_values = TRUE;

    foreach ($keys as $key) {
      if (empty($this->input[$key])) {
        $has_required_values = FALSE;
        break;
      }
    }

    return $has_required_values;
  }

  /**
   * Check that none of the keys are set in input.
   *
   * @param $keys
   *
   * @return bool
   */
  protected function hasNoKeysSet($keys) {
    $has_no_keys_set = TRUE;

    foreach ($keys as $key) {
      if (isset($this->input[$key])) {
        $has_no_keys_set = FALSE;
        break;
      }
    }

    return $has_no_keys_set;
  }

  /**
   * Whether creating a node for this post type is enabled.
   *
   * @param $post_type
   *
   * @return array|mixed|null
   */
  protected function createNodeFromPostType($post_type) {
    return $this->config->get($post_type . '_create_node');
  }

  /**
   * Create a node.
   *
   * @param $title
   *   The title for the node.
   * @param $post_type
   *   The IndieWeb post type.
   * @param $link_input_name
   *   The name of the property in input for auto syndication.
   */
  protected function createNode($title, $post_type, $link_input_name = NULL) {

    $this->values = [
      'uid' => $this->config->get($post_type . '_uid'),
      'title' => $title,
      'type' => $this->config->get($post_type . '_node_type'),
      'status' => $this->config->get($post_type . '_status'),
    ];

    // Add link to syndicate to.
    if ($link_input_name && $this->config->get($post_type . '_auto_send_webmention')) {
      if (isset($input['mp-syndicate-to'])) {
        $this->input['mp-syndicate-to'] += $this->input[$link_input_name];
      }
      else {
        $this->input['mp-syndicate-to'] = $this->input[$link_input_name];
      }
    }

    // Allow code to change the values and payload.
    \Drupal::moduleHandler()->alter('indieweb_micropub_node_pre_create', $this->values, $this->input);

    $this->node = Node::create($this->values);

    // Content.
    $content_field_name = $this->config->get($post_type . '_content_field');
    if (!empty($this->input['content'][0]) && $this->node->hasField($content_field_name)) {
      $this->node->set($content_field_name, $this->input['content']);
    }

    // Link.
    $link_field_name = $this->config->get($post_type . '_link_field');
    if ($link_field_name && $this->node->hasField($link_field_name)) {
      $this->node->set($link_field_name, ['uri' => $this->input[$link_input_name][0], 'title' => '']);
    }

    // Uploads.
    $this->handleUpload($post_type . '_upload_field');

    // Categories.
    $this->handleCategories($post_type . '_tags_field');
  }

  /**
   * Saves the node.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function saveNode() {

    $this->node->save();
    if ($this->node->id()) {

      // Syndicate.
      $this->syndicateTo();

      // Allow modules to react after the node is saved.
      \Drupal::moduleHandler()->invokeAll('indieweb_micropub_node_saved', [$this->node, $this->values, $this->input, $this->payload_original]);

      header('Location: ' . $this->node->toUrl('canonical', ['absolute' => TRUE])->toString());
      return new Response('', 201);
    }

  }

  /**
   * Helper function to upload file(s).
   *
   * Currently limited to 1 file.
   *
   * @param $file_key
   *   The key in the $_FILES variable to look for in upload.
   *
   * @return bool|\Drupal\file\FileInterface
   */
  protected function saveUpload($file_key) {
    $file = FALSE;

    // Return early if there are no uploads.
    $files = \Drupal::request()->files->get($file_key);
    if (empty($files)) {
      return $file;
    }

    // Set files.
    \Drupal::request()->files->set('files', [$file_key => $files]);

    // Try to save the file.
    try {
      $file = file_save_upload($file_key, array(), "public://", 0);
      $messages = drupal_get_messages();
      if (!empty($messages)) {
        foreach ($messages as $message) {
          $this->getLogger('indieweb_micropub')->notice('Error saving file: @message', ['@message' => print_r($message, 1)]);
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('indieweb_micropub')->notice('Exception saving file: @message', ['@message' => $e->getMessage()]);
    }

    return $file;
  }

  /**
   * Handle uploads
   *
   * @param $upload_field
   */
  protected function handleUpload($upload_field) {
    // File (currently only image, limited to 1).
    $file_field_name = $this->config->get($upload_field);
    if ($file_field_name && $this->node->hasField($file_field_name)) {
      $file = $this->saveUpload('photo');
      if ($file) {
        $this->node->set($file_field_name, $file->id());
      }
    }
  }

  /**
   * Handle and set categories.
   *
   * @param $config_key
   *   The config key for the tags field.
   */
  protected function handleCategories($config_key) {
    $tags_field_name = $this->config->get($config_key);

    if ($tags_field_name && $this->node->hasField($tags_field_name) && !empty($this->input['category'])) {
      $values = $bundles = [];
      $auto_create = FALSE;

      // Check field definition settings of the field.
      $field_settings = $this->node->getFieldDefinition($tags_field_name)->getSettings();
      if (!empty($field_settings['handler_settings']['auto_create'])) {
        $auto_create = TRUE;
      }
      if (!empty($field_settings['handler_settings']['target_bundles'])) {
        $bundles = $field_settings['handler_settings']['target_bundles'];
      }

      // Only go further if we have bundles, should be as it's a required
      // property.
      if (!empty($bundles)) {

        // Take the first bundle, there should only be one, but you never know.
        $vocabulary = array_shift($bundles);

        // Get any ids that match with the incoming categories.
        $existing_categories = [];
        $existing_category_ids = \Drupal::entityQuery('taxonomy_term')
          ->condition('name', $this->input['category'], 'IN')
          ->execute();

        // Get the actual terms.
        if (!empty($existing_category_ids)) {
          $existing_categories_loaded = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($existing_category_ids);
          foreach ($existing_categories_loaded as $loaded_term) {
            $existing_categories[$loaded_term->label()] = $loaded_term;
          }
        }

        // Now loop over incoming categories.
        foreach ($this->input['category'] as $category) {

          // Auto create and not existing term.
          if ($auto_create && !isset($existing_categories[$category])) {
            $term = Term::create([
              'name' => $category,
              'vid' => $vocabulary,
              'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
            ]);
            $values[] = ['entity' => $term];
          }
          // Existing term.
          elseif (isset($existing_categories[$category])) {
            $values[] = ['entity' => $existing_categories[$category]];
          }
        }

        if (!empty($values)) {
          $this->node->set($tags_field_name, $values);
        }
      }
    }
  }

  /**
   * Syndicate to.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  protected function syndicateTo() {
    if (!empty($this->input['mp-syndicate-to']) && $this->node->isPublished()) {
      $source = $this->node->toUrl()->setAbsolute(TRUE)->toString();
      foreach ($this->input['mp-syndicate-to'] as $target) {
        indieweb_webmention_create_queue_item($source, $target, $this->node->id(), 'node');
      }
    }
  }

}
