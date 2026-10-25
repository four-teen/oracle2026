const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

const project = join(__dirname, '..');
const introScript = readFileSync(join(project, 'assets/js/oracle-intro.js'), 'utf8');
const template = readFileSync(join(project, 'index.php'), 'utf8').replace(/\r\n/g, '\n');
const catalogScript = template.match(/\(function \(\) \{\s*const batchSize =[\s\S]*?\n      \}\)\(\);/)[0];

class Element extends EventTarget {
  constructor() {
    super();
    this.classes = new Set();
    this.classList = {
      add: name => this.classes.add(name),
      remove: name => this.classes.delete(name),
      contains: name => this.classes.has(name),
      toggle: (name, enabled) => enabled ? this.classes.add(name) : this.classes.delete(name),
    };
    this.dataset = {};
    this.attributes = {};
    this.entries = [];
    this.hidden = true;
    this.textContent = '';
  }
  setAttribute(name, value) { this.attributes[name] = value; }
  querySelector() { return null; }
  querySelectorAll() { return this.entries; }
  get innerHTML() { return this.textContent; }
  set innerHTML(value) { this.entries = []; }
  insertAdjacentHTML(position, html) {
    for (const match of html.matchAll(/<article[^>]*data-titleid="(\d+)"/g)) {
      this.entries.push({ dataset: { titleid: match[1] } });
    }
  }
  scrollIntoView() {}
}

async function harness({ count = 12, total = 905, error = '', reducedMotion = false } = {}) {
  const root = new Element();
  root.dataset.oracleCatalogState = 'loading';
  const ids = Object.fromEntries([
    'research-count', 'research-total', 'research-grid', 'empty-state',
    'repository-indexed-count', 'repository-indexed-stat', 'repository-search-form',
  ].map(id => [id, new Element()]));
  ids['research-grid'].entries = Array.from({ length: count }, (_, i) => ({ dataset: { titleid: String(i + 1) } }));
  const intro = new Element();
  const main = new Element();
  const status = new Element();
  const retry = new Element();
  intro.querySelector = selector => selector === '.oracle-intro-status' ? status : retry;
  const document = new EventTarget();
  document.documentElement = root;
  document.readyState = 'loading';
  document.getElementById = id => ids[id] || null;
  document.querySelector = selector => ({
    '.oracle-intro': intro, '.landing-main': main, '.oracle-intro-retry': retry,
  }[selector] || null);
  document.createElement = () => new Element();
  let resolveFonts;
  document.fonts = { ready: new Promise(resolve => { resolveFonts = resolve; }) };
  const window = new EventTarget();
  let now = 0;
  let nextTimer = 0;
  let reloads = 0;
  const timers = new Map();
  window.matchMedia = () => ({ matches: reducedMotion });
  window.location = { href: 'http://localhost/oracle/', origin: 'http://localhost', reload: () => { reloads++; } };
  window.history = { replaceState() {} };
  window.AbortController = AbortController;
  window.setTimeout = (callback, delay) => {
    const id = ++nextTimer;
    timers.set(id, { callback, time: now + delay });
    return id;
  };
  window.clearTimeout = id => timers.delete(id);
  window.requestAnimationFrame = callback => window.setTimeout(callback, 16);
  const requests = [];
  const fetch = (url, options) => new Promise((resolve, reject) => {
    requests.push({ url, resolve, reject });
    options.signal?.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), { name: 'AbortError' })));
  });
  const context = vm.createContext({ document, window, fetch, Event, URL, AbortController, navigator: {} });
  vm.runInContext(introScript, context);
  vm.runInContext(catalogScript.replace(/<\?=([\s\S]*?)\?>/g, (_, php) => {
    if (php.includes('$initialResearchBatchSize')) return '12';
    if (php.includes('$researchTotalCount')) return String(total);
    if (php.includes('$landingDataError')) return JSON.stringify(error);
    if (php.includes('$researchSdgNames')) return '{}';
    if (php.includes("app_link('index.php')")) return '"/oracle/index.php"';
    if (php.includes("app_link('assets/img/sdgs/')")) return '"/oracle/assets/img/sdgs/"';
    throw new Error('Unexpected PHP fixture: ' + php);
  }), context);
  document.dispatchEvent(new Event('DOMContentLoaded'));
  const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
  const advance = async milliseconds => {
    const target = now + milliseconds;
    while (true) {
      const next = [...timers].filter(([, timer]) => timer.time <= target).sort((a, b) => a[1].time - b[1].time)[0];
      if (!next) break;
      now = next[1].time;
      timers.delete(next[0]);
      next[1].callback();
      await flush();
    }
    now = target;
    await flush();
  };
  const respond = async (index, payload, ok = true) => {
    requests[index].resolve({ ok, text: async () => JSON.stringify(payload) });
    await flush();
  };
  return {
    root, ids, status, retry, requests, advance, respond,
    load: () => window.dispatchEvent(new Event('load')),
    fonts: async () => { resolveFonts(); await flush(); },
    revealed: () => root.classList.contains('oracle-intro-ready'),
    reloads: () => reloads,
  };
}

test('preloaded results wait for the logo animation, page assets, and fonts', async () => {
  const app = await harness();
  app.load();
  await app.advance(10000);
  assert.equal(app.revealed(), false, 'slow fonts must keep the logo visible');
  await app.fonts();
  await app.advance(32);
  assert.equal(app.revealed(), true);
  assert.equal(app.ids['research-total'].textContent, '905');
  assert.equal(app.ids['repository-indexed-count'].textContent, '905');
});

test('slow page assets keep the logo visible after data and animation are ready', async () => {
  const app = await harness();
  await app.fonts();
  await app.advance(10000);
  assert.equal(app.revealed(), false);
  app.load();
  await app.advance(32);
  assert.equal(app.revealed(), true);
});

test('a fast load still lets the logo animation finish', async () => {
  const app = await harness();
  app.load();
  await app.fonts();
  await app.advance(2100);
  assert.equal(app.revealed(), false);
  await app.advance(132);
  assert.equal(app.revealed(), true);
});

test('failed initial data stays covered through retries, then updates both counters', async () => {
  const app = await harness({ count: 0, total: 0, error: 'Database temporarily unavailable' });
  app.load();
  await app.fonts();
  await app.advance(5000);
  assert.equal(app.revealed(), false);
  await app.respond(0, { message: 'Temporary error' }, false);
  await app.advance(750);
  assert.equal(app.requests.length, 2);
  assert.equal(app.revealed(), false);
  await app.respond(1, { total: 1205, items: [{ titleid: 1, title: 'Recovered research' }], has_more: true });
  await app.advance(32);
  assert.equal(app.revealed(), true);
  assert.equal(app.ids['research-count'].textContent, '1');
  assert.equal(app.ids['research-total'].textContent, '1205');
  assert.equal(app.ids['repository-indexed-count'].textContent, '1,205');
  assert.equal(app.ids['repository-indexed-stat'].attributes['aria-label'], '1,205 indexed research records');
});

test('repeated failures show Retry instead of revealing a blank catalog', async () => {
  const app = await harness({ count: 0, total: 0, error: 'Unavailable' });
  app.load();
  await app.fonts();
  await app.respond(0, {}, false);
  await app.advance(750);
  await app.respond(1, {}, false);
  await app.advance(1500);
  await app.respond(2, {}, false);
  await app.advance(10000);
  assert.equal(app.revealed(), false);
  assert.equal(app.root.dataset.oracleCatalogState, 'error');
  assert.equal(app.retry.hidden, false);
  app.retry.dispatchEvent(new Event('click'));
  assert.equal(app.reloads(), 1);
});

test('an empty list with a positive total is retried as incomplete data', async () => {
  const app = await harness({ count: 0, total: 905 });
  app.load();
  await app.fonts();
  await app.respond(0, { total: 905, items: [], has_more: true });
  await app.advance(3000);
  assert.equal(app.revealed(), false);
  assert.equal(app.requests.length, 2);
  await app.respond(1, { total: 905, items: [{ titleid: 1 }], has_more: true });
  await app.advance(32);
  assert.equal(app.revealed(), true);
});

test('a stalled recovery request times out and retries without revealing the page', async () => {
  const app = await harness({ count: 0, total: 0, error: 'Unavailable' });
  app.load();
  await app.fonts();
  await app.advance(15750);
  assert.equal(app.requests.length, 2);
  assert.equal(app.revealed(), false);
  await app.respond(1, { total: 0, items: [], has_more: false });
  await app.advance(32);
  assert.equal(app.revealed(), true, 'a confirmed empty catalog is valid');
});

test('unrenderable items cannot mark the research list ready', async () => {
  const app = await harness({ count: 0, total: 905 });
  app.load();
  await app.fonts();
  await app.respond(0, { total: 905, items: [{}], has_more: true });
  await app.advance(3000);
  assert.equal(app.revealed(), false);
  assert.equal(app.requests.length, 2);
});

test('reduced motion skips the animation delay but still waits for data', async () => {
  const app = await harness({ count: 0, total: 0, error: 'Unavailable', reducedMotion: true });
  app.load();
  await app.fonts();
  await app.advance(100);
  assert.equal(app.revealed(), false);
  await app.respond(0, { total: 0, items: [], has_more: false });
  await app.advance(32);
  assert.equal(app.revealed(), true);
});

test('searching after initialization still refreshes the list and indexed count', async () => {
  const app = await harness();
  app.ids['repository-search-form'].dispatchEvent(new Event('submit', { cancelable: true }));
  await app.respond(0, { total: 7, items: [{ titleid: 100 }], has_more: false });
  assert.equal(app.ids['research-total'].textContent, '7');
  assert.equal(app.ids['repository-indexed-count'].textContent, '7');
});
