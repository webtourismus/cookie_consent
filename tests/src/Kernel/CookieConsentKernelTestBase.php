<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\cookie_consent\ConsentSettings;

/**
 * Base class of the cookie_consent kernel tests.
 */
abstract class CookieConsentKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'filter',
    'cookie_consent',
  ];

  /**
   * Modules whose default config is installed.
   *
   * Without the language module, the shipped German texts apply, as on the
   * websites.
   *
   * @var string[]
   */
  protected static array $configModules = ['system', 'cookie_consent'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(static::$configModules);
    $this->installSchema('cookie_consent', ['cookie_consent_log']);
    // The module is installed with the banner disabled.
    $this->setConsentSetting('enabled', TRUE);
  }

  /**
   * Changes a value of the settings.
   */
  protected function setConsentSetting(string $key, mixed $value): void {
    $this->config(ConsentSettings::CONFIG_NAME)->set($key, $value)->save();
  }

  /**
   * Returns the consent settings service.
   */
  protected function consentSettings(): ConsentSettings {
    return $this->container->get(ConsentSettings::class);
  }

}
