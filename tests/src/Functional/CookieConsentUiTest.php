<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\cookie_consent\ConsentLog;
use Drupal\cookie_consent\Entity\ConsentService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the frontend output and the administration pages.
 */
#[Group('cookie_consent')]
#[RunTestsInSeparateProcesses]
class CookieConsentUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cookie_consent', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('cookie_consent.settings')->set('enabled', TRUE)->save();
  }

  /**
   * Tests that the bootstrap runs before every other script on the frontend.
   */
  public function testFrontend(): void {
    $this->drupalGet('user/login');
    $html = $this->getSession()->getPage()->getContent();

    $bootstrap = strpos($html, 'cookieConsentBootstrap({');
    $this->assertNotFalse($bootstrap, 'The bootstrap script is inlined.');
    $before = substr($html, 0, $bootstrap);
    $this->assertStringNotContainsString('<script', substr($before, 0, strrpos($before, '<script')), 'No script runs before the bootstrap.');
    $this->assertSession()->responseContains('js/vendor/cookieconsent/cookieconsent.umd.js');
    $this->assertSession()->responseContains('js/cookie-consent.js');

    $settings = $this->getDrupalSettings()['cookieConsent'];
    $this->assertSame(1, $settings['revision']);
    $this->assertSame('cookie_consent', $settings['bootstrap']['cookieName']);
    $this->assertStringEndsWith('/cookie-consent/log', $settings['logUrl']);

    $this->config('cookie_consent.settings')->set('enabled', FALSE)->save();
    $this->drupalGet('user/login');
    $this->assertSession()->responseNotContains('cookieConsentBootstrap');
    $this->assertSession()->responseNotContains('cookieconsent.umd.js');
  }

  /**
   * Tests that administration pages have no banner.
   */
  public function testNoBannerOnAdminPages(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer cookie consent']));
    $this->drupalGet('admin/config/system/cookie-consent');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('cookieConsentBootstrap');
  }

  /**
   * Tests the settings form.
   */
  public function testSettingsForm(): void {
    $this->drupalGet('admin/config/system/cookie-consent');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer cookie consent']));
    $this->drupalGet('admin/config/system/cookie-consent');
    $this->submitForm(['data_layer' => 'data layer'], 'Save configuration');
    $this->assertSession()->pageTextContains('The data layer must be a valid JavaScript variable name.');

    $this->submitForm([
      'data_layer' => 'dataLayer',
      'revision' => 2,
      // The login page is not accessible to logged-in users.
      'privacy_page' => '/user/password',
      'categories[analytics][label]' => 'Analytics',
      'accept_all' => 'Sure',
      'url_passthrough' => TRUE,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('cookie_consent.settings');
    $this->assertSame(2, $config->get('revision'));
    $this->assertSame('/user/password', $config->get('privacy_page'));
    $this->assertSame('Analytics', $config->get('categories.analytics.label'));
    $this->assertSame('Sure', $config->get('texts.accept_all'));
    $this->assertTrue($config->get('url_passthrough'));
    $this->assertSame('Marketing', $config->get('categories.marketing.label'), 'Other categories are kept.');

    $this->submitForm(['privacy_page' => '/does-not-exist'], 'Save configuration');
    $this->assertSession()->pageTextContains('This path does not exist or you do not have permission to link to /does-not-exist.');
  }

  /**
   * Tests listing and adding services.
   */
  public function testServices(): void {
    $this->drupalPlaceBlock('local_actions_block');
    $this->drupalLogin($this->drupalCreateUser(['administer cookie consent']));
    $this->drupalGet('admin/config/system/cookie-consent/services');
    $this->assertSession()->pageTextContains('Google Analytics');
    $this->assertSession()->pageTextContains('_ga, _ga_*');

    $this->clickLink('Add service');
    $edit = [
      'label' => 'Booking widget',
      'id' => 'booking_widget',
      'category' => 'functional',
      'provider' => 'Booking GmbH',
      'privacy_url' => 'https://booking.example.com/privacy',
      'cookies' => "bw_*|1 year|Session\n\n  bw_lang | 1 month ",
      'embed_hosts' => "https://booking.example.com/widget",
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('Invalid host "https://booking.example.com/widget"');

    $edit['embed_hosts'] = "Booking.Example.com\nwidgets.example.org";
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('Saved the service Booking widget.');

    $service = ConsentService::load('booking_widget');
    $this->assertTrue($service->status());
    $this->assertSame('functional', $service->getCategory());
    $this->assertSame([
      ['name' => 'bw_*', 'duration' => '1 year', 'description' => 'Session'],
      ['name' => 'bw_lang', 'duration' => '1 month', 'description' => ''],
    ], $service->getCookies());
    $this->assertSame(['booking.example.com', 'widgets.example.org'], $service->getEmbedHosts());

    $this->drupalGet('admin/config/system/cookie-consent/services/booking_widget');
    $this->assertSession()->fieldValueEquals('cookies', "bw_* | 1 year | Session\nbw_lang | 1 month | ");
  }

  /**
   * Tests the consent log report and the CSV export.
   */
  public function testReport(): void {
    $log = \Drupal::service(ConsentLog::class);
    foreach (['3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f' => 'all', 'ab0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f' => 'necessary'] as $id => $type) {
      $log->insert($log->validate([
        'consentId' => $id,
        'revision' => 1,
        'acceptType' => $type,
        'categories' => ['necessary'],
        'services' => [],
        'langcode' => 'en',
      ]));
    }

    $this->drupalGet('admin/reports/cookie-consent');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view cookie consent log']));
    $this->drupalGet('admin/reports/cookie-consent');
    $this->assertSession()->pageTextContains('3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f');
    $this->assertSession()->pageTextContains('ab0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f');
    $this->assertSession()->pageTextContains('50 %');

    $this->submitForm(['consent_id' => 'AB0C4B6E-8A1D-4C2B-9E7F-1A2B3C4D5E6F'], 'Search');
    $this->assertStringContainsString('consent_id=AB0C4B6E', $this->getUrl());
    $this->assertSession()->pageTextNotContains('3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f');
    $this->assertSession()->pageTextContains('ab0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f');

    $this->drupalGet('admin/reports/cookie-consent/export');
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
    $csv = $this->getSession()->getPage()->getContent();
    $lines = array_values(array_filter(explode("\n", $csv)));
    $this->assertSame('created,consent_id,revision,accept_type,categories,services,langcode', $lines[0]);
    $this->assertCount(3, $lines);
    $this->assertStringContainsString(',ab0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f,1,necessary,necessary,', $csv);
  }

}
