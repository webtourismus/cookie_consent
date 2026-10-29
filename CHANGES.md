# Changes

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
