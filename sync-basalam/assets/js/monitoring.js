/* Dashboard requests stay on the WordPress admin origin; credentials stay in PHP. */
(() => {
    'use strict';
    const root = document.querySelector('.basalam-monitoring-page');
    if (!root || !window.woosalamMonitoring) return;
    const config = window.woosalamMonitoring;
    const find = (selector) => root.querySelector(selector);
    const all = (selector) => [...root.querySelectorAll(selector)];
    const message = (selector, text = '') => {
        const element = find(selector);
        element.textContent = text;
        element.hidden = !text;
    };
    const format = () => {
        all('time[datetime]').forEach(element => {
            try {
                element.textContent = new Intl.DateTimeFormat('fa-IR', {
                    dateStyle: 'medium', timeStyle: 'short', timeZone: config.timezone || 'UTC'
                }).format(new Date(element.dateTime));
            } catch (_) { /* Keep the server-rendered date if Intl/timezone is unavailable. */ }
        });
    };
    const request = async (operation, values = {}) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), operation.startsWith('purchase_') ? 45000 : 8000);
        try {
            const response = await fetch(config.ajaxUrl, {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                body: new URLSearchParams({ action: 'sync_basalam_monitoring', nonce: config.nonce, operation, ...values })
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data?.message || 'درخواست کامل نشد؛ صفحه را تازه کنید.');
            return result.data;
        } catch (error) {
            if (error.name === 'AbortError' || error instanceof TypeError || error instanceof SyntaxError) {
                throw new Error('پاسخ در مهلت مقرر دریافت نشد. کمی بعد دوباره تلاش کنید.');
            }
            throw error;
        } finally { clearTimeout(timer); }
    };
    let tab = 'performance', statusVersion = 0, privateVersion = 0, statusBusy = false, mutation = false, planLoaded = false, planBusy = false;
    const dialog = find('#monitoring-changes-dialog');
    let dialogTrigger = null;
    let purchaseBusy = false, purchaseLoaded = false;
    const returnedKey = new URLSearchParams(window.location.search).get('idempotency_key');
    const validKey = value => /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value || '');
    let paymentKey = validKey(returnedKey) ? returnedKey : null;
    function showPayment(data) {
        find('#monitoring-payment-content').innerHTML = data.html;
        paymentKey = find('.monitoring-payment')?.dataset.paymentKey || null;
        const form = find('#monitoring-plan-form');
        // A new purchase always requires the explicit terminal-state action.
        if (form) form.hidden = data.status !== 'none';
        format();
    }
    async function paymentStatus(check = false) {
        if (purchaseBusy) return;
        purchaseBusy = true;
        message('#monitoring-plans-message', 'در حال بررسی نتیجهٔ خرید…');
        try {
            const data = await request(check ? 'purchase_check' : 'purchase_status', paymentKey ? { key: paymentKey } : {});
            showPayment(data);
            purchaseLoaded = true;
            message('#monitoring-plans-message');
            if (data.activated) await refresh(true);
        } catch (error) { message('#monitoring-plans-message', error.message); }
        finally { purchaseBusy = false; }
    }
    async function newPurchase() {
        if (purchaseBusy) return;
        purchaseBusy = true;
        try {
            const data = await request('purchase_new');
            showPayment(data);
            const url = new URL(window.location.href);
            url.searchParams.delete('idempotency_key'); url.searchParams.delete('payment_return');
            window.history.replaceState(null, '', url);
            message('#monitoring-plans-message', 'برای خرید جدید، پلن و شرایط را دوباره انتخاب کنید.');
            const form = find('#monitoring-plan-form');
            if (form) form.reset();
        } catch (error) { message('#monitoring-plans-message', error.message); }
        finally { purchaseBusy = false; }
    }
    async function purchase(form) {
        if (purchaseBusy || !form.reportValidity()) return;
        const plan = new FormData(form).get('plan');
        if (!plan) { message('#monitoring-plans-message', 'ابتدا پلن را انتخاب کنید.'); return; }
        purchaseBusy = true;
        const submit = form.querySelector('button[type=submit]');
        submit.disabled = true;
        try {
            message('#monitoring-plans-message', 'در حال بررسی مجوز غرفه…');
            const prepared = await request('purchase_prepare');
            if (!prepared.ready) throw new Error('مجوز غرفه آماده نیست؛ اتصال باسلام را بررسی کنید.');
            message('#monitoring-plans-message', 'در حال بررسی اتصال و ظرفیت فروشگاه…');
            const preflight = await request('purchase_preflight', { plan });
            if (!preflight.eligible) throw new Error([preflight.message, preflight.guidance].filter(Boolean).join(' '));
            message('#monitoring-plans-message', 'در حال ساخت فاکتور…');
            const data = await request('purchase_checkout', { plan, accepted: '1' });
            showPayment(data);
            message('#monitoring-plans-message', 'جزئیات فاکتور را بررسی کنید؛ برای پرداخت دکمهٔ ادامه را بزنید.');
        } catch (error) {
            message('#monitoring-plans-message', error.message + ' اگر فاکتور ساخته شده، از پیگیری آخرین خرید استفاده کنید.');
        } finally { purchaseBusy = false; submit.disabled = false; }
    }
    const pages = {
        history: { cursors: [null], page: 0, next: null, busy: false, loaded: false },
        changes: { cursors: [null], page: 0, next: null, busy: false, loaded: false, scan: null }
    };
    function syncPlans() {
        const meta = find('.monitoring-meta');
        all('#monitoring-plan-form input[name="plan"]').forEach(input => {
            input.disabled = !meta || (meta.dataset.active === '1' && input.value !== meta.dataset.plan);
            if (input.disabled) input.checked = false;
        });
    }
    function selectTab(next, focus = false) {
        tab = next;
        all('[role="tab"]').forEach(button => {
            const selected = button.dataset.tab === tab;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            if (selected && focus) button.focus();
        });
        all('.monitoring-pane').forEach(pane => { pane.hidden = pane.id !== `monitoring-pane-${tab}`; });
        find('#monitoring-plan-section').hidden = tab !== 'subscription';
        if (tab === 'history' && !pages.history.loaded) loadPage('history', 0, true);
        if (tab === 'subscription' && !planLoaded) loadPlans();
        if (tab === 'subscription' && !purchaseLoaded && !purchaseBusy) paymentStatus();
    }
    function replaceStatus(data) {
        // This fragment is escaped on the server and never contains upstream HTML.
        find('#monitoring-dashboard').innerHTML = data.html;
        if (!data.available) {
            ++privateVersion;
            find('#monitoring-history-content').textContent = 'برای مشاهدهٔ تاریخچه، اتصال و گزارش تازه را بررسی کنید.';
            find('#monitoring-changes-content').textContent = '';
            pages.history.loaded = false;
            pages.history.page = 0;
            pages.history.next = null;
            pages.history.cursors = [null];
            pages.changes.scan = null;
            pagination('history');
            if (dialog.open) dialog.close();
        }
        selectTab(tab);
        syncPlans();
        format();
    }
    async function refresh(force = false, quiet = false) {
        if (statusBusy || mutation) return;
        const version = ++statusVersion;
        statusBusy = true;
        all('[data-action="refresh"]').forEach(button => { button.disabled = true; });
        if (!quiet) message('#monitoring-message', 'در حال دریافت گزارش…');
        try {
            const data = await request('status', { refresh: force ? '1' : '0' });
            if (version !== statusVersion) return;
            // A poll started on another tab must not erase an in-progress consent form.
            if (!quiet || tab !== 'settings') replaceStatus(data);
            if (!quiet) message('#monitoring-message', data.stale ? 'گزارش تازه دریافت نشد؛ آخرین اطلاعات ذخیره‌شده نمایش داده شده است.' : '');
        } catch (error) { if (version === statusVersion) message('#monitoring-message', error.message); }
        finally {
            statusBusy = false;
            all('[data-action="refresh"]').forEach(button => { button.disabled = mutation; });
        }
    }
    function pagination(kind) {
        const state = pages[kind];
        find(`[data-action="${kind}-prev"]`).disabled = state.busy || state.page === 0;
        find(`[data-action="${kind}-next"]`).disabled = state.busy || !state.next;
        find(`#monitoring-${kind}-page`).textContent = state.loaded ? `صفحهٔ ${(state.page + 1).toLocaleString('fa-IR')}` : '';
    }
    async function loadPage(kind, target, reset = false) {
        const state = pages[kind];
        if (state.busy) return;
        const cursor = reset ? null : target > state.page ? state.next : state.cursors[target];
        const scan = state.scan;
        const version = privateVersion;
        state.busy = true;
        pagination(kind);
        message(`#monitoring-${kind}-message`, 'در حال دریافت…');
        try {
            const data = await request(kind, { ...(cursor ? { cursor } : {}), ...(kind === 'changes' ? { scan } : {}) });
            // Closing a dialog and opening another scan must not mix the two reports.
            if (version !== privateVersion || (kind === 'changes' && state.scan !== scan)) return;
            if (reset) state.cursors = [null];
            state.cursors[target] = cursor;
            state.page = target;
            state.next = data.next_cursor;
            state.loaded = true;
            find(`#monitoring-${kind}-content`).innerHTML = data.html;
            message(`#monitoring-${kind}-message`);
            format();
        } catch (error) { message(`#monitoring-${kind}-message`, `${error.message} محتوای قبلی، در صورت وجود، حفظ شده است.`); }
        finally { state.busy = false; pagination(kind); }
    }
    async function loadPlans() {
        if (planBusy) return;
        planBusy = true;
        message('#monitoring-plans-message', 'در حال دریافت پلن‌ها…');
        try {
            const data = await request('plans');
            find('#monitoring-plans-content').innerHTML = data.html;
            planLoaded = true;
            syncPlans();
            if (find('.monitoring-payment')) find('#monitoring-plan-form').hidden = true;
            if (!purchaseBusy) message('#monitoring-plans-message');
        } catch (error) { message('#monitoring-plans-message', error.message); }
        finally { planBusy = false; }
    }
    root.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button || button.disabled) return;
        if (button.dataset.tab || button.dataset.goTab) selectTab(button.dataset.tab || button.dataset.goTab);
        const action = button.dataset.action;
        if (action === 'refresh') refresh(true);
        if (action === 'history-refresh') loadPage('history', 0, true);
        if (action === 'plans-refresh') loadPlans();
        if (action === 'purchase-check') paymentStatus(true);
        if (action === 'purchase-new') newPurchase();
        if (action === 'changes' && !pages.changes.busy) {
            pages.changes = { cursors: [null], page: 0, next: null, busy: false, loaded: false, scan: button.dataset.scan };
            find('#monitoring-changes-content').textContent = '';
            dialogTrigger = button;
            dialog.showModal();
            loadPage('changes', 0, true);
        }
        if (action === 'changes-close') dialog.close();
        for (const kind of ['history', 'changes']) {
            if (action === `${kind}-next` && pages[kind].next) loadPage(kind, pages[kind].page + 1);
            if (action === `${kind}-prev` && pages[kind].page > 0) loadPage(kind, pages[kind].page - 1);
        }
    });
    root.addEventListener('keydown', event => {
        if (event.target.getAttribute('role') !== 'tab') return;
        const tabs = all('[role="tab"]'), index = tabs.indexOf(event.target);
        const offset = { ArrowLeft: 1, ArrowRight: -1 }[event.key];
        if (offset === undefined && !['Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + offset + tabs.length) % tabs.length;
        selectTab(tabs[next].dataset.tab, true);
    });
    root.addEventListener('change', event => {
        if (event.target.matches('#monitoring-plan-form input[name="plan"]')) {
            find('#monitoring-plan-selection').textContent = `پلن انتخاب‌شده: ${event.target.closest('label').querySelector('span').textContent}`;
        }
    });
    root.addEventListener('submit', async event => {
        if (event.target.id === 'monitoring-plan-form') { event.preventDefault(); purchase(event.target); return; }
        if (event.target.id !== 'monitoring-settings-form') return;
        event.preventDefault();
        if (mutation || !event.target.reportValidity()) return;
        const form = new FormData(event.target);
        mutation = true;
        ++statusVersion;
        const submit = event.target.querySelector('button[type="submit"]');
        submit.disabled = true;
        message('#monitoring-message', 'در حال ثبت تنظیم…');
        try {
            const data = await request('settings', { enabled: form.get('enabled'), accepted: form.get('accepted') || '0' });
            replaceStatus(data);
            message('#monitoring-message', 'تنظیم اصلاح خودکار ثبت شد.');
            find('#monitoring-settings-form button').focus();
        } catch (error) {
            // Do not repeat an ambiguous mutation. The seller explicitly refreshes to verify it.
            message('#monitoring-message', `${error.message} پیش از تلاش دوباره، گزارش را تازه کنید.`);
        } finally { mutation = false; all('[data-action="refresh"]').forEach(button => { button.disabled = statusBusy; }); }
    });
    dialog.addEventListener('close', () => { if (dialogTrigger?.isConnected) dialogTrigger.focus(); });
    format();
    if (paymentKey) { tab = 'subscription'; selectTab(tab); }
    refresh();
    setInterval(() => {
        if (!document.hidden && !dialog.open && !['settings', 'subscription'].includes(tab) && !purchaseBusy) refresh(false, true);
    }, 30000);
})();
