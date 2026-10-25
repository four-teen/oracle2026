(function () {
  'use strict';

  const root = document.documentElement;
  if (!root.hasAttribute('data-director-page-state')) return;

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let animationReady = reducedMotion;
  let pageReady = document.readyState === 'complete';
  let fontsReady = !document.fonts;
  let imagesReady = false;
  let revealing = false;
  let navigating = false;
  let revealed = false;
  let readinessTimer;

  root.classList.add('director-loader-loading');

  const update = () => {
    const loader = document.querySelector('.director-page-loader');
    if (!loader || revealed || navigating) return;

    const failed = root.dataset.directorPageState === 'error';
    const status = loader.querySelector('.oracle-intro-status');
    const retry = loader.querySelector('.oracle-intro-retry');
    if (status) status.textContent = failed
      ? 'This page could not finish loading. Please try again.'
      : 'Loading ' + root.dataset.directorPageTitle + '...';
    if (retry) retry.hidden = !failed;

    if (failed || root.dataset.directorPageState !== 'ready' || !pageReady || !fontsReady
      || !imagesReady || !animationReady || revealing) return;

    revealing = true;
    window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
      revealing = false;
      if (root.dataset.directorPageState !== 'ready' || navigating) return;
      revealed = true;
      window.clearTimeout(readinessTimer);
      root.classList.add('director-loader-ready');
      root.classList.remove('director-loader-loading');
      loader.setAttribute('aria-hidden', 'true');
      document.querySelector('.coordinator-shell').setAttribute('aria-busy', 'false');
    }));
  };

  const fail = () => {
    if (revealed || navigating) return;
    root.dataset.directorPageState = 'error';
    update();
  };

  document.addEventListener('oracle:director-state', update);
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('.coordinator-shell').setAttribute('aria-busy', 'true');
    document.querySelector('.director-page-loader .oracle-intro-retry').addEventListener('click', () => window.location.reload());

    if (!animationReady) window.setTimeout(() => { animationReady = true; update(); }, 700);
    if (document.fonts) document.fonts.ready.then(() => { fontsReady = true; update(); });

    // Lazy goal icons must also be ready before the page is revealed.
    const images = Array.from(document.querySelectorAll('.director-workspace img'));
    Promise.all(images.map(image => {
      image.loading = 'eager';
      if (typeof image.decode === 'function') return image.decode().catch(() => {});
      if (image.complete) return Promise.resolve();
      return new Promise(resolve => {
        image.addEventListener('load', resolve, { once: true });
        image.addEventListener('error', resolve, { once: true });
      });
    })).then(() => { imagesReady = true; update(); });

    // Failed or blocked page scripts show a retry action instead of an endless logo.
    readinessTimer = window.setTimeout(fail, 30000);
    update();
  }, { once: true });

  window.addEventListener('load', () => { pageReady = true; update(); }, { once: true });
  window.addEventListener('error', event => {
    if (event.target?.matches?.('[data-director-page-script]')
      || /\/assets\/js\/(coordinator-dashboard|director-analytics)\.js(?:\?|$)/.test(event.filename || '')) fail();
  }, true);

  const isDirectorUrl = value => {
    const url = new URL(value, window.location.href);
    return url.origin === window.location.origin && url.pathname.startsWith(root.dataset.directorBasePath);
  };
  const showForNavigation = () => {
    if (!revealed) return;
    navigating = true;
    root.classList.remove('director-loader-ready');
    root.classList.add('director-loader-loading');
    const loader = document.querySelector('.director-page-loader');
    loader.setAttribute('aria-hidden', 'false');
    loader.querySelector('.oracle-intro-status').textContent = 'Loading the selected page...';
    document.querySelector('.coordinator-shell').setAttribute('aria-busy', 'true');
  };
  document.addEventListener('click', event => {
    const link = event.target.closest?.('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
      || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
    const url = new URL(link.href, window.location.href);
    const current = new URL(window.location.href);
    if (url.hash && url.pathname === current.pathname && url.search === current.search) return;
    if (isDirectorUrl(link.href)) showForNavigation();
  });
  document.addEventListener('submit', event => {
    if (!event.defaultPrevented && event.target.method.toLowerCase() === 'get' && isDirectorUrl(event.target.action)) showForNavigation();
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted || !navigating) return;
    navigating = false;
    revealed = false;
    update();
  });
})();
