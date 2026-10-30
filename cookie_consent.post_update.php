<?php

/**
 * @file
 * Post update functions for the cookie_consent module.
 */

declare(strict_types=1);

/**
 * Plays YouTube videos from youtube-nocookie.com.
 */
function cookie_consent_post_update_youtube_nocookie(): string {
  $config = \Drupal::configFactory()->getEditable('cookie_consent.settings');
  if ($config->get('youtube_nocookie') === NULL) {
    $config->set('youtube_nocookie', TRUE)->save(TRUE);
  }
  return (string) t('YouTube videos are played from youtube-nocookie.com.');
}

/**
 * Opens the banner only on pages that load Google Tag Manager.
 */
function cookie_consent_post_update_require_google_tag(): string {
  $config = \Drupal::configFactory()->getEditable('cookie_consent.settings');
  if ($config->get('require_google_tag') === NULL) {
    $config->set('require_google_tag', TRUE)->save(TRUE);
  }
  return (string) t('The banner opens only on pages that load Google Tag Manager.');
}
