document.addEventListener('DOMContentLoaded', function () {
  const config = window.basalamProductQueueStatus;
  if (!config) return;

  const updateModal = document.getElementById('BasalamUpdateProductsModal');
  const createModal = document.getElementById('basalamAddProductsModal');
  const modals = [updateModal, createModal].filter(Boolean);
  if (!modals.length) return;

  let timer;
  let request;
  const visible = () => !document.hidden && modals.some(modal => modal.getClientRects().length > 0);
  const show = (id, value) => {
    const element = document.getElementById(id);
    if (element) element.hidden = !value;
  };
  const count = (prefix, value) => {
    const element = document.getElementById(prefix + '-queue-count');
    if (element) element.textContent = Number(value).toLocaleString('fa-IR') + ' محصول';
    show(prefix + '-queue-count-row', value > 0);
  };
  function render(status) {
    show('update-type-selection', !status.active);
    show('quick-update-in-progress', status.active && status.type === 'quick');
    show('active-jobs-info', status.active && status.type === 'full');
    count('update', status.count);
    const quickStatus = document.getElementById('quick-update-status');
    if (quickStatus) quickStatus.textContent = status.status === 'processing' ? 'در حال پردازش' : 'در انتظار';

    const creation = status.create;
    if (!creation) return;
    count('create', creation.count);
    show('active-create-jobs-info', creation.active);
    show('BasalamCancelCreateJobs', creation.active);
    show('basalamAddProductsForm', !creation.active);
  }

  function stop() {
    clearTimeout(timer);
    if (request) request.abort();
  }

  async function refresh() {
    clearTimeout(timer);
    if (!visible() || request) return;
    const controller = new AbortController();
    request = controller;
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const body = new URLSearchParams({action: 'get_update_queue_status', _wpnonce: config.nonce});
      const response = await fetch(config.url, {method: 'POST', body, signal: controller.signal});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error('Queue status unavailable');
      if (visible()) render(result.data);
    } catch (error) {
      // Queue counters remain visible with their last rendered values when a
      // refresh fails; no explanatory status text is shown in the dialogs.
    } finally {
      clearTimeout(timeout);
      request = null;
      if (visible()) timer = setTimeout(refresh, 10000);
    }
  }

  function checkVisibility() {
    if (visible()) refresh();
    else stop();
  }
  const observer = new MutationObserver(checkVisibility);
  modals.forEach(modal => observer.observe(modal, {attributes: true, attributeFilter: ['style', 'class', 'hidden']}));
  document.addEventListener('visibilitychange', checkVisibility);
  window.addEventListener('pagehide', stop);
});
