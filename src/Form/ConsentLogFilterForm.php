<?php

declare(strict_types=1);

namespace Drupal\cookie_consent\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Filters the consent log by consent ID.
 */
class ConsentLogFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cookie_consent_log_filter';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $consent_id
   *   The current filter.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $consent_id = ''): array {
    $form['filter'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline', 'clearfix']],
    ];
    $form['filter']['consent_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Consent ID'),
      '#description' => $this->t('Visitors find their consent ID in the cookie settings of the website.'),
      '#default_value' => $consent_id,
      '#size' => 40,
      '#maxlength' => 36,
    ];
    $form['filter']['actions'] = ['#type' => 'actions'];
    $form['filter']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];
    if ($consent_id !== '') {
      $form['filter']['actions']['reset'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reset'),
        '#submit' => ['::resetForm'],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $consentId = trim((string) $form_state->getValue('consent_id'));
    $form_state->setRedirect('cookie_consent.report', [], $consentId !== '' ? ['query' => ['consent_id' => $consentId]] : []);
  }

  /**
   * Removes the filter.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function resetForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('cookie_consent.report');
  }

}
