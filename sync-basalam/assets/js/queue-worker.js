// A connected admin request can reach PHP when the host drops server loopbacks.
// The server's advisory lock and rate limit also apply to this worker.
(function () {
  const config = window.syncBasalamQueueWorker;
  if (!config) return;
  let timer;
  let running = false;
  let stopped = false;

  function schedule(delay) {
    clearTimeout(timer);
    if (!stopped && !document.hidden) timer = setTimeout(run, delay);
  }

  async function run() {
    if (running || stopped || document.hidden) return;
    running = true;
    let delay = 30000;
    try {
      // Keep the connection open until PHP returns. Aborting on a short timer
      // would recreate the LiteSpeed disconnect failure this path recovers.
      const body = new URLSearchParams({action: config.action, nonce: config.nonce});
      const response = await fetch(config.url, {method: 'POST', credentials: 'same-origin', body});
      if (response.status === 401 || response.status === 403) {
        stopped = true;
        return;
      }
      const result = await response.json();
      if (response.ok && result.success && result.data.processed > 0) delay = 1000;
    } catch (error) {
      // Retry quietly after transient host/network failures.
    } finally {
      running = false;
      schedule(delay);
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) clearTimeout(timer);
    else schedule(1000);
  });
  window.addEventListener('pagehide', function () { stopped = true; clearTimeout(timer); });
  window.addEventListener('pageshow', function () { stopped = false; schedule(1000); });
  schedule(1000);
})();
