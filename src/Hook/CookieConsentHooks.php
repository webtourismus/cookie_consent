<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Hook;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\cookie_consent\ConsentLog;
use Drupal\cookie_consent\ConsentSettings;
use Drupal\cookie_consent\EmbedGate;
use Drupal\media\IFrameMarkup;
use Drupal\media\OEmbed\Resource;

/**
 * Hook implementations for cookie_consent.
 */
class CookieConsentHooks {

  use StringTranslationTrait;

  public function __construct(
    protected ConsentSettings $settings,
    protected EmbedGate $embedGate,
    protected ConsentLog $consentLog,
    protected ModuleExtensionList $moduleList,
  ) {}

  /**
   * Implements hook_page_attachments().
   *
   * Adds the Consent Mode defaults as first script in <head> and the banner.
   *
   * @param array<mixed> $attachments
   *   The page attachments.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $this->settings->attach($attachments);
  }

  /**
   * Implements hook_cron().
   *
   * Deletes consent log entries older than the retention period.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->consentLog->purge();
  }

  /**
   * Implements hook_theme().
   *
   * @return array<string, array<string, mixed>>
   *   The theme hooks.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'cookie_consent_embed_placeholder' => [
        'variables' => [
          'service' => '',
          'provider' => '',
          'text' => '',
          'load_label' => '',
          'settings_label' => '',
          'privacy_label' => '',
          'privacy_url' => '',
        ],
      ],
      'cookie_consent_declaration' => [
        'variables' => [
          'categories' => [],
          'labels' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_library_info_alter().
   *
   * The geolocation module loads the Google Maps API as external script on
   * every page with a map. It is replaced by a loader that adds the script
   * once the visitor accepts the google_maps service.
   *
   * @param array<string, array<string, mixed>> $libraries
   *   The libraries of the extension.
   * @param string $extension
   *   The extension.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension !== 'geolocation_google_maps' || empty($libraries['google']['js'])) {
      return;
    }
    $external = array_keys(array_filter($libraries['google']['js'], fn(array $options): bool => ($options['type'] ?? '') === 'external'));
    if ($external === []) {
      return;
    }
    foreach ($external as $url) {
      unset($libraries['google']['js'][$url]);
    }
    $libraries['google']['js']['/' . $this->moduleList->getPath('cookie_consent') . '/js/cookie-consent.google-maps.js'] = [];
    $libraries['google']['drupalSettings']['cookieConsentGoogleMaps']['src'] = (string) reset($external);
    $libraries['google']['dependencies'][] = 'core/drupal';
    $libraries['google']['dependencies'][] = 'core/drupalSettings';
  }

  /**
   * Implements hook_preprocess_HOOK() for media_oembed_iframe.
   *
   * Plays YouTube videos from youtube-nocookie.com, which stores nothing in
   * the browser before the video is played. Loading the video still needs
   * consent, the embed stays blocked until then.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_media_oembed_iframe')]
  public function preprocessMediaOembedIframe(array &$variables): void {
    $variables['#cache']['tags'] = array_merge($variables['#cache']['tags'] ?? [], $this->settings->config()->getCacheTags());
    $resource = $variables['resource'] ?? NULL;
    if (!$this->settings->useYoutubeNocookie() || !$resource instanceof Resource || $resource->getProvider()?->getName() !== 'YouTube') {
      return;
    }
    $variables['media'] = IFrameMarkup::create(
      preg_replace('#//(?:www\.)?youtube\.com/embed/#i', '//www.youtube-nocookie.com/embed/', (string) $variables['media']) ?? (string) $variables['media'],
    );
  }

  /**
   * Implements hook_preprocess_HOOK() for field templates.
   *
   * Blocks oEmbed videos and Google Maps of the geolocation module until the
   * visitor accepts their service.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_field')]
  public function preprocessField(array &$variables): void {
    $formatter = $variables['element']['#formatter'] ?? '';
    if ($formatter !== 'oembed' && $formatter !== 'geolocation_map') {
      return;
    }
    foreach ($variables['items'] ?? [] as $delta => $item) {
      if (!isset($item['content']) || !is_array($item['content'])) {
        continue;
      }
      $service = $formatter === 'oembed' ? $this->embedGate->serviceForOembed($item['content']) : 'google_maps';
      if ($service !== NULL) {
        $variables['items'][$delta]['content'] = $this->embedGate->wrap($item['content'], $service);
      }
    }
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    return match ($route_name) {
      'cookie_consent.settings' => '<p>' . $this->t('Google tags get the consent through Google Consent Mode. Other tags in Google Tag Manager are triggered by the data layer event %event, see the README of the module.', ['%event' => ConsentSettings::EVENT]) . '</p>',
      'entity.cookie_consent_service.collection' => '<p>' . $this->t('Enable the services used on this website. They are listed in the banner and in the cookie declaration, which can be placed as a block or with the text filter token [cookie-consent-declaration].') . '</p>',
      'cookie_consent.report' => '<p>' . $this->t('Every choice in the banner is logged with the random consent ID shown to the visitor in the <a href=":settings">cookie settings</a>. No IP addresses are stored.', [':settings' => Url::fromRoute('cookie_consent.settings')->toString()]) . '</p>',
      default => NULL,
    };
  }

}
