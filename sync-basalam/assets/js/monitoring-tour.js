/*
 * MonitoringTour: dependency-free, WordPress onboarding.
 * start({ selectTab(tab), onFinish({completed, reason, step}), trigger? })
 * stop() closes without marking completion. Persistence and purchase eligibility
 * belong to the host app. SelectTab may return a promise; it must expose the tab.
 * Stable anchors: [data-tour="impact|latest-scan|issues|history|settings|subscription|refresh"].
 */
(() => {
  'use strict';
  const fa = value => Number(value).toLocaleString('fa-IR');
  const motion = () => !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fullSteps = [
    { tab: 'overview', target: 'impact', fallback: '.impact-grid', icon: 'shield', title: 'اثر پایشگر را ببینید', text: 'تعداد محصولات اصلاح‌شده و مجموع اختلاف قیمت‌های اصلاح‌شده اینجاست. این آمار نشان می‌دهد پایشگر کدام خطاهای قیمت و موجودی را برطرف کرده است.' },
    { tab: 'overview', target: 'latest-scan', fallback: '.kpis', icon: 'chart-line', title: 'عملکرد همهٔ دورها', text: 'مجموع بررسی‌ها، اصلاح‌ها و مغایرت‌ها را در تمام دورهای ثبت‌شده ببینید.' },
    { tab: 'history', target: 'issues', fallback: '#issues-section', icon: 'attention', title: 'قدم بعدی را پیدا کنید', text: 'هر مورد را باز کنید تا دلیل مغایرت و راه رسیدگی به همان محصول را ببینید. از راهنمای هر مورد برای بررسی اتصال، قیمت یا موجودی کمک بگیرید.' },
    { tab: 'history', target: 'history', preferred: '[data-tour="history"] .scan-row > summary', fallback: '#panel-history > .section-heading', icon: 'time', title: 'مسیر هر اصلاح را دنبال کنید', text: 'هر دور پایش را برای دیدن نتیجه باز کنید. در جزئیات اصلاح‌ها، مقدار قبلی و مقدار تأییدشدهٔ قیمت یا موجودی کنار هم قرار دارند.' },
    { tab: 'settings', target: 'settings', fallback: '#panel-settings', icon: 'wrench', title: 'کنترل اصلاح‌ها دست شماست', text: 'با اصلاح خودکار، قیمت و موجودی باسلام از مرجع ووسلام به‌روز می‌شود. می‌توانید اصلاح را با تأیید خودتان متوقف کنید و همچنان گزارش پایش را ببینید.' },
    { tab: 'subscription', target: 'subscription', fallback: '#panel-subscription', icon: 'wallet', title: 'اشتراک مناسب فروشگاهتان', text: 'ظرفیت پلن، تعداد محصولات در حال پایش و زمان باقی‌مانده را اینجا ببینید. پس از پایان دوره، پلن تازه را انتخاب و پرداخت را از همین بخش ادامه دهید.' },
    { tab: 'overview', target: 'refresh', preferred: '[data-action="refresh"]', icon: 'refresh', title: 'همیشه از آخرین گزارش شروع کنید', text: 'این دکمه تازه‌ترین نتیجهٔ ثبت‌شده را نمایش می‌دهد. هر زمان خواستید مراحل را دوباره مرور کنید، «راهنمای پایشگر» در دسترس شماست.' }
  ];
  const coverageSteps = [
    {tab:'coverage',target:'coverage-search',icon:'search',title:'محصولتان را پیدا کنید',text:'عنوان یا شناسهٔ ووکامرس را بنویسید؛ جستجو پس از مکث کوتاه انجام می‌شود. با Enter هم می‌توانید جستجو کنید. جستجو روی تمام محصولات متصل به غرفه انجام می‌شود؛ شناسه را با ارقام فارسی هم می‌توانید وارد کنید.'},
    {tab:'coverage',target:'coverage-filters',icon:'box',title:'همهٔ محصولات، در یک فهرست',text:'فیلتر «همه» تمام محصولات متصل را نشان می‌دهد. با «در حال پایش» و «بدون پایش» انتخاب‌ها را جدا ببینید. جستجو با همین فیلترها ترکیب می‌شود.'},
    {tab:'coverage',target:'coverage-default',icon:'chart-line',title:'پرفروش‌ترین‌ها در اولویت‌اند',text:'انتخاب خودکار بر اساس تعداد فروش ثبت‌شده در ووکامرس است. در فروش مساوی، شناسهٔ کوچک‌تر اولویت دارد. انتخاب‌های ثبت‌شده حفظ می‌شوند و شما می‌توانید آن‌ها را تغییر دهید.'},
    {tab:'coverage',target:'coverage-actions',fallback:'.coverage-catalog',icon:'refresh',title:'خروج یا جایگزینی محصول',text:'از منوی سه‌نقطه، «خروج از پایش» را انتخاب کنید. با «جایگزینی»، پنجره‌ای باز می‌شود؛ محصول جدید را جستجو و انتخاب کنید و «تأیید جایگزینی» را بزنید. انصراف تغییری ایجاد نمی‌کند.'},
    {tab:'coverage',target:'coverage-capacity',icon:'shield',title:'ظرفیت را مدیریت کنید',text:'هر محصول با همهٔ تنوع‌هایش فقط یک جایگاه می‌گیرد. تا وقتی ظرفیت خالی دارید، محصولات بدون پایش را اضافه کنید؛ با ظرفیت پر هم جایگزینی ممکن است.'}
  ];
  let active = null;
  let serial = 0;
  const icon = name => `<span class="mt-icon" aria-hidden="true">${window.WoosalamIcons[name] || window.WoosalamIcons.info}</span>`;
  const afterPaint = () => new Promise(resolve => {
    let firstFrame = 0, secondFrame = 0, complete = false;
    const done = () => {
      if (complete) return;
      complete = true;
      clearTimeout(deadline);
      cancelAnimationFrame(firstFrame);
      cancelAnimationFrame(secondFrame);
      resolve();
    };
    // Hidden browser tabs can suspend animation frames. A paint is useful for
    // geometry, but must never leave the guide's controls waiting indefinitely.
    const deadline = setTimeout(done, 60);
    firstFrame = requestAnimationFrame(() => { secondFrame = requestAnimationFrame(done); });
  });
  const focusable = root => [...root.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), [tabindex="0"]')].filter(node => node.getClientRects().length);

  function viewportBounds() {
    const viewport = window.visualViewport;
    const width = viewport?.width || window.innerWidth;
    const height = viewport?.height || window.innerHeight;
    const x = viewport?.offsetLeft || 0, y = viewport?.offsetTop || 0;
    const bar = document.querySelector('#wpadminbar')?.getBoundingClientRect();
    const inset = bar && bar.top <= y && bar.bottom > y ? bar.bottom - y : 0;
    return { width, height, x, y, inset, margin: width < 600 ? 12 : 24 };
  }

  function targetFor(tour, step) {
    const viewport = viewportBounds();
    const selectors = [
      step.preferred, `[data-tour="${step.target}"]`, step.fallback
    ].filter(Boolean);
    for (const selector of selectors) {
      const node = [...tour.root.querySelectorAll(selector)].find(node => node.getClientRects().length && !node.closest('[hidden]'));
      if (!node) continue;
      const box = node.getBoundingClientRect();
      const cardWidth = Math.min(380, viewport.width - viewport.margin * 2);
      const beside = Math.max(box.left - viewport.x, viewport.x + viewport.width - box.right) >= cardWidth + viewport.margin + 28;
      // Keep the real subject visible above the card. A tall list/card gets a
      // meaningful child anchor; never invent a rectangle elsewhere on the page.
      const budget = Math.max(64, viewport.height - viewport.inset - viewport.margin * 2 - Math.min(tour.naturalCardHeight || tour.card.scrollHeight, viewport.height * .58) - 36);
      if (box.height > (beside ? viewport.height - viewport.inset - viewport.margin * 2 : budget)) {
        const compact = {
          impact: '.impact-money, .impact-money .impact-number, .section-heading',
          'latest-scan': '.kpis, .kpi, .kpi strong, .section-heading',
          issues: '.issue > summary, .issue-name, .empty-compact h3, .empty-compact, .section-heading',
          history: '.scan-row > summary, .scan-date, .section-heading, h2',
          settings: '.settings-controls, [data-action="repair-toggle"], .repair-state, h2',
          subscription: '.subscription-numbers, .subscription-numbers > div, .subscription-current-heading, .simple-plan, .simple-plan-top, .subscription-intro',
          'first-report': 'h2',
          'coverage-actions': '.coverage-list-head, .empty h2',
          'coverage-default': 'summary'
        }[step.target] || 'h2, h3, summary';
        const candidates = compact.split(',').flatMap(selector => [...node.querySelectorAll(selector)]).filter(child => child.getClientRects().length && !child.closest('[hidden]'));
        return candidates.find(child => child.getBoundingClientRect().height <= budget) || candidates[0] || node;
      }
      return node;
    }
    return null;
  }

  function create(options) {
    const root = document.querySelector('.basalam-monitoring-page');
    if (!root) return null;
    const hasReport = options.hasReport ?? Boolean(root.querySelector('[data-tour="impact"]'));
    const needsSubscription = options.needsSubscription ?? false;
    const welcome = {
      tab: 'overview', target: 'first-report', fallback: '#panel-overview .empty', icon: 'time',
      title: needsSubscription ? 'آمادهٔ شروع پایش فروشگاه' : 'اولین گزارش در راه است',
      text: needsSubscription
        ? 'با انتخاب اشتراک، پایش فروشگاه شروع می‌شود. آمار پس از اولین دور زمان‌بندی‌شده نمایش داده می‌شود؛ آن زمان می‌توانید راهنمای کامل را دوباره باز کنید.'
        : 'گزارش فروشگاه پس از اولین دور زمان‌بندی‌شده اینجا نمایش داده می‌شود. فعلاً با بخش‌های پایشگر آشنا شوید؛ پس از دریافت آمار، راهنما را دوباره برای دیدن همهٔ مراحل باز کنید.'
    };
    const pendingHistory = { ...fullSteps[3], title: 'تاریخچهٔ پایش اینجا شکل می‌گیرد', text: 'پس از اولین پایش، نتیجهٔ هر دور در این بخش ثبت می‌شود. جزئیات اصلاح‌ها، مقدار قبلی و مقدار تأییدشدهٔ قیمت یا موجودی را نشان می‌دهد.' };
    const steps = options.section === 'coverage' ? coverageSteps : hasReport ? fullSteps : [welcome, pendingHistory, ...fullSteps.slice(4)];
    const dialog = document.createElement('dialog');
    const id = ++serial;
    dialog.className = 'monitor-tour';
    dialog.setAttribute('dir', 'rtl');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', `mt-title-${id}`);
    dialog.setAttribute('aria-describedby', `mt-copy-${id}`);
    dialog.innerHTML = `<svg class="mt-shade" aria-hidden="true" focusable="false"><defs><mask id="mt-mask-${id}" maskUnits="userSpaceOnUse"><rect width="100%" height="100%" fill="white"></rect><rect class="mt-hole" rx="16" fill="black"></rect></mask></defs><rect width="100%" height="100%" fill="rgba(24,35,49,.65)" mask="url(#mt-mask-${id})"></rect></svg><div class="mt-spotlight" aria-hidden="true"></div><section class="mt-card"><header class="mt-header"><span class="mt-label">راهنمای پایشگر</span><button type="button" class="mt-close" data-tour-action="skip" aria-label="بستن راهنمای مرحله‌به‌مرحله">${icon('close')}</button></header><div class="mt-step-content"><span class="mt-step-icon" aria-hidden="true"></span><p class="mt-counter"></p><h2 id="mt-title-${id}" class="mt-title" tabindex="-1"></h2><p id="mt-copy-${id}" class="mt-copy"></p></div><div class="mt-progress" aria-hidden="true">${steps.map((_, i) => `<span data-tour-dot="${i}"></span>`).join('')}</div><footer class="mt-footer"><button type="button" class="mt-next" data-tour-action="next"></button><button type="button" class="mt-back" data-tour-action="back">${icon('arrow-right')} قبلی</button><button type="button" class="mt-skip" data-tour-action="skip">بعداً می‌بینم</button></footer></section><p class="mt-live" aria-live="polite" aria-atomic="true"></p>`;
    // Allow even the final section to reach the top on short pages. Removed on
    // close; no persistent padding or product layout changes are needed.
    const spacer = document.createElement('div');
    spacer.className = 'mt-scroll-space';
    spacer.setAttribute('aria-hidden', 'true');
    root.append(spacer, dialog);
    const tour = {
      root, spacer, dialog, options, steps, step: 0, token: 0, target: null, raf: 0, busy: false,
      trigger: options.trigger || document.activeElement,
      card: dialog.querySelector('.mt-card'),
      hole: dialog.querySelector('.mt-hole'),
      ring: dialog.querySelector('.mt-spotlight'),
      title: dialog.querySelector('.mt-title'),
      onGeometry: () => scheduleLayout(tour)
    };
    dialog.addEventListener('click', event => {
      const action = event.target.closest('[data-tour-action]')?.dataset.tourAction;
      if (!action) return;
      if (action === 'skip') finish(tour, 'skipped');
      else if (tour.busy) return;
      else if (action === 'back' && tour.step > 0) void showStep(tour, tour.step - 1);
      else if (action === 'next' && tour.step < tour.steps.length - 1) void showStep(tour, tour.step + 1);
      else if (action === 'next') finish(tour, 'completed');
    });
    dialog.addEventListener('cancel', event => { event.preventDefault(); finish(tour, 'skipped'); });
    dialog.addEventListener('keydown', event => {
      if (event.key === 'Tab') {
        const controls = focusable(tour.card);
        const first = controls[0], last = controls.at(-1);
        const current = document.activeElement;
        if (!controls.includes(current)) { event.preventDefault(); (event.shiftKey ? last : first)?.focus(); }
        else if (event.shiftKey && current === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && current === last) { event.preventDefault(); first?.focus(); }
      }
      if (['PageDown', 'PageUp', 'Home', 'End'].includes(event.key) && !tour.card.contains(event.target)) event.preventDefault();
    });
    dialog.addEventListener('wheel', event => { if (!tour.card.contains(event.target)) event.preventDefault(); }, { passive: false });
    dialog.addEventListener('touchmove', event => { if (!tour.card.contains(event.target)) event.preventDefault(); }, { passive: false });
    window.addEventListener('resize', tour.onGeometry);
    window.addEventListener('scroll', tour.onGeometry, true);
    window.visualViewport?.addEventListener('resize', tour.onGeometry);
    window.visualViewport?.addEventListener('scroll', tour.onGeometry);
    tour.observer = typeof ResizeObserver === 'function' ? new ResizeObserver(tour.onGeometry) : null;
    tour.observer?.observe(tour.card);
    tour.mutations = typeof MutationObserver === 'function' ? new MutationObserver(tour.onGeometry) : null;
    tour.mutations?.observe(root.querySelector('.app-shell') || root, { childList: true, subtree: true });
    document.fonts?.ready.then(() => scheduleLayout(tour));
    dialog.showModal();
    return tour;
  }

  function scheduleLayout(tour) {
    if (active !== tour || tour.raf) return;
    tour.raf = requestAnimationFrame(() => { tour.raf = 0; position(tour); });
  }

  function position(tour) {
    if (active !== tour || !tour.dialog.open) return;
    const { width, height, x: offsetX, y: offsetY, inset, margin } = viewportBounds();
    const safeTop = offsetY + inset + margin;
    const safeBottom = offsetY + height - margin;
    const gap = 20;
    const cardWidth = Math.min(380, width - margin * 2);
    tour.spacer.style.height = `${height}px`;
    tour.card.style.width = `${cardWidth}px`;
    tour.card.style.maxHeight = `${Math.max(100, safeBottom - safeTop)}px`;
    // Measure before any docked-height constraint. Otherwise resize observers
    // can alternate between a large subject and its compact child indefinitely.
    tour.naturalCardHeight = tour.card.offsetHeight;
    const responsiveTarget = targetFor(tour, tour.steps[tour.step]);
    const changed = responsiveTarget !== tour.target;
    if (changed) {
      if (tour.target) tour.observer?.unobserve(tour.target);
      tour.target = responsiveTarget;
      if (tour.target) tour.observer?.observe(tour.target);
    }
    let cardHeight = tour.card.offsetHeight;
    let box = tour.target?.getBoundingClientRect();
    if (!tour.busy && box && (changed || box.top < safeTop + 7 || box.bottom > safeBottom - 8)) {
      window.scrollTo({ top: Math.max(0, window.scrollY + box.top - safeTop - 8), behavior: 'instant' });
      box = tour.target.getBoundingClientRect();
    }
    const padding = 8;
    if (!box || !box.width || !box.height || box.bottom <= safeTop || box.top >= safeBottom) {
      tour.hole.setAttribute('width', '0');
      tour.hole.setAttribute('height', '0');
      tour.ring.hidden = true;
      Object.assign(tour.card.style, { left: `${offsetX + (width - cardWidth) / 2}px`, top: `${safeTop + Math.max(0, (safeBottom - safeTop - cardHeight) / 2)}px` });
      tour.card.dataset.placement = 'center';
      tour.dialog.classList.add('mt-ready');
      return;
    }
    const left = Math.max(offsetX + margin / 2, box.left - padding);
    const right = Math.min(offsetX + width - margin / 2, box.right + padding);
    const top = Math.max(offsetY + inset + 8, box.top - padding);
    const bottom = Math.min(offsetY + height - 8, box.bottom + padding);
    const availableBelow = safeBottom - bottom;
    const availableAbove = top - safeTop;
    const availableLeft = left - offsetX - margin;
    const availableRight = offsetX + width - margin - right;
    let cardLeft, cardTop, placement;
    if (width >= 900 && Math.max(availableLeft, availableRight) >= cardWidth + gap) {
      const onLeft = availableLeft >= cardWidth + gap;
      cardLeft = onLeft ? left - gap - cardWidth : right + gap;
      cardTop = Math.max(safeTop, Math.min(top + (bottom - top - cardHeight) / 2, safeBottom - cardHeight));
      placement = onLeft ? 'left' : 'right';
    } else if (availableBelow >= cardHeight + gap) {
      cardTop = bottom + gap;
      cardLeft = Math.max(offsetX + margin, Math.min(right - cardWidth, offsetX + width - margin - cardWidth));
      placement = 'below';
    } else if (availableAbove >= cardHeight + gap) {
      cardTop = top - gap - cardHeight;
      cardLeft = Math.max(offsetX + margin, Math.min(right - cardWidth, offsetX + width - margin - cardWidth));
      placement = 'above';
    } else {
      // A short viewport gets a scrollable description with fixed navigation.
      // The spotlight always keeps the actual anchor's geometry.
      const below = availableBelow >= availableAbove;
      const room = Math.max(100, (below ? availableBelow : availableAbove) - gap);
      tour.card.style.maxHeight = `${room}px`;
      cardHeight = tour.card.offsetHeight;
      cardLeft = Math.max(offsetX + margin, Math.min(right - cardWidth, offsetX + width - margin - cardWidth));
      cardTop = below ? bottom + gap : top - gap - cardHeight;
      placement = below ? 'below' : 'above';
    }
    const bounds = { x: left, y: top, width: Math.max(1, right - left), height: Math.max(1, bottom - top) };
    for (const [key, value] of Object.entries(bounds)) tour.hole.setAttribute(key, String(value));
    Object.assign(tour.ring.style, { left: `${left}px`, top: `${top}px`, width: `${bounds.width}px`, height: `${bounds.height}px` });
    tour.ring.hidden = false;
    Object.assign(tour.card.style, { left: `${cardLeft}px`, top: `${cardTop}px` });
    tour.card.dataset.placement = placement;
    tour.dialog.classList.add('mt-ready');
  }

  function scrollToTarget(tour, top) {
    const scrollingRoot = document.scrollingElement || document.documentElement;
    top = Math.max(0, Math.min(top, scrollingRoot.scrollHeight - scrollingRoot.clientHeight));
    const animated = motion() && Math.abs(window.scrollY - top) > 1;
    if (!animated) {
      window.scrollTo({ top, behavior: 'instant' });
      return afterPaint();
    }
    return new Promise(resolve => {
      let settled = false, timeout;
      const done = () => {
        if (settled) return;
        settled = true;
        clearTimeout(timeout);
        document.removeEventListener('scrollend', onEnd);
        window.removeEventListener('scrollend', onEnd);
        if (tour.cancelScroll === done) tour.cancelScroll = null;
        resolve();
      };
      const onEnd = () => { if (Math.abs(window.scrollY - top) <= 2) done(); };
      tour.cancelScroll = done;
      // Register before starting: fast/small scrolls can complete immediately.
      document.addEventListener('scrollend', onEnd);
      window.addEventListener('scrollend', onEnd);
      timeout = setTimeout(() => {
        // Older browsers may not emit scrollend. End the motion before enabling
        // navigation so a quick second click has a stable target.
        if (active === tour) window.scrollTo({ top, behavior: 'instant' });
        void afterPaint().then(done);
      }, 600);
      window.scrollTo({ top, behavior: 'smooth' });
    });
  }

  async function showStep(tour, index) {
    if (active !== tour) return;
    const token = ++tour.token;
    tour.step = index;
    tour.busy = true;
    tour.dialog.querySelectorAll('.mt-next, .mt-back').forEach(button => { button.disabled = true; });
    tour.dialog.classList.add('mt-changing');
    const step = tour.steps[index];
    tour.dialog.dataset.target = step.target;
    try { await tour.options.selectTab?.(step.tab); }
    catch { finish(tour, 'interrupted'); return; }
    await afterPaint();
    if (active !== tour || token !== tour.token) return;
    if (tour.target) tour.observer?.unobserve(tour.target);
    tour.target = targetFor(tour, step);
    if (tour.target) tour.observer?.observe(tour.target);
    tour.title.textContent = step.title;
    tour.dialog.querySelector('.mt-copy').textContent = step.text;
    if (step.target === 'issues' && !tour.root.querySelector('#issues-section .issue') && tour.root.querySelector('#issues-section .empty.success')) {
      tour.title.textContent = 'موارد نیازمند رسیدگی اینجاست';
      tour.dialog.querySelector('.mt-copy').textContent = 'در گزارش فعلی موردی برای رسیدگی ثبت نشده است. اگر محصولی نیاز به پیگیری داشته باشد، علت و راه رسیدگی به همان محصول در این بخش نمایش داده می‌شود.';
    }
    if (step.target === 'history' && !tour.root.querySelector('[data-tour="history"] .scan-row')) {
      tour.title.textContent = 'تاریخچهٔ پایش اینجا شکل می‌گیرد';
      tour.dialog.querySelector('.mt-copy').textContent = 'پس از ثبت هر دور، نتیجه و جزئیات اصلاح‌ها را اینجا می‌بینید. اگر دریافت تاریخچه با خطا روبه‌رو شد، از «تازه‌سازی تاریخچه» استفاده کنید.';
    }
    if (step.target === 'coverage-actions' && !tour.root.querySelector('#panel-coverage .coverage-row')) {
      tour.title.textContent = 'عملیات هر محصول اینجا قرار می‌گیرد';
      tour.dialog.querySelector('.mt-copy').textContent = 'پس از نمایش محصولات، از منوی هر محصول می‌توانید آن را از پایش خارج یا جایگزین کنید. برای پیدا کردن محصول، جستجو و فیلترهای همین فهرست را بررسی کنید.';
    }
    tour.dialog.querySelector('.mt-counter').textContent = `قدم ${fa(index + 1)} از ${fa(tour.steps.length)}`;
    tour.dialog.querySelector('.mt-step-icon').innerHTML = icon(step.icon);
    tour.dialog.querySelector('.mt-next').innerHTML = index === tour.steps.length - 1 ? `شروع کار با پایشگر ${icon('checkmark')}` : `بعدی ${icon('arrow-left')}`;
    tour.dialog.querySelector('.mt-back').hidden = index === 0;
    tour.dialog.querySelectorAll('[data-tour-dot]').forEach((dot, i) => { dot.dataset.state = i === index ? 'current' : i < index ? 'done' : 'upcoming'; });
    tour.dialog.querySelector('.mt-live').textContent = `قدم ${fa(index + 1)} از ${fa(tour.steps.length)}: ${tour.title.textContent}`;
    tour.dialog.querySelector('.mt-step-content').scrollTop = 0;
    position(tour);
    if (tour.target) {
      const current = tour.target.getBoundingClientRect();
      const viewport = viewportBounds();
      const desiredTop = viewport.y + viewport.inset + viewport.margin + 8;
      const desiredY = Math.max(0, window.scrollY + current.top - desiredTop);
      await scrollToTarget(tour, desiredY);
    }
    await afterPaint();
    if (active !== tour || token !== tour.token) return;
    position(tour);
    // On the first step the guide itself also has a brief entrance transition.
    if (motion() && tour.card.getAnimations) {
      let animationDeadline;
      await Promise.race([
        Promise.all(tour.card.getAnimations().map(animation => animation.finished.catch(() => {}))),
        new Promise(resolve => { animationDeadline = setTimeout(resolve, 350); })
      ]);
      clearTimeout(animationDeadline);
    }
    if (active !== tour || token !== tour.token) return;
    position(tour);
    tour.title.focus({ preventScroll: true });
    tour.dialog.classList.remove('mt-changing');
    tour.busy = false;
    tour.dialog.querySelectorAll('.mt-next, .mt-back').forEach(button => { button.disabled = false; });
  }

  function finish(tour, reason) {
    if (active !== tour) return;
    active = null;
    tour.token++;
    if (tour.cancelScroll) {
      window.scrollTo({ top: window.scrollY, behavior: 'instant' });
      tour.cancelScroll();
    }
    cancelAnimationFrame(tour.raf);
    tour.observer?.disconnect();
    tour.mutations?.disconnect();
    window.removeEventListener('resize', tour.onGeometry);
    window.removeEventListener('scroll', tour.onGeometry, true);
    window.visualViewport?.removeEventListener('resize', tour.onGeometry);
    window.visualViewport?.removeEventListener('scroll', tour.onGeometry);
    tour.dialog.close();
    tour.dialog.remove();
    tour.spacer.remove();
    if (tour.trigger?.isConnected && tour.trigger.getClientRects().length) tour.trigger.focus({ preventScroll: true });
    else document.querySelector('[role="tab"][aria-selected="true"]')?.focus({ preventScroll: true });
    tour.options.onFinish?.({ completed: reason === 'completed', reason, step: tour.step });
  }

  window.MonitoringTour = Object.freeze({
    start(options = {}) {
      if (active) finish(active, 'restarted');
      const tour = create(options);
      if (!tour) return;
      active = tour;
      void showStep(tour, 0);
    },
    stop() { if (active) finish(active, 'interrupted'); },
    get isOpen() { return Boolean(active); }
  });
})();
