<?php

declare(strict_types=1);

namespace Drupal\Tests\cookie_consent\Kernel;

use Drupal\cookie_consent\DeclarationBuilder;
use Drupal\cookie_consent\Entity\ConsentService;
use Drupal\filter\FilterPluginCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the cookie declaration and its text filter.
 */
#[CoversClass(DeclarationBuilder::class)]
#[Group('cookie_consent')]
#[RunTestsInSeparateProcesses]
class DeclarationTest extends CookieConsentKernelTestBase {

  /**
   * Renders a render array.
   *
   * @param array<string, mixed> $build
   *   The render array.
   */
  protected function renderBuild(array $build): string {
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Tests the declaration.
   */
  public function testDeclaration(): void {
    ConsentService::load('google_ads')->set('purpose', 'Ads <script>alert(1)</script>')->save();
    $build = $this->container->get(DeclarationBuilder::class)->build();
    $html = $this->renderBuild($build);

    $this->assertStringContainsString('<h3>Statistik</h3>', $html);
    $this->assertStringContainsString('<h4>Google Analytics</h4>', $html);
    $this->assertStringContainsString('<code>_ga_*</code>', $html);
    $this->assertStringContainsString('<td>2 Jahre</td>', $html);
    $this->assertStringContainsString('href="https://policies.google.com/privacy"', $html);
    $this->assertStringContainsString('Ads &lt;script&gt;', $html);
    $this->assertStringContainsString('Sets no cookies.', $html, 'Google Tag Manager sets no cookies.');
    $this->assertStringContainsString('data-cc="show-preferencesModal"', $html);
    $this->assertStringNotContainsString('Meta Pixel', $html, 'Disabled services are not listed.');
    $this->assertStringNotContainsString('Funktional', $html, 'Categories without services are not listed.');
    $this->assertContains('config:cookie_consent_service_list', $build['#cache']['tags']);
  }

  /**
   * Tests the text filter.
   */
  public function testFilter(): void {
    $filters = new FilterPluginCollection($this->container->get('plugin.manager.filter'), [
      'cookie_consent_declaration' => ['status' => TRUE],
    ]);
    $filter = $filters->get('cookie_consent_declaration');

    $result = $filter->process('<p>Intro</p><p>[cookie-consent-declaration]</p><p>End</p>', 'en');
    $text = (string) $result->getProcessedText();
    $this->assertStringStartsWith('<p>Intro</p><div class="cookie-consent-declaration">', $text);
    $this->assertMatchesRegularExpression('#</div>\s*<p>End</p>$#', $text);
    $this->assertContains('config:cookie_consent.settings', $result->getCacheTags());

    $this->assertSame('No token $1 here', (string) $filter->process('No token $1 here', 'en')->getProcessedText());
    $inline = (string) $filter->process('Text [cookie-consent-declaration] text', 'en')->getProcessedText();
    $this->assertStringContainsString('cookie-consent-declaration__category', $inline);
  }

}
