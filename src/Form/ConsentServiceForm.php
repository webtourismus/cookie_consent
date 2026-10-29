<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\cookie_consent\ConsentSettings;
use Drupal\cookie_consent\Entity\ConsentService;
use Drupal\cookie_consent\Entity\ConsentServiceInterface;

/**
 * Adds and edits services that need consent.
 *
 * Dependencies are autowired.
 *
 * @see \Drupal\Core\DependencyInjection\AutowireTrait
 */
class ConsentServiceForm extends EntityForm {

  use AutowireTrait;

  public function __construct(
    protected ConsentSettings $settings,
  ) {}

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
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\cookie_consent\Entity\ConsentServiceInterface $service */
    $service = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('Shown to visitors, e.g. "Google Analytics".'),
      '#default_value' => $service->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $service->id(),
      '#machine_name' => ['exists' => [ConsentService::class, 'load']],
      '#disabled' => !$service->isNew(),
      '#description' => $this->t('Used in the data layer (cookie_consent.services.ID), in the Twig filter cookie_consent_embed and in the consent log.'),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Used on this website'),
      '#description' => $this->t('Only enabled services are shown in the banner and the cookie declaration.'),
      '#default_value' => $service->status(),
    ];
    $form['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#options' => array_map(fn(array $category): string => $category['label'], $this->settings->getCategories()),
      '#default_value' => $service->getCategory(),
      '#required' => TRUE,
    ];
    $form['provider'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Provider'),
      '#description' => $this->t('Company that receives the data, e.g. "Google Ireland Limited".'),
      '#default_value' => $service->getProvider(),
    ];
    $form['purpose'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Purpose'),
      '#rows' => 2,
      '#default_value' => $service->getPurpose(),
    ];
    $form['privacy_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Privacy policy of the provider'),
      '#default_value' => $service->getPrivacyUrl(),
    ];
    $form['cookies'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cookies'),
      '#description' => $this->t('One cookie per line: name | duration | description. Use * as wildcard, e.g. _ga_*. First-party cookies are deleted when the visitor rejects the service.'),
      '#default_value' => implode("\n", array_map(
        fn(array $cookie): string => implode(' | ', [
          $cookie['name'],
          $cookie['duration'] ?? '',
          $cookie['description'] ?? '',
        ]),
        $service->getCookies(),
      )),
      '#rows' => 4,
    ];
    $form['embed_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Hosts of embedded content'),
      '#description' => $this->t('One host per line, e.g. youtube.com. Subdomains are included. Videos (oEmbed) from these hosts are loaded only after consent.'),
      '#default_value' => implode("\n", $service->getEmbedHosts()),
      '#rows' => 3,
    ];
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#description' => $this->t('Order within the category.'),
      '#default_value' => $service->getWeight(),
    ];
    return $form;
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
    parent::validateForm($form, $form_state);
    foreach ($this->parseCookies((string) $form_state->getValue('cookies')) as $cookie) {
      if (!preg_match('/^[A-Za-z0-9_.*-]+$/', $cookie['name'])) {
        $form_state->setErrorByName('cookies', $this->t('Invalid cookie name "@name".', ['@name' => $cookie['name']]));
      }
    }
    foreach ($this->parseLines((string) $form_state->getValue('embed_hosts')) as $host) {
      if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/i', $host)) {
        $form_state->setErrorByName('embed_hosts', $this->t('Invalid host "@host", enter it without protocol and path.', ['@host' => $host]));
      }
    }
    $privacyUrl = (string) $form_state->getValue('privacy_url');
    if ($privacyUrl !== '' && !UrlHelper::isValid($privacyUrl, TRUE)) {
      $form_state->setErrorByName('privacy_url', $this->t('The privacy policy must be an absolute URL.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * The cookies and hosts are entered as text and stored as lists.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The service.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof ConsentServiceInterface);
    foreach (['id', 'label', 'category', 'provider', 'purpose', 'privacy_url'] as $key) {
      $entity->set($key, trim((string) $form_state->getValue($key)));
    }
    $entity->set('status', (bool) $form_state->getValue('status'));
    $entity->set('weight', (int) $form_state->getValue('weight'));
    $entity->set('cookies', $this->parseCookies((string) $form_state->getValue('cookies')));
    $entity->set('embed_hosts', array_map('strtolower', $this->parseLines((string) $form_state->getValue('embed_hosts'))));
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the service %label. Raise the revision in the settings if visitors have to consent again.', ['%label' => (string) $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Parses the cookie lines "name | duration | description".
   *
   * @return array<int, array{name: string, duration: string, description: string}>
   *   The cookies.
   */
  protected function parseCookies(string $text): array {
    $cookies = [];
    foreach ($this->parseLines($text) as $line) {
      $parts = array_map('trim', explode('|', $line, 3));
      $cookies[] = [
        'name' => $parts[0],
        'duration' => $parts[1] ?? '',
        'description' => $parts[2] ?? '',
      ];
    }
    return $cookies;
  }

  /**
   * Returns the trimmed, non-empty lines of a text.
   *
   * @return string[]
   *   The lines.
   */
  protected function parseLines(string $text): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn(string $line): bool => $line !== ''));
  }

}
