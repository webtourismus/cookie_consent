<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Kernel;

use Drupal\cookie_consent\Hook\CookieConsentHooks;
use Drupal\media\IFrameMarkup;
use Drupal\media\OEmbed\Provider;
use Drupal\media\OEmbed\Resource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that YouTube videos are played from youtube-nocookie.com.
 */
#[Group('cookie_consent')]
#[RunTestsInSeparateProcesses]
class YoutubeNocookieTest extends CookieConsentKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'file', 'image', 'media'];

  /**
   * The oEmbed HTML returned by YouTube.
   */
  protected const YOUTUBE_HTML = '<iframe width="200" height="113" src="https://www.youtube.com/embed/aqz-KE-bpKQ?feature=oembed" title="Big Buck Bunny"></iframe>';

  /**
   * Runs the preprocess hook for an oEmbed resource.
   *
   * @return array<string, mixed>
   *   The template variables.
   */
  protected function preprocess(string $providerName, string $html): array {
    $provider = new Provider($providerName, 'https://example.com', [['url' => 'https://example.com/oembed']]);
    $variables = [
      'resource' => Resource::rich($html, 200, 113, $provider),
      'media' => IFrameMarkup::create($html),
    ];
    $this->container->get('class_resolver')
      ->getInstanceFromDefinition(CookieConsentHooks::class)
      ->preprocessMediaOembedIframe($variables);
    return $variables;
  }

  /**
   * Tests the rewrite, also while the banner is disabled.
   */
  public function testRewrite(): void {
    $this->setConsentSetting('enabled', FALSE);
    $variables = $this->preprocess('YouTube', static::YOUTUBE_HTML);

    $this->assertInstanceOf(IFrameMarkup::class, $variables['media']);
    $this->assertSame(
      '<iframe width="200" height="113" src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?feature=oembed" title="Big Buck Bunny"></iframe>',
      (string) $variables['media'],
    );
    $this->assertContains('config:cookie_consent.settings', $variables['#cache']['tags']);

    $twice = $this->preprocess('YouTube', (string) $variables['media']);
    $this->assertSame((string) $variables['media'], (string) $twice['media'], 'Rewriting twice changes nothing.');
  }

  /**
   * Tests that the rewrite can be disabled and ignores other providers.
   */
  public function testNoRewrite(): void {
    $vimeo = '<iframe src="https://player.vimeo.com/video/1?youtube.com/embed/"></iframe>';
    $this->assertSame($vimeo, (string) $this->preprocess('Vimeo', $vimeo)['media']);

    $this->setConsentSetting('youtube_nocookie', FALSE);
    $variables = $this->preprocess('YouTube', static::YOUTUBE_HTML);
    $this->assertSame(static::YOUTUBE_HTML, (string) $variables['media']);
    $this->assertContains('config:cookie_consent.settings', $variables['#cache']['tags'], 'Enabling the setting invalidates the iframe.');
  }

}
