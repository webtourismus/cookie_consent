/**
 * @file
 * Cookie banner: runs the banner library, sends consent changes to Google
 * tags and the data layer, logs them and reveals blocked embeds.
 *
 * The consent defaults and a stored consent are already applied by the
 * inline cookie-consent.bootstrap.js before Google Tag Manager starts.
 */

((Drupal, drupalSettings, once, CookieConsent, bootstrap, helpers) => {
  const settings = drupalSettings.cookieConsent;
  if (!settings || !CookieConsent || !bootstrap || !helpers) {
    return;
  }
  const config = settings.bootstrap;
  let ready = false;

  /**
   * Sends the consent to Google tags and the data layer.
   *
   * @param {object} cookie
   *   The consent cookie value.
   */
  function applyConsent(cookie) {
    bootstrap.applyConsent(config, window, cookie);
  }

  /**
   * Stores the consent in the consent log as proof.
   *
   * @param {object} cookie
   *   The consent cookie value.
   */
  function logConsent(cookie) {
    const payload = helpers.logPayload(
      cookie,
      CookieConsent.getUserPreferences(),
      settings.langcode,
    );
    // keepalive delivers the request even if the visitor leaves the page.
    fetch(settings.logUrl, {
      method: 'POST',
      keepalive: true,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).catch(() => {});
  }

  /**
   * Returns the category of a service.
   *
   * @param {string} service
   *   The service.
   *
   * @return {string|undefined}
   *   The category, undefined for unknown services.
   */
  function categoryOf(service) {
    return Object.keys(settings.categories).find(
      (category) => service in settings.categories[category].services,
    );
  }

  /**
   * Whether the visitor accepted a service.
   *
   * @param {string} service
   *   The service.
   * @param {string} [category]
   *   The category, looked up if omitted.
   *
   * @return {boolean}
   *   TRUE if the service is accepted.
   */
  function acceptedService(service, category) {
    return (
      ready &&
      CookieConsent.validConsent() &&
      CookieConsent.acceptedService(service, category || categoryOf(service))
    );
  }

  /**
   * Runs the script loader of a service, e.g. the Google Maps API.
   *
   * Scripts register loaders in window.cookieConsentLoaders.
   *
   * @param {string} service
   *   The service.
   */
  function runLoader(service) {
    const loaders = window.cookieConsentLoaders || {};
    if (typeof loaders[service] === 'function') {
      loaders[service]();
    }
  }

  /**
   * Runs the script loaders of all accepted services.
   */
  function runAcceptedLoaders() {
    Object.keys(window.cookieConsentLoaders || {}).forEach((service) => {
      if (acceptedService(service)) {
        runLoader(service);
      }
    });
  }

  /**
   * Replaces a blocked embed with its original content.
   *
   * @param {HTMLElement} wrapper
   *   The element with the data-cookie-consent-service attribute.
   */
  function reveal(wrapper) {
    const template = wrapper.querySelector(':scope > template');
    if (!template) {
      return;
    }
    runLoader(wrapper.dataset.cookieConsentService);
    wrapper.replaceChildren(template.content.cloneNode(true));
    wrapper.classList.add('cookie-consent-embed--loaded');
    // E.g. geolocation maps are initialized by a behavior.
    Drupal.attachBehaviors(wrapper, drupalSettings);
  }

  /**
   * Reveals all blocked embeds of accepted services.
   *
   * @param {Element|Document} context
   *   The context to search in.
   */
  function revealAccepted(context) {
    context
      .querySelectorAll('[data-cookie-consent-service]')
      .forEach((wrapper) => {
        if (
          acceptedService(
            wrapper.dataset.cookieConsentService,
            wrapper.dataset.cookieConsentCategory,
          )
        ) {
          reveal(wrapper);
        }
      });
  }

  /**
   * Loads a blocked embed on request of the visitor.
   *
   * With a stored consent, the service is added to it. Without one, only
   * this embed is loaded: accepting a single service must not count as an
   * answer to the banner.
   *
   * @param {HTMLElement} wrapper
   *   The element with the data-cookie-consent-service attribute.
   */
  function load(wrapper) {
    const service = wrapper.dataset.cookieConsentService;
    const category = wrapper.dataset.cookieConsentCategory;
    if (!ready || !CookieConsent.validConsent()) {
      reveal(wrapper);
      return;
    }
    CookieConsent.acceptService(
      helpers.withService(
        CookieConsent.getUserPreferences(),
        category,
        service,
      ),
      category,
    );
  }

  /**
   * Shows the stored consent ID and date in the preferences.
   *
   * @param {HTMLElement} modal
   *   The modal element.
   */
  function showStoredConsent(modal) {
    const cookie = CookieConsent.getCookie();
    const date = cookie.lastConsentTimestamp
      ? new Date(cookie.lastConsentTimestamp).toLocaleString(settings.langcode)
      : '–';
    modal.querySelectorAll('[data-cookie-consent-id]').forEach((element) => {
      element.textContent = cookie.consentId || '–';
    });
    modal.querySelectorAll('[data-cookie-consent-date]').forEach((element) => {
      element.textContent = date;
    });
  }

  const callbacks = {
    onFirstConsent: ({ cookie }) => {
      applyConsent(cookie);
      logConsent(cookie);
    },
    onConsent: () => {
      runAcceptedLoaders();
      revealAccepted(document);
    },
    onChange: ({ cookie }) => {
      applyConsent(cookie);
      logConsent(cookie);
      runAcceptedLoaders();
      revealAccepted(document);
    },
    onModalShow: ({ modalName }) => {
      if (modalName === 'preferencesModal') {
        showStoredConsent(document.getElementById('cc-main') || document);
      }
    },
  };

  CookieConsent.run(
    helpers.libraryConfig(
      settings,
      callbacks,
      window.location.pathname,
      window.location.hostname,
    ),
  ).then(() => {
    ready = true;
    runAcceptedLoaders();
    revealAccepted(document);
  });

  /**
   * Binds the "load content" buttons of blocked embeds.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.cookieConsentEmbeds = {
    attach(context) {
      once(
        'cookie-consent-embed',
        '[data-cookie-consent-service]',
        context,
      ).forEach((wrapper) => {
        wrapper
          .querySelectorAll('[data-cookie-consent-load]')
          .forEach((button) => {
            button.addEventListener('click', () => load(wrapper));
          });
      });
      revealAccepted(context);
    },
  };

  /**
   * Public API for custom code, e.g. Drupal.cookieConsent.show().
   */
  Drupal.cookieConsent = {
    show: () => CookieConsent.showPreferences(),
    acceptedCategory: (category) =>
      ready &&
      CookieConsent.validConsent() &&
      CookieConsent.acceptedCategory(category),
    acceptedService,
  };
})(
  Drupal,
  drupalSettings,
  once,
  window.CookieConsent,
  window.cookieConsentBootstrap,
  window.cookieConsentHelpers,
);
