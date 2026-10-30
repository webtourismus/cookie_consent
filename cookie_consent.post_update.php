<?php

/**
 * @file
 * Post update functions for the cookie_consent module.
 */

declare(strict_types=1);

use Drupal\language\ConfigurableLanguageManagerInterface;

/**
 * Plays YouTube videos from youtube-nocookie.com.
 */
function cookie_consent_post_update_youtube_nocookie(): string {
  $config = \Drupal::configFactory()->getEditable('cookie_consent.settings');
  if ($config->get('youtube_nocookie') === NULL) {
    $config->set('youtube_nocookie', TRUE)->save();
  }
  return (string) t('YouTube videos are played from youtube-nocookie.com.');
}

/**
 * Opens the banner only on pages that load Google Tag Manager.
 */
function cookie_consent_post_update_require_google_tag(): string {
  $config = \Drupal::configFactory()->getEditable('cookie_consent.settings');
  if ($config->get('require_google_tag') === NULL) {
    $config->set('require_google_tag', TRUE)->save();
  }
  return (string) t('The banner opens only on pages that load Google Tag Manager.');
}

/**
 * Removes label texts from the settings, they are interface translations now.
 */
function cookie_consent_post_update_label_texts(): string {
  $keys = [
    'close',
    'service_counter',
    'privacy_link',
    'imprint_link',
    'consent_id_label',
    'consent_date_label',
    'table_name',
    'table_service',
    'table_duration',
    'table_description',
    'embed_load',
    'embed_settings',
    'embed_privacy',
    'declaration_provider',
    'declaration_purpose',
    'declaration_cookies',
    'declaration_no_cookies',
  ];
  $config = \Drupal::configFactory()->getEditable('cookie_consent.settings');
  foreach ($keys as $key) {
    $config->clear('texts.' . $key);
  }
  $config->save();

  $languageManager = \Drupal::languageManager();
  if ($languageManager instanceof ConfigurableLanguageManagerInterface) {
    foreach (array_keys($languageManager->getLanguages()) as $langcode) {
      $override = $languageManager->getLanguageConfigOverride($langcode, 'cookie_consent.settings');
      if ($override->isNew()) {
        continue;
      }
      foreach ($keys as $key) {
        $override->clear('texts.' . $key);
      }
      $override->get() === [] ? $override->delete() : $override->save();
    }
  }
  return (string) t('Labels of the banner are translated with the interface translation.');
}
