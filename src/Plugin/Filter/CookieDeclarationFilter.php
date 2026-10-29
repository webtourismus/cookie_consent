<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\cookie_consent\DeclarationBuilder;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Replaces [cookie-consent-declaration] with the cookie declaration.
 */
#[Filter(
  id: 'cookie_consent_declaration',
  title: new TranslatableMarkup('Cookie declaration'),
  type: FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
  description: new TranslatableMarkup('Replaces [cookie-consent-declaration] with the list of services and cookies. Place it after "Limit allowed HTML tags".'),
  weight: 100,
)]
class CookieDeclarationFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The token in the text.
   */
  public const TOKEN = '[cookie-consent-declaration]';

  /**
   * Constructs the filter.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\cookie_consent\DeclarationBuilder $declaration
   *   The declaration builder.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DeclarationBuilder $declaration,
    protected RendererInterface $renderer,
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
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(DeclarationBuilder::class),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    if (!str_contains($text, static::TOKEN)) {
      return new FilterProcessResult($text);
    }
    $build = $this->declaration->build();
    $markup = (string) $this->renderer->renderInIsolation($build);
    // The editor wraps the token in a paragraph, which must not contain the
    // block-level markup of the declaration.
    $text = preg_replace_callback(
      '#<p>\s*' . preg_quote(static::TOKEN, '#') . '\s*</p>|' . preg_quote(static::TOKEN, '#') . '#',
      fn(): string => $markup,
      $text,
    );
    $result = new FilterProcessResult($text ?? '');
    $result->addCacheableDependency(BubbleableMetadata::createFromRenderArray($build));
    $result->addAttachments($build['#attached'] ?? []);
    return $result;
  }

  /**
   * {@inheritdoc}
   *
   * @param bool $long
   *   Whether to return the long version of the tips.
   */
  public function tips($long = FALSE): string {
    return (string) $this->t('@token is replaced with the list of services and cookies.', ['@token' => static::TOKEN]);
  }

}
