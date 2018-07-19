<?php

namespace Drupal\indieweb\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class MicropubSettingsForm extends ConfigFormBase {

  /**
   * Returns supported post types.
   *
   * @return array
   */
  protected function getPostTypes() {
    $post_types = [
      'article' => [
        'description' => $this->t("An article request contains 'content', 'name' and the 'h' value is 'entry'. Think of it as a blog post. The article can also contain a 'mp-syndicate-to' value which will contain the channel you want to publish to, see the <a href=':link_send'>Send webmention screen</a> to configure this.", [':link_send' => Url::fromRoute('indieweb.admin.publish_settings')->toString(),])
      ],
      'note' => [
        'description' => $this->t("A note request contains 'content', but no 'name' and the 'h' value is 'entry'. Think of it as a Tweet. The note can also contain a 'mp-syndicate-to' value which will contain the channel you want to publish to, see the <a href=':link_send'>Send webmention screen</a> to configure this.", [':link_send' => Url::fromRoute('indieweb.admin.publish_settings')->toString(),])
      ],
      'like' => [
        'description' => $this->t("A like request contains a URL in 'like-of' and 'h' value is 'entry'. The like can also contain a 'mp-syndicate-to' value which will contain the channel you want to publish to, see the <a href=':link_send'>Send webmention screen</a> to configure this."),
        'optional_body' => TRUE,
        'link_field' => TRUE,
        'send_webmention' => TRUE,
      ],
      'reply' => [
        'description' => $this->t("A reply request contains a URL in 'in-reply-to', has content and 'h' value is 'entry'. The reply can also contain a 'mp-syndicate-to' value which will contain the channel you want to publish to, see the <a href=':link_send'>Send webmention screen</a> to configure this."),
        'link_field' => TRUE,
        'send_webmention' => TRUE,
      ],
      'repost' => [
        'description' => $this->t("A repost request contains a URL in 'repost-of' and 'h' value is 'entry'. The repost can also contain a 'mp-syndicate-to' value which will contain the channel you want to publish to, see the <a href=':link_send'>Send webmention screen</a> to configure this."),
        'optional_body' => TRUE,
        'link_field' => TRUE,
        'send_webmention' => TRUE,
      ],
      'bookmark' => [
        'description' => $this->t("A bookmark request contains a URL in 'bookmark-of' and 'h' value is 'entry'. The bookmark can also contain a 'mp-syndicate-to' value which will contain the channel you want to publish to, see the <a href=':link_send'>Send webmention screen</a> to configure this."),
        'optional_body' => TRUE,
        'link_field' => TRUE,
        'send_webmention' => TRUE,
      ],
      'event' => [
        'description' => $this->t("An event request contains a start and end date and the 'h' value is 'event'. The event can also contain a 'mp-syndicate-to' value which will contain the channel you want to publish to, see the <a href=':link_send'>Send webmention screen</a> to configure this."),
        'date_field' => TRUE,
        'optional_body' => TRUE,
      ],
      'rsvp' => [
        'description' => $this->t("An rsvp request contains an rsvp field. The rsvp can also contain a 'mp-syndicate-to' value which will contain the channel you want to publish to, see the <a href=':link_send'>Send webmention screen</a> to configure this."),
        'rsvp_field' => TRUE,
        'optional_body' => TRUE,
        'link_field' => TRUE,
        'send_webmention' => TRUE,
      ],
    ];

    return $post_types;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['indieweb.micropub'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'indieweb_micropub_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['#attached']['library'][] = 'indieweb/admin';

    $config = $this->config('indieweb.micropub');

    $form['info'] = [
      '#markup' => '<p>' . $this->t("Allow posting to your site. Before you can post, you need to authenticate and enable the IndieAuth Authentication API.<br />See <a href=':link_indieauth'>IndieAuth</a> to configure. More information about micropub: see <a href='https://indieweb.org/Micropub' target='_blank'>https://indieweb.org/Micropub</a>.",
          [
            ':link_indieauth' => Url::fromRoute('indieweb.admin.indieauth_settings')->toString(),
          ]) .
        '</p><p>' . $this->t("A very good client to test is <a href='https://quill.p3k.io' target='_blank'>https://quill.p3k.io</a>. A full list is available at <a href='https://indieweb.org/Micropub/Clients'>https://indieweb.org/Micropub/Clients</a>.<br />Indigenous (iOS and Android) are also microsub readers.") . '</p><p>Even if you do not decide to use the micropub endpoint, this screen gives you a good overview what kind of content types and fields you can create which can be used for sending webmentions or read by microformat parsers.</p>',
    ];

    $form['micropub'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Micropub'),
    ];

    $form['micropub']['micropub_enable'] = [
      '#title' => $this->t('Enable micropub'),
      '#type' => 'checkbox',
      '#default_value' => $config->get('micropub_enable'),
      '#description' => $this->t('This will allow the endpoint to receive requests.')
    ];

    $form['micropub']['micropub_add_header_link'] = [
      '#title' => $this->t('Add micropub endpoint to header'),
      '#type' => 'checkbox',
      '#default_value' => $config->get('micropub_add_header_link'),
      '#description' => $this->t('The endpoint will look like <strong>https://@domain/indieweb/micropub</strong><br />This link will be added on the front page. You can also add this manually to html.html.twig.<br /><div class="indieweb-highlight-code">&lt;link rel="micropub" href="https://@domain/indieweb/micropub" /&gt;</div>', ['@domain' => \Drupal::request()->getHttpHost()]),
      '#states' => array(
        'visible' => array(
          ':input[name="micropub_enable"]' => array('checked' => TRUE),
        ),
      ),
    ];

    $form['micropub']['micropub_me'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Me'),
      '#default_value' => $config->get('micropub_me'),
      '#description' => $this->t('Every request will contain an access token which will be verified to make sure it is really you who is posting.<br />The response of the access token check request contains the "me" value which should match with your domain.<br />This is the value of your domain. Make sure there is a trailing slash, e.g. <strong>@domain/</strong>', ['@domain' => \Drupal::request()->getSchemeAndHttpHost()]),
    ];

    $form['micropub']['micropub_log_payload'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log the payload in watchdog on the micropub endpoint.'),
      '#default_value' => $config->get('micropub_log_payload'),
    ];

    // Collect fields.
    $text_fields = $upload_fields = $link_fields = $date_range_fields = $option_fields = $tag_fields = [];
    $fields = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node');
    /** @var \Drupal\Core\Field\FieldStorageDefinitionInterface $field */
    foreach ($fields as $key => $field) {
      if (in_array($field->getType(), ['text_with_summary', 'text_long'])) {
        $text_fields[$key] = $field->getName();
      }
      if (in_array($field->getType(), ['file', 'image'])) {
        $upload_fields[$key] = $field->getName();
      }
      if (in_array($field->getType(), ['link'])) {
        $link_fields[$key] = $field->getName();
      }
      if (in_array($field->getType(), ['daterange'])) {
        $date_range_fields[$key] = $field->getName();
      }
      if (in_array($field->getType(), ['list_string'])) {
        $option_fields[$key] = $field->getName();
      }
      if (in_array($field->getType(), ['entity_reference'])) {
        $settings = $field->getSettings();
        if (isset($settings['target_type']) && $settings['target_type'] == 'taxonomy_term') {
          $tag_fields[$key] = $field->getName();
        }
      }
    }

    foreach ($this->getPostTypes() as $post_type => $configuration) {

      $form[$post_type] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Create a node when a micropub @post_type is posted', ['@post_type' => $post_type]),
        '#description' => $configuration['description'],
        '#states' => array(
          'visible' => array(
            ':input[name="micropub_enable"]' => array('checked' => TRUE),
          ),
        ),
      ];

      $form[$post_type][$post_type . '_create_node'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable'),
        '#default_value' => $config->get($post_type . '_create_node'),
      ];

      $form[$post_type][$post_type . '_status'] = [
        '#type' => 'radios',
        '#title' => $this->t('Status'),
        '#options' => [
          0 => $this->t('Unpublished'),
          1 => $this->t('Published'),
        ],
        '#default_value' => $config->get($post_type . '_status'),
        '#states' => array(
          'visible' => array(
            ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
          ),
        ),
        '#description' => $this->t('When the payload contains the "post-status" property, its value will take precedence over this one. See <a href="https://indieweb.org/Micropub-extensions#Post_Status" target="_blank">https://indieweb.org/Micropub-extensions#Post_Status</a>'),
      ];

      $form[$post_type][$post_type . '_uid'] = [
        '#type' => 'number',
        '#title' => $this->t('The user id which will own the created node'),
        '#default_value' => $config->get($post_type . '_uid'),
        '#states' => array(
          'visible' => array(
            ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
          ),
        ),
      ];

      $form[$post_type][$post_type . '_node_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Node type'),
        '#description' => $this->t('Select the node type to use for creating a node'),
        '#options' => node_type_get_names(),
        '#default_value' => $config->get($post_type . '_node_type'),
        '#states' => array(
          'visible' => array(
            ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
          ),
        ),
      ];

      // Date field.
      if (isset($configuration['date_field'])) {
        $form[$post_type]['event_date_field'] = [
          '#type' => 'select',
          '#title' => $this->t('Date field'),
          '#description' => $this->t('Select the field which will be used to store the date. Make sure the field exists on the node type.<br />This can only be a date range field.'),
          '#options' => $date_range_fields,
          '#default_value' => $config->get($post_type . '_date_field'),
          '#states' => array(
            'visible' => array(
              ':input[name="'. $post_type . '_create_node"]' => array('checked' => TRUE),
            ),
          ),
        ];
      }

      // RSVP field.
      if (isset($configuration['rsvp_field'])) {
        $form[$post_type][$post_type . '_rsvp_field'] = [
          '#type' => 'select',
          '#title' => $this->t('RSVP field'),
          '#description' => $this->t('Select the field which will be used to store the RSVP value. Make sure the field exists on the node type.<br />This can only be a list option field with following values:<br />yes|I am going!<br />no|I am not going<br />maybe|I might attend<br />interested|I am interested'),
          '#options' => $option_fields,
          '#default_value' => $config->get($post_type . '_rsvp_field'),
          '#states' => array(
            'visible' => array(
              ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
            ),
          ),
        ];
      }

      // Link field.
      if (isset($configuration['link_field'])) {
        $form[$post_type][$post_type . '_link_field'] = [
          '#type' => 'select',
          '#title' => $this->t('Link field'),
          '#description' => $this->t('Select the field which will be used to store the link. Make sure the field exists on the node type.'),
          '#options' => $link_fields,
          '#default_value' => $config->get($post_type . '_link_field'),
          '#states' => array(
            'visible' => array(
              ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
            ),
          ),
        ];
      }

      // Send webmention.
      if (isset($configuration['send_webmention'])) {
        $form[$post_type][$post_type . '_auto_send_webmention'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Send webmention'),
          '#default_value' => $config->get($post_type . '_auto_send_webmention'),
          '#states' => array(
            'visible' => array(
              ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
            ),
          ),
          '#description' => $this->t('Automatically send a webmention to the URL that is found in the link field.'),
        ];
      }

      // Content field.
      $optional_body = [];
      if (isset($configuration['optional_body'])) {
        $optional_body = ['' => $this->t('Do not store content')];
      }
      $form[$post_type][$post_type . '_content_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Content field'),
        '#description' => $this->t('Select the field which will be used to store the content. Make sure the field exists on the node type.'),
        '#options' => $optional_body + $text_fields,
        '#default_value' => $config->get($post_type . '_content_field'),
        '#states' => array(
          'visible' => array(
            ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
          ),
        ),
      ];

      // Upload field.
      $form[$post_type][$post_type . '_upload_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Upload field'),
        '#description' => $this->t('Select the field which will be used to store files. Make sure the field exists on the node type.<br />Currently only supports saving 1 file in the "image" section of a micropub request.'),
        '#options' => ['' => $this->t('Do not allow uploads')] + $upload_fields,
        '#default_value' => $config->get($post_type . '_upload_field'),
        '#states' => array(
          'visible' => array(
            ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
          ),
        ),
      ];

      // Tags field.
      $form[$post_type][$post_type . '_tags_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Tags field'),
        '#description' => $this->t('Select the field which will be used to store tags. Make sure the field exists on the node type.<br />Field can only be a reference field targeting a taxonomy vocabulary and should only have one target bundle.'),
        '#options' => ['' => $this->t('Do not store tags')] + $tag_fields,
        '#default_value' => $config->get($post_type . '_tags_field'),
        '#states' => array(
          'visible' => array(
            ':input[name="' . $post_type . '_create_node"]' => array('checked' => TRUE),
          ),
        ),
      ];

    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $config = $this->config('indieweb.micropub');

    $config
      ->set('micropub_enable', $form_state->getValue('micropub_enable'))
      ->set('micropub_add_header_link', $form_state->getValue('micropub_add_header_link'))
      ->set('micropub_me', $form_state->getValue('micropub_me'))
      ->set('micropub_log_payload', $form_state->getValue('micropub_log_payload'));


    // Loop over post types.
    foreach ($this->getPostTypes() as $post_type => $configuration) {

     $config->set($post_type . '_create_node', $form_state->getValue($post_type . '_create_node'))
        ->set($post_type . '_status', $form_state->getValue($post_type . '_status'))
        ->set($post_type . '_uid', $form_state->getValue($post_type . '_uid'))
        ->set($post_type . '_node_type', $form_state->getValue($post_type . '_node_type'))
        ->set($post_type . '_content_field', $form_state->getValue($post_type . '_content_field'))
        ->set($post_type . '_upload_field', $form_state->getValue($post_type . '_upload_field'))
        ->set($post_type . '_tags_field', $form_state->getValue($post_type . '_tags_field'));

      if (isset($configuration['link_field'])) {
        $config->set($post_type . '_link_field', $form_state->getValue($post_type . '_link_field'));
      }

      if (isset($configuration['date_field'])) {
        $config->set($post_type . '_date_field', $form_state->getValue($post_type . '_date_field'));
      }

      if (isset($configuration['rsvp_field'])) {
        $config->set($post_type . '_rsvp_field', $form_state->getValue($post_type . '_rsvp_field'));
      }

      if (isset($configuration['send_webmention'])) {
        $config->set($post_type . '_auto_send_webmention', $form_state->getValue($post_type . '_auto_send_webmention'));
      }

    }

    $config->save();

    Cache::invalidateTags(['rendered']);

    parent::submitForm($form, $form_state);
  }

}
