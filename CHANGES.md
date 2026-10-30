# Changes

## 1.3.0

- Simpler settings: labels such as table headers, "Close" or "Load content"
  are interface translations now, the settings keep the wording of the banner.
  A post update removes the former label texts: run `drush updb` and
  `drush cex`.
- Removed the "Cookie declaration" block, use the text filter
  `[cookie-consent-declaration]`.
- README: basic and advanced Google Consent Mode.

## 1.2.1

- Withdrawing consent also deletes cookies on parent domains, e.g. the
  Google Analytics cookies on ".example.com" for "booking.example.com".
  Before, only cookies of the host itself and without "www." were deleted.

## 1.2.0

- The banner opens only on pages that load Google Tag Manager (setting, on by
  default, only with the google_tag module). Pages excluded in the Google Tag
  settings keep stored consent, blocked content and the cookie settings.
- Post update enables it on existing sites: run `drush updb` and `drush cex`.

## 1.1.0

- YouTube videos are played from youtube-nocookie.com (setting "Play YouTube
  videos from youtube-nocookie.com", enabled by default). Applies even while
  the banner is disabled.
- Post update enables it on existing sites: run `drush updb` and `drush cex`.

## 1.0.0

- Installed with the banner disabled, so the module can be rolled out to
  sites that still use another consent tool and enabled per site later.
- Cookie banner (vanilla-cookieconsent 3.1.0, vendored) with "Accept all" and
  "Reject all" of equal weight, granular categories and services, revision
  management and texts in config (translatable).
- Google Consent Mode v2: defaults and a stored consent are set by an inline
  script before Google Tag Manager starts. Data layer event
  `cookie_consent_update` for other tags, `cookie_consent_cmp: 'drupal'` to
  block other consent tools in a shared container.
- Proof of consent: log of consent ID, choice, revision and language without
  IP addresses, report with statistics, search and CSV export, purged after
  the retention period by cron.
- Two-click embeds: oEmbed videos (YouTube, Vimeo) and geolocation Google
  Maps are loaded only after consent. Twig filter `cookie_consent_embed` for
  other content.
- Cookie declaration as block and text filter `[cookie-consent-declaration]`.
