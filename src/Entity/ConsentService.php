<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\cookie_consent\ConsentServiceListBuilder;
use Drupal\cookie_consent\Form\ConsentServiceForm;

/**
 * Defines the cookie consent service entity.
 */
#[ConfigEntityType(
  id: 'cookie_consent_service',
  label: new TranslatableMarkup('Cookie consent service'),
  label_collection: new TranslatableMarkup('Services'),
  label_singular: new TranslatableMarkup('service'),
  label_plural: new TranslatableMarkup('services'),
  config_prefix: 'service',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => ConsentServiceListBuilder::class,
    'form' => [
      'add' => ConsentServiceForm::class,
      'edit' => ConsentServiceForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/system/cookie-consent/services',
    'add-form' => '/admin/config/system/cookie-consent/services/add',
    'edit-form' => '/admin/config/system/cookie-consent/services/{cookie_consent_service}',
    'delete-form' => '/admin/config/system/cookie-consent/services/{cookie_consent_service}/delete',
  ],
  admin_permission: 'administer cookie consent',
  label_count: [
    'singular' => '@count service',
    'plural' => '@count services',
  ],
  config_export: [
    'id',
    'label',
    'weight',
    'category',
    'provider',
    'purpose',
    'privacy_url',
    'cookies',
    'embed_hosts',
  ],
)]
class ConsentService extends ConfigEntityBase implements ConsentServiceInterface {

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The name shown to visitors.
   */
  protected ?string $label = NULL;

  /**
   * The weight within the category.
   */
  protected int $weight = 0;

  /**
   * The machine name of the consent category.
   */
  protected string $category = 'marketing';

  /**
   * The company that provides the service.
   */
  protected string $provider = '';

  /**
   * What the service is used for.
   */
  protected string $purpose = '';

  /**
   * The URL of the provider's privacy policy.
   */
  protected string $privacy_url = '';

  /**
   * The cookies the service sets.
   *
   * @var array<int, array{name: string, duration: string, description: string}>
   */
  protected array $cookies = [];

  /**
   * The hosts of content embedded from this service.
   *
   * @var string[]
   */
  protected array $embed_hosts = [];

  /**
   * {@inheritdoc}
   */
  public function getCategory(): string {
    return $this->category;
  }

  /**
   * {@inheritdoc}
   */
  public function getProvider(): string {
    return $this->provider;
  }

  /**
   * {@inheritdoc}
   */
  public function getPurpose(): string {
    return $this->purpose;
  }

  /**
   * {@inheritdoc}
   */
  public function getPrivacyUrl(): string {
    return $this->privacy_url;
  }

  /**
   * {@inheritdoc}
   */
  public function getCookies(): array {
    return array_values($this->cookies);
  }

  /**
   * {@inheritdoc}
   */
  public function getEmbedHosts(): array {
    return array_values($this->embed_hosts);
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

}
