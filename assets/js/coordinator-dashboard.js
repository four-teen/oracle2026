(function () {
  'use strict';

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
        : 'Bar length compares manuscript totals. Colors show programs.';
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
