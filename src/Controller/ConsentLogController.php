<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\cookie_consent\ConsentLog;
use Drupal\cookie_consent\ConsentSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives the consents sent by the banner.
 *
 * Dependencies are autowired.
 *
 * @see \Drupal\Core\DependencyInjection\AutowireTrait
 */
class ConsentLogController extends ControllerBase {

  /**
   * Flood event name.
   */
  public const FLOOD_EVENT = 'cookie_consent.log';

  /**
   * Requests allowed per IP address within the flood window.
   */
  public const FLOOD_LIMIT = 30;

  /**
   * Flood window in seconds.
   */
  public const FLOOD_WINDOW = 3600;

  /**
   * Maximum size of the request body in bytes.
   */
  public const MAX_BODY_SIZE = 8192;

  public function __construct(
    protected ConsentLog $consentLog,
    protected ConsentSettings $settings,
    protected FloodInterface $flood,
  ) {}

  /**
   * Stores a consent.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   204 on success, 400 for an invalid consent, 404 if the banner is
   *   disabled, 413 for a too large body, 429 if the flood limit is reached.
   */
  public function log(Request $request): Response {
    if (!$this->settings->isEnabled()) {
      return new Response('', Response::HTTP_NOT_FOUND);
    }
    if (!$this->flood->isAllowed(static::FLOOD_EVENT, static::FLOOD_LIMIT, static::FLOOD_WINDOW)) {
      return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register(static::FLOOD_EVENT, static::FLOOD_WINDOW);

    $content = (string) $request->getContent();
    if (strlen($content) > static::MAX_BODY_SIZE) {
      return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }
    try {
      $record = $this->consentLog->validate(json_decode($content, TRUE, 16, JSON_THROW_ON_ERROR));
    }
    catch (\JsonException | \InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
    }
    $this->consentLog->insert($record);
    return new Response('', Response::HTTP_NO_CONTENT);
  }

}
