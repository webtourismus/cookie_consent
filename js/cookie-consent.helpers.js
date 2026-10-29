/**
 * @file
 * Pure helper functions of the cookie banner, tested with node --test.
 */

((root) => {
  /**
   * Converts a cookie name with "*" wildcards to a regular expression.
   *
   * @param {string} pattern
   *   The cookie name, e.g. "_ga_*".
   *
   * @return {string|RegExp}
   *   The name itself without wildcard, otherwise a regular expression.
   */
  function cookiePattern(pattern) {
    if (pattern.indexOf('*') === -1) {
      return pattern;
    }
    const escaped = pattern
      .split('*')
      .map((part) => part.replace(/[.+?^${}()|[\]\\]/g, '\\$&'))
      .join('.*');
    return new RegExp(`^${escaped}$`);
  }

  /**
   * Whether the banner must not open on its own on this page.
   *
   * Visitors have to be able to read the privacy policy and the imprint
   * without the banner covering them.
   *
   * @param {string} pathname
   *   The path of the current page.
   * @param {string[]} legalPaths
   *   The paths of the privacy policy and the imprint.
   *
   * @return {boolean}
   *   TRUE on the privacy policy and the imprint.
   */
  function isLegalPage(pathname, legalPaths) {
    const normalize = (path) => path.replace(/\/+$/, '') || '/';
    return legalPaths.map(normalize).indexOf(normalize(pathname)) !== -1;
  }

  /**
   * Returns the services of a category with one more accepted service.
   *
   * @param {object} preferences
   *   The result of CookieConsent.getUserPreferences().
   * @param {string} category
   *   The category of the service.
   * @param {string} service
   *   The service to accept.
   *
   * @return {string[]}
   *   The accepted services of the category.
   */
  function withService(preferences, category, service) {
    const accepted = (preferences.acceptedServices || {})[category] || [];
    return accepted.indexOf(service) === -1
      ? accepted.concat([service])
      : accepted;
  }

  /**
   * Returns the body of the consent log request.
   *
   * @param {object} cookie
   *   The consent cookie value.
   * @param {object} preferences
   *   The result of CookieConsent.getUserPreferences().
   * @param {string} langcode
   *   The language of the page.
   *
   * @return {object}
   *   The payload, see \Drupal\cookie_consent\ConsentLog::validate().
   */
  function logPayload(cookie, preferences, langcode) {
    return {
      consentId: cookie.consentId,
      revision: cookie.revision,
      acceptType: preferences.acceptType,
      categories: cookie.categories,
      services: cookie.services || {},
      langcode,
    };
  }

  /**
   * Builds the configuration of CookieConsent.run().
   *
   * @param {object} settings
   *   drupalSettings.cookieConsent.
   * @param {object} callbacks
   *   The callbacks onFirstConsent, onConsent, onChange and onModalShow.
   * @param {string} pathname
   *   The path of the current page.
   *
   * @return {object}
   *   The configuration.
   */
  function libraryConfig(settings, callbacks, pathname) {
    const categories = {};
    Object.keys(settings.categories).forEach((id) => {
      const category = settings.categories[id];
      const services = {};
      let cookies = [];
      Object.keys(category.services).forEach((serviceId) => {
        const service = category.services[serviceId];
        const serviceCookies = service.cookies.map((name) => ({
          name: cookiePattern(name),
        }));
        services[serviceId] = {
          label: service.label,
          cookies: serviceCookies,
        };
        cookies = cookies.concat(serviceCookies);
      });
      categories[id] = {
        enabled: category.required,
        readOnly: category.required,
        services,
        autoClear: { cookies },
      };
    });

    return {
      revision: settings.revision,
      autoShow: !isLegalPage(pathname, settings.legalPaths),
      hideFromBots: true,
      manageScriptTags: true,
      autoClearCookies: true,
      cookie: {
        name: settings.bootstrap.cookieName,
        expiresAfterDays: settings.expiryDays,
        sameSite: 'Lax',
      },
      guiOptions: {
        consentModal: {
          layout: settings.layout,
          position: settings.position,
          equalWeightButtons: true,
          flipButtons: false,
        },
        preferencesModal: {
          layout: 'box',
          equalWeightButtons: true,
          flipButtons: false,
        },
      },
      categories,
      language: {
        default: settings.langcode,
        translations: { [settings.langcode]: settings.translation },
      },
      ...callbacks,
    };
  }

  const helpers = {
    cookiePattern,
    isLegalPage,
    withService,
    logPayload,
    libraryConfig,
  };
  if (typeof module === 'object' && module.exports) {
    module.exports = helpers;
  } else {
    root.cookieConsentHelpers = helpers;
  }
})(typeof window === 'undefined' ? globalThis : window);
