<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Kernel;

use Drupal\cookie_consent\ConsentLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the consent log.
 */
#[CoversClass(ConsentLog::class)]
#[Group('cookie_consent')]
#[RunTestsInSeparateProcesses]
class ConsentLogTest extends CookieConsentKernelTestBase {

  /**
   * A valid consent as sent by the banner.
   *
   * @var array<string, mixed>
   */
  protected const PAYLOAD = [
    'consentId' => '3F0C4B6E-8A1D-4C2B-9E7F-1A2B3C4D5E6F',
    'revision' => 1,
    'acceptType' => 'custom',
    'categories' => ['necessary', 'analytics', 'unknown'],
    'services' => [
      'necessary' => ['cookie_consent', 'google_tag_manager'],
      'analytics' => ['google_analytics', 'removed_service'],
      'marketing' => [],
      'unknown' => ['x'],
    ],
    'langcode' => 'en',
  ];

  /**
   * Returns the consent log.
   */
  protected function consentLog(): ConsentLog {
    return $this->container->get(ConsentLog::class);
  }

  /**
   * Tests that a valid consent is normalized.
   */
  public function testValidate(): void {
    $this->assertSame([
      'consent_id' => '3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f',
      'revision' => 1,
      'accept_type' => 'custom',
      'categories' => ['necessary', 'analytics'],
      'services' => [
        'necessary' => ['cookie_consent', 'google_tag_manager'],
        'analytics' => ['google_analytics'],
        'marketing' => [],
        'external_media' => [],
      ],
      'langcode' => 'en',
    ], $this->consentLog()->validate(static::PAYLOAD));
  }

  /**
   * Tests that invalid consents are rejected.
   *
   * @param array<string, mixed> $changes
   *   Changes to the valid payload.
   */
  #[DataProvider('providerInvalid')]
  public function testValidateInvalid(array $changes): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->consentLog()->validate(array_merge(static::PAYLOAD, $changes));
  }

  /**
   * Data provider for testValidateInvalid().
   *
   * @return array<string, array{0: array<string, mixed>}>
   *   Changes to the valid payload.
   */
  public static function providerInvalid(): array {
    return [
      'no uuid' => [['consentId' => 'abc']],
      'uuid v1' => [['consentId' => '3f0c4b6e-8a1d-1c2b-9e7f-1a2b3c4d5e6f']],
      'consent id not a string' => [['consentId' => 1]],
      'negative revision' => [['revision' => -1]],
      'revision as string' => [['revision' => '1']],
      'unknown accept type' => [['acceptType' => 'maybe']],
      'unknown language' => [['langcode' => 'xx']],
      'categories as object' => [['categories' => ['a' => 'necessary']]],
      'services of a category not a list' => [['services' => ['analytics' => 'google_analytics']]],
    ];
  }

  /**
   * Tests that a non-object payload is rejected.
   */
  public function testValidateScalar(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->consentLog()->validate('consent');
  }

  /**
   * Tests storing, selecting and counting consents.
   */
  public function testInsertSelectStatistics(): void {
    $log = $this->consentLog();
    $record = $log->validate(static::PAYLOAD);
    $id = $log->insert($record);
    $log->insert(['accept_type' => 'all', 'consent_id' => 'ab0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f'] + $record);
    $log->insert(['accept_type' => 'all'] + $record);

    $entries = $log->select(' 3F0C4B6E-8A1D-4C2B-9E7F-1A2B3C4D5E6F ')->execute()->fetchAll();
    $this->assertCount(2, $entries);
    $first = end($entries);
    $this->assertSame($id, (int) $first->id);
    $this->assertSame('necessary,analytics', $first->categories);
    $this->assertSame([
      'necessary' => ['cookie_consent', 'google_tag_manager'],
      'analytics' => ['google_analytics'],
      'marketing' => [],
      'external_media' => [],
    ], json_decode($first->services, TRUE));
    $this->assertCount(3, $log->select()->execute()->fetchAll());

    $this->assertSame(['all' => 2, 'necessary' => 0, 'custom' => 1], $log->statistics(0));
    $this->assertSame(['all' => 0, 'necessary' => 0, 'custom' => 0], $log->statistics(\Drupal::time()->getRequestTime() + 1));
  }

  /**
   * Tests that cron deletes entries after the retention period.
   */
  public function testPurge(): void {
    $log = $this->consentLog();
    $log->insert($log->validate(static::PAYLOAD));
    $database = $this->container->get('database');
    $now = \Drupal::time()->getRequestTime();
    $database->update(ConsentLog::TABLE)->fields(['created' => $now - 11 * 86400])->execute();
    $log->insert($log->validate(static::PAYLOAD));

    $this->setConsentSetting('log_retention_days', 0);
    $this->assertSame(0, $log->purge(), 'Retention 0 keeps entries forever.');

    $this->setConsentSetting('log_retention_days', 10);
    $this->container->get('module_handler')->invoke('cookie_consent', 'cron');
    $this->assertSame(1, (int) $database->select(ConsentLog::TABLE)->countQuery()->execute()->fetchField());
  }

}
