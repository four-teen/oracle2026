(function () {
  'use strict';

  const root = document.documentElement;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let animationReady = reducedMotion;
  let pageReady = document.readyState === 'complete';
  let fontsReady = !document.fonts;
  let revealPending = false;

  root.classList.add('oracle-intro-loading');

  const updateIntro = () => {
    const intro = document.querySelector('.oracle-intro');

    if (!intro || root.classList.contains('oracle-intro-ready')) {
      return;
    }

    const state = root.dataset.oracleCatalogState;
    const status = intro.querySelector('.oracle-intro-status');
    const retry = intro.querySelector('.oracle-intro-retry');

    if (status) {
      status.textContent = state === 'error'
        ? 'Research records could not be loaded. Please try again.'
        : 'Loading research records and indexed count...';
    }

    if (retry) {
      retry.hidden = state !== 'error';
    }

    if (state !== 'ready' || !pageReady || !fontsReady || !animationReady || revealPending) {
      return;
    }

    // Give the populated list and counters a rendering frame before fading out.
    revealPending = true;
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        root.classList.add('oracle-intro-ready');
        root.classList.remove('oracle-intro-loading');
        intro.setAttribute('aria-hidden', 'true');
        const main = document.querySelector('.landing-main');

        if (main) {
          main.setAttribute('aria-busy', 'false');
        }
      });
    });
  };

  document.addEventListener('oracle:catalog-state', updateIntro);
  document.addEventListener('DOMContentLoaded', () => {
    const retry = document.querySelector('.oracle-intro-retry');
    const main = document.querySelector('.landing-main');

    if (main) {
      main.setAttribute('aria-busy', 'true');
    }

    if (retry) {
      retry.addEventListener('click', () => window.location.reload());
    }

    if (!animationReady) {
      window.setTimeout(() => {
        animationReady = true;
        updateIntro();
      }, 2200);
    }

    if (document.fonts) {
      document.fonts.ready.then(() => {
        fontsReady = true;
        updateIntro();
      });
    }

    updateIntro();
  }, { once: true });

  window.addEventListener('load', () => {
    pageReady = true;
    updateIntro();
  }, { once: true });
})();
