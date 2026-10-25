/**
 * Coherent data regions. This API is available before ready handlers execute,
 * so the action-owning modules can explicitly mark semantic success/failure.
 * It never starts a request, retries a mutation or disables page navigation.
 */
(function (window, document) {
  "use strict";
  // Keep read transport shorter than the loader watchdog. A stalled jqXHR must
  // actually settle so action owners can unlock their explicit read-only retry.
  // Financial owners can supply their own longer timeout; never retry a POST.
  window.WoosalamGateRequests = {
    post: function (url, data, timeout) {
      var financial = data && ["salam_gate_pay_parcel", "salam_gate_send_pay", "salam_gate_parcel_checkout_pay", "salam_gate_parcel_cancel", "salam_gate_register_shop", "salam_gate_onboarding_register"].indexOf(data.action) !== -1;
      var duration = typeof timeout === "number" && Number.isFinite(timeout) && timeout > 0 ? timeout : (financial ? 90000 : 40000);
      return window.jQuery.ajax({ url: url, type: "POST", dataType: "json", timeout: duration, data: data });
    }
  };
  var records = new WeakMap(), timeoutMs = 45000;
  var noTicket = { ready: function () {}, fail: function () {} };

  function children(root, attribute) {
    return Array.prototype.filter.call(root.children, function (child) { return child.hasAttribute(attribute); })[0] || null;
  }
  function setAttribute(element, key, value) {
    if (element && element.getAttribute(key) !== value) element.setAttribute(key, value);
  }
  function text(element, value) {
    if (element && element.textContent !== value) element.textContent = value;
  }
  function update(record) {
    var tasks = Array.from(record.tasks.values());
    var pending = !tasks.length || tasks.some(function (task) { return task.state === "loading"; });
    var failed = tasks.filter(function (task) { return task.state === "error"; });
    var state = failed.length ? "error" : (pending ? "loading" : "ready");
    setAttribute(record.root, "data-sg-load-state", state);
    setAttribute(record.root, "aria-busy", state === "loading" ? "true" : "false");
    if (record.content) {
      if (state === "ready") record.content.removeAttribute("inert");
      else if (!record.content.hasAttribute("inert")) record.content.setAttribute("inert", "");
    }
    if (record.indicator) {
      record.indicator.hidden = state === "ready";
      setAttribute(record.indicator, "role", state === "error" ? "alert" : "status");
      var spinner = record.indicator.querySelector("[data-sg-load-spinner]");
      if (spinner) spinner.hidden = state !== "loading";
      text(record.indicator.querySelector("[data-sg-load-title]"), state === "error" ? "دریافت اطلاعات ناموفق بود" : "در حال دریافت اطلاعات…");
      text(record.indicator.querySelector("[data-sg-load-message]"), failed.length
        ? failed.map(function (task) { return task.message; }).filter(function (message, index, all) { return all.indexOf(message) === index; }).join(" ")
        : "");
      var message = record.indicator.querySelector("[data-sg-load-message]");
      if (message) message.hidden = state !== "error";
      if (message) message.classList.toggle("sg-feedback-toast-only", state === "error" && typeof window.woosalamNotify === "function");
      var retry = record.indicator.querySelector("[data-sg-load-retry]");
      if (retry) { retry.hidden = !failed.length; retry.disabled = pending && tasks.length > 0; }
    }
    if (state === "ready" && record.initialTimer) { window.clearTimeout(record.initialTimer); record.initialTimer = null; }
  }
  function register(root) {
    var existing = records.get(root);
    if (existing) return existing;
    var record = { root: root, indicator: children(root, "data-sg-load-indicator"), content: children(root, "data-sg-load-content"), tasks: new Map(), initialTimer: null };
    records.set(root, record);
    // If a page script never initializes, a finite error replaces the spinner.
    // Reload is safe: this control exists before any payment/create action.
    if (root.getAttribute("data-sg-load-state") === "loading") {
      record.initialTimer = window.setTimeout(function () {
        record.initialTimer = null;
        if (record.tasks.size) return;
        record.tasks.set("initialization", { state: "error", message: "آماده‌سازی صفحه انجام نشد. برای دریافت دوباره اطلاعات، تلاش دوباره را بزنید.", retry: function () { window.location.reload(); } });
        if (typeof window.woosalamNotify === "function") window.woosalamNotify(record.tasks.get("initialization").message, "error");
        update(record);
      }, timeoutMs);
      update(record);
    }
    var retry = record.indicator && record.indicator.querySelector("[data-sg-load-retry]");
    if (retry) retry.addEventListener("click", function () {
      if (retry.disabled) return;
      var failed = Array.from(record.tasks.values()).filter(function (task) { return task.state === "error"; });
      failed.forEach(function (task) { if (typeof task.retry === "function") task.retry(); });
    });
    return record;
  }
  function begin(element, key, retry) {
    var root = element && element.closest && element.closest("[data-sg-load]");
    if (!root) return noTicket;
    var record = register(root), previous = record.tasks.get(key);
    record.tasks.delete("initialization");
    if (previous && previous.timer) window.clearTimeout(previous.timer);
    var task = { state: "loading", message: "", retry: typeof retry === "function" ? retry : function () { window.location.reload(); }, timer: null };
    record.tasks.set(key, task);
    if (record.initialTimer) { window.clearTimeout(record.initialTimer); record.initialTimer = null; }
    function finish(state, message) {
      if (record.tasks.get(key) !== task) return; // Ignore superseded reads.
      var newlyFailed = state === "error" && task.state !== "error";
      if (task.timer) window.clearTimeout(task.timer);
      task.timer = null;
      task.state = state;
      task.message = typeof message === "string" && message.trim() ? message : "ارتباط برای دریافت اطلاعات برقرار نشد. کمی بعد دوباره تلاش کنید.";
      if (newlyFailed && typeof window.woosalamNotify === "function") window.woosalamNotify(task.message, "error");
      update(record);
    }
    task.timer = window.setTimeout(function () { finish("error", "دریافت اطلاعات بیش از حد طول کشید. اتصال اینترنت را بررسی کنید و دوباره تلاش کنید."); }, timeoutMs);
    update(record);
    return { ready: function () { finish("ready"); }, fail: function (message) { finish("error", message); } };
  }
  window.WoosalamGateLoading = { begin: begin };
  function initRegions() { document.querySelectorAll("[data-sg-load]").forEach(register); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initRegions);
  else initRegions();
}(window, document));

/**
 * Shared Salam Gate admin chrome and dialogs.
 * Existing index.js remains responsible for actions and showing/hiding dialogs.
 */
(function () {
  "use strict";

  function init() {
    var pages = Array.prototype.slice.call(document.querySelectorAll(".salam-gate-page"));
    var orderOverlay = document.getElementById("salam-gate-parcel-modal");
    if (!pages.length && !orderOverlay) return;

    var records = new Map();
    var accountStates = new WeakMap();
    var openDialogs = [];
    var keyboardListening = false;
    var titleId = 0;
    var focusableSelector = "a[href], button, input:not([type='hidden']), select, textarea, [tabindex], [contenteditable='true']";
    var triggers = [
      ["[data-sg-bo-view], [data-sg-bo-import-confirm]", "#sg-order-preview-modal"],
      ["[data-send-open-receiver]", "[data-send-receiver-modal]"],
      [".sg-settings__edit-shop", "[data-sg-shop-edit-modal]"],
      [".sg-settings__edit-addr", "[data-sg-address-edit-modal]"],
      ["[data-sender-edit-address]", "[data-sg-address-details-modal]"],
      ["[data-send-items] button", "[data-send-remove-modal]"],
      ["button[data-sgp-filter-modal]", ".sg-modal-overlay[data-sgp-filter-modal]"],
      ["[data-sgp-print-modal]", "[data-sgp-print-modal-el]"],
      [".sgp-detail", "#sgp-detail-modal"],
      [".salam-gate-open-modal", "#salam-gate-parcel-modal"]
    ];

    function visible(element) {
      if (!element || !element.isConnected || element.hidden || !element.getClientRects().length) return false;
      var style = window.getComputedStyle(element);
      return style.display !== "none" && style.visibility !== "hidden";
    }

    function canFocus(element) {
      return visible(element) && !element.disabled && !element.closest("[inert]");
    }

    function tabbable(dialog) {
      return Array.prototype.filter.call(dialog.querySelectorAll(focusableSelector), function (element) {
        if (!canFocus(element) || element.tabIndex < 0) return false;
        // A radio group is one Tab stop; checked radio wins, otherwise the first.
        if (element.type === "radio" && element.name) {
          var group = Array.prototype.filter.call(dialog.querySelectorAll("input[type='radio']"), function (radio) {
            return radio.name === element.name && canFocus(radio);
          });
          var selected = group.filter(function (radio) { return radio.checked; })[0] || group[0];
          return element === selected;
        }
        return true;
      });
    }

    function focusDialog(record) {
      if (record.dialog.contains(document.activeElement) && canFocus(document.activeElement)) return;
      var elements = tabbable(record.dialog);
      var target = record.dialog.querySelector("[autofocus]");
      if (!canFocus(target)) {
        target = elements.filter(function (element) {
          return !element.matches("[data-close-modal], .sg-modal__close, .salam-gate-modal__close, .basalam-modal-close-abs");
        })[0] || elements[0] || record.dialog;
      }
      target.focus({ preventScroll: true });
    }

    function topDialog() {
      return openDialogs[openDialogs.length - 1] || null;
    }

    function unrelatedDialog(element) {
      var dialog = element.closest("[role='dialog'], [role='alertdialog'], .media-modal, .components-modal__frame, .ui-dialog, .wp-dialog, .basalam-modal-content, .basalam-info-modal-overlay, .basalam-plus-modal-overlay");
      if (!dialog || !visible(dialog)) return false;
      var owned = false;
      records.forEach(function (record) {
        if (dialog === record.dialog || dialog === record.overlay) owned = true;
      });
      return !owned;
    }

    function containFocus(event) {
      var record = topDialog();
      if (!record || record.dialog.contains(event.target) || unrelatedDialog(event.target)) return;
      // Refocusing inside the dialog fires focusin again; containment then exits.
      focusDialog(record);
    }

    function keyboard(event) {
      var record = topDialog();
      if (!record) return;
      // Leave unrelated WordPress dialogs and their keyboard handling alone.
      if (unrelatedDialog(event.target)) return;

      if (event.key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        // A pending quote selection/payment cannot be abandoned visually while
        // its result is unknown. The owning checkout unlocks only on completion.
        if (record.overlay.getAttribute("data-sg-financial-busy") === "true") return;
        var close = record.dialog.querySelector("[data-close-modal], [data-sgpc-close], .salam-gate-modal__close, .basalam-modal-close-abs");
        if (close && !close.disabled) close.click();
        // Dialogs with no close control still follow the existing hide contract.
        if (visible(record.overlay)) record.overlay.style.display = "none";
        syncDialogs();
        return;
      }

      var legacyClose = event.target.closest(".basalam-modal-close-abs");
      if (legacyClose && !legacyClose.matches("button, a[href]") && (event.key === "Enter" || event.key === " ")) {
        event.preventDefault();
        legacyClose.click();
        syncDialogs();
        return;
      }

      if (event.key !== "Tab") return;
      var elements = tabbable(record.dialog);
      var first = elements[0] || record.dialog;
      var last = elements[elements.length - 1] || record.dialog;
      var active = document.activeElement;
      if (!record.dialog.contains(active) || !elements.length ||
          (event.shiftKey && (active === first || active === record.dialog)) ||
          (!event.shiftKey && (active === last || active === record.dialog))) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus({ preventScroll: true });
      }
    }

    function rememberOpener(event) {
      triggers.some(function (mapping) {
        var opener = event.target.closest(mapping[0]);
        if (!opener) return false;
        var overlay = document.querySelector(mapping[1]);
        var record = records.get(overlay);
        // A print choice is hidden once its disclosure closes. Return focus
        // to the persistent button, not an invisible option after the dialog.
        var printMenu = opener.closest("[data-sgp-print-menu]");
        if (record) record.pendingOpener = printMenu ? printMenu.querySelector("[data-sgp-print-toggle]") || opener : opener;
        return true;
      });
    }

    function register(overlay) {
      if (records.has(overlay)) return;
      var dialog = overlay.querySelector(".sg-modal, .salam-gate-modal");
      if (!dialog) return;
      dialog.setAttribute("role", "dialog");
      if (!dialog.hasAttribute("tabindex")) dialog.setAttribute("tabindex", "-1");
      if (!dialog.hasAttribute("aria-label") && !dialog.hasAttribute("aria-labelledby")) {
        var heading = dialog.querySelector(".sg-modal__header h4, .sg-modal__header h3, .salam-gate-modal__header h3");
        if (heading) {
          if (!heading.id) {
            do { titleId += 1; } while (document.getElementById("sg-dialog-title-" + titleId));
            heading.id = "sg-dialog-title-" + titleId;
          }
          dialog.setAttribute("aria-labelledby", heading.id);
        }
      }
      var close = dialog.querySelector(".basalam-modal-close-abs");
      if (close && !close.matches("button, a[href]")) {
        close.setAttribute("role", "button");
        close.setAttribute("tabindex", "0");
        close.setAttribute("aria-label", "بستن");
      }
      records.set(overlay, { overlay: overlay, dialog: dialog, open: false, opener: null, pendingOpener: null });
    }

    function syncDialogs() {
      pages.forEach(function (page) {
        page.querySelectorAll(".sg-modal-overlay").forEach(register);
      });
      if (orderOverlay) register(orderOverlay);

      var previousTop = topDialog();
      var closed = [];
      records.forEach(function (record) {
        var nowOpen = visible(record.overlay);
        if (nowOpen && !record.open) {
          record.opener = record.pendingOpener ||
            (!record.overlay.contains(document.activeElement) ? document.activeElement : null);
          record.pendingOpener = null;
          record.open = true;
          openDialogs.push(record);
          focusDialog(record);
        } else if (!nowOpen && record.open) {
          record.open = false;
          openDialogs = openDialogs.filter(function (item) { return item !== record; });
          closed.push(record);
        }
        var hidden = nowOpen ? "false" : "true";
        if (record.overlay.getAttribute("aria-hidden") !== hidden) record.overlay.setAttribute("aria-hidden", hidden);
        if (!record.overlay.isConnected) records.delete(record.overlay);
      });

      var top = topDialog();
      openDialogs.forEach(function (record) {
        var modal = record === top ? "true" : "false";
        if (record.dialog.getAttribute("aria-modal") !== modal) record.dialog.setAttribute("aria-modal", modal);
      });
      if (closed.indexOf(previousTop) !== -1) {
        var opener = closed.slice().reverse().map(function (record) { return record.opener; }).filter(function (element) {
          return canFocus(element) && (!top || top.dialog.contains(element));
        })[0];
        if (opener) {
          opener.focus({ preventScroll: true });
        } else if (top) {
          focusDialog(top);
        }
      }
      // Existing handlers can switch a modal's stage while its overlay stays open.
      if (top && top.overlay.contains(document.activeElement) && !canFocus(document.activeElement)) focusDialog(top);
      if (!!top !== keyboardListening) {
        keyboardListening = !!top;
        document[keyboardListening ? "addEventListener" : "removeEventListener"]("keydown", keyboard);
        document[keyboardListening ? "addEventListener" : "removeEventListener"]("focusin", containFocus);
      }
    }

    var labels = {
      active: ["حساب فعال", "active"],
      pending: ["در انتظار تأیید", "pending"],
      inactive: ["حساب غیرفعال", "inactive"],
      disabled: ["غیرفعال در این سایت", "inactive"],
      rejected: ["درخواست رد شده", "inactive"],
      not_registered: ["فعال‌سازی نشده", "neutral"],
      not_connected: ["اتصال برقرار نیست", "error"],
      error_auth: ["نیاز به اتصال مجدد", "error"],
      error_forbidden: ["دسترسی‌های حساب را تأیید کنید.", "error"],
      error_network: ["بررسی وضعیت ناموفق", "error"],
      loading: ["در حال بررسی وضعیت", "neutral"]
    };

    function syncAccount(page) {
      var container = page.querySelector("#sg-state-container");
      var stateNode = container && container.querySelector("[data-state]");
      var state = stateNode ? stateNode.getAttribute("data-state") : page.getAttribute("data-sg-account-state");
      if (!labels[state]) return;
      // Results, validation messages and city options all change child nodes.
      // They are not account transitions and must not rewrite navigation or
      // wake every select observer on the page.
      if (accountStates.get(page) === state) return;
      accountStates.set(page, state);
      if (page.getAttribute("data-sg-account-state") !== state) page.setAttribute("data-sg-account-state", state);
      var badge = page.querySelector("[data-sg-account-status]");
      if (badge) {
        badge.setAttribute("data-state", state);
        badge.className = "sg-account-status sg-account-status--" + labels[state][1];
        if (badge.textContent !== labels[state][0]) badge.textContent = labels[state][0];
      }
      var nav = page.querySelector("[data-sg-nav]");
      if (!nav) return;
      nav.querySelectorAll("[data-sg-nav-item]").forEach(function (link) {
        if (link.getAttribute("data-sg-requires-active") === "true") link.hidden = state !== "active";
        if (link.getAttribute("data-sg-requires-registration") === "true") {
          link.hidden = state !== "not_registered" && nav.getAttribute("data-sg-current-view") !== "activation";
        }
      });
    }

    // Class/text changes while typing or loading city options do not need a
    // complete dialog layout pass. Batch relevant work into one animation frame.
    var dialogSyncQueued = false;
    function scheduleDialogs(changes) {
      var relevant = changes.some(function (change) {
        if (change.type === "attributes") {
          var target = change.target;
          if (target.matches(".sg-modal-overlay, #salam-gate-parcel-modal")) return true;
          return openDialogs.some(function (record) {
            return record.overlay.contains(target) || target.contains(record.overlay);
          });
        }
        if (openDialogs.some(function (record) { return record.overlay.contains(change.target); })) return true;
        return Array.prototype.some.call(change.addedNodes, function (node) {
          return node.nodeType === 1 && (node.matches(".sg-modal-overlay") || node.querySelector(".sg-modal-overlay"));
        }) || Array.prototype.some.call(change.removedNodes, function (node) {
          return node.nodeType === 1 && openDialogs.some(function (record) { return node === record.overlay || node.contains(record.overlay); });
        });
      });
      if (!relevant || dialogSyncQueued) return;
      dialogSyncQueued = true;
      (window.requestAnimationFrame || function (callback) { return window.setTimeout(callback, 16); })(function () {
        dialogSyncQueued = false;
        syncDialogs();
      });
    }

    pages.forEach(function (page) {
      page.addEventListener("click", rememberOpener, true);
      page.addEventListener("salamgate:account-state", function () { syncAccount(page); });
      new MutationObserver(scheduleDialogs).observe(page, {
        childList: true, subtree: true, attributes: true, attributeFilter: ["style", "class", "hidden"]
      });
      syncAccount(page);
      var stateContainer = page.querySelector("#sg-state-container");
      if (stateContainer) {
        new MutationObserver(function () { syncAccount(page); }).observe(stateContainer, { childList: true, subtree: true });
      }
    });
    if (orderOverlay) {
      new MutationObserver(scheduleDialogs).observe(orderOverlay, {
        childList: true, subtree: true, attributes: true, attributeFilter: ["style", "class", "hidden"]
      });
      document.querySelectorAll(".salam-gate-open-modal").forEach(function (opener) {
        opener.addEventListener("click", rememberOpener, true);
      });
    }
    syncDialogs();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
}());
