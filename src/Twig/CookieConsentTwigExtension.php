<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Twig;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\cookie_consent\EmbedGate;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Provides the Twig filter cookie_consent_embed.
 *
 * Usage in templates:
 * {{ content.field_map|cookie_consent_embed('google_maps') }}
 */
class CookieConsentTwigExtension extends AbstractExtension {

  public function __construct(
    protected EmbedGate $embedGate,
    protected RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return \Twig\TwigFilter[]
   *   The filters.
   */
  public function getFilters(): array {
    return [
      new TwigFilter('cookie_consent_embed', [$this, 'embed']),
    ];
  }

  /**
   * Blocks content until the visitor accepts a service.
   *
   * @param mixed $content
   *   A render array, safe markup or plain text, which is escaped.
   * @param string $service
   *   The machine name of the service.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The rendered content inside the placeholder.
   */
  public function embed(mixed $content, string $service): MarkupInterface|string {
    if (!is_array($content)) {
      $content = $content instanceof MarkupInterface ? ['#markup' => $content] : ['#plain_text' => (string) $content];
    }
    $element = $this->embedGate->wrap($content, $service);
    return $this->renderer->render($element);
  }

}
