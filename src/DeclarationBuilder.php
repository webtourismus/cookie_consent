<?php

declare(strict_types=1);

namespace Drupal\cookie_consent;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Builds the cookie declaration for the privacy policy.
 */
class DeclarationBuilder {

  public function __construct(
    protected ConsentSettings $settings,
  ) {}

  /**
   * Builds the cookie declaration.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function build(): array {
    $cacheability = new CacheableMetadata();
    $this->settings->addCacheableDependencies($cacheability);

    $categories = [];
    foreach ($this->settings->getActiveCategories() as $id => $category) {
      $services = [];
      foreach ($category['services'] as $service) {
        $services[] = [
          'label' => (string) $service->label(),
          'provider' => $service->getProvider(),
          'purpose' => $service->getPurpose(),
          'privacy_url' => UrlHelper::stripDangerousProtocols($service->getPrivacyUrl()),
          'cookies' => $service->getCookies(),
        ];
      }
      if ($services === []) {
        continue;
      }
      $categories[] = [
        'id' => $id,
        'label' => $category['label'],
        'description' => $category['description'],
        'services' => $services,
      ];
    }

    $build = [
      '#theme' => 'cookie_consent_declaration',
      '#categories' => $categories,
    ];
    $cacheability->applyTo($build);
    return $build;
  }

}
