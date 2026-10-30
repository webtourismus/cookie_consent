<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\cookie_consent\ConsentSettings;
use Drupal\cookie_consent\Entity\ConsentService;
use Drupal\cookie_consent\Hook\CookieConsentHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the configuration passed to the page.
 */
#[CoversClass(ConsentSettings::class)]
#[Group('cookie_consent')]
#[RunTestsInSeparateProcesses]
class ConsentSettingsTest extends CookieConsentKernelTestBase {

  /**
   * Tests the configuration of the inline bootstrap script.
   */
  public function testBootstrapConfig(): void {
    $config = $this->consentSettings()->bootstrapConfig();

    $this->assertSame('cookie_consent', $config['cookieName']);
    $this->assertSame('cookie_consent_update', $config['event']);
    $this->assertSame('dataLayer', $config['dataLayer']);
    $this->assertSame(1, $config['revision']);
    $this->assertSame(500, $config['waitForUpdate']);
    $this->assertTrue($config['adsDataRedaction']);
    $this->assertFalse($config['urlPassthrough']);
    $this->assertSame(['necessary'], $config['required']);
    $this->assertSame(['analytics_storage'], $config['signals']['analytics']);
    $this->assertSame(['ad_storage', 'ad_user_data', 'ad_personalization'], $config['signals']['marketing']);
    $this->assertSame([], $config['signals']['external_media']);
    // Disabled services (Meta Pixel) are left out, empty categories kept.
    $this->assertSame(['google_ads'], $config['services']['marketing']);
    $this->assertSame([], $config['services']['functional']);
    $this->assertSame(['youtube', 'vimeo', 'google_maps'], $config['services']['external_media']);
    $this->assertSame(['cookie_consent', 'google_tag_manager'], $config['services']['necessary']);
  }

  /**
   * Tests that unknown Consent Mode signals are dropped.
   */
  public function testUnknownSignalsAreDropped(): void {
    $this->setConsentSetting('categories.analytics.consent_signals', ['analytics_storage', 'bogus']);
    $this->assertSame(['analytics_storage'], $this->consentSettings()->bootstrapConfig()['signals']['analytics']);
  }

  /**
   * Tests the inline script.
   */
  public function testBootstrapScript(): void {
    $this->setConsentSetting('data_layer', '</script><script>alert(1)</script>');
    $script = $this->consentSettings()->bootstrapScript();

    $this->assertStringStartsWith('/**', $script);
    $this->assertStringContainsString('function cookieConsentBootstrap(config, win, doc)', $script);
    $this->assertStringEndsWith(', window, document);', $script);
    $this->assertStringNotContainsString('</script>', $script, 'The configuration cannot close the script element.');
  }

  /**
   * Tests the drupalSettings of the banner.
   */
  public function testClientSettings(): void {
    $this->setConsentSetting('texts.consent_title', '<b>Privacy</b>');
    $this->setConsentSetting('privacy_page', '/user/login');
    $this->setConsentSetting('revision', 4);
    ConsentService::load('google_analytics')->set('label', 'Analytics <i>4</i>')->save();

    $cacheability = new CacheableMetadata();
    $settings = $this->consentSettings()->clientSettings($cacheability);

    $this->assertSame(4, $settings['revision']);
    $this->assertSame(4, $settings['bootstrap']['revision']);
    $this->assertSame(182, $settings['expiryDays']);
    $this->assertSame('box', $settings['layout']);
    $this->assertSame('bottom left', $settings['position']);
    $this->assertSame('en', $settings['langcode']);
    $this->assertSame('/cookie-consent/log', $settings['logUrl']);
    $this->assertSame(['/user/login'], $settings['legalPaths']);

    // Functional has no enabled services and is not offered.
    $this->assertSame(['necessary', 'analytics', 'marketing', 'external_media'], array_keys($settings['categories']));
    $this->assertTrue($settings['categories']['necessary']['required']);
    $this->assertFalse($settings['categories']['analytics']['required']);
    $this->assertSame(['_ga', '_ga_*'], $settings['categories']['analytics']['services']['google_analytics']['cookies']);
    $this->assertSame('Analytics &lt;i&gt;4&lt;/i&gt;', $settings['categories']['analytics']['services']['google_analytics']['label']);

    $translation = $settings['translation'];
    $this->assertSame('&lt;b&gt;Privacy&lt;/b&gt;', $translation['consentModal']['title']);
    $this->assertStringEndsWith(' {{revisionMessage}}', $translation['consentModal']['description']);
    $this->assertSame('Alle ablehnen', $translation['consentModal']['acceptNecessaryBtn']);
    $this->assertSame('<a href="/user/login">Privacy policy</a>', $translation['consentModal']['footer']);

    $sections = $translation['preferencesModal']['sections'];
    $this->assertSame(['analytics', 'marketing', 'external_media'], array_values(array_filter(array_column($sections, 'linkedCategory'), fn($id) => $id !== 'necessary')));
    $analytics = $sections[2];
    $this->assertSame('analytics', $analytics['linkedCategory']);
    $this->assertSame('_ga_*', $analytics['cookieTable']['body'][1]['name']);
    $this->assertSame('Analytics &lt;i&gt;4&lt;/i&gt;', $analytics['cookieTable']['body'][1]['service']);
    $this->assertArrayNotHasKey('cookieTable', $sections[4], 'External media sets no first-party cookies.');
    $last = end($sections);
    $this->assertStringContainsString('<span data-cookie-consent-id>', $last['description']);
    $this->assertStringContainsString('<a href="/user/login">Privacy policy</a>', $last['description']);

    $this->assertContains('config:cookie_consent.settings', $cacheability->getCacheTags());
  }

  /**
   * Tests that invalid layouts fall back to the defaults.
   */
  public function testInvalidLayoutFallsBack(): void {
    $this->setConsentSetting('layout', 'fullscreen');
    $this->setConsentSetting('position', 'somewhere');
    $settings = $this->consentSettings()->clientSettings(new CacheableMetadata());
    $this->assertSame('box', $settings['layout']);
    $this->assertSame('bottom left', $settings['position']);
  }

  /**
   * Tests that invalid legal paths are skipped.
   */
  public function testInvalidLegalPathIsSkipped(): void {
    $this->setConsentSetting('imprint_page', 'no-leading-slash');
    $this->assertSame([], $this->consentSettings()->legalLinks(new CacheableMetadata()));
  }

  /**
   * Tests the page attachments.
   */
  public function testAttach(): void {
    $attachments = [];
    $this->consentSettings()->attach($attachments);

    [$element, $key] = $attachments['#attached']['html_head'][0];
    $this->assertSame('cookie_consent_bootstrap', $key);
    $this->assertSame('script', $element['#tag']);
    $this->assertSame(-1000, $element['#weight']);
    $this->assertContains('cookie_consent/banner', $attachments['#attached']['library']);
    $this->assertArrayHasKey('cookieConsent', $attachments['#attached']['drupalSettings']);
    $this->assertContains('config:cookie_consent.settings', $attachments['#cache']['tags']);
    $this->assertContains('config:cookie_consent_service_list', $attachments['#cache']['tags']);
    $this->assertContains('languages:language_interface', $attachments['#cache']['contexts']);
    $this->assertContains('route', $attachments['#cache']['contexts']);
  }

  /**
   * Tests that the module is installed with the banner disabled.
   *
   * Sites can install the module while another consent tool still runs.
   */
  public function testShippedDisabled(): void {
    $storage = new FileStorage($this->container->get('extension.list.module')->getPath('cookie_consent') . '/config/install');
    $this->assertFalse($storage->read(ConsentSettings::CONFIG_NAME)['enabled']);
  }

  /**
   * Tests that the banner opens only on pages with Google Tag Manager.
   */
  public function testAutoShow(): void {
    // Without the google_tag module the banner always opens.
    $this->assertTrue($this->consentSettings()->autoShow([]));

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnMap([['google_tag', TRUE]]);
    $settings = new ConsentSettings(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('language_manager'),
      $this->container->get('router.admin_context'),
      $this->container->get('extension.list.module'),
      $this->container->getParameter('app.root'),
      $moduleHandler,
    );
    $this->assertTrue($settings->autoShow(['core/drupal', 'google_tag/gtm', 'google_tag/gtag']));
    $this->assertTrue($settings->autoShow(['google_tag/gtag']));
    $this->assertFalse($settings->autoShow(['core/drupal', 'cookie_consent/banner']), 'Pages excluded in the Google Tag settings.');

    $this->setConsentSetting('require_google_tag', FALSE);
    $this->assertTrue($settings->autoShow([]));
  }

  /**
   * Tests that the page attachments alter hook passes the decision on.
   */
  public function testPageAttachmentsAlter(): void {
    $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(CookieConsentHooks::class);
    $attachments = [];
    $hooks->pageAttachmentsAlter($attachments);
    $this->assertSame([], $attachments, 'Pages without the banner are not changed.');

    $this->consentSettings()->attach($attachments);
    $hooks->pageAttachmentsAlter($attachments);
    $this->assertTrue($attachments['#attached']['drupalSettings']['cookieConsent']['autoShow']);
  }

  /**
   * Tests that nothing is attached while the banner is disabled.
   */
  public function testAttachDisabled(): void {
    $this->setConsentSetting('enabled', FALSE);
    $attachments = [];
    $this->consentSettings()->attach($attachments);

    $this->assertArrayNotHasKey('#attached', $attachments);
    $this->assertContains('config:cookie_consent.settings', $attachments['#cache']['tags']);
  }

}
