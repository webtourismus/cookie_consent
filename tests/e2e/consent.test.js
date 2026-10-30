/**
 * @file
 * End-to-end test against a site with the module enabled.
 *
 * Uses the installed Google Chrome, external requests (GTM, YouTube, Google
 * Maps) are blocked and recorded. Writes entries to the consent log.
 *
 * E2E_BASE_URL=https://example.ddev.site \
 * E2E_VIDEO_PATH=/page-with-youtube E2E_MAP_PATH=/page-with-map \
 * node --test "tests/e2e/*.test.js"
 */

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright-core');

const baseUrl = process.env.E2E_BASE_URL;
const videoPath = process.env.E2E_VIDEO_PATH;
const mapPath = process.env.E2E_MAP_PATH || '/';
const external =
  /googletagmanager\.com|google-analytics\.com|youtube\.com|ytimg\.com|maps\.googleapis\.com|maps\.gstatic\.com/;
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

let browser;

before(async () => {
  if (baseUrl) {
    browser = await chromium.launch({ channel: 'chrome', headless: true });
  }
});

after(async () => {
  await browser?.close();
});

/**
 * Opens a fresh browser context without cookies.
 */
async function openPage(path) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  // The banner hides itself from automated browsers (hideFromBots).
  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => false });
  });
  const blocked = [];
  await context.route(external, (route) => {
    blocked.push(route.request().url());
    return route.abort();
  });
  const page = await context.newPage();
  await page.goto(baseUrl + path);
  return { context, page, blocked };
}

/**
 * Returns the data layer with gtag() arguments converted to arrays.
 */
function dataLayer(page) {
  return page.evaluate(() =>
    window.dataLayer.map((entry) =>
      Object.prototype.toString.call(entry) === '[object Arguments]'
        ? Array.from(entry)
        : entry,
    ),
  );
}

/**
 * Returns the consent cookie value.
 */
async function consentCookie(context) {
  const cookie = (await context.cookies()).find(
    (c) => c.name === 'cookie_consent',
  );
  return cookie ? JSON.parse(decodeURIComponent(cookie.value)) : null;
}

/**
 * Clicks a button of the consent banner and waits for the consent log.
 */
async function answerBanner(page, name) {
  const logged = page.waitForResponse(
    (r) =>
      r.url().includes('/cookie-consent/log') &&
      r.request().method() === 'POST',
  );
  await page.locator('#cc-main .cm').getByRole('button', { name }).click();
  const response = await logged;
  assert.equal(response.status(), 204, 'The consent is logged.');
}

/**
 * Clicks "load content" of the first blocked embed.
 *
 * Clicked in the page: the banner or a popup of the site may cover it.
 */
function clickLoad(page) {
  return page
    .locator('[data-cookie-consent-load]')
    .first()
    .evaluate((button) => button.click());
}

const skip =
  baseUrl && videoPath ? false : 'E2E_BASE_URL and E2E_VIDEO_PATH must be set';

test('first visit: denied defaults, banner, blocked embeds', { skip }, async () => {
  const { context, page, blocked } = await openPage(videoPath);
  const layer = await dataLayer(page);

  assert.deepEqual(layer[0].slice(0, 2), ['consent', 'default']);
  assert.equal(layer[0][2].analytics_storage, 'denied');
  assert.equal(layer[0][2].ad_storage, 'denied');
  assert.equal(layer[0][2].security_storage, 'granted');
  assert.ok(
    !layer.some((e) => Array.isArray(e) && e[1] === 'update'),
    'No consent update without a choice.',
  );
  assert.ok(layer.some((e) => e.cookie_consent_cmp === 'drupal'));

  await page.locator('#cc-main .cm').waitFor({ state: 'visible' });
  assert.ok((await page.locator('.cookie-consent-embed__placeholder').count()) > 0);
  assert.equal(await page.locator('.cookie-consent-embed iframe').count(), 0);
  assert.equal(await consentCookie(context), null);
  assert.ok(!blocked.some((url) => /youtube/.test(url)), 'YouTube is not contacted.');
  await context.close();
});

test('reject all: stored, logged and applied before GTM on the next page', { skip }, async () => {
  const { context, page } = await openPage(videoPath);
  await answerBanner(page, 'Alle ablehnen');

  const cookie = await consentCookie(context);
  assert.deepEqual(cookie.categories, ['necessary']);
  assert.match(cookie.consentId, uuid);

  let layer = await dataLayer(page);
  const update = layer.find((e) => Array.isArray(e) && e[1] === 'update');
  assert.equal(update[2].analytics_storage, 'denied');
  const event = layer.find((e) => e.event === 'cookie_consent_update');
  assert.equal(event.cookie_consent.analytics, false);
  assert.equal(event.cookie_consent.necessary, true);

  await page.reload();
  layer = await dataLayer(page);
  const updateIndex = layer.findIndex((e) => Array.isArray(e) && e[1] === 'update');
  const gtmIndex = layer.findIndex((e) => e.event === 'gtm.js');
  assert.ok(updateIndex > 0, 'The stored consent is applied on page load.');
  assert.ok(gtmIndex === -1 || updateIndex < gtmIndex, 'The update comes before GTM starts.');
  assert.equal(await page.locator('#cc-main .cm').isVisible(), false);
  await context.close();
});

test('accept all: granted signals and all embeds loaded', { skip }, async () => {
  const { context, page } = await openPage(videoPath);
  await answerBanner(page, 'Alle akzeptieren');

  const layer = await dataLayer(page);
  const update = layer.find((e) => Array.isArray(e) && e[1] === 'update');
  assert.equal(update[2].analytics_storage, 'granted');
  assert.equal(update[2].ad_user_data, 'granted');
  const embeds = await page.locator('.cookie-consent-embed').count();
  await page.waitForFunction(
    (count) => document.querySelectorAll('.cookie-consent-embed--loaded iframe').length === count,
    embeds,
  );
  assert.equal(await page.locator('.cookie-consent-embed__placeholder').count(), 0);
  await context.close();
});

test('load content without consent: only this embed, banner stays', { skip }, async () => {
  const { context, page } = await openPage(videoPath);
  await page.locator('#cc-main .cm').waitFor({ state: 'visible' });
  const embeds = await page.locator('.cookie-consent-embed').count();
  await clickLoad(page);

  assert.equal(await page.locator('.cookie-consent-embed--loaded iframe').count(), 1);
  assert.equal(await page.locator('.cookie-consent-embed__placeholder').count(), embeds - 1);
  assert.equal(await page.locator('#cc-main .cm').isVisible(), true);
  assert.equal(await consentCookie(context), null);
  await context.close();
});

test('load content with stored consent: service added and logged', { skip }, async () => {
  const { context, page } = await openPage(videoPath);
  await answerBanner(page, 'Alle ablehnen');

  const logged = page.waitForResponse((r) => r.url().includes('/cookie-consent/log'));
  await clickLoad(page);
  assert.equal((await logged).status(), 204);

  const cookie = await consentCookie(context);
  assert.deepEqual(cookie.services.external_media, ['youtube']);
  assert.ok(cookie.categories.includes('external_media'));
  const embeds = await page.locator('.cookie-consent-embed').count();
  await page.waitForFunction(
    (count) => document.querySelectorAll('.cookie-consent-embed--loaded').length === count,
    embeds,
  );
  const layer = await dataLayer(page);
  const events = layer.filter((e) => e.event === 'cookie_consent_update');
  assert.equal(events.at(-1).cookie_consent.services.youtube, true);
  assert.equal(events.at(-1).cookie_consent.analytics, false);
  await context.close();
});

test('Google Maps is loaded only after consent', { skip }, async () => {
  const { context, page, blocked } = await openPage(mapPath);
  if ((await page.locator('[data-cookie-consent-service="google_maps"]').count()) === 0) {
    await context.close();
    return;
  }
  await page.waitForTimeout(1000);
  assert.ok(!blocked.some((url) => /maps\.googleapis/.test(url)), 'No Maps request before consent.');

  await answerBanner(page, 'Alle akzeptieren');
  await page.waitForFunction(() => document.querySelector('.cookie-consent-embed--loaded .geolocation-map-wrapper'));
  await page.waitForTimeout(1000);
  assert.ok(blocked.some((url) => /maps\.googleapis/.test(url)), 'The map loads the Maps API after consent.');
  await context.close();
});

test('withdrawal deletes analytics cookies on parent domains', { skip }, async () => {
  const { context, page } = await openPage(videoPath);
  await answerBanner(page, 'Alle akzeptieren');
  const host = new URL(baseUrl).hostname;
  const parent = host.split('.').slice(-2).join('.');
  // Google Analytics sets its cookies on the highest possible domain.
  await context.addCookies([
    { name: '_ga', value: 'GA1.1.1.1', domain: `.${parent}`, path: '/' },
    { name: '_ga_TEST', value: 'GS1.1.1', domain: `.${parent}`, path: '/' },
  ]);

  await page.evaluate(() => Drupal.cookieConsent.show());
  const logged = page.waitForResponse((r) => r.url().includes('/cookie-consent/log'));
  await page.locator('#cc-main .pm').getByRole('button', { name: 'Alle ablehnen' }).click();
  await logged;

  const names = (await context.cookies()).map((c) => c.name);
  assert.ok(!names.includes('_ga'), '_ga is deleted.');
  assert.ok(!names.includes('_ga_TEST'), '_ga_* is deleted.');
  assert.ok(names.includes('cookie_consent'));
  await context.close();
});

test('preferences show the consent ID', { skip }, async () => {
  const { context, page } = await openPage(videoPath);
  await answerBanner(page, 'Alle ablehnen');
  const { consentId } = await consentCookie(context);

  await page.evaluate(() => Drupal.cookieConsent.show());
  await page.locator('#cc-main .pm').waitFor({ state: 'visible' });
  assert.equal(await page.locator('[data-cookie-consent-id]').textContent(), consentId);
  assert.notEqual(await page.locator('[data-cookie-consent-date]').textContent(), '–');
  await context.close();
});
