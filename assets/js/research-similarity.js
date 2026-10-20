(function () {
  'use strict';
  const configElement = document.getElementById('research-match-config');
  if (!configElement) return;
  const config = JSON.parse(configElement.textContent);
  const startButton = document.getElementById('rm-start');
  const resumeButton = document.getElementById('rm-resume');
  const pauseButton = document.getElementById('rm-pause');
  const message = document.getElementById('rm-action-message');
  const jobMessage = document.getElementById('rm-job-message');
  let batch = config.batch;
  const initiallyCompleted = batch ? Number(batch.completed_runs || 0) : 0;
  let working = false;
  let starting = false;
  let pauseRequested = false;
  let startRequestId = null;
  const randomId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('');
  const format = value => Number(value || 0).toLocaleString();

  const request = async (action, extra = {}) => {
    const response = await fetch(config.endpoint, {
      method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' },
      body: new URLSearchParams({ csrf_token: config.csrf, action, batch_id: batch ? batch.batch_id : '', ...extra }),
    });
    if (response.redirected) throw new Error('Your session needs attention. Sign in again, then resume the saved evaluation.');
    let payload;
    try { payload = await response.json(); } catch (_) { throw new Error('The server returned invalid JSON (HTTP ' + response.status + '). Check the server error log; saved progress is retained.'); }
    if (!response.ok || !payload.batch) throw new Error(payload.message || 'Unable to continue. Saved progress is retained.');
    return payload.batch;
  };

  const render = () => {
    if (!batch) return;
    document.getElementById('rm-job').hidden = false;
    document.getElementById('rm-job-title').textContent = 'Evaluation #' + batch.batch_id + ' · ' + batch.status.charAt(0).toUpperCase() + batch.status.slice(1);
    document.getElementById('rm-job-count').textContent = format(batch.processed_comparisons) + ' / ' + format(batch.expected_comparisons) + ' calculations · ' + format(batch.completed_runs) + ' / ' + format(batch.catalog_count) + ' manuscripts';
    const progress = document.getElementById('rm-progress');
    progress.max = Math.max(1, Number(batch.expected_comparisons));
    progress.value = Number(batch.processed_comparisons);
    const url = new URL(config.collectionUrl, location.origin);
    url.searchParams.set('batch', batch.batch_id);
    document.getElementById('rm-job-open').href = url.href;
    resumeButton.hidden = working || starting || batch.status === 'completed';
    pauseButton.hidden = !working;
    pauseButton.disabled = pauseRequested;
    startButton.disabled = working || starting || !config.canStart;
    if (Number(batch.completed_runs) > initiallyCompleted) {
      const status = document.getElementById('rm-auto-live-status');
      const empty = document.getElementById('rm-auto-empty');
      if (empty) empty.hidden = true;
      if (status) status.textContent = 'Automated reports are saved for ' + format(batch.completed_runs) + ' completed source runs. Open View saved runs in a new tab to inspect the latest reports while processing continues.';
    }
  };

  const process = async () => {
    if (working || starting || !batch || batch.status === 'completed') return;
    working = true;
    pauseRequested = false;
    render();
    jobMessage.textContent = 'Calculating and saving in short batches. You can pause at any time.';
    try {
      batch = await request('resume');
      while (batch.status === 'running' && !pauseRequested) {
        batch = await request('process');
        render();
      }
      if (pauseRequested && batch.status !== 'completed') batch = await request('pause');
      jobMessage.textContent = batch.status === 'completed'
        ? 'Evaluation completed. All calculations are saved. Open saved runs to review or export them.'
        : 'Evaluation paused. Resume continues from the saved position.';
    } catch (error) {
      jobMessage.textContent = error.message;
      // A request can commit despite a lost response. Resume reads the persisted cursor.
    } finally {
      working = false;
      pauseRequested = false;
      render();
    }
  };

  startButton.addEventListener('click', async () => {
    if (working || starting) return;
    starting = true;
    startButton.disabled = true;
    render();
    message.textContent = 'Saving the catalog snapshot and preparing evaluation runs…';
    startRequestId = startRequestId || randomId();
    try {
      batch = await request('start', { request_id: startRequestId });
      startRequestId = null;
      starting = false;
      message.textContent = 'Catalog snapshot saved. Each manuscript has its own system-generated run.';
      render();
      await process();
    } catch (error) {
      message.textContent = error.message;
      startButton.disabled = false;
    } finally {
      starting = false;
      render();
    }
  });
  resumeButton.addEventListener('click', process);
  pauseButton.addEventListener('click', () => {
    pauseRequested = true;
    pauseButton.disabled = true;
    jobMessage.textContent = 'Pausing after the current request finishes saving…';
  });
  render();
})();
