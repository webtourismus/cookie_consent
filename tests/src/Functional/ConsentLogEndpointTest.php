<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\cookie_consent\ConsentLog;
use Drupal\cookie_consent\Controller\ConsentLogController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the endpoint that receives consents from the banner.
 */
#[CoversClass(ConsentLogController::class)]
#[Group('cookie_consent')]
#[RunTestsInSeparateProcesses]
class ConsentLogEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cookie_consent'];

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
   * Sends a request to the endpoint.
   */
  protected function request(string $method, string $body = ''): ResponseInterface {
    return $this->getHttpClient()->request($method, $this->buildUrl('/cookie-consent/log'), [
      'body' => $body,
      'headers' => ['Content-Type' => 'application/json'],
      'http_errors' => FALSE,
    ]);
  }

  /**
   * Returns the number of log entries.
   */
  protected function countEntries(): int {
    return (int) \Drupal::database()->select(ConsentLog::TABLE)->countQuery()->execute()->fetchField();
  }

  /**
   * Tests valid and invalid requests, the size limit and the flood limit.
   */
  public function testEndpoint(): void {
    $payload = json_encode([
      'consentId' => '3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f',
      'revision' => 1,
      'acceptType' => 'all',
      'categories' => ['necessary', 'analytics', 'marketing', 'external_media'],
      'services' => ['analytics' => ['google_analytics']],
      'langcode' => 'en',
    ]);

    $response = $this->request('POST', $payload);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertSame(1, $this->countEntries());
    $entry = \Drupal::database()->select(ConsentLog::TABLE, 'l')->fields('l')->execute()->fetchObject();
    $this->assertIsObject($entry);
    $this->assertSame('3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f', $entry->consent_id);
    $this->assertSame('all', $entry->accept_type);
    $this->assertSame('necessary,analytics,marketing,external_media', $entry->categories);

    $response = $this->request('POST', '{broken');
    $this->assertSame(400, $response->getStatusCode());
    $response = $this->request('POST', json_encode(['consentId' => 'abc']));
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('The consent ID must be a UUID v4.', json_decode((string) $response->getBody(), TRUE)['error']);
    $this->assertSame(413, $this->request('POST', str_repeat(' ', ConsentLogController::MAX_BODY_SIZE + 1))->getStatusCode());
    $this->assertSame(405, $this->request('GET')->getStatusCode());
    $this->assertSame(1, $this->countEntries());

    // 4 requests reached the controller so far (routing rejects GET), the
    // limit counts invalid requests as well.
    for ($i = 4; $i < ConsentLogController::FLOOD_LIMIT; $i++) {
      $this->request('POST', '{}');
    }
    $this->assertSame(429, $this->request('POST', $payload)->getStatusCode());
    $this->assertSame(1, $this->countEntries());

    $this->config('cookie_consent.settings')->set('enabled', FALSE)->save();
    $this->assertSame(404, $this->request('POST', $payload)->getStatusCode());
  }

}
