/** Render the admin shell immediately; resolve an uncached account asynchronously. */
(function (window, document, $) {
  "use strict";
  function init() {
    var root = document.querySelector(".salam-gate-page[data-sg-account-deferred='true']");
    if (!root || !window.basalam_ajax || !$) return;
    var body = root.querySelector("#sg-state-container, .salam-gate-parcels-page");
    if (!body) return;
    var request = null, leaving = false;
    var reloadKey = "salam-gate-account-reload:" + window.location.pathname + window.location.search;
    var states = ["not_connected", "error_network", "error_auth", "error_forbidden", "not_registered", "pending", "rejected", "inactive", "active", "disabled"];

    function loading(on) {
      body.setAttribute("aria-busy", on ? "true" : "false");
      if (on) {
        var title = body.querySelector(".sg-loading__text"), hint = body.querySelector("[data-sg-account-load-message]");
        if (title) title.textContent = "در حال دریافت اطلاعات…";
        if (hint) hint.hidden = true;
      }
      body.querySelectorAll("[data-sg-account-retry]").forEach(function (button) { button.disabled = on; button.hidden = on; });
      body.querySelectorAll(".sg-state--loading .sg-loading-spinner").forEach(function (spinner) { spinner.hidden = !on; });
    }
    function failure(message) {
      if (leaving) return;
      var title = body.querySelector(".sg-loading__text");
      if (title) title.textContent = "دریافت وضعیت حساب انجام نشد";
      var hint = body.querySelector("[data-sg-account-load-message]");
      if (hint) {
        hint.hidden = false;
        hint.textContent = message;
        hint.classList.toggle("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
      }
      if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, "error");
      loading(false);
    }
    function notifyState(state) {
      root.setAttribute("data-sg-account-state", state);
      root.dispatchEvent(new window.CustomEvent("salamgate:account-state"));
    }
    function reloadOnce() {
      // A failed cache write must not create an automatic reload loop. This
      // short-lived marker contains no identity, account or parcel data.
      try {
        var previous = Number(window.sessionStorage.getItem(reloadKey));
        if (previous && Date.now() - previous < 30000) return false;
        window.sessionStorage.setItem(reloadKey, String(Date.now()));
      } catch (ignored) {
        // Storage can be disabled. Do not risk looping; keep an explicit retry.
        return false;
      }
      leaving = true;
      window.location.reload();
      return true;
    }
    function resolve() {
      if (request || leaving) return;
      loading(true);
      request = $.ajax({
        // Offer retry when the account check has not responded within 10 seconds.
        url: window.basalam_ajax.ajaxurl, type: "POST", dataType: "json", timeout: 10000,
        data: { action: "salam_gate_account_state", _wpnonce: window.basalam_ajax.nonce, fresh: "0" }
      }).done(function (response) {
        if (leaving) return;
        var data = response && response.success === true ? response.data : null;
        if (!data || states.indexOf(data.state) < 0 || typeof data.html !== "string" || !data.html.trim()) {
          var errorMessage = response && response.data && response.data.message;
          failure(typeof errorMessage === "string" && errorMessage.trim()
            ? errorMessage : "پاسخ معتبر برای بررسی وضعیت حساب دریافت نشد؛ صفحه را تازه‌سازی کنید و دوباره تلاش کنید.");
          return;
        }
        if (data.state === "error_network") {
          // Keep provider/validation details visible; an unavailable read is not proof of disconnection.
          failure(typeof data.message === "string" && data.message.trim()
            ? data.message : "وضعیت حساب سلام‌گیت دریافت نشد؛ کمی بعد دوباره تلاش کنید.");
          return;
        }
        if (data.state.indexOf("error_") === 0 || data.state === "not_connected" || data.state === "not_registered" || data.state === "disabled") {
          $(body).html(data.html);
          notifyState(data.state);
          return;
        }
        // Tools are initialized only after a full render with a verified active account.
        if (!reloadOnce()) failure("وضعیت حساب دریافت شد، اما صفحه آماده نشد. برای ادامه یک‌بار صفحه را تازه کنید.");
      }).fail(function (xhr, status) {
        if (status === "abort") return;
        if (xhr && (xhr.status === 401 || xhr.status === 403)) {
          failure("اعتبار صفحه پایان یافته یا دسترسی مجاز نیست؛ صفحه را تازه‌سازی کنید.");
        } else if (status === "timeout") {
          failure("دریافت وضعیت حساب بیش از حد طول کشید؛ دوباره تلاش کنید.");
        } else if (status === "parsererror") {
          failure("پاسخ سایت برای بررسی حساب معتبر نیست؛ صفحه را تازه‌سازی کنید و تنظیمات امنیتی سایت را بررسی کنید.");
        } else {
          failure("درخواست بررسی وضعیت حساب انجام نشد؛ اتصال سایت و پاسخ سرور را بررسی کنید.");
        }
      }).always(function () {
        request = null;
        if (!leaving) loading(false);
      });
    }
    root.addEventListener("click", function (event) {
      var button = event.target.closest("[data-sg-account-retry]");
      if (!button || !root.contains(button)) return;
      event.preventDefault();
      try { window.sessionStorage.removeItem(reloadKey); } catch (ignored) {}
      resolve();
    });
    window.addEventListener("pagehide", function () {
      leaving = true;
      if (request && typeof request.abort === "function") request.abort();
    });
    // Navigating away remains native and is never intercepted or disabled.
    resolve();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
}(window, document, window.jQuery));
