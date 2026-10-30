<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\PathElement;
use Drupal\Core\Url;
use Drupal\cookie_consent\ConsentSettings;

/**
 * Configures the cookie banner.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cookie_consent_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The config names.
   */
  protected function getEditableConfigNames(): array {
    return [ConsentSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(ConsentSettings::CONFIG_NAME);
    $target = ConsentSettings::CONFIG_NAME . ':';

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('The banner asks visitors for consent and passes their choice to Google Tag Manager (Google Consent Mode v2). The services that need consent are managed in the <a href=":services">Services</a> tab. Texts are translated in the "Translate" tab.', [
        ':services' => Url::fromRoute('entity.cookie_consent_service.collection')->toString(),
      ]) . '</p>',
    ];

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General'),
      '#open' => TRUE,
    ];
    $form['general']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the cookie banner and send consent signals'),
      '#description' => $this->t('Before enabling, make sure no other consent tool (e.g. one loaded by Google Tag Manager) runs on this website.'),
      '#config_target' => $target . 'enabled',
    ];
    $form['general']['revision'] = [
      '#type' => 'number',
      '#title' => $this->t('Revision of the consent texts'),
      '#description' => $this->t('Raise the number after changing services or purposes: all visitors are asked again.'),
      '#min' => 0,
      '#required' => TRUE,
      '#config_target' => $target . 'revision',
    ];
    $form['general']['expiry_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Ask again after'),
      '#field_suffix' => $this->t('days'),
      '#description' => $this->t('Common: 182 days (6 months), at most 395 days (13 months).'),
      '#min' => 1,
      '#max' => 395,
      '#required' => TRUE,
      '#config_target' => $target . 'expiry_days',
    ];
    $form['general']['privacy_page'] = [
      '#type' => 'path',
      '#title' => $this->t('Privacy policy'),
      '#description' => $this->t('Path of the privacy policy, e.g. /node/12. Linked in the banner, the banner does not open on its own there.'),
      '#convert_path' => PathElement::CONVERT_NONE,
      '#config_target' => $target . 'privacy_page',
    ];
    $form['general']['imprint_page'] = [
      '#type' => 'path',
      '#title' => $this->t('Imprint'),
      '#description' => $this->t('Path of the imprint, e.g. /node/13. Linked in the banner, the banner does not open on its own there.'),
      '#convert_path' => PathElement::CONVERT_NONE,
      '#config_target' => $target . 'imprint_page',
    ];
    $form['general']['youtube_nocookie'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Play YouTube videos from youtube-nocookie.com'),
      '#description' => $this->t('Applies even while the banner is disabled. Videos still need consent: YouTube receives data once the video is loaded.'),
      '#config_target' => $target . 'youtube_nocookie',
    ];
    $form['general']['layout'] = [
      '#type' => 'select',
      '#title' => $this->t('Banner layout'),
      '#options' => array_combine(ConsentSettings::LAYOUTS, ConsentSettings::LAYOUTS),
      '#config_target' => $target . 'layout',
    ];
    $form['general']['position'] = [
      '#type' => 'select',
      '#title' => $this->t('Banner position'),
      '#options' => array_combine(ConsentSettings::POSITIONS, ConsentSettings::POSITIONS),
      '#config_target' => $target . 'position',
    ];

    $form['google'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Consent Mode'),
      '#open' => TRUE,
    ];
    $form['google']['data_layer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Data layer'),
      '#description' => $this->t('Name of the Google Tag Manager data layer, usually "dataLayer".'),
      '#required' => TRUE,
      '#config_target' => $target . 'data_layer',
    ];
    $form['google']['wait_for_update'] = [
      '#type' => 'number',
      '#title' => $this->t('Wait for consent update'),
      '#field_suffix' => $this->t('milliseconds'),
      '#description' => $this->t('How long Google tags wait for the consent of a first-time visitor before they send data without cookies.'),
      '#min' => 0,
      '#max' => 10000,
      '#config_target' => $target . 'wait_for_update',
    ];
    $form['google']['ads_data_redaction'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Redact ads data without marketing consent'),
      '#description' => $this->t('Google Ads removes click identifiers from requests while ad_storage is denied.'),
      '#config_target' => $target . 'ads_data_redaction',
    ];
    $form['google']['url_passthrough'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pass ad click information through URLs'),
      '#description' => $this->t('Improves conversion measurement without cookies by adding parameters to internal links. Changes the URLs of the website.'),
      '#config_target' => $target . 'url_passthrough',
    ];

    $form['categories'] = [
      '#type' => 'details',
      '#title' => $this->t('Categories'),
      '#description' => $this->t('Categories without enabled services are not shown to visitors.'),
      // Every category has a label and a description field.
      '#tree' => TRUE,
    ];
    foreach ($config->get('categories') ?? [] as $id => $category) {
      $form['categories'][$id] = [
        '#type' => 'fieldset',
        '#title' => $id,
        '#description' => $category['consent_signals'] ? $this->t('Grants: @signals', ['@signals' => implode(', ', $category['consent_signals'])]) : NULL,
      ];
      $form['categories'][$id]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#required' => TRUE,
        '#config_target' => $target . 'categories.' . $id . '.label',
      ];
      $form['categories'][$id]['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#rows' => 2,
        '#config_target' => $target . 'categories.' . $id . '.description',
      ];
    }

    $form['texts'] = [
      '#type' => 'details',
      '#title' => $this->t('Texts'),
      '#description' => $this->t('Plain text, HTML is not allowed. Links to the privacy policy and the imprint are added automatically.'),
    ];
    $descriptions = [
      'embed_text' => $this->t('@service is replaced by the name of the service, @provider by its provider.'),
      'service_counter' => $this->t('Singular and plural separated by "|".'),
    ];
    foreach ($this->textFields() as $key => [$title, $multiline]) {
      $form['texts'][$key] = [
        '#type' => $multiline ? 'textarea' : 'textfield',
        '#title' => $title,
        '#description' => $descriptions[$key] ?? NULL,
        '#rows' => 3,
        '#required' => TRUE,
        '#config_target' => $target . 'texts.' . $key,
      ];
    }

    $form['log'] = [
      '#type' => 'details',
      '#title' => $this->t('Consent log'),
    ];
    $form['log']['log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep log entries for'),
      '#field_suffix' => $this->t('days'),
      '#description' => $this->t('Proof of consent is needed as long as claims can be raised, usually 3 years (1095 days). 0 keeps entries forever.'),
      '#min' => 0,
      '#config_target' => $target . 'log_retention_days',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', (string) $form_state->getValue('data_layer'))) {
      $form_state->setErrorByName('data_layer', $this->t('The data layer must be a valid JavaScript variable name.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * Returns the text fields.
   *
   * @return array<string, array{0: \Drupal\Core\StringTranslation\TranslatableMarkup, 1: bool}>
   *   Title and whether the text has multiple lines, keyed by config key.
   */
  protected function textFields(): array {
    return [
      'consent_title' => [$this->t('Banner title'), FALSE],
      'consent_description' => [$this->t('Banner text'), TRUE],
      'revision_message' => [$this->t('Note when the texts have changed'), TRUE],
      'accept_all' => [$this->t('Accept all button'), FALSE],
      'reject_all' => [$this->t('Reject all button'), FALSE],
      'show_preferences' => [$this->t('Settings button'), FALSE],
      'preferences_title' => [$this->t('Settings title'), FALSE],
      'preferences_description' => [$this->t('Settings introduction'), TRUE],
      'save_preferences' => [$this->t('Save selection button'), FALSE],
      'close' => [$this->t('Close button label'), FALSE],
      'service_counter' => [$this->t('Label after the number of services'), FALSE],
      'privacy_link' => [$this->t('Privacy policy link'), FALSE],
      'imprint_link' => [$this->t('Imprint link'), FALSE],
      'more_title' => [$this->t('Title of the section about the stored consent'), FALSE],
      'more_description' => [$this->t('Text of the section about the stored consent'), TRUE],
      'consent_id_label' => [$this->t('Label of the consent ID'), FALSE],
      'consent_date_label' => [$this->t('Label of the consent date'), FALSE],
      'table_name' => [$this->t('Cookie table: name'), FALSE],
      'table_service' => [$this->t('Cookie table: service'), FALSE],
      'table_duration' => [$this->t('Cookie table: duration'), FALSE],
      'table_description' => [$this->t('Cookie table: description'), FALSE],
      'embed_text' => [$this->t('Placeholder text of blocked external content'), TRUE],
      'embed_load' => [$this->t('Placeholder button to load the content'), FALSE],
      'embed_settings' => [$this->t('Placeholder button to open the settings'), FALSE],
      'embed_privacy' => [$this->t('Placeholder link to the privacy policy of the provider'), FALSE],
      'declaration_provider' => [$this->t('Cookie declaration: provider'), FALSE],
      'declaration_purpose' => [$this->t('Cookie declaration: purpose'), FALSE],
      'declaration_cookies' => [$this->t('Cookie declaration: cookies'), FALSE],
      'declaration_no_cookies' => [$this->t('Cookie declaration: no cookies'), FALSE],
    ];
  }

}
