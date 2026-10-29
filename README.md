# Cookie consent

Cookie banner for Drupal websites with Google Tag Manager:

- Banner with "Accept all" / "Reject all" of equal weight, categories and
  single services, texts translatable in config.
- Google Consent Mode v2 for Google Tag Manager, set before GTM starts.
- Proof of consent: log with consent ID, choice and date, no IP addresses.
- Two-click embeds: YouTube/Vimeo (oEmbed) and Google Maps (geolocation) are
  loaded only after consent.
- Cookie declaration for the privacy policy, generated from the services.

The banner is [vanilla-cookieconsent](https://github.com/orestbida/cookieconsent)
3.1.0 (MIT), vendored in `js/vendor/cookieconsent`.

## Installation

```bash
ddev composer require webtourismus/cookie_consent
ddev drush en cookie_consent -y
ddev drush locale:import de modules/contrib/cookie_consent/translations/cookie_consent.de.po
```

The module is installed **disabled**: no banner, no consent signals, no
blocked embeds. Install it on sites that still use another consent tool and switch
over later, site by site.

To switch a site over:

1. **Settings** (`/admin/config/system/cookie-consent`): set the paths of the
   privacy policy and the imprint. The banner links them and does not open on
   its own there.
2. **Services** tab: enable the services used on this website (Meta Pixel is
   disabled by default), add others, e.g. a booking widget.
3. **Theme**: the "Cookies" link in the footer opens the settings with
   `data-cc="show-preferencesModal"`:
   ```twig
   <button type="button" data-cc="show-preferencesModal">Cookies</button>
   ```
4. **Privacy policy**: place the "Cookie declaration" block, or enable the text
   filter "Cookie declaration" (after "Limit allowed HTML tags") and write
   `[cookie-consent-declaration]` into the text.
5. **GTM**: block the previous consent tool for the site, see below.
6. Settings: tick "Show the cookie banner and send consent signals".
7. `ddev drush cex`

## Google Tag Manager

The module does not load GTM, the `google_tag` module does. An inline script
runs as the first script in `<head>`:

1. `gtag('consent', 'default', …)`: all signals `denied`, except
   `security_storage`, with `wait_for_update`.
2. Optional `ads_data_redaction` and `url_passthrough`.
3. `dataLayer.push({cookie_consent_cmp: 'drupal'})`.
4. With a stored consent: `gtag('consent', 'update', …)` and the event below,
   still before GTM starts.

| Category | Consent Mode signals |
|---|---|
| necessary (always) | security_storage |
| functional | functionality_storage, personalization_storage |
| analytics | analytics_storage |
| marketing | ad_storage, ad_user_data, ad_personalization |
| external_media | none, embeds are blocked by the module |

On every change in the banner, and on page load with a stored consent:

```js
dataLayer.push({
  event: 'cookie_consent_update',
  cookie_consent: {
    necessary: true, functional: false, analytics: true, marketing: false,
    external_media: false,
    services: {google_analytics: true, google_ads: false, youtube: false, …},
  },
});
```

### Container setup

One container can serve several sites, with and without this module.

1. **Variables** (Data Layer Variable, version 2):
   `cookie_consent_cmp`, `cookie_consent.analytics`,
   `cookie_consent.marketing`, `cookie_consent.services.meta_pixel`, …
2. **Another consent tool** loaded by GTM: add a blocking trigger "Consent
   Initialization – All Pages" with condition `cookie_consent_cmp` equals
   `drupal` to its tag. Sites with this module then run without it, the
   others keep it until they are switched over.
3. **Google tags** (GA4, Google Ads, Conversion Linker): keep "Built-in
   consent checks", nothing else to do. They read the Consent Mode signals.
4. **Other tags** (Meta Pixel, Microsoft UET, Hotjar, …):
   - Consent settings: "Require additional consent", e.g. `ad_storage`.
   - Trigger: Custom Event `cookie_consent_update` with condition
     `cookie_consent.marketing` equals `true` (or the service flag).
   - Tag firing option "Once per page".
   The event fires on page load if consent is stored and right after the
   visitor's choice, so the tag runs exactly once in both cases.
5. Check in GTM Preview / Tag Assistant: "Consent" tab shows `default`
   before `gtm.js` and `update` after the choice.

## Embeds

Blocked content is rendered in a `<template>` next to a placeholder. "Load
content" adds the service to the stored consent and loads all embeds of the
service. Without a stored consent, only this embed is loaded and the banner
stays open.

- **oEmbed videos**: the service is found by the host of the video URL
  (service field "Hosts of embedded content").
- **geolocation maps** (`geolocation_map` formatter): service `google_maps`.
  The Google Maps API script of the geolocation module is replaced by a
  loader that adds it only after the visitor accepts `google_maps`, also for
  maps of other formatters or Views. Without the banner (administration
  pages) it loads right away.
- **Other markup** in templates:
  ```twig
  {{ content.field_booking_widget|cookie_consent_embed('seekda') }}
  ```
- **Scripts** in templates or the html_head region:
  ```html
  <script type="text/plain" data-category="marketing"
    data-service="meta_pixel">…</script>
  ```

Disabled or unknown services are not blocked.

Styling: custom properties `--cookie-consent-embed-*` in
`css/cookie-consent.css`, banner colors with the
[`--cc-*` properties](https://cookieconsent.orestbida.com/advanced/ui-customization.html).

## Consent log

`/admin/reports/cookie-consent` (permission "View the cookie consent log"):
statistics of the last 30 days, search by consent ID, CSV export. Visitors
find their consent ID in the cookie settings. Entries are deleted by cron after
the retention period (default 3 years).

The banner sends each choice to `POST /cookie-consent/log` (30 requests per
hour and IP address).

## Changing texts or services

Raise the **revision** in the settings after adding services or changing
purposes: all visitors are asked again and see the revision message.

## JavaScript API

```js
Drupal.cookieConsent.show();
Drupal.cookieConsent.acceptedCategory('analytics');
Drupal.cookieConsent.acceptedService('youtube');

// Run code once a service is accepted (on page load or later):
window.cookieConsentLoaders = window.cookieConsentLoaders || {};
window.cookieConsentLoaders.seekda = () => { /* load the widget */ };
```

A loader runs every time consent for its service is applied, make it
idempotent. See `js/cookie-consent.google-maps.js`.

## Development

```bash
# PHP (in a site with drupal/core-dev, module in web/modules/contrib)
ddev exec "SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite \
  SIMPLETEST_BASE_URL=http://localhost \
  vendor/bin/phpunit -c web/core web/modules/contrib/cookie_consent"
# JavaScript unit tests, no dependencies
node --test "tests/js/*.test.js"
# End-to-end against a DDEV site, uses the installed Google Chrome
npm install
E2E_BASE_URL=https://example.ddev.site E2E_VIDEO_PATH=/videos \
  npm run test:e2e
```
