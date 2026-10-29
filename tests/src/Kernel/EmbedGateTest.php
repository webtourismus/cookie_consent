<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Kernel;

use Drupal\Core\Render\Markup;
use Drupal\cookie_consent\EmbedGate;
use Drupal\cookie_consent\Entity\ConsentService;
use Drupal\cookie_consent\Hook\CookieConsentHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests blocking of embedded content.
 */
#[CoversClass(EmbedGate::class)]
#[Group('cookie_consent')]
#[RunTestsInSeparateProcesses]
class EmbedGateTest extends CookieConsentKernelTestBase {

  /**
   * The oEmbed iframe as rendered by the core oEmbed formatter.
   *
   * @var array<string, mixed>
   */
  protected const OEMBED_IFRAME = [
    '#type' => 'html_tag',
    '#tag' => 'iframe',
    '#attributes' => [
      'src' => '/media/oembed?url=https%3A//www.youtube.com/watch%3Fv%3DdQw4w9WgXcQ&max_width=0&max_height=0&hash=abc',
      'width' => 200,
      'height' => 113,
    ],
  ];

  /**
   * Returns the embed gate.
   */
  protected function embedGate(): EmbedGate {
    return $this->container->get(EmbedGate::class);
  }

  /**
   * Tests matching URLs to services.
   */
  #[DataProvider('providerServiceForUrl')]
  public function testServiceForUrl(string $url, ?string $expected): void {
    $this->assertSame($expected, $this->embedGate()->serviceForUrl($url)?->id());
  }

  /**
   * Data provider for testServiceForUrl().
   *
   * @return array<string, array{0: string, 1: string|null}>
   *   URL and expected service ID.
   */
  public static function providerServiceForUrl(): array {
    return [
      'youtube' => ['https://youtube.com/watch?v=1', 'youtube'],
      'youtube subdomain' => ['https://www.youtube.com/watch?v=1', 'youtube'],
      'youtube uppercase' => ['https://WWW.YouTube.com/watch?v=1', 'youtube'],
      'youtube nocookie' => ['https://www.youtube-nocookie.com/embed/1', 'youtube'],
      'short link' => ['https://youtu.be/1', 'youtube'],
      'vimeo' => ['https://player.vimeo.com/video/1', 'vimeo'],
      'maps' => ['https://maps.googleapis.com/maps/api/js', 'google_maps'],
      'suffix is not a subdomain' => ['https://notyoutube.com/watch?v=1', NULL],
      'unknown host' => ['https://example.com/video', NULL],
      'relative' => ['/media/1', NULL],
      'empty' => ['', NULL],
    ];
  }

  /**
   * Tests that disabled services are not matched.
   */
  public function testDisabledServiceIsNotMatched(): void {
    ConsentService::load('vimeo')->disable()->save();
    $this->assertNull($this->embedGate()->serviceForUrl('https://vimeo.com/1'));
  }

  /**
   * Tests reading the provider URL of the oEmbed iframe.
   */
  public function testServiceForOembed(): void {
    $this->assertSame('youtube', $this->embedGate()->serviceForOembed(static::OEMBED_IFRAME)?->id());
    $this->assertNull($this->embedGate()->serviceForOembed(['#type' => 'html_tag', '#tag' => 'iframe']));
  }

  /**
   * Tests wrapping a render array.
   */
  public function testWrap(): void {
    ConsentService::load('youtube')->set('privacy_url', 'javascript:alert(1)')->save();
    $this->setConsentSetting('texts.embed_text', 'Video of @service by @provider <b>here</b>.');
    $element = static::OEMBED_IFRAME + ['#prefix' => Markup::create('<p>before</p>')];

    $wrapped = $this->embedGate()->wrap($element, 'youtube');

    $this->assertSame(static::OEMBED_IFRAME['#attributes'], $wrapped['#attributes'], 'The iframe itself is unchanged.');
    $this->assertSame('<div class="cookie-consent-embed" data-cookie-consent-service="youtube" data-cookie-consent-category="external_media"><template><p>before</p>', (string) $wrapped['#prefix']);
    $suffix = (string) $wrapped['#suffix'];
    $this->assertStringStartsWith('</template><div class="cookie-consent-embed__placeholder">', $suffix);
    $this->assertStringEndsWith('</div>', $suffix);
    $this->assertStringContainsString('Video of YouTube by Google Ireland Limited &lt;b&gt;here&lt;/b&gt;.', $suffix);
    $this->assertStringContainsString('data-cookie-consent-load', $suffix);
    $this->assertStringContainsString('data-cc="show-preferencesModal"', $suffix);
    $this->assertStringNotContainsString('javascript:', $suffix);
    $this->assertContains('config:cookie_consent.service.youtube', $wrapped['#cache']['tags']);
    $this->assertContains('config:cookie_consent.settings', $wrapped['#cache']['tags']);

    $html = (string) $this->container->get('renderer')->renderInIsolation($wrapped);
    $this->assertMatchesRegularExpression('#^<div class="cookie-consent-embed"[^>]*><template><p>before</p><iframe src="/media/oembed\?url=[^"]+" width="200" height="113"></iframe>\s*</template><div class="cookie-consent-embed__placeholder">#', $html);
  }

  /**
   * Tests that nothing is blocked while the banner is disabled.
   */
  public function testWrapDisabled(): void {
    $this->setConsentSetting('enabled', FALSE);
    $wrapped = $this->embedGate()->wrap(static::OEMBED_IFRAME, 'youtube');
    $this->assertArrayNotHasKey('#prefix', $wrapped);
    $this->assertContains('config:cookie_consent.settings', $wrapped['#cache']['tags'], 'Enabling the banner invalidates the element.');
  }

  /**
   * Tests that disabled and unknown services are not blocked.
   */
  public function testWrapUnknownService(): void {
    ConsentService::load('vimeo')->disable()->save();
    $this->assertArrayNotHasKey('#prefix', $this->embedGate()->wrap(static::OEMBED_IFRAME, 'vimeo'));
    $this->assertArrayNotHasKey('#prefix', $this->embedGate()->wrap(static::OEMBED_IFRAME, 'unknown'));
  }

  /**
   * Tests the field preprocess hook for the oEmbed and geolocation formatters.
   */
  public function testPreprocessField(): void {
    $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(CookieConsentHooks::class);
    $unknown = static::OEMBED_IFRAME;
    $unknown['#attributes']['src'] = '/media/oembed?url=https%3A//example.com/video';

    $variables = [
      'element' => ['#formatter' => 'oembed'],
      'items' => [
        ['content' => static::OEMBED_IFRAME],
        ['content' => $unknown],
      ],
    ];
    $hooks->preprocessField($variables);
    $this->assertStringContainsString('data-cookie-consent-service="youtube"', (string) $variables['items'][0]['content']['#prefix']);
    $this->assertArrayNotHasKey('#prefix', $variables['items'][1]['content'], 'Unknown providers are not blocked.');

    $variables = [
      'element' => ['#formatter' => 'geolocation_map'],
      'items' => [['content' => ['#type' => 'container']]],
    ];
    $hooks->preprocessField($variables);
    $this->assertStringContainsString('data-cookie-consent-service="google_maps"', (string) $variables['items'][0]['content']['#prefix']);

    $variables = [
      'element' => ['#formatter' => 'string'],
      'items' => [['content' => static::OEMBED_IFRAME]],
    ];
    $hooks->preprocessField($variables);
    $this->assertArrayNotHasKey('#prefix', $variables['items'][0]['content']);
  }

  /**
   * Tests that the external Google Maps API script is replaced by a loader.
   */
  public function testGoogleMapsLibrary(): void {
    $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(CookieConsentHooks::class);
    $url = 'https://maps.googleapis.com/maps/api/js?key=abc&callback=Drupal.geolocation.google.load';
    $libraries = [
      'google' => [
        'js' => [$url => ['type' => 'external', 'attributes' => ['async' => 'async']]],
        'dependencies' => ['geolocation_google_maps/googlemapsapi'],
      ],
    ];

    $unchanged = $libraries;
    $hooks->libraryInfoAlter($unchanged, 'geolocation');
    $this->assertSame($libraries, $unchanged, 'Other extensions are not changed.');

    $hooks->libraryInfoAlter($libraries, 'geolocation_google_maps');
    $this->assertSame(['/' . $this->container->get('extension.list.module')->getPath('cookie_consent') . '/js/cookie-consent.google-maps.js'], array_keys($libraries['google']['js']));
    $this->assertSame($url, $libraries['google']['drupalSettings']['cookieConsentGoogleMaps']['src']);
    $this->assertContains('geolocation_google_maps/googlemapsapi', $libraries['google']['dependencies']);
    $this->assertContains('core/drupalSettings', $libraries['google']['dependencies']);

    $altered = $libraries;
    $hooks->libraryInfoAlter($altered, 'geolocation_google_maps');
    $this->assertSame($libraries, $altered, 'Altering twice changes nothing.');
  }

  /**
   * Tests the Twig filter.
   */
  public function testTwigFilter(): void {
    $twig = $this->container->get('twig');
    $render = function (string $template, array $context): string {
      $build = [
        '#type' => 'inline_template',
        '#template' => $template,
        '#context' => $context,
      ];
      return (string) $this->container->get('renderer')->renderInIsolation($build);
    };

    $html = $render("{{ widget|cookie_consent_embed('google_maps') }}", ['widget' => Markup::create('<iframe src="https://maps.google.com/x"></iframe>')]);
    $this->assertStringContainsString('<template><iframe src="https://maps.google.com/x"></iframe></template>', $html);

    $html = $render("{{ widget|cookie_consent_embed('google_maps') }}", ['widget' => '<script>alert(1)</script>']);
    $this->assertStringContainsString('<template>&lt;script&gt;', $html, 'Plain strings are escaped.');

    $html = $render("{{ widget|cookie_consent_embed('google_maps') }}", ['widget' => ['#markup' => Markup::create('<b>map</b>')]]);
    $this->assertStringContainsString('<template><b>map</b></template>', $html);

    $this->assertNotNull($twig->getFilter('cookie_consent_embed'));
  }

}
