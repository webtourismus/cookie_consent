<?php

declare(strict_types=1);

namespace Drupal\cookie_consent;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Stores the consents of visitors as proof (GDPR Art. 7(1)).
 *
 * Only the random consent ID of the banner, the choice and the time are
 * stored, no IP address or other personal data.
 */
class ConsentLog {

  /**
   * The database table.
   */
  public const TABLE = 'cookie_consent_log';

  /**
   * How the visitor answered: all, only necessary or a custom selection.
   */
  public const ACCEPT_TYPES = ['all', 'necessary', 'custom'];

  public function __construct(
    protected Connection $database,
    protected ConsentSettings $settings,
    protected LanguageManagerInterface $languageManager,
    protected TimeInterface $time,
  ) {}

  /**
   * Validates and normalizes a consent sent by the banner.
   *
   * Unknown categories and services are dropped instead of rejected: a page
   * from the cache may still know a service that was removed since.
   *
   * @param mixed $payload
   *   The decoded JSON body, see cookieConsentHelpers.logPayload().
   *
   * @return array{consent_id: string, revision: int, accept_type: string, categories: string[], services: array<string, string[]>, langcode: string}
   *   The record to store.
   *
   * @throws \InvalidArgumentException
   *   If the payload is invalid.
   */
  public function validate(mixed $payload): array {
    if (!is_array($payload)) {
      throw new \InvalidArgumentException('The consent must be a JSON object.');
    }
    $consentId = $payload['consentId'] ?? NULL;
    if (!is_string($consentId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $consentId)) {
      throw new \InvalidArgumentException('The consent ID must be a UUID v4.');
    }
    $revision = $payload['revision'] ?? NULL;
    if (!is_int($revision) || $revision < 0) {
      throw new \InvalidArgumentException('The revision must be a non-negative integer.');
    }
    $acceptType = $payload['acceptType'] ?? NULL;
    if (!in_array($acceptType, static::ACCEPT_TYPES, TRUE)) {
      throw new \InvalidArgumentException('Unknown accept type.');
    }
    $langcode = $payload['langcode'] ?? NULL;
    if (!is_string($langcode) || $this->languageManager->getLanguage($langcode) === NULL) {
      throw new \InvalidArgumentException('Unknown language.');
    }
    $categories = $payload['categories'] ?? NULL;
    $services = $payload['services'] ?? [];
    if (!is_array($categories) || !array_is_list($categories) || !is_array($services)) {
      throw new \InvalidArgumentException('Categories must be a list, services an object.');
    }

    $known = [];
    foreach ($this->settings->getActiveCategories() as $id => $category) {
      $known[$id] = array_map('strval', array_keys($category['services']));
    }
    $categories = array_values(array_intersect(array_keys($known), $categories));
    $normalizedServices = [];
    foreach ($known as $category => $serviceIds) {
      $given = $services[$category] ?? [];
      if (!is_array($given)) {
        throw new \InvalidArgumentException('The services of a category must be a list.');
      }
      $normalizedServices[$category] = array_values(array_intersect($serviceIds, $given));
    }

    return [
      'consent_id' => strtolower($consentId),
      'revision' => $revision,
      'accept_type' => $acceptType,
      'categories' => $categories,
      'services' => $normalizedServices,
      'langcode' => $langcode,
    ];
  }

  /**
   * Stores a validated consent.
   *
   * @param array{consent_id: string, revision: int, accept_type: string, categories: string[], services: array<string, string[]>, langcode: string} $record
   *   The record returned by ::validate().
   *
   * @return int
   *   The ID of the log entry.
   */
  public function insert(array $record): int {
    return (int) $this->database->insert(static::TABLE)
      ->fields([
        'consent_id' => $record['consent_id'],
        'revision' => $record['revision'],
        'accept_type' => $record['accept_type'],
        'categories' => implode(',', $record['categories']),
        'services' => json_encode($record['services'], JSON_THROW_ON_ERROR),
        'langcode' => $record['langcode'],
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Deletes entries older than the retention period.
   *
   * @return int
   *   The number of deleted entries.
   */
  public function purge(): int {
    $days = (int) $this->settings->config()->get('log_retention_days');
    if ($days <= 0) {
      return 0;
    }
    return (int) $this->database->delete(static::TABLE)
      ->condition('created', $this->time->getRequestTime() - $days * 86400, '<')
      ->execute();
  }

  /**
   * Returns a query of the log entries, newest first.
   *
   * @param string $consentId
   *   Only entries of this consent ID, all if empty.
   */
  public function select(string $consentId = ''): SelectInterface {
    $query = $this->database->select(static::TABLE, 'l')
      ->fields('l')
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC');
    if ($consentId !== '') {
      $query->condition('consent_id', strtolower(trim($consentId)));
    }
    return $query;
  }

  /**
   * Counts the entries per accept type.
   *
   * @param int $since
   *   Only entries created at or after this timestamp.
   *
   * @return array<string, int>
   *   The number of entries keyed by accept type, including zero counts.
   */
  public function statistics(int $since): array {
    $query = $this->database->select(static::TABLE, 'l')
      ->condition('created', $since, '>=')
      ->groupBy('accept_type');
    $query->addField('l', 'accept_type');
    $query->addExpression('COUNT(*)', 'count');
    $counts = array_fill_keys(static::ACCEPT_TYPES, 0);
    foreach ($query->execute()?->fetchAllKeyed() ?? [] as $type => $count) {
      $counts[$type] = (int) $count;
    }
    return $counts;
  }

}
