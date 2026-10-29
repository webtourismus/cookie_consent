/**
 * @file
 * Tests js/cookie-consent.bootstrap.js, run with: node --test "tests/js/*.test.js"
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const bootstrap = require('../../js/cookie-consent.bootstrap.js');

const config = {
  cookieName: 'cookie_consent',
  event: 'cookie_consent_update',
  dataLayer: 'dataLayer',
  revision: 2,
  waitForUpdate: 500,
  adsDataRedaction: true,
  urlPassthrough: false,
  signals: {
    necessary: ['security_storage'],
    functional: ['functionality_storage', 'personalization_storage'],
    analytics: ['analytics_storage'],
    marketing: ['ad_storage', 'ad_user_data', 'ad_personalization'],
    external_media: [],
  },
  required: ['necessary'],
  services: {
    necessary: ['cookie_consent'],
    functional: [],
    analytics: ['google_analytics'],
    marketing: ['google_ads', 'meta_pixel'],
    external_media: ['youtube'],
  },
};

const now = Date.parse('2026-10-29T12:00:00Z');

function consentCookie(overrides = {}) {
  const value = {
    categories: ['necessary', 'analytics'],
    revision: 2,
    data: null,
    consentTimestamp: '2026-10-01T10:00:00.000Z',
    consentId: '3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f',
    services: {
      necessary: ['cookie_consent'],
      analytics: ['google_analytics'],
      marketing: [],
      external_media: [],
    },
    languageCode: 'de',
    lastConsentTimestamp: '2026-10-01T10:00:00.000Z',
    expirationTime: now + 86400000,
    ...overrides,
  };
  // Same encoding as the banner library.
  return `cookie_consent=${encodeURIComponent(JSON.stringify(value))}`;
}

function run(cookie) {
  const win = {};
  const doc = { cookie };
  const result = bootstrap(config, win, doc);
  return { result, dataLayer: win.dataLayer };
}

function isArguments(value) {
  return Object.prototype.toString.call(value) === '[object Arguments]';
}

test('sets denied defaults before anything else', () => {
  const { result, dataLayer } = run('');
  assert.equal(result, null);
  assert.ok(isArguments(dataLayer[0]), 'gtag() must push the arguments object');
  assert.deepEqual(Array.from(dataLayer[0]), [
    'consent',
    'default',
    {
      security_storage: 'granted',
      functionality_storage: 'denied',
      personalization_storage: 'denied',
      analytics_storage: 'denied',
      ad_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      wait_for_update: 500,
    },
  ]);
  assert.deepEqual(Array.from(dataLayer[1]), [
    'set',
    'ads_data_redaction',
    true,
  ]);
  assert.deepEqual(dataLayer[2], { cookie_consent_cmp: 'drupal' });
  assert.equal(dataLayer.length, 3, 'no update without stored consent');
});

test('sets url_passthrough only when enabled', () => {
  const win = {};
  bootstrap(
    { ...config, adsDataRedaction: false, urlPassthrough: true },
    win,
    { cookie: '' },
  );
  assert.deepEqual(Array.from(win.dataLayer[1]), [
    'set',
    'url_passthrough',
    true,
  ]);
});

test('keeps an existing data layer', () => {
  const win = { dataLayer: [{ existing: true }] };
  bootstrap(config, win, { cookie: '' });
  assert.deepEqual(win.dataLayer[0], { existing: true });
});

test('applies a stored consent before GTM starts', () => {
  const { result, dataLayer } = run(`other=1; ${consentCookie()}; last=2`);
  assert.equal(result.consentId, '3f0c4b6e-8a1d-4c2b-9e7f-1a2b3c4d5e6f');
  assert.deepEqual(Array.from(dataLayer[3]), [
    'consent',
    'update',
    {
      security_storage: 'granted',
      functionality_storage: 'denied',
      personalization_storage: 'denied',
      analytics_storage: 'granted',
      ad_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
    },
  ]);
  assert.deepEqual(dataLayer[4], {
    event: 'cookie_consent_update',
    cookie_consent: {
      necessary: true,
      functional: false,
      analytics: true,
      marketing: false,
      external_media: false,
      services: {
        cookie_consent: true,
        google_analytics: true,
        google_ads: false,
        meta_pixel: false,
        youtube: false,
      },
    },
  });
});

test('ignores consent of another revision', () => {
  assert.equal(run(consentCookie({ revision: 1 })).result, null);
});

test('ignores expired consent', () => {
  assert.equal(
    run(consentCookie({ expirationTime: now - 1000 })).result,
    null,
  );
});

test('ignores incomplete or malformed cookies', () => {
  assert.equal(run(consentCookie({ consentId: '' })).result, null);
  assert.equal(run(consentCookie({ categories: 'analytics' })).result, null);
  assert.equal(run(consentCookie({ lastConsentTimestamp: '' })).result, null);
  assert.equal(run('cookie_consent=%7Bbroken').result, null);
  assert.equal(run('cookie_consent=').result, null);
  assert.equal(run('xcookie_consent=1').result, null);
});

test('readConsent does not match cookies with the same prefix', () => {
  const cookie = consentCookie().replace('cookie_consent=', 'cookie_consent_old=');
  assert.equal(bootstrap.readConsent(config, cookie, now), null);
});

test('signalStates grants a signal if any granting category is accepted', () => {
  const shared = {
    ...config,
    signals: { a: ['ad_storage'], b: ['ad_storage'] },
    required: [],
  };
  assert.deepEqual(bootstrap.signalStates(shared, ['a']), {
    ad_storage: 'granted',
  });
  assert.deepEqual(bootstrap.signalStates(shared, ['b']), {
    ad_storage: 'granted',
  });
  assert.deepEqual(bootstrap.signalStates(shared, []), {
    ad_storage: 'denied',
  });
});

test('consentEvent tolerates a cookie without services', () => {
  const event = bootstrap.consentEvent(config, { categories: ['marketing'] });
  assert.equal(event.cookie_consent.marketing, true);
  assert.equal(event.cookie_consent.services.google_ads, false);
  assert.equal(event.cookie_consent.services.cookie_consent, true);
});

test('uses a custom data layer name', () => {
  const win = {};
  bootstrap({ ...config, dataLayer: 'gtmLayer' }, win, { cookie: '' });
  assert.equal(win.dataLayer, undefined);
  assert.equal(win.gtmLayer.length, 3);
});
