<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shipped texts and their English translation.
 */
#[Group('cookie_consent')]
#[RunTestsInSeparateProcesses]
class TranslationTest extends CookieConsentKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language'];

  /**
   * {@inheritdoc}
   */
  protected static array $configModules = ['system', 'language', 'cookie_consent'];

  /**
   * Tests the German config and the English overrides.
   */
  public function testTranslation(): void {
    $storage = $this->container->get('config.storage');
    $this->assertSame('Alle ablehnen', $storage->read('cookie_consent.settings')['texts']['reject_all']);
    $this->assertSame('Statistik', $storage->read('cookie_consent.settings')['categories']['analytics']['label']);

    $overrides = $this->container->get('language.config_factory_override');
    $this->assertSame('Reject all', $overrides->getOverride('en', 'cookie_consent.settings')->get('texts.reject_all'));
    $this->assertSame('Statistics', $overrides->getOverride('en', 'cookie_consent.settings')->get('categories.analytics.label'));
    $this->assertSame('2 years', $overrides->getOverride('en', 'cookie_consent.service.google_analytics')->get('cookies.0.duration'));
    // The override of a sequence item keeps the untranslated cookie name.
    $this->assertSame('_ga', $this->container->get('config.factory')->get('cookie_consent.service.google_analytics')->get('cookies.0.name'));
  }

  /**
   * Tests the French overrides and their post update.
   */
  public function testFrench(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $languageManager = $this->container->get('language_manager');
    $this->assertSame('Tout refuser', $languageManager->getLanguageConfigOverride('fr', 'cookie_consent.settings')->get('texts.reject_all'));
    $this->assertSame('2 ans', $languageManager->getLanguageConfigOverride('fr', 'cookie_consent.service.google_analytics')->get('cookies.0.duration'));

    // Sites that had French before get the missing overrides, changed ones
    // are kept.
    $languageManager->getLanguageConfigOverride('fr', 'cookie_consent.settings')->delete();
    $languageManager->getLanguageConfigOverride('fr', 'cookie_consent.service.youtube')->set('purpose', 'Changé')->save();
    require_once $this->container->get('extension.list.module')->getPath('cookie_consent') . '/cookie_consent.post_update.php';
    cookie_consent_post_update_french_texts();
    $this->assertSame('Tout refuser', $languageManager->getLanguageConfigOverride('fr', 'cookie_consent.settings')->get('texts.reject_all'));
    $this->assertSame('Changé', $languageManager->getLanguageConfigOverride('fr', 'cookie_consent.service.youtube')->get('purpose'));
  }

  /**
   * Tests that the post update removes the former label texts.
   */
  public function testPostUpdateLabelTexts(): void {
    // Written to the storage directly, the schema no longer knows the keys.
    $storage = $this->container->get('config.storage');
    $data = $storage->read('cookie_consent.settings');
    $data['texts'] += ['table_name' => 'Name', 'close' => 'Schließen'];
    $storage->write('cookie_consent.settings', $data);
    $english = $storage->createCollection('language.en');
    $override = $english->read('cookie_consent.settings');
    $override['texts']['close'] = 'Close';
    $english->write('cookie_consent.settings', $override);
    $this->container->get('config.factory')->reset();

    require_once $this->container->get('extension.list.module')->getPath('cookie_consent') . '/cookie_consent.post_update.php';
    cookie_consent_post_update_label_texts();

    $texts = $storage->read('cookie_consent.settings')['texts'];
    $this->assertArrayNotHasKey('table_name', $texts);
    $this->assertArrayNotHasKey('close', $texts);
    $this->assertSame('Alle ablehnen', $texts['reject_all'], 'Other texts are kept.');
    $overrideTexts = $english->read('cookie_consent.settings')['texts'];
    $this->assertArrayNotHasKey('close', $overrideTexts);
    $this->assertSame('Reject all', $overrideTexts['reject_all']);
  }

}
