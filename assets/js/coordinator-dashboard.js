(function () {
  'use strict';

  const contributorTabs = document.querySelector('[data-contributor-tabs]');
  if (contributorTabs) {
    const tabs = Array.from(contributorTabs.querySelectorAll('[data-contributor-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-contributor-panel]'));
    const activateRole = (selectedTab, focus = false) => {
      tabs.forEach(tab => {
        const selected = tab === selectedTab;
        tab.setAttribute('aria-selected', String(selected));
        tab.tabIndex = selected ? 0 : -1;
      });
      panels.forEach(panel => { panel.hidden = panel.dataset.contributorPanel !== selectedTab.dataset.contributorTab; });
      if (focus) selectedTab.focus();
    };
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => activateRole(tab));
      tab.addEventListener('keydown', event => {
        let nextIndex;
        if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = tabs.length - 1;
        if (nextIndex === undefined) return;
        event.preventDefault();
        activateRole(tabs[nextIndex], true);
      });
    });
    activateRole(tabs[0]);
    contributorTabs.hidden = false;
  }

  const goalToggle = document.querySelector('[data-sdg-expand]');
  const extraGoals = Array.from(document.querySelectorAll('[data-sdg-extra]'));
  if (goalToggle && extraGoals.length) {
    const setExpanded = expanded => {
      extraGoals.forEach(goal => { goal.hidden = !expanded; });
      goalToggle.setAttribute('aria-expanded', String(expanded));
      goalToggle.querySelector('[data-sdg-expand-label]').textContent = expanded
        ? 'Show fewer goals'
        : 'View all 17 goals';
      goalToggle.querySelector('i').className = expanded ? 'bx bx-chevron-up' : 'bx bx-chevron-down';
    };
    setExpanded(true);
    goalToggle.hidden = false;
    goalToggle.addEventListener('click', () => {
      setExpanded(goalToggle.getAttribute('aria-expanded') !== 'true');
    });
  }

  const chart = document.getElementById('college-output-chart');
  if (!chart) return;

  const segments = Array.from(chart.querySelectorAll('.coord-program-segment'));
  let frame = 0;
  const fitLabels = () => {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
      segments.forEach(segment => {
        const label = segment.querySelector('.coord-program-label');
        segment.classList.toggle('has-label', segment.clientWidth >= label.scrollWidth + 20);
      });
    });
  };

  const buttons = chart.querySelectorAll('[data-chart-view]');
  buttons.forEach(button => {
    button.addEventListener('click', () => {
      const share = button.dataset.chartView === 'share';
      chart.dataset.view = share ? 'share' : 'volume';
      buttons.forEach(option => option.setAttribute('aria-pressed', String(option === button)));
      chart.querySelectorAll('[data-axis-count]').forEach(tick => {
        tick.textContent = share ? tick.dataset.axisShare : tick.dataset.axisCount;
      });
      chart.querySelector('[data-chart-hint]').textContent = share
        ? 'Each bar is 100% of its college’s output. Colors show program shares.'
        : 'Bar length compares totals. Colors identify programs.';
      fitLabels();
    });
  });

  if (window.ResizeObserver) {
    const observer = new ResizeObserver(fitLabels);
    segments.forEach(segment => observer.observe(segment));
  } else {
    window.addEventListener('resize', fitLabels);
  }
  if (document.fonts) document.fonts.ready.then(fitLabels);
  fitLabels();
})();
