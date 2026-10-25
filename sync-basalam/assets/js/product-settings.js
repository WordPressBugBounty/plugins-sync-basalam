document.addEventListener('DOMContentLoaded', function () {
  const forms = Array.from(document.querySelectorAll('[data-basalam-ajax-settings]'));
  const dialog = document.getElementById('basalam-settings-update-dialog');
  if (!forms.length || !dialog) return;

  const updateButton = dialog.querySelector('[data-basalam-settings-update]');
  const dismissButton = dialog.querySelector('[data-basalam-settings-dismiss]');
  let saving = false;
  let updating = false;
  let returnFocus;
  let previousOverflow = '';

  function feedback(root, message, error = false) {
    const region = root.querySelector('.basalam-settings-feedback');
    region.textContent = message;
    region.classList.toggle('is-error', error);
  }

  async function request(body) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
      const response = await fetch(ajaxurl, {
        method: 'POST', credentials: 'same-origin', body, signal: controller.signal,
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.data?.message || 'درخواست انجام نشد. لطفاً دوباره تلاش کنید.');
      }
      return result.data;
    } catch (error) {
      if (error.name === 'AbortError' || error instanceof TypeError || error instanceof SyntaxError) {
        throw new Error('پاسخ سرور دریافت نشد. اتصال را بررسی و دوباره تلاش کنید.');
      }
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  function syncFields(settings) {
    forms.forEach(form => {
      Object.entries(settings).forEach(([key, saved]) => {
        const value = saved === null ? '' : String(saved);
        const name = 'sync_basalam_settings[' + key + ']';
        const fields = Array.from(form.elements).filter(field => field.name === name);
        fields.forEach(field => {
          if (field.type === 'checkbox') {
            field.checked = field.value === value || (saved === true && field.value === 'true');
          } else if (field.type === 'hidden' && fields.some(item => item.type === 'checkbox')) {
            // Preserve the unchecked sentinel (empty/no/false), even after a checked save.
            return;
          } else if (key !== 'price_change_value' || field.type === 'hidden') {
            field.value = value;
          }
        });

        if (key === 'price_change_value') {
          const input = form.querySelector('[data-role="price-change-input"]');
          const toggle = form.querySelector('.toggle-percentage');
          if (input && toggle) {
            toggle.checked = value === 'commission';
            input.value = toggle.checked ? '' : Number(value).toLocaleString('en-US');
            toggle.dispatchEvent(new Event('change', {bubbles: true}));
          }
        }
      });
    });
  }

  function showUpdateDialog(form, submitter) {
    const advanced = document.getElementById('basalam-modal');
    returnFocus = form.id === 'basalam-advanced-settings-form'
      ? document.getElementById('sync-basalam-onboarding-advanced-settings') : submitter;
    if (advanced) advanced.style.display = 'none';
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    feedback(dialog, '');
    dialog.showModal();
  }

  dialog.addEventListener('close', () => {
    const toasts = dialog.querySelector('#basalam-toast-container');
    if (toasts) document.body.appendChild(toasts);
    document.body.style.overflow = previousOverflow;
    if (returnFocus?.isConnected) returnFocus.focus();
  });
  dialog.addEventListener('cancel', event => {
    if (updating) event.preventDefault();
  });
  dismissButton.addEventListener('click', () => dialog.close());

  forms.forEach(form => {
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (saving || updating) return;
      saving = true;

      // Existing price and checkbox handlers normalize values before this listener.
      const body = new FormData(form);
      const submitter = event.submitter || form.querySelector('[type="submit"]');
      const originalLabel = submitter?.innerHTML;
      const controls = forms.flatMap(item => Array.from(item.elements)).map(field => [field, field.disabled]);
      controls.forEach(([field]) => { field.disabled = true; });
      if (submitter) submitter.textContent = 'در حال ذخیره…';
      form.setAttribute('aria-busy', 'true');
      feedback(form, 'در حال ذخیره تنظیمات…');

      let data;
      try {
        data = await request(body);
      } catch (error) {
        feedback(form, error.message, true);
      } finally {
        controls.forEach(([field, disabled]) => { field.disabled = disabled; });
        if (submitter) submitter.innerHTML = originalLabel;
        form.removeAttribute('aria-busy');
        saving = false;
      }

      if (!data) return;
      syncFields(data.settings);
      document.dispatchEvent(new CustomEvent('basalam:settings-saved', {detail: {form, settings: data.settings}}));
      if (data.product_settings_changed) showUpdateDialog(form, submitter);
      if (window.BasalamToast) {
        feedback(form, '');
        window.BasalamToast.success(data.message);
        // Native modal dialogs live above ordinary z-index layers.
        const toasts = document.getElementById('basalam-toast-container');
        if (dialog.open && toasts) dialog.appendChild(toasts);
      } else {
        feedback(form, data.message);
      }
    });
  });

  updateButton.addEventListener('click', async () => {
    if (updating || saving) return;
    updating = true;
    updateButton.disabled = true;
    dismissButton.disabled = true;
    const originalLabel = updateButton.textContent;
    updateButton.textContent = 'در حال شروع بروزرسانی…';
    dialog.setAttribute('aria-busy', 'true');
    feedback(dialog, '');
    const body = new FormData();
    body.set('action', 'update_products_in_basalam');
    body.set('update_type', 'full');
    body.set('_wpnonce', dialog.querySelector('[name="_wpnonce"]').value);
    try {
      const data = await request(body);
      dialog.close();
      feedback(document.getElementById('basalam-settings-form'), data.message);
      if (window.BasalamToast) window.BasalamToast.success(data.message);
    } catch (error) {
      feedback(dialog, error.message, true);
    } finally {
      updating = false;
      updateButton.disabled = false;
      dismissButton.disabled = false;
      updateButton.textContent = originalLabel;
      dialog.removeAttribute('aria-busy');
    }
  });
});
