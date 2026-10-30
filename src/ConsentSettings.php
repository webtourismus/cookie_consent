<?php

declare(strict_types=1);

namespace Drupal\cookie_consent;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\cookie_consent\Entity\ConsentServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides the consent configuration to the page, the embeds and the log.
 *
 * All texts passed to the banner are escaped here, the banner library inserts
 * them as HTML.
 */
class ConsentSettings {

  use StringTranslationTrait;

  /**
   * Name of the cookie that stores the consent.
   */
  public const COOKIE_NAME = 'cookie_consent';

  /**
   * Data layer event pushed on page load with stored consent and on changes.
   */
  public const EVENT = 'cookie_consent_update';

  /**
   * Config name of the settings.
   */
  public const CONFIG_NAME = 'cookie_consent.settings';

  /**
   * Google Consent Mode signals, in the order Google documents them.
   */
  public const CONSENT_SIGNALS = [
    'ad_storage',
    'ad_user_data',
    'ad_personalization',
    'analytics_storage',
    'functionality_storage',
    'personalization_storage',
    'security_storage',
  ];

  /**
   * Layouts of the consent banner supported by the library.
   */
  public const LAYOUTS = ['box', 'box wide', 'box inline', 'cloud', 'cloud inline', 'bar', 'bar inline'];

  /**
   * Positions of the consent banner supported by the library.
   */
  public const POSITIONS = [
    'bottom left',
    'bottom center',
    'bottom right',
    'middle center',
    'top left',
    'top center',
    'top right',
    'bottom',
    'top',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
    protected AdminContext $adminContext,
    protected ModuleExtensionList $moduleList,
    #[Autowire(param: 'app.root')]
    protected string $appRoot,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns the settings in the current interface language.
   */
  public function config(): ImmutableConfig {
    return $this->configFactory->get(static::CONFIG_NAME);
  }

  /**
   * Whether the banner is enabled at all.
   */
  public function isEnabled(): bool {
    return (bool) $this->config()->get('enabled');
  }

  /**
   * Whether YouTube videos are played from youtube-nocookie.com.
   *
   * Independent of the banner, sites with another consent tool use it too.
   */
  public function useYoutubeNocookie(): bool {
    return (bool) $this->config()->get('youtube_nocookie');
  }

  /**
   * Whether the banner opens on its own on a page.
   *
   * With the google_tag module, only pages that load a Google tag need a
   * decision. Its container conditions exclude e.g. the login page. The
   * banner still runs there, so stored consent, embeds and the settings
   * button keep working.
   *
   * @param string[] $libraries
   *   The libraries attached to the page.
   */
  public function autoShow(array $libraries): bool {
    if (!$this->config()->get('require_google_tag') || !$this->moduleHandler->moduleExists('google_tag')) {
      return TRUE;
    }
    return array_intersect(['google_tag/gtm', 'google_tag/gtag'], $libraries) !== [];
  }

  /**
   * Whether the banner and the consent signals belong on the current page.
   */
  public function isActive(): bool {
    return $this->isEnabled() && !$this->adminContext->isAdminRoute();
  }

  /**
   * Adds the cacheability of all consent settings.
   */
  public function addCacheableDependencies(CacheableMetadata $cacheability): void {
    $cacheability
      ->addCacheableDependency($this->config())
      ->addCacheTags($this->entityTypeManager->getDefinition('cookie_consent_service')->getListCacheTags())
      ->addCacheContexts(['languages:language_interface']);
  }

  /**
   * Returns the consent categories ordered by weight.
   *
   * @return array<string, array{label: string, description: string, required: bool, weight: int, consent_signals: string[]}>
   *   The categories keyed by machine name.
   */
  public function getCategories(): array {
    $categories = [];
    foreach ($this->config()->get('categories') ?? [] as $id => $category) {
      $categories[$id] = [
        'label' => (string) ($category['label'] ?? $id),
        'description' => (string) ($category['description'] ?? ''),
        'required' => !empty($category['required']),
        'weight' => (int) ($category['weight'] ?? 0),
        'consent_signals' => array_values(array_intersect($category['consent_signals'] ?? [], static::CONSENT_SIGNALS)),
      ];
    }
    uasort($categories, fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);
    return $categories;
  }

  /**
   * Returns the enabled services of existing categories.
   *
   * @return array<string, \Drupal\cookie_consent\Entity\ConsentServiceInterface>
   *   The services keyed by ID, ordered by category, weight and label.
   */
  public function getServices(): array {
    $categories = $this->getCategories();
    $categoryWeights = array_flip(array_keys($categories));
    /** @var \Drupal\cookie_consent\Entity\ConsentServiceInterface[] $services */
    $services = $this->entityTypeManager->getStorage('cookie_consent_service')->loadByProperties(['status' => TRUE]);
    $services = array_filter($services, fn(ConsentServiceInterface $service): bool => isset($categories[$service->getCategory()]));
    $sortKey = fn(ConsentServiceInterface $service): array => [
      $categoryWeights[$service->getCategory()],
      $service->getWeight(),
      (string) $service->label(),
    ];
    uasort($services, fn(ConsentServiceInterface $a, ConsentServiceInterface $b): int => $sortKey($a) <=> $sortKey($b));
    return $services;
  }

  /**
   * Returns an enabled service.
   */
  public function getService(string $id): ?ConsentServiceInterface {
    return $this->getServices()[$id] ?? NULL;
  }

  /**
   * Returns the categories visitors can choose, with their services.
   *
   * Optional categories without enabled services are left out: there is
   * nothing to consent to.
   *
   * @return array<string, array{label: string, description: string, required: bool, weight: int, consent_signals: string[], services: \Drupal\cookie_consent\Entity\ConsentServiceInterface[]}>
   *   The categories keyed by machine name.
   */
  public function getActiveCategories(): array {
    $services = [];
    foreach ($this->getServices() as $id => $service) {
      $services[$service->getCategory()][$id] = $service;
    }
    $active = [];
    foreach ($this->getCategories() as $id => $category) {
      $category['services'] = $services[$id] ?? [];
      if ($category['required'] || $category['services'] !== []) {
        $active[$id] = $category;
      }
    }
    return $active;
  }

  /**
   * Returns the configuration of the inline bootstrap script.
   *
   * @return array<string, mixed>
   *   The configuration, see js/cookie-consent.bootstrap.js.
   */
  public function bootstrapConfig(): array {
    $config = $this->config();
    $signals = [];
    $required = [];
    $services = [];
    foreach ($this->getCategories() as $id => $category) {
      $signals[$id] = $category['consent_signals'];
      $services[$id] = [];
      if ($category['required']) {
        $required[] = $id;
      }
    }
    foreach ($this->getServices() as $id => $service) {
      $services[$service->getCategory()][] = $id;
    }
    return [
      'cookieName' => static::COOKIE_NAME,
      'event' => static::EVENT,
      'dataLayer' => $config->get('data_layer') ?: 'dataLayer',
      'revision' => (int) $config->get('revision'),
      'waitForUpdate' => (int) $config->get('wait_for_update'),
      'adsDataRedaction' => (bool) $config->get('ads_data_redaction'),
      'urlPassthrough' => (bool) $config->get('url_passthrough'),
      'signals' => $signals,
      'required' => $required,
      'services' => $services,
    ];
  }

  /**
   * Returns the inline script that sets the consent defaults before GTM.
   */
  public function bootstrapScript(): string {
    $file = $this->appRoot . '/' . $this->moduleList->getPath('cookie_consent') . '/js/cookie-consent.bootstrap.js';
    $json = json_encode($this->bootstrapConfig(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return trim((string) file_get_contents($file)) . "\ncookieConsentBootstrap(" . $json . ", window, document);";
  }

  /**
   * Returns the drupalSettings for the banner in the current language.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the generated URLs.
   *
   * @return array<string, mixed>
   *   The settings, see js/cookie-consent.js.
   */
  public function clientSettings(CacheableMetadata $cacheability): array {
    $this->addCacheableDependencies($cacheability);
    $config = $this->config();
    $categories = [];
    foreach ($this->getActiveCategories() as $id => $category) {
      $services = [];
      foreach ($category['services'] as $serviceId => $service) {
        $services[$serviceId] = [
          'label' => Html::escape((string) $service->label()),
          'cookies' => array_column($service->getCookies(), 'name'),
        ];
      }
      $categories[$id] = [
        'required' => $category['required'],
        'services' => $services,
      ];
    }
    $layout = (string) $config->get('layout');
    $position = (string) $config->get('position');
    $logUrl = Url::fromRoute('cookie_consent.log_endpoint')->toString(TRUE);
    $cacheability->addCacheableDependency($logUrl);
    return [
      'bootstrap' => $this->bootstrapConfig(),
      'revision' => (int) $config->get('revision'),
      'expiryDays' => max(1, (int) $config->get('expiry_days')),
      'layout' => in_array($layout, static::LAYOUTS, TRUE) ? $layout : 'box',
      'position' => in_array($position, static::POSITIONS, TRUE) ? $position : 'bottom left',
      'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
      'categories' => $categories,
      'translation' => $this->translation($cacheability),
      'legalPaths' => array_values(array_column($this->legalLinks($cacheability), 'path')),
      'logUrl' => $logUrl->getGeneratedUrl(),
    ];
  }

  /**
   * Attaches the bootstrap script, the banner and its settings to a page.
   *
   * @param array<mixed> $attachments
   *   The page attachments.
   */
  public function attach(array &$attachments): void {
    $cacheability = CacheableMetadata::createFromRenderArray($attachments);
    $this->addCacheableDependencies($cacheability);
    $cacheability->addCacheContexts(['route']);
    if ($this->isActive()) {
      // The lowest weight keeps the script first in <head>, before Google Tag
      // Manager. JSON_HEX_TAG in the script keeps it from closing <script>.
      $attachments['#attached']['html_head'][] = [
        [
          '#type' => 'html_tag',
          '#tag' => 'script',
          '#value' => Markup::create($this->bootstrapScript()),
          '#weight' => -1000,
        ],
        'cookie_consent_bootstrap',
      ];
      $attachments['#attached']['library'][] = 'cookie_consent/banner';
      $attachments['#attached']['drupalSettings']['cookieConsent'] = $this->clientSettings($cacheability);
    }
    $cacheability->applyTo($attachments);
  }

  /**
   * Returns the links to the privacy policy and the imprint.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the generated URLs.
   *
   * @return array<string, array{path: string, label: string}>
   *   The links keyed by "privacy" and "imprint", missing if not configured.
   */
  public function legalLinks(CacheableMetadata $cacheability): array {
    $config = $this->config();
    $links = [];
    $labels = [
      'privacy' => $this->t('Privacy policy'),
      'imprint' => $this->t('Imprint'),
    ];
    foreach (['privacy' => 'privacy_page', 'imprint' => 'imprint_page'] as $key => $setting) {
      $path = trim((string) $config->get($setting));
      if ($path === '') {
        continue;
      }
      try {
        $url = Url::fromUserInput($path)->toString(TRUE);
      }
      catch (\InvalidArgumentException) {
        continue;
      }
      $cacheability->addCacheableDependency($url);
      $links[$key] = [
        'path' => $url->getGeneratedUrl(),
        'label' => (string) $labels[$key],
      ];
    }
    return $links;
  }

  /**
   * Returns a text of the settings, escaped for HTML.
   */
  public function text(string $key): string {
    return Html::escape((string) $this->config()->get('texts.' . $key));
  }

  /**
   * Builds the translation object of the banner library.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the generated URLs.
   *
   * @return array<string, mixed>
   *   The consentModal and preferencesModal texts.
   */
  protected function translation(CacheableMetadata $cacheability): array {
    $links = [];
    foreach ($this->legalLinks($cacheability) as $link) {
      $links[] = '<a href="' . Html::escape($link['path']) . '">' . Html::escape($link['label']) . '</a>';
    }
    $footer = implode("\n", $links);

    $sections = [
      ['description' => $this->text('preferences_description')],
    ];
    foreach ($this->getActiveCategories() as $id => $category) {
      $section = [
        'title' => Html::escape($category['label']),
        'description' => Html::escape($category['description']),
        'linkedCategory' => $id,
      ];
      $rows = [];
      foreach ($category['services'] as $service) {
        foreach ($service->getCookies() as $cookie) {
          $rows[] = [
            'name' => Html::escape((string) $cookie['name']),
            'service' => Html::escape((string) $service->label()),
            'duration' => Html::escape((string) ($cookie['duration'] ?? '')),
            'description' => Html::escape((string) ($cookie['description'] ?? '')),
          ];
        }
      }
      if ($rows !== []) {
        $section['cookieTable'] = [
          'headers' => [
            'name' => Html::escape((string) $this->t('Name')),
            'service' => Html::escape((string) $this->t('Service')),
            'duration' => Html::escape((string) $this->t('Duration')),
            'description' => Html::escape((string) $this->t('Description')),
          ],
          'body' => $rows,
        ];
      }
      $sections[] = $section;
    }
    $sections[] = [
      'title' => $this->text('more_title'),
      'description' => $this->text('more_description')
      . '<br><br>' . Html::escape((string) $this->t('Consent ID')) . ': <span data-cookie-consent-id>–</span>'
      . '<br>' . Html::escape((string) $this->t('Last change')) . ': <span data-cookie-consent-date>–</span>'
      . ($footer !== '' ? '<br><br>' . implode(' · ', $links) : ''),
    ];

    return [
      'consentModal' => [
        'label' => $this->text('consent_title'),
        'title' => $this->text('consent_title'),
        // The library replaces the placeholder with the revision message
        // when the texts have changed since the visitor's consent.
        'description' => $this->text('consent_description') . ' {{revisionMessage}}',
        'revisionMessage' => '<br><br>' . $this->text('revision_message'),
        'acceptAllBtn' => $this->text('accept_all'),
        'acceptNecessaryBtn' => $this->text('reject_all'),
        'showPreferencesBtn' => $this->text('show_preferences'),
        'footer' => $footer,
      ],
      'preferencesModal' => [
        'title' => $this->text('preferences_title'),
        'acceptAllBtn' => $this->text('accept_all'),
        'acceptNecessaryBtn' => $this->text('reject_all'),
        'savePreferencesBtn' => $this->text('save_preferences'),
        'closeIconLabel' => Html::escape((string) $this->t('Close')),
        // Singular and plural, the library picks one.
        'serviceCounterLabel' => Html::escape((string) $this->t('service|services')),
        'sections' => $sections,
      ],
    ];
  }

}
