<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A service that needs consent, e.g. Google Analytics or YouTube.
 */
interface ConsentServiceInterface extends ConfigEntityInterface {

  /**
   * Returns the machine name of the consent category.
   */
  public function getCategory(): string;

  /**
   * Returns the company that provides the service.
   */
  public function getProvider(): string;

  /**
   * Returns what the service is used for.
   */
  public function getPurpose(): string;

  /**
   * Returns the URL of the provider's privacy policy, may be empty.
   */
  public function getPrivacyUrl(): string;

  /**
   * Returns the cookies the service sets.
   *
   * @return array<int, array{name: string, duration: string, description: string}>
   *   The cookies, a "*" in the name matches any characters.
   */
  public function getCookies(): array;

  /**
   * Returns the hosts of content embedded from this service.
   *
   * @return string[]
   *   Host names, subdomains are matched as well.
   */
  public function getEmbedHosts(): array;

  /**
   * Returns the weight within the category.
   */
  public function getWeight(): int;

}
