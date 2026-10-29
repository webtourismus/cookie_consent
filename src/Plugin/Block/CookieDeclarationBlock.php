<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\cookie_consent\DeclarationBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the services and cookies, for the privacy policy.
 */
#[Block(
  id: 'cookie_consent_declaration',
  admin_label: new TranslatableMarkup('Cookie declaration'),
  category: new TranslatableMarkup('Cookie consent'),
)]
class CookieDeclarationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\cookie_consent\DeclarationBuilder $declaration
   *   The declaration builder.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DeclarationBuilder $declaration,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    // @phpstan-ignore new.static
    return new static($configuration, $plugin_id, $plugin_definition, $container->get(DeclarationBuilder::class));
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function build(): array {
    return $this->declaration->build();
  }

}
