const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');
const script = readFileSync(join(__dirname, '../assets/js/director-loader.js'), 'utf8');

class Element extends EventTarget {
  constructor() {
    super();
    this.dataset = {};
    this.attributes = {};
    this.classes = new Set();
    this.classList = { add: value => this.classes.add(value), remove: value => this.classes.delete(value) };
    this.hidden = true;
    this.textContent = '';
  }
  hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
  setAttribute(name, value) { this.attributes[name] = value; }
}

async function harness({ director = true, state = 'loading', reducedMotion = false, images = [] } = {}) {
  const root = new Element();
  if (director) root.setAttribute('data-director-page-state', state);
  root.dataset = { directorPageState: state, directorPageTitle: 'SDG Statistics', directorBasePath: '/oracle/director/' };
  const loader = new Element();
  const shell = new Element();
  const status = new Element();
  const retry = new Element();
  loader.querySelector = selector => selector === '.oracle-intro-status' ? status : retry;
  const document = new EventTarget();
  document.documentElement = root;
  document.readyState = 'loading';
  document.querySelector = selector => ({
    '.director-page-loader': loader, '.coordinator-shell': shell,
    '.director-page-loader .oracle-intro-retry': retry,
  }[selector] || null);
  document.querySelectorAll = () => images;
  let resolveFonts;
  document.fonts = { ready: new Promise(resolve => { resolveFonts = resolve; }) };
  const window = new EventTarget();
  let reloads = 0;
  window.location = { href: 'http://localhost/oracle/director/?campus=2', origin: 'http://localhost', reload: () => { reloads++; } };
  window.matchMedia = () => ({ matches: reducedMotion });
  let now = 0;
  let timerId = 0;
  const timers = new Map();
  window.setTimeout = (callback, delay) => {
    const id = ++timerId;
    timers.set(id, { callback, time: now + delay });
    return id;
  };
  window.clearTimeout = id => timers.delete(id);
  window.requestAnimationFrame = callback => window.setTimeout(callback, 16);
  vm.runInNewContext(script, { document, window, Event, URL });
  document.dispatchEvent(new Event('DOMContentLoaded'));
  const flush = async () => { for (let i = 0; i < 16; i++) await Promise.resolve(); };
  const advance = async milliseconds => {
    const end = now + milliseconds;
    await flush();
    while (true) {
      const next = [...timers].filter(([, item]) => item.time <= end).sort((a, b) => a[1].time - b[1].time)[0];
      if (!next) break;
      now = next[1].time;
      timers.delete(next[0]);
      next[1].callback();
      await flush();
    }
    now = end;
    await flush();
  };
  const dispatch = (target, type, properties = {}) => {
    const event = new Event(type, { cancelable: true });
    for (const [key, value] of Object.entries(properties)) Object.defineProperty(event, key, { value });
    target.dispatchEvent(event);
  };
  return {
    root, shell, loader, status, retry, advance, dispatch, window, document,
    ready: () => { root.dataset.directorPageState = 'ready'; document.dispatchEvent(new Event('oracle:director-state')); },
    load: () => window.dispatchEvent(new Event('load')),
    fonts: async () => { resolveFonts(); await flush(); },
    revealed: () => root.classes.has('director-loader-ready'),
    reloads: () => reloads,
  };
}

test('director content is revealed only after assets, widgets, and logo assembly', async () => {
  const app = await harness();
  app.load(); app.ready(); await app.fonts();
  await app.advance(650);
  assert.equal(app.revealed(), false);
  await app.advance(82);
  assert.equal(app.revealed(), true);
  assert.equal(app.shell.attributes['aria-busy'], 'false');
  assert.equal(app.loader.attributes['aria-hidden'], 'true');
  await app.advance(40000);
  assert.equal(app.root.dataset.directorPageState, 'ready', 'successful loading must cancel the failure timer');
});

test('a slow dashboard chart readiness signal keeps the logo visible', async () => {
  const app = await harness();
  app.load(); await app.fonts(); await app.advance(5000);
  assert.equal(app.revealed(), false);
  app.ready(); await app.advance(32);
  assert.equal(app.revealed(), true);
});

test('a ready data set still waits for page assets and fonts', async () => {
  const app = await harness();
  app.ready(); await app.advance(3000);
  assert.equal(app.revealed(), false);
  app.load(); await app.advance(32);
  assert.equal(app.revealed(), false);
  await app.fonts(); await app.advance(32);
  assert.equal(app.revealed(), true);
});

test('goal image decoding completes before the page is revealed', async () => {
  let finishImage;
  const image = { decode: () => new Promise(resolve => { finishImage = resolve; }) };
  const app = await harness({ images: [image] });
  app.ready(); app.load(); await app.fonts(); await app.advance(3000);
  assert.equal(image.loading, 'eager');
  assert.equal(app.revealed(), false);
  finishImage(); await app.advance(32);
  assert.equal(app.revealed(), true);
});

test('a failed decorative image does not trap an otherwise complete page', async () => {
  const app = await harness({ images: [{ decode: () => Promise.reject(new Error('Image fixture')) }] });
  app.ready(); app.load(); await app.fonts(); await app.advance(732);
  assert.equal(app.revealed(), true);
});

test('server data errors keep the logo and offer a working retry button', async () => {
  const app = await harness({ state: 'error' });
  app.load(); await app.fonts(); await app.advance(1000);
  assert.equal(app.revealed(), false);
  assert.equal(app.retry.hidden, false);
  app.retry.dispatchEvent(new Event('click'));
  assert.equal(app.reloads(), 1);
});

test('failed page scripts and stalled initialization offer retry', async () => {
  const failed = await harness();
  failed.dispatch(failed.window, 'error', { filename: 'http://localhost/oracle/assets/js/director-analytics.js?v=1' });
  assert.equal(failed.retry.hidden, false);
  assert.equal(failed.root.dataset.directorPageState, 'error');
  const stalled = await harness();
  stalled.load(); await stalled.fonts(); await stalled.advance(30000);
  assert.equal(stalled.revealed(), false);
  assert.equal(stalled.retry.hidden, false);
});

test('a late initialization error cannot race the reveal frames', async () => {
  const app = await harness({ reducedMotion: true });
  app.ready(); app.load(); await app.fonts(); await app.advance(16);
  app.dispatch(app.window, 'error', { filename: 'http://localhost/oracle/assets/js/coordinator-dashboard.js' });
  await app.advance(16);
  assert.equal(app.revealed(), false);
  assert.equal(app.retry.hidden, false);
});

test('reduced motion skips assembly delay while preserving data readiness', async () => {
  const app = await harness({ reducedMotion: true });
  app.load(); await app.fonts(); await app.advance(100);
  assert.equal(app.revealed(), false);
  app.ready(); await app.advance(32);
  assert.equal(app.revealed(), true);
});

test('main page and other modules do not activate the director loader', async () => {
  const app = await harness({ director: false });
  assert.equal(app.root.classes.has('director-loader-loading'), false);
});

test('director navigation shows the logo and browser-back restores the loaded page', async () => {
  const app = await harness();
  app.ready(); app.load(); await app.fonts(); await app.advance(732);
  const link = { href: 'http://localhost/oracle/director/sdg.php?campus=2', target: '', hasAttribute: () => false };
  link.closest = () => link;
  app.dispatch(app.document, 'click', { target: link, button: 0 });
  assert.equal(app.root.classes.has('director-loader-loading'), true);
  assert.equal(app.loader.attributes['aria-hidden'], 'false');
  app.dispatch(app.window, 'pageshow', { persisted: true });
  await app.advance(32);
  assert.equal(app.revealed(), true);
});

test('external navigation and logout do not activate the director logo', async () => {
  const app = await harness();
  app.ready(); app.load(); await app.fonts(); await app.advance(732);
  const link = { href: 'https://example.test/', target: '', hasAttribute: () => false };
  link.closest = () => link;
  app.dispatch(app.document, 'click', { target: link, button: 0 });
  app.dispatch(app.document, 'submit', { target: { action: 'http://localhost/oracle/logout.php', method: 'post' } });
  assert.equal(app.revealed(), true);
});
