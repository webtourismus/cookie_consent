/**
 * @file
 * Tests js/cookie-consent.helpers.js, run with: node --test "tests/js/*.test.js"
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const helpers = require('../../js/cookie-consent.helpers.js');

const settings = {
  bootstrap: { cookieName: 'cookie_consent' },
  revision: 3,
  expiryDays: 182,
  layout: 'box',
  position: 'bottom left',
  langcode: 'en',
  legalPaths: ['/en/privacy', '/en/imprint'],
  categories: {
    necessary: {
      required: true,
      services: {
        cookie_consent: { label: 'Cookie consent', cookies: ['cookie_consent'] },
      },
    },
    analytics: {
      required: false,
      services: {
        google_analytics: {
          label: 'Google Analytics',
          cookies: ['_ga', '_ga_*'],
        },
      },
    },
  },
  translation: { consentModal: { title: 'Privacy' }, preferencesModal: {} },
};

test('cookiePattern keeps plain names and converts wildcards', () => {
  assert.equal(helpers.cookiePattern('_ga'), '_ga');
  const pattern = helpers.cookiePattern('_ga_*');
  assert.ok(pattern instanceof RegExp);
  assert.ok(pattern.test('_ga_ABC123'));
  assert.ok(!pattern.test('x_ga_ABC123'));
  assert.ok(!pattern.test('_gat'));
  const dotted = helpers.cookiePattern('a.b*');
  assert.ok(dotted.test('a.bc'));
  assert.ok(!dotted.test('axbc'), 'dots are literal');
});

test('parentDomains lists the domains cookies can be set on', () => {
  assert.deepEqual(helpers.parentDomains('factory.dev1.example.com'), [
    'dev1.example.com',
    'example.com',
  ]);
  assert.deepEqual(helpers.parentDomains('www.hotel.at'), ['hotel.at']);
  assert.deepEqual(helpers.parentDomains('hotel.at'), []);
  assert.deepEqual(helpers.parentDomains('localhost'), []);
  assert.deepEqual(helpers.parentDomains('127.0.0.1'), []);
});

test('clearItems deletes cookies on the host and its parent domains', () => {
  const items = helpers.clearItems(['_ga', '_ga_*'], 'www.hotel.at');
  assert.equal(items.length, 4);
  assert.deepEqual(items[0], { name: '_ga' });
  assert.deepEqual(items[1], { name: '_ga', domain: 'hotel.at' });
  assert.ok(items[2].name instanceof RegExp);
  assert.equal(items[3].domain, 'hotel.at');
});

test('isLegalPage ignores trailing slashes', () => {
  assert.ok(helpers.isLegalPage('/en/privacy/', settings.legalPaths));
  assert.ok(helpers.isLegalPage('/en/imprint', settings.legalPaths));
  assert.ok(!helpers.isLegalPage('/en/rooms', settings.legalPaths));
  assert.ok(!helpers.isLegalPage('/', []));
});

test('withService adds a service once', () => {
  const preferences = { acceptedServices: { external_media: ['vimeo'] } };
  assert.deepEqual(
    helpers.withService(preferences, 'external_media', 'youtube'),
    ['vimeo', 'youtube'],
  );
  assert.deepEqual(
    helpers.withService(preferences, 'external_media', 'vimeo'),
    ['vimeo'],
  );
  assert.deepEqual(helpers.withService({}, 'marketing', 'google_ads'), [
    'google_ads',
  ]);
});

test('logPayload contains the fields validated by the server', () => {
  const cookie = {
    consentId: '3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f',
    revision: 3,
    categories: ['necessary'],
    services: { necessary: ['cookie_consent'] },
  };
  assert.deepEqual(
    helpers.logPayload(cookie, { acceptType: 'necessary' }, 'de'),
    {
      consentId: '3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f',
      revision: 3,
      acceptType: 'necessary',
      categories: ['necessary'],
      services: { necessary: ['cookie_consent'] },
      langcode: 'de',
    },
  );
});

test('libraryConfig maps the settings to the banner library', () => {
  const onChange = () => {};
  const config = helpers.libraryConfig(settings, { onChange }, '/en/rooms');
  assert.equal(config.revision, 3);
  assert.equal(config.autoShow, true);
  assert.equal(config.hideFromBots, true);
  assert.equal(config.onChange, onChange);
  assert.deepEqual(config.cookie, {
    name: 'cookie_consent',
    expiresAfterDays: 182,
    sameSite: 'Lax',
  });
  assert.equal(config.guiOptions.consentModal.equalWeightButtons, true);
  assert.equal(config.guiOptions.preferencesModal.equalWeightButtons, true);
  assert.deepEqual(config.categories.necessary.enabled, true);
  assert.deepEqual(config.categories.necessary.readOnly, true);
  assert.deepEqual(config.categories.analytics.enabled, false);
  assert.equal(
    config.categories.analytics.services.google_analytics.label,
    'Google Analytics',
  );
  const cleared = config.categories.analytics.autoClear.cookies.map(
    (cookie) => cookie.name,
  );
  assert.equal(cleared[0], '_ga');
  assert.ok(cleared[1] instanceof RegExp);
  const onSubdomain = helpers.libraryConfig(settings, {}, '/', 'en.hotel.at');
  assert.deepEqual(
    onSubdomain.categories.analytics.services.google_analytics.cookies[1],
    { name: '_ga', domain: 'hotel.at' },
  );
  assert.equal(config.language.default, 'en');
  assert.equal(config.language.translations.en, settings.translation);
});

test('libraryConfig does not open the banner on legal pages', () => {
  const config = helpers.libraryConfig(settings, {}, '/en/privacy');
  assert.equal(config.autoShow, false);
});

test('libraryConfig does not open the banner on pages without GTM', () => {
  const withoutGtm = { ...settings, autoShow: false };
  assert.equal(helpers.libraryConfig(withoutGtm, {}, '/en/rooms').autoShow, false);
  const withGtm = { ...settings, autoShow: true };
  assert.equal(helpers.libraryConfig(withGtm, {}, '/en/rooms').autoShow, true);
});
