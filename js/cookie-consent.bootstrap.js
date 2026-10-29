/**
 * @file
 * Google Consent Mode defaults, inlined as the first script in <head>.
 *
 * Runs before Google Tag Manager, drupalSettings and the banner library, so
 * it has no dependencies. With a stored consent it sends the consent update
 * right away, Google tags never see the page without the visitor's choice.
 * The helper functions are reused by cookie-consent.js.
 *
 * Configuration: see \Drupal\cookie_consent\ConsentSettings::bootstrapConfig().
 */

/**
 * Sets the consent defaults and applies a stored consent.
 *
 * @param {object} config
 *   The configuration.
 * @param {Window} win
 *   The window.
 * @param {Document} doc
 *   The document.
 *
 * @return {object|null}
 *   The stored consent, or null if there is no valid consent.
 */
function cookieConsentBootstrap(config, win, doc) {
  const helpers = cookieConsentBootstrap;
  const gtag = helpers.gtag(config, win);

  gtag('consent', 'default', {
    ...helpers.signalStates(config, []),
    wait_for_update: config.waitForUpdate,
  });
  if (config.adsDataRedaction) {
    gtag('set', 'ads_data_redaction', true);
  }
  if (config.urlPassthrough) {
    gtag('set', 'url_passthrough', true);
  }
  // Lets the shared GTM container block other consent tools on this site.
  helpers.dataLayer(config, win).push({ cookie_consent_cmp: 'drupal' });

  const consent = helpers.readConsent(config, doc.cookie, Date.now());
  if (consent) {
    helpers.applyConsent(config, win, consent);
  }
  return consent;
}

/**
 * Returns the data layer, created if missing.
 *
 * @param {object} config
 *   The configuration.
 * @param {Window} win
 *   The window.
 *
 * @return {Array}
 *   The data layer.
 */
cookieConsentBootstrap.dataLayer = function (config, win) {
  win[config.dataLayer] = win[config.dataLayer] || [];
  return win[config.dataLayer];
};

/**
 * Returns a gtag() function that writes to the configured data layer.
 *
 * @param {object} config
 *   The configuration.
 * @param {Window} win
 *   The window.
 *
 * @return {function}
 *   The gtag() function.
 */
cookieConsentBootstrap.gtag = function (config, win) {
  const dataLayer = cookieConsentBootstrap.dataLayer(config, win);
  return function gtag() {
    // Google tags only process the arguments object, not an array.
    // eslint-disable-next-line prefer-rest-params
    dataLayer.push(arguments);
  };
};

/**
 * Reads a valid consent from the consent cookie.
 *
 * Uses the same rules as the banner library: a consent ID, the categories,
 * both timestamps and the current revision.
 *
 * @param {object} config
 *   The configuration.
 * @param {string} cookies
 *   The document.cookie string.
 * @param {number} now
 *   The current time in milliseconds.
 *
 * @return {object|null}
 *   The consent, or null if there is no valid consent.
 */
cookieConsentBootstrap.readConsent = function (config, cookies, now) {
  const prefix = `${config.cookieName}=`;
  const cookie = String(cookies || '')
    .split(';')
    .map((part) => part.trim())
    .find((part) => part.indexOf(prefix) === 0);
  if (!cookie) {
    return null;
  }
  let consent;
  try {
    consent = JSON.parse(decodeURIComponent(cookie.substring(prefix.length)));
  } catch (e) {
    return null;
  }
  if (
    !consent ||
    typeof consent.consentId !== 'string' ||
    consent.consentId === '' ||
    !Array.isArray(consent.categories) ||
    !consent.consentTimestamp ||
    !consent.lastConsentTimestamp ||
    consent.revision !== config.revision ||
    (consent.expirationTime && consent.expirationTime < now)
  ) {
    return null;
  }
  return consent;
};

/**
 * Returns the Google Consent Mode state of all configured signals.
 *
 * A signal is granted if a required or an accepted category grants it.
 *
 * @param {object} config
 *   The configuration.
 * @param {string[]} accepted
 *   The accepted categories.
 *
 * @return {object}
 *   The state ('granted' or 'denied') keyed by signal.
 */
cookieConsentBootstrap.signalStates = function (config, accepted) {
  const states = {};
  Object.keys(config.signals).forEach((category) => {
    const granted =
      config.required.indexOf(category) !== -1 ||
      accepted.indexOf(category) !== -1;
    config.signals[category].forEach((signal) => {
      states[signal] =
        granted || states[signal] === 'granted' ? 'granted' : 'denied';
    });
  });
  return states;
};

/**
 * Returns the data layer event of a consent.
 *
 * Contains a flag for every category and service, so GTM's recursive merge
 * of the data layer never keeps outdated values.
 *
 * @param {object} config
 *   The configuration.
 * @param {object} consent
 *   The consent from the cookie.
 *
 * @return {object}
 *   The data layer event.
 */
cookieConsentBootstrap.consentEvent = function (config, consent) {
  const state = { services: {} };
  Object.keys(config.services).forEach((category) => {
    state[category] =
      config.required.indexOf(category) !== -1 ||
      consent.categories.indexOf(category) !== -1;
    const acceptedServices = (consent.services || {})[category] || [];
    config.services[category].forEach((service) => {
      state.services[service] =
        config.required.indexOf(category) !== -1 ||
        acceptedServices.indexOf(service) !== -1;
    });
  });
  return { event: config.event, cookie_consent: state };
};

/**
 * Sends the consent update to Google tags and the data layer event.
 *
 * @param {object} config
 *   The configuration.
 * @param {Window} win
 *   The window.
 * @param {object} consent
 *   The consent from the cookie.
 */
cookieConsentBootstrap.applyConsent = function (config, win, consent) {
  const helpers = cookieConsentBootstrap;
  helpers.gtag(
    config,
    win,
  )('consent', 'update', helpers.signalStates(config, consent.categories));
  helpers.dataLayer(config, win).push(helpers.consentEvent(config, consent));
};

if (typeof module === 'object' && module.exports) {
  module.exports = cookieConsentBootstrap;
}
