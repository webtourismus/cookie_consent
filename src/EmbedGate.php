<?php

declare(strict_types=1);

namespace Drupal\cookie_consent;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\cookie_consent\Entity\ConsentServiceInterface;

/**
 * Blocks embedded content until the visitor accepts its service.
 *
 * The original markup is rendered inside a <template> element, which the
 * browser does not load, next to a placeholder. cookie-consent.js moves it
 * into the page once the service is accepted.
 */
class EmbedGate {

  public function __construct(
    protected ConsentSettings $settings,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Returns the enabled service whose embed hosts match a URL.
   */
  public function serviceForUrl(string $url): ?ConsentServiceInterface {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
      return NULL;
    }
    foreach ($this->settings->getServices() as $service) {
      foreach ($service->getEmbedHosts() as $embedHost) {
        $embedHost = strtolower(trim($embedHost));
        if ($embedHost !== '' && ($host === $embedHost || str_ends_with($host, '.' . $embedHost))) {
          return $service;
        }
      }
    }
    return NULL;
  }

  /**
   * Returns the service of an iframe rendered by the core oEmbed formatter.
   *
   * The iframe points to Drupal's /media/oembed route, the provider URL is
   * in its "url" query parameter.
   *
   * @param array<string, mixed> $element
   *   The render array of the iframe.
   */
  public function serviceForOembed(array $element): ?ConsentServiceInterface {
    $src = (string) ($element['#attributes']['src'] ?? '');
    if ($src === '') {
      return NULL;
    }
    $query = UrlHelper::parse($src)['query'];
    return $this->serviceForUrl((string) ($query['url'] ?? ''));
  }

  /**
   * Wraps a render array, so it is loaded only after consent.
   *
   * @param array<string, mixed> $element
   *   The render array of the embed.
   * @param string $service
   *   The machine name of the service.
   *
   * @return array<string, mixed>
   *   The wrapped render array, unchanged if the banner is disabled or the
   *   service is not enabled.
   */
  public function wrap(array $element, string $service): array {
    $cacheability = CacheableMetadata::createFromRenderArray($element);
    $parts = $this->wrapperParts($service, $cacheability);
    $cacheability->applyTo($element);
    if ($parts === NULL) {
      return $element;
    }
    // #prefix and #suffix keep the element itself unchanged, so themes can
    // still alter it, e.g. the iframe attributes of a video.
    $element['#prefix'] = Markup::create($parts[0] . ($element['#prefix'] ?? ''));
    $element['#suffix'] = Markup::create(($element['#suffix'] ?? '') . $parts[1]);
    return $element;
  }

  /**
   * Returns the markup before and after the blocked content.
   *
   * @param string $service
   *   The machine name of the service.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the placeholder.
   *
   * @return array{0: string, 1: string}|null
   *   The opening and closing markup, NULL if nothing has to be blocked.
   */
  protected function wrapperParts(string $service, CacheableMetadata $cacheability): ?array {
    $this->settings->addCacheableDependencies($cacheability);
    if (!$this->settings->isEnabled()) {
      return NULL;
    }
    $service = $this->settings->getService($service);
    if ($service === NULL) {
      return NULL;
    }
    $cacheability->addCacheableDependency($service);

    $placeholder = [
      '#theme' => 'cookie_consent_embed_placeholder',
      '#service' => (string) $service->label(),
      '#provider' => $service->getProvider() ?: (string) $service->label(),
      '#text' => (string) $this->settings->config()->get('texts.embed_text'),
      '#privacy_url' => UrlHelper::stripDangerousProtocols($service->getPrivacyUrl()),
    ];
    $placeholderMarkup = (string) $this->renderer->renderInIsolation($placeholder);
    $cacheability->addCacheableDependency(CacheableMetadata::createFromRenderArray($placeholder));

    $open = '<div class="cookie-consent-embed" data-cookie-consent-service="' . Html::escape((string) $service->id())
      . '" data-cookie-consent-category="' . Html::escape($service->getCategory()) . '"><template>';
    $close = '</template>' . $placeholderMarkup . '</div>';
    return [$open, $close];
  }

}
