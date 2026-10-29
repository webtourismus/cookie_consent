<?php

declare(strict_types=1);

namespace Drupal\cookie_consent;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the services that need consent, grouped by category.
 */
class ConsentServiceListBuilder extends ConfigEntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected ConsentSettings $settings,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    // @phpstan-ignore new.static
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get(ConsentSettings::class),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The services ordered by category, weight and label.
   */
  public function load(): array {
    $weights = array_flip(array_keys($this->settings->getCategories()));
    /** @var \Drupal\cookie_consent\Entity\ConsentServiceInterface[] $services */
    $services = parent::load();
    $sortKey = fn($service): array => [
      $weights[$service->getCategory()] ?? PHP_INT_MAX,
      $service->getWeight(),
      (string) $service->label(),
    ];
    uasort($services, fn($a, $b): int => $sortKey($a) <=> $sortKey($b));
    return $services;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The header.
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Service'),
      'category' => $this->t('Category'),
      'provider' => $this->t('Provider'),
      'cookies' => $this->t('Cookies'),
      'status' => $this->t('Used'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The row.
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\cookie_consent\Entity\ConsentServiceInterface $entity */
    $categories = $this->settings->getCategories();
    return [
      'label' => $entity->label(),
      'category' => $categories[$entity->getCategory()]['label'] ?? $entity->getCategory(),
      'provider' => $entity->getProvider(),
      'cookies' => implode(', ', array_column($entity->getCookies(), 'name')),
      'status' => $entity->status() ? $this->t('Yes') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

}
