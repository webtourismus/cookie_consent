/**
 * @file
 * Loads the Google Maps API of the geolocation module only after consent.
 *
 * Replaces the external script of the geolocation_google_maps/google library,
 * see CookieConsentHooks::libraryInfoAlter(). Without the banner, e.g. on
 * administration pages, the API is loaded right away.
 */

((Drupal, drupalSettings) => {
  const settings = drupalSettings.cookieConsentGoogleMaps;
  if (!settings || !settings.src) {
    return;
  }
  let loaded = false;

  /**
   * Adds the Google Maps API script once.
   */
  function load() {
    if (loaded) {
      return;
    }
    loaded = true;
    let { src } = settings;
    // The library is cached for all languages, geolocation adds the language
    // of the current page with hook_js_alter(), which no longer matches.
    const lang = document.documentElement.lang;
    if (lang && /[?&]language=/.test(src)) {
      src = src.replace(/([?&]language=)[^&]*/, `$1${encodeURIComponent(lang)}`);
    }
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);
  }

  // cookie-consent.js runs the loader of a service once it is accepted.
  window.cookieConsentLoaders = window.cookieConsentLoaders || {};
  window.cookieConsentLoaders.google_maps = load;

  if (!drupalSettings.cookieConsent) {
    load();
  } else if (
    Drupal.cookieConsent &&
    Drupal.cookieConsent.acceptedService('google_maps')
  ) {
    // The banner was ready before this script ran.
    load();
  }
})(Drupal, drupalSettings);
