(function () {
  'use strict';

  const initialCharts = [];
  const markDirectorReady = () => {
    const root = document.documentElement;
    if (root.dataset.directorPageState !== 'loading') return;
    root.dataset.directorPageState = 'ready';
    document.dispatchEvent(new Event('oracle:director-state'));
  };

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

  const outputChart = document.querySelector('[data-research-output-chart]');
  if (outputChart) {
    const panel = outputChart.closest('.coord-production');
    const navigation = panel.querySelector('[data-output-navigation]');
    const title = panel.querySelector('[data-output-title]');
    const heading = panel.querySelector('[data-output-heading]');
    const level = panel.querySelector('[data-output-level]');
    const hint = panel.querySelector('[data-output-hint]');
    const legend = panel.querySelector('[data-output-legend]');
    const back = panel.querySelector('[data-output-back]');
    const backLabel = panel.querySelector('[data-output-back-label]');
    const choices = panel.querySelector('[data-output-choices]');
    const itemCount = panel.querySelector('[data-output-item-count]');
    const totalCount = panel.querySelector('[data-output-total]');
    const empty = panel.querySelector('[data-output-empty]');
    const figures = panel.querySelector('.coord-all-program-figures');
    const figureLabel = panel.querySelector('[data-program-figures-label]');
    const campusFigures = Array.from(panel.querySelectorAll('[data-campus-figure]'));
    const figureGroups = Array.from(panel.querySelectorAll('[data-program-figure]'));
    const scroll = outputChart.parentElement;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const levels = [
      { single: 'campus', plural: 'campuses', axis: 'Campuses' },
      { single: 'college', plural: 'colleges', axis: 'Colleges' },
      { single: 'program', plural: 'programs', axis: 'Programs' }
    ];
    const seriesDefinitions = [
      { key: 'total', name: 'Total research', color: '#15803d', width: 4 },
      { key: 'thesis', name: 'Thesis', color: '#2563eb', width: 2.5 },
      { key: 'capstone', name: 'Capstone', color: '#c2410c', width: 2.5 },
      { key: 'rde', name: 'Research, Development and Extension', color: '#7c3aed', width: 2.5 }
    ];
    let payload;
    try { payload = JSON.parse(outputChart.dataset.chartData || '{}'); }
    catch (error) { payload = null; }

    const validItem = (item, depth) => item
      && Number.isInteger(item.id) && item.id >= 0
      && typeof item.name === 'string' && item.name.trim() !== ''
      && Number.isInteger(item.total) && item.total >= 0
      && item.types && seriesDefinitions.slice(1).every(series => Number.isInteger(item.types[series.key])
        && item.types[series.key] >= 0 && item.types[series.key] <= item.total)
      && seriesDefinitions.slice(1).reduce((sum, series) => sum + item.types[series.key], 0) <= item.total
      && typeof item.color === 'string' && /^#[0-9a-f]{6}$/i.test(item.color)
      && Array.isArray(item.children)
      && (depth === 2 ? item.children.length === 0 : item.children.every(child => validItem(child, depth + 1)));
    const validData = payload && Array.isArray(payload.campuses)
      && payload.campuses.length > 0 && payload.campuses.every(item => validItem(item, 0));

    const showUnavailable = () => {
      navigation.hidden = true;
      choices.hidden = true;
      hint.hidden = true;
      legend.hidden = true;
      empty.hidden = true;
      outputChart.hidden = false;
      outputChart.classList.remove('is-changing');
      outputChart.classList.add('coord-chart-unavailable');
      outputChart.style.minWidth = '';
      outputChart.removeAttribute('aria-busy');
      outputChart.textContent = 'The graph is unavailable. Exact program counts are shown below.';
      campusFigures.forEach(group => { group.hidden = false; });
      figureGroups.forEach(group => { group.hidden = false; });
      figureLabel.textContent = 'View exact program counts';
      figures.open = true;
    };

    if (!window.ApexCharts || !validData) {
      showUnavailable();
    } else {
      let path = [];
      let currentItems = payload.campuses;
      let changing = false;
      let outputGraph;
      const hiddenSeries = new Set();
      const legendButtons = new Map();
      const formatCount = value => value.toLocaleString();
      const metricValue = (item, series) => series.key === 'total' ? item.total : item.types[series.key];
      const visibleSeries = () => seriesDefinitions.filter(series => !hiddenSeries.has(series.key));
      const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[character]));
      const wrapLabel = name => {
        const lines = [''];
        name.split(/\s+/).forEach(word => {
          const index = lines.length - 1;
          if (lines[index] && (lines[index] + ' ' + word).length > 24) lines.push(word);
          else lines[index] = (lines[index] + ' ' + word).trim();
        });
        return lines;
      };

      const renderLegend = () => {
        seriesDefinitions.forEach(series => {
          let button = legendButtons.get(series.key);
          if (!button) {
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'coord-output-legend-item';
            button.dataset.seriesKey = series.key;
            button.style.setProperty('--series-color', series.color);
            button.style.setProperty('--series-width', series.width + 'px');
            button.setAttribute('aria-controls', outputChart.id);
            const swatch = document.createElement('span');
            swatch.className = 'coord-output-legend-swatch';
            swatch.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.className = 'coord-output-legend-label';
            label.textContent = series.name;
            const count = document.createElement('span');
            count.className = 'coord-output-legend-count';
            button.append(swatch, label, count);
            button.addEventListener('click', async event => {
              if (changing) return;
              const keyboard = event.detail === 0;
              if (hiddenSeries.has(series.key)) hiddenSeries.delete(series.key);
              else hiddenSeries.add(series.key);
              await changeView(path);
              if (keyboard) button.focus({ preventScroll: true });
            });
            legendButtons.set(series.key, button);
            legend.append(button);
          }
          const total = currentItems.reduce((sum, item) => sum + metricValue(item, series), 0);
          const shown = !hiddenSeries.has(series.key);
          button.setAttribute('aria-pressed', String(shown));
          button.setAttribute('aria-label', (shown ? 'Hide ' : 'Show ') + series.name + ' line. ' + formatCount(total) + ' manuscripts.');
          button.title = (shown ? 'Hide ' : 'Show ') + series.name + ' line';
          button.querySelector('.coord-output-legend-count').textContent = formatCount(total);
        });
      };

      const renderChoices = () => {
        choices.replaceChildren();
        const canDrill = path.length < 2;
        choices.setAttribute('aria-label', levels[path.length].axis + ' research counts');
        currentItems.forEach((item, index) => {
          const choice = document.createElement(canDrill ? 'button' : 'div');
          choice.className = 'coord-output-choice';
          choice.style.setProperty('--output-color', seriesDefinitions[0].color);
          const dot = document.createElement('span');
          dot.className = 'coord-output-choice-dot';
          dot.setAttribute('aria-hidden', 'true');
          const copy = document.createElement('span');
          copy.className = 'coord-output-choice-copy';
          const name = document.createElement('span');
          name.className = 'coord-output-choice-name';
          name.textContent = item.name;
          copy.append(name);
          if (canDrill) {
            const actionLabel = 'View ' + levels[path.length + 1].plural;
            choice.type = 'button';
            choice.dataset.itemId = String(item.id);
            choice.setAttribute('aria-controls', outputChart.id);
            choice.setAttribute('aria-label', item.name + ': ' + formatCount(item.total) + ' manuscripts. ' + actionLabel + '.');
            const action = document.createElement('small');
            action.textContent = actionLabel;
            copy.append(action);
            choice.addEventListener('click', event => selectItem(index, event.detail === 0));
          }
          const count = document.createElement('strong');
          count.className = 'coord-output-choice-count';
          count.textContent = formatCount(item.total);
          const countLabel = document.createElement('span');
          countLabel.className = 'visually-hidden';
          countLabel.textContent = ' manuscripts';
          count.append(countLabel);
          choice.append(dot, copy, count);
          choices.append(choice);
        });
      };

      const updateInterface = () => {
        const view = levels[path.length];
        const parent = path[path.length - 1];
        const total = parent ? parent.total : currentItems.reduce((sum, item) => sum + item.total, 0);
        title.textContent = 'Research output by ' + view.single;
        heading.textContent = parent ? parent.name : 'All campuses';
        level.textContent = path.length ? ['All campuses'].concat(path.map(item => item.name)).join(' / ') : 'University overview';
        back.hidden = path.length === 0;
        backLabel.textContent = 'Back to ' + (path.length ? levels[path.length - 1].plural : 'campuses');
        itemCount.textContent = currentItems.length + ' ' + (currentItems.length === 1 ? view.single : view.plural);
        totalCount.textContent = formatCount(total) + ' ' + (total === 1 ? 'manuscript' : 'manuscripts');
        hint.textContent = path.length < 2
          ? 'Select a colored line, point, or name below to explore its ' + levels[path.length + 1].plural + '.'
          : 'Program counts for this college. Use Back to colleges to return.';
        empty.textContent = 'No ' + view.plural + ' are linked to this ' + (path.length === 2 ? 'college' : 'campus') + ' yet.';
        outputChart.setAttribute('aria-label', view.axis + ' on the X axis and research counts on the Y axis'
          + (parent ? ' for ' + parent.name : ' across all campuses') + '. Compare total research, thesis, capstone, and research, development and extension using the legend.');
        outputChart.style.minWidth = 'max(100%, ' + (currentItems.length * (path.length === 1 ? 220 : 100) + 100) + 'px)';
        outputChart.dataset.level = view.plural;
        outputChart.dataset.drillable = String(path.length < 2);
        campusFigures.forEach(group => { group.hidden = Boolean(path[0]) && group.dataset.campusId !== String(path[0].id); });
        figureGroups.forEach(group => { group.hidden = Boolean(path[1]) && group.dataset.collegeId !== String(path[1].id); });
        figureLabel.textContent = parent ? 'View exact program counts for ' + parent.name : 'View exact program counts';
        renderLegend();
        renderChoices();
      };

      const viewOptions = () => {
        const highest = Math.max(1, ...currentItems.map(item => item.total));
        const magnitude = 10 ** Math.floor(Math.log10(highest / 4));
        const step = Math.max(1, [1, 2, 5, 10].find(value => value * magnitude >= highest / 4) * magnitude);
        const maximum = Math.ceil(highest / step) * step;
        const displayedSeries = visibleSeries();
        const totalSeriesIndex = displayedSeries.findIndex(series => series.key === 'total');
        return {
          chart: { height: 390 },
          series: displayedSeries.map(series => ({
            name: series.name, data: currentItems.map(item => metricValue(item, series))
          })),
          colors: displayedSeries.map(series => series.color),
          stroke: { curve: 'smooth', width: displayedSeries.map(series => series.width), lineCap: 'round' },
          fill: { type: 'solid', opacity: 1 },
          markers: {
            size: 4, colors: ['#ffffff'], strokeColors: displayedSeries.map(series => series.color),
            strokeWidth: 2, hover: { sizeOffset: 2 }
          },
          dataLabels: { enabledOnSeries: totalSeriesIndex >= 0 ? [totalSeriesIndex] : [] },
          xaxis: {
            type: 'category',
            categories: currentItems.map(item => path.length === 1 ? wrapLabel(item.name) : item.name),
            tickPlacement: 'on',
            title: { text: levels[path.length].axis, style: { color: '#64748b', fontSize: '12px', fontWeight: 600 }, offsetY: 4 },
            axisBorder: { show: false }, axisTicks: { show: false },
            labels: {
              rotate: 0, trim: false, hideOverlappingLabels: false, maxHeight: 110,
              style: { colors: '#526172', fontSize: '11px' }
            },
            tooltip: { enabled: false }
          },
          yaxis: {
            min: 0, max: maximum, tickAmount: maximum / step,
            reversed: false, decimalsInFloat: 0,
            title: { text: 'Research count', style: { color: '#64748b', fontSize: '12px', fontWeight: 600 } },
            labels: {
              minWidth: 45, maxWidth: 65,
              formatter: value => formatCount(Math.round(value)),
              style: { colors: '#64748b', fontSize: '11px' }
            }
          }
        };
      };

      const changeView = async (nextPath, focus = false) => {
        if (changing) return;
        const previousItemId = path[path.length - 1]?.id;
        const goingBack = nextPath.length < path.length;
        changing = true;
        path = nextPath;
        currentItems = path.length ? path[path.length - 1].children : payload.campuses;
        updateInterface();
        back.disabled = true;
        legendButtons.forEach(button => { button.disabled = true; });
        choices.querySelectorAll('button').forEach(button => { button.disabled = true; });
        outputChart.setAttribute('aria-busy', 'true');
        outputChart.classList.add('is-changing');
        scroll.scrollLeft = 0;
        empty.hidden = currentItems.length > 0;
        outputChart.hidden = currentItems.length === 0;
        let failed = false;
        try {
          if (currentItems.length) {
            await Promise.all([
              outputGraph.updateOptions(viewOptions(), true, !reducedMotion, false),
              new Promise(resolve => window.setTimeout(resolve, reducedMotion ? 0 : 450))
            ]);
          }
        } catch (error) {
          failed = true;
          showUnavailable();
        } finally {
          changing = false;
          back.disabled = false;
          legendButtons.forEach(button => { button.disabled = false; });
          choices.querySelectorAll('button').forEach(button => { button.disabled = false; });
          outputChart.classList.remove('is-changing');
          outputChart.removeAttribute('aria-busy');
        }
        if (focus && !failed) {
          const target = goingBack ? Array.from(choices.querySelectorAll('button'))
            .find(button => button.dataset.itemId === String(previousItemId)) : back;
          target?.focus({ preventScroll: true });
        }
      };

      const selectItem = (index, focus = false) => {
        if (path.length >= 2 || changing || !Number.isInteger(index) || !currentItems[index]) return;
        changeView(path.concat(currentItems[index]), focus);
      };
      back.addEventListener('click', event => changeView(path.slice(0, -1), event.detail === 0));
      updateInterface();

      const initialOptions = viewOptions();
      outputGraph = new window.ApexCharts(outputChart, {
        ...initialOptions,
        chart: {
          ...initialOptions.chart,
          type: 'line', toolbar: { show: false }, zoom: { enabled: false }, parentHeightOffset: 0,
          fontFamily: 'Public Sans, Segoe UI, Arial, sans-serif',
          animations: {
            enabled: !reducedMotion, easing: 'easeinout', speed: 650,
            animateGradually: { enabled: false }, dynamicAnimation: { enabled: true, speed: 450 }
          },
          events: {
            click: event => {
              if (path.length >= 2 || changing) return;
              const bounds = outputChart.querySelector('.apexcharts-grid')?.getBoundingClientRect();
              if (!bounds?.width || event.clientX < bounds.left - 10 || event.clientX > bounds.right + 10
                || event.clientY < bounds.top - 10 || event.clientY > bounds.bottom + 10) return;
              // The category's full column is tappable, including zero totals.
              const index = Math.round(((event.clientX - bounds.left) / bounds.width) * (currentItems.length - 1));
              selectItem(Math.max(0, Math.min(currentItems.length - 1, index)));
            }
          }
        },
        legend: { show: false },
        dataLabels: {
          ...initialOptions.dataLabels,
          enabled: true, offsetX: 0, offsetY: -12,
          formatter: (value, options) => formatCount(currentItems[options.dataPointIndex]?.total || 0),
          style: { fontSize: '12px', fontWeight: 700, colors: ['#334155'] }, background: { enabled: false }
        },
        grid: {
          borderColor: '#e8edf2', strokeDashArray: 4,
          xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } },
          padding: { top: 22, left: 12, right: 24, bottom: 8 }
        },
        tooltip: {
          enabled: true, shared: true, intersect: false,
          custom: ({ dataPointIndex }) => {
            const item = currentItems[dataPointIndex];
            if (!item) return '';
            const rows = visibleSeries().map(series => '<div class="coord-output-tooltip-row">'
              + '<span class="coord-output-tooltip-dot" style="background:' + series.color + '"></span>'
              + '<span>' + escapeHtml(series.name) + '</span><strong>' + formatCount(metricValue(item, series)) + '</strong></div>');
            return '<div class="coord-output-tooltip"><strong class="coord-output-tooltip-title">' + escapeHtml(item.name)
              + '</strong>' + rows.join('') + '</div>';
          }
        },
        noData: { text: 'Select a line in the legend to display research output.', style: { color: '#64748b', fontSize: '12px' } },
        states: { hover: { filter: { type: 'none' } }, active: { filter: { type: 'none' } } }
      });

      initialCharts.push(outputGraph.render().then(() => {
        navigation.hidden = false;
        hint.hidden = false;
        legend.hidden = false;
        choices.hidden = false;
      }).catch(showUnavailable));
    }
  }

  const chart = document.getElementById('college-output-chart');
  if (!chart || chart.querySelectorAll('[data-chart-view]').length === 0) {
    Promise.all(initialCharts).then(markDirectorReady);
    return;
  }

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
  Promise.all(initialCharts).then(markDirectorReady);
})();
