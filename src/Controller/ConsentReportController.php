<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\cookie_consent\ConsentLog;
use Drupal\cookie_consent\Form\ConsentLogFilterForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shows and exports the consent log.
 *
 * Dependencies are autowired.
 *
 * @see \Drupal\Core\DependencyInjection\AutowireTrait
 */
class ConsentReportController extends ControllerBase {

  /**
   * Entries per page.
   */
  protected const PER_PAGE = 50;

  /**
   * Days covered by the statistics.
   */
  protected const STATISTICS_DAYS = 30;

  public function __construct(
    protected ConsentLog $consentLog,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {}

  /**
   * Shows the statistics and the log entries.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function report(Request $request): array {
    $consentId = trim((string) $request->query->get('consent_id', ''));
    $labels = $this->acceptTypeLabels();

    $counts = $this->consentLog->statistics($this->time->getRequestTime() - static::STATISTICS_DAYS * 86400);
    $total = array_sum($counts);
    $statisticsRows = [];
    foreach ($counts as $type => $count) {
      $statisticsRows[] = [
        $labels[$type],
        $count,
        $total > 0 ? round($count / $total * 100) . ' %' : '–',
      ];
    }

    $build['statistics'] = [
      '#type' => 'table',
      '#caption' => $this->t('Consents of the last @days days', ['@days' => static::STATISTICS_DAYS]),
      '#header' => [$this->t('Choice'), $this->t('Consents'), $this->t('Share')],
      '#rows' => $statisticsRows,
    ];
    $build['filter'] = $this->formBuilder()->getForm(ConsentLogFilterForm::class, $consentId);

    /** @var \Drupal\Core\Database\Query\PagerSelectExtender $query */
    $query = $this->consentLog->select($consentId)->extend(PagerSelectExtender::class);
    $rows = [];
    foreach ($query->limit(static::PER_PAGE)->execute() as $entry) {
      $rows[] = [
        $this->dateFormatter->format((int) $entry->created, 'short'),
        $entry->consent_id,
        $labels[$entry->accept_type] ?? $entry->accept_type,
        str_replace(',', ', ', $entry->categories),
        $entry->revision,
        $entry->langcode,
      ];
    }
    $build['log'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('Consent ID'),
        $this->t('Choice'),
        $this->t('Accepted categories'),
        $this->t('Revision'),
        $this->t('Language'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No consents logged yet.'),
    ];
    $build['pager'] = ['#type' => 'pager'];
    $build['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Export as CSV'),
      '#url' => Url::fromRoute('cookie_consent.report_export', [], $consentId !== '' ? ['query' => ['consent_id' => $consentId]] : []),
      '#attributes' => ['class' => ['button']],
    ];
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * Exports the log entries as CSV.
   */
  public function export(Request $request): StreamedResponse {
    $query = $this->consentLog->select(trim((string) $request->query->get('consent_id', '')));
    $response = new StreamedResponse(function () use ($query): void {
      $handle = fopen('php://output', 'w');
      if ($handle === FALSE) {
        return;
      }
      fputcsv($handle, ['created', 'consent_id', 'revision', 'accept_type', 'categories', 'services', 'langcode'], escape: '');
      foreach ($query->execute() ?? [] as $entry) {
        fputcsv($handle, [
          gmdate('c', (int) $entry->created),
          $entry->consent_id,
          $entry->revision,
          $entry->accept_type,
          $entry->categories,
          $entry->services,
          $entry->langcode,
        ], escape: '');
      }
      fclose($handle);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="cookie-consent-log-' . gmdate('Y-m-d', $this->time->getRequestTime()) . '.csv"');
    return $response;
  }

  /**
   * Returns the labels of the accept types.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels keyed by accept type.
   */
  protected function acceptTypeLabels(): array {
    return [
      'all' => new TranslatableMarkup('Accept all'),
      'necessary' => new TranslatableMarkup('Only necessary'),
      'custom' => new TranslatableMarkup('Custom selection'),
    ];
  }

}
